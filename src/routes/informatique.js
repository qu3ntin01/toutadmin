const express = require('express');

const db = require('../db');
const audit = require('../audit');
const it = require('../it');
const dev = require('../dev');
const finance = require('../finance');
const { requireIt } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

// Le parc logiciel et les accès applicatifs relèvent du service informatique,
// désigné par l'administration — pas de la gestion financière, qui paie les
// factures sans répondre des habilitations qu'elles ouvrent.
router.use(requireIt);

const back = (anchor) => `/informatique#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

/**
 * Un horodatage de formulaire (« 2026-04-12T09:30 ») devient « 2026-04-12 09:30 »,
 * la forme que SQLite compare et trie comme du texte.
 */
function readMoment(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  const match = trimmed.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})$/);
  if (!match || !isValidDateString(match[1])) return { ok: false };
  if (Number(match[2]) > 23 || Number(match[3]) > 59) return { ok: false };
  return { ok: true, value: `${match[1]} ${match[2]}:${match[3]}` };
}

function readSeats(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: 0 };
  const value = Number(trimmed);
  if (!Number.isInteger(value) || value < 0 || value > 100000) return { ok: false };
  return { ok: true, value };
}

function readCost(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || value < 0 || value > 1e7) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

function employees() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

function licenceFields(body) {
  const name = (body.name || '').trim().slice(0, 120);
  if (!name) return { ok: false, message: 'Nom du logiciel obligatoire.' };
  if (!it.LICENCE_KINDS.includes(body.kind)) return { ok: false, message: 'Type de licence invalide.' };
  if (!it.CRITICALITIES.includes(body.criticality)) return { ok: false, message: 'Criticité invalide.' };
  if (!finance.BILLING_PERIODS.includes(body.billing_period)) return { ok: false, message: 'Périodicité invalide.' };

  const seats = readSeats(body.seats);
  if (!seats.ok) return { ok: false, message: 'Nombre de sièges invalide.' };
  const cost = readCost(body.unit_cost);
  if (!cost.ok) return { ok: false, message: 'Coût invalide.' };
  const renewal = readDate(body.renewal_date);
  if (!renewal.ok) return { ok: false, message: 'Date de renouvellement invalide.' };

  return {
    ok: true,
    fields: {
      name,
      publisher: (body.publisher || '').trim().slice(0, 120),
      kind: body.kind,
      seats: seats.value,
      unitCost: cost.value,
      billingPeriod: body.billing_period,
      renewalDate: renewal.value,
      ownerId: Number(body.owner_id) || null,
      criticality: body.criticality,
      personalData: body.personal_data === '1',
      notes: (body.notes || '').trim().slice(0, 1000),
    },
  };
}

// ---------------------------------------------------------------- écrans

router.get('/', (req, res) => {
  res.render('informatique', {
    licenceList: it.licences({ includeRetired: req.query.retires === '1' }),
    showRetired: req.query.retires === '1',
    review: it.accessReview(),
    incidentList: it.incidents({ limit: 100 }),
    serviceList: dev.services({ includeRetired: true }),
    summary: it.summary(),
    kinds: it.LICENCE_KINDS,
    criticalities: it.CRITICALITIES,
    severities: it.SEVERITIES,
    incidentStatuses: it.INCIDENT_STATUSES,
    billingPeriods: finance.BILLING_PERIODS,
    employees: employees(),
    downtime: it.downtimeMinutes,
    today: new Date().toISOString().slice(0, 10),
  });
});

router.get('/logiciels/:id', (req, res) => {
  const licence = it.licenceById(req.params.id);
  if (!licence) return res.status(404).render('error', { message: 'Logiciel introuvable.' });

  const openIds = new Set(it.accesses(licence.id, { includeRevoked: false }).map((a) => a.user_id));
  res.render('logiciel', {
    licence,
    accessList: it.accesses(licence.id),
    grantable: employees().filter((u) => !openIds.has(u.id)),
    yearlyCost: it.yearlyCost(licence),
    kinds: it.LICENCE_KINDS,
    criticalities: it.CRITICALITIES,
    statuses: it.LICENCE_STATUSES,
    levels: it.ACCESS_LEVELS,
    billingPeriods: finance.BILLING_PERIODS,
    employees: employees(),
  });
});

// ---------------------------------------------------------------- logiciels

router.post('/logiciels', (req, res) => {
  const parsed = licenceFields(req.body);
  if (!parsed.ok) return fail(req, res, back('nouveau'), parsed.message);

  const id = it.createLicence({ ...parsed.fields, status: 'Actif' });
  audit.log(req, 'informatique.logiciel_ajoute', 'software_licences', id, { nom: parsed.fields.name });
  setFlash(req, 'success', 'Logiciel enregistré.');
  res.redirect(`/informatique/logiciels/${id}`);
});

router.post('/logiciels/:id/modifier', (req, res) => {
  const licence = it.licenceById(req.params.id);
  if (!licence) return fail(req, res, back('logiciels'), 'Logiciel introuvable.');

  const parsed = licenceFields(req.body);
  if (!parsed.ok) return fail(req, res, `/informatique/logiciels/${licence.id}`, parsed.message);
  if (!it.LICENCE_STATUSES.includes(req.body.status)) {
    return fail(req, res, `/informatique/logiciels/${licence.id}`, 'Statut invalide.');
  }
  // Réduire les sièges sous le nombre d'accès ouverts mettrait l'instance en
  // défaut de licence sans que rien ne le signale : la saisie est refusée.
  if (parsed.fields.seats > 0 && parsed.fields.seats < licence.seats_used) {
    return fail(req, res, `/informatique/logiciels/${licence.id}`,
      `${licence.seats_used} accès sont ouverts : retirez-en avant de descendre à ${parsed.fields.seats} sièges.`);
  }

  it.updateLicence(licence.id, { ...parsed.fields, status: req.body.status });
  setFlash(req, 'success', 'Logiciel mis à jour.');
  res.redirect(`/informatique/logiciels/${licence.id}`);
});

router.post('/logiciels/:id/supprimer', (req, res) => {
  const licence = it.licenceById(req.params.id);
  if (!licence) return fail(req, res, back('logiciels'), 'Logiciel introuvable.');

  it.removeLicence(licence.id);
  audit.log(req, 'informatique.logiciel_supprime', 'software_licences', licence.id, { nom: licence.name });
  setFlash(req, 'success', 'Logiciel supprimé, avec ses accès.');
  res.redirect(back('logiciels'));
});

// ---------------------------------------------------------------- accès

router.post('/logiciels/:id/acces', (req, res) => {
  const licence = it.licenceById(req.params.id);
  if (!licence) return fail(req, res, back('logiciels'), 'Logiciel introuvable.');

  const target = `/informatique/logiciels/${licence.id}`;
  if (!it.ACCESS_LEVELS.includes(req.body.level)) return fail(req, res, target, "Niveau d'accès invalide.");

  const userId = Number(req.body.user_id);
  if (!db.prepare('SELECT 1 FROM users WHERE id = ? AND active = 1').get(userId)) {
    return fail(req, res, target, 'Membre introuvable.');
  }

  const result = it.grantAccess({
    licenceId: licence.id,
    userId,
    level: req.body.level,
    grantedBy: req.session.user.id,
    note: (req.body.note || '').trim(),
  });
  if (!result.ok) {
    const message = result.reason === 'complet'
      ? `Les ${result.seats} sièges de cette licence sont attribués.`
      : 'Cet accès est déjà ouvert.';
    return fail(req, res, target, message);
  }

  audit.log(req, 'informatique.acces_ouvert', 'software_accesses', result.id, {
    logiciel: licence.name, beneficiaire: userId, niveau: req.body.level,
  });
  setFlash(req, 'success', 'Accès ouvert.');
  res.redirect(target);
});

router.post('/acces/:id/revoquer', (req, res) => {
  const id = Number(req.params.id);
  const row = db.prepare('SELECT licence_id, user_id FROM software_accesses WHERE id = ?').get(id);
  if (!row) return fail(req, res, back('acces'), 'Accès introuvable.');

  it.revokeAccess(id);
  audit.log(req, 'informatique.acces_revoque', 'software_accesses', id, { beneficiaire: row.user_id });
  setFlash(req, 'success', 'Accès révoqué.');
  res.redirect(req.body.retour === 'revue' ? back('acces') : `/informatique/logiciels/${row.licence_id}`);
});

router.post('/acces/:id/revu', (req, res) => {
  if (!it.markReviewed(Number(req.params.id))) return fail(req, res, back('acces'), 'Accès introuvable.');
  setFlash(req, 'success', 'Accès marqué comme revu.');
  res.redirect(back('acces'));
});

// ---------------------------------------------------------------- incidents

router.post('/incidents', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 150);
  if (!title) return fail(req, res, back('incidents'), "Intitulé de l'incident obligatoire.");
  if (!it.SEVERITIES.includes(req.body.severity)) return fail(req, res, back('incidents'), 'Gravité invalide.');

  const started = readMoment(req.body.started_at, { required: true });
  if (!started.ok) return fail(req, res, back('incidents'), 'Date de début invalide.');
  const detected = readMoment(req.body.detected_at);
  if (!detected.ok) return fail(req, res, back('incidents'), 'Date de détection invalide.');

  const id = it.createIncident({
    reference: (req.body.reference || '').trim().slice(0, 40),
    title,
    serviceId: Number(req.body.service_id) || null,
    severity: req.body.severity,
    startedAt: started.value,
    detectedAt: detected.value,
    impact: (req.body.impact || '').trim().slice(0, 1000),
    declaredBy: req.session.user.id,
  });
  audit.log(req, 'informatique.incident_declare', 'it_incidents', id, { titre: title, gravite: req.body.severity });
  setFlash(req, 'success', 'Incident déclaré.');
  res.redirect(back('incidents'));
});

router.post('/incidents/:id/modifier', (req, res) => {
  const incident = it.incidentById(req.params.id);
  if (!incident) return fail(req, res, back('incidents'), 'Incident introuvable.');
  if (!it.SEVERITIES.includes(req.body.severity)) return fail(req, res, back('incidents'), 'Gravité invalide.');
  if (!it.INCIDENT_STATUSES.includes(req.body.status)) return fail(req, res, back('incidents'), 'Statut invalide.');

  const started = readMoment(req.body.started_at, { required: true });
  const detected = readMoment(req.body.detected_at);
  const resolved = readMoment(req.body.resolved_at);
  if (!started.ok || !detected.ok || !resolved.ok) return fail(req, res, back('incidents'), 'Date invalide.');
  // Un rétablissement antérieur au début fausserait le délai moyen sans bruit.
  if (resolved.value && resolved.value < started.value) {
    return fail(req, res, back('incidents'), 'Le rétablissement ne peut pas précéder le début de la panne.');
  }
  // Un incident déclaré résolu sans heure de rétablissement ne se mesure pas.
  const closing = req.body.status === 'Résolu' || req.body.status === 'Clos';
  if (closing && !resolved.value) {
    return fail(req, res, back('incidents'), "Renseignez l'heure de rétablissement pour clore l'incident.");
  }

  it.updateIncident(incident.id, {
    title: (req.body.title || '').trim().slice(0, 150) || incident.title,
    serviceId: Number(req.body.service_id) || null,
    severity: req.body.severity,
    startedAt: started.value,
    detectedAt: detected.value,
    resolvedAt: resolved.value,
    impact: (req.body.impact || '').trim().slice(0, 1000),
    cause: (req.body.cause || '').trim().slice(0, 1000),
    remediation: (req.body.remediation || '').trim().slice(0, 1000),
    status: req.body.status,
  });
  setFlash(req, 'success', 'Incident mis à jour.');
  res.redirect(back('incidents'));
});

router.post('/incidents/:id/supprimer', (req, res) => {
  const incident = it.incidentById(req.params.id);
  if (!incident) return fail(req, res, back('incidents'), 'Incident introuvable.');

  it.removeIncident(incident.id);
  audit.log(req, 'informatique.incident_supprime', 'it_incidents', incident.id, { titre: incident.title });
  setFlash(req, 'success', 'Incident supprimé.');
  res.redirect(back('incidents'));
});

module.exports = router;
