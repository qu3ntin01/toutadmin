const express = require('express');

const db = require('../db');
const audit = require('../audit');
const safety = require('../safety');
const { requireHR } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

// Le document unique, le registre des accidents et le suivi médical sont des
// obligations de l'employeur : ils relèvent des RH et de l'administration.
router.use(requireHR);

const back = (anchor) => `/sante-securite#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function employees() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

router.get('/', (req, res) => {
  res.render('sante-securite', {
    riskList: safety.risks(),
    incidentList: safety.incidents(),
    indicators: safety.indicators(),
    ppeList: safety.ppeItems(),
    ppeGiven: safety.ppeAssignments(),
    visitList: safety.visits(),
    upcoming: safety.upcoming(),
    incidentKinds: safety.INCIDENT_KINDS,
    visitKinds: safety.VISIT_KINDS,
    severities: safety.SEVERITIES,
    likelihoods: safety.LIKELIHOODS,
    actionThreshold: safety.ACTION_THRESHOLD,
    employees: employees(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Document unique ----------

router.post('/risques', (req, res) => {
  const unit = (req.body.unit || '').trim();
  const hazard = (req.body.hazard || '').trim();
  if (!unit || !hazard) return fail(req, res, 'risques', 'Unité de travail et danger sont requis.');

  const severity = Number(req.body.severity);
  const likelihood = Number(req.body.likelihood);
  if (!safety.SEVERITIES.includes(severity) || !safety.LIKELIHOODS.includes(likelihood)) {
    return fail(req, res, 'risques', 'Cotation invalide.');
  }

  const reviewed = readDate(req.body.reviewed_on);
  const next = readDate(req.body.next_review);
  if (!reviewed.ok || !next.ok) return fail(req, res, 'risques', 'Date invalide.');

  const id = safety.createRisk({
    unit: unit.slice(0, 120),
    hazard: hazard.slice(0, 200),
    exposure: (req.body.exposure || '').trim().slice(0, 300),
    severity,
    likelihood,
    measures: (req.body.measures || '').trim().slice(0, 2000),
    reviewedOn: reviewed.value,
    nextReview: next.value,
  });
  audit.log(req, 'duerp.risque_ajoute', 'risk_assessments', id, { unite: unit, danger: hazard });
  setFlash(req, 'success', 'Risque consigné au document unique.');
  res.redirect(back('risques'));
});

router.post('/risques/:id/supprimer', (req, res) => {
  const risk = safety.riskById(req.params.id);
  if (!risk) return fail(req, res, 'risques', 'Risque introuvable.');
  safety.deleteRisk(risk.id);
  audit.log(req, 'duerp.risque_supprime', 'risk_assessments', risk.id, { danger: risk.hazard });
  setFlash(req, 'success', 'Risque retiré du document unique.');
  res.redirect(back('risques'));
});

// ---------- Registre des accidents ----------

router.post('/accidents', (req, res) => {
  const occurred = readDate(req.body.occurred_on, { required: true });
  if (!occurred.ok) return fail(req, res, 'accidents', "Date de l'accident invalide.");
  if (occurred.value > new Date().toISOString().slice(0, 10)) {
    return fail(req, res, 'accidents', 'Un accident ne se consigne pas à l\'avance.');
  }
  if (!safety.INCIDENT_KINDS.includes(req.body.kind)) return fail(req, res, 'accidents', 'Nature invalide.');

  const declared = readDate(req.body.declared_on);
  if (!declared.ok) return fail(req, res, 'accidents', 'Date de déclaration invalide.');

  const days = Number(req.body.days_off || 0);
  if (!Number.isInteger(days) || days < 0 || days > 3650) return fail(req, res, 'accidents', "Nombre de jours d'arrêt invalide.");

  const id = safety.createIncident({
    occurredOn: occurred.value,
    userId: Number(req.body.user_id) || null,
    kind: req.body.kind,
    location: (req.body.location || '').trim().slice(0, 160),
    description: (req.body.description || '').trim().slice(0, 3000),
    daysOff: days,
    declaredOn: declared.value,
    followUp: (req.body.follow_up || '').trim().slice(0, 2000),
  });
  audit.log(req, 'sst.accident_consigne', 'workplace_incidents', id, { nature: req.body.kind, jours_arret: days });
  setFlash(req, 'success', 'Accident consigné au registre.');
  res.redirect(back('accidents'));
});

router.post('/accidents/:id/supprimer', (req, res) => {
  safety.deleteIncident(Number(req.params.id));
  audit.log(req, 'sst.accident_supprime', 'workplace_incidents', Number(req.params.id));
  setFlash(req, 'success', 'Entrée retirée du registre.');
  res.redirect(back('accidents'));
});

// ---------- Équipements de protection ----------

router.post('/protections', (req, res) => {
  const name = (req.body.name || '').trim();
  if (!name) return fail(req, res, 'protections', 'Intitulé invalide.');

  const validity = (req.body.validity_months || '').trim();
  const months = validity ? Number(validity) : null;
  if (validity && (!Number.isInteger(months) || months < 1 || months > 600)) {
    return fail(req, res, 'protections', 'Durée de validité invalide.');
  }

  safety.createPpe({ name: name.slice(0, 120), category: (req.body.category || 'Protection').trim().slice(0, 60), validityMonths: months });
  setFlash(req, 'success', 'Équipement déclaré.');
  res.redirect(back('protections'));
});

router.post('/protections/:id/supprimer', (req, res) => {
  safety.deletePpe(Number(req.params.id));
  setFlash(req, 'success', 'Équipement supprimé, avec ses remises.');
  res.redirect(back('protections'));
});

router.post('/protections/remettre', (req, res) => {
  const issued = readDate(req.body.issued_on, { required: true });
  if (!issued.ok) return fail(req, res, 'protections', 'Date de remise invalide.');

  const done = safety.issuePpe({
    ppeId: Number(req.body.ppe_id),
    userId: Number(req.body.user_id),
    issuedOn: issued.value,
  });
  if (!done) return fail(req, res, 'protections', 'Équipement introuvable.');

  setFlash(req, 'success', "Remise consignée. L'échéance découle de la durée de validité.");
  res.redirect(back('protections'));
});

router.post('/protections/remises/:id/rendre', (req, res) => {
  safety.returnPpe(Number(req.params.id));
  setFlash(req, 'success', 'Restitution consignée.');
  res.redirect(back('protections'));
});

// ---------- Visites médicales ----------

router.post('/visites', (req, res) => {
  const userId = Number(req.body.user_id);
  if (!db.prepare('SELECT 1 FROM users WHERE id = ?').get(userId)) return fail(req, res, 'visites', 'Membre introuvable.');
  if (!safety.VISIT_KINDS.includes(req.body.kind)) return fail(req, res, 'visites', 'Type de visite invalide.');

  const scheduled = readDate(req.body.scheduled_on);
  const done = readDate(req.body.done_on);
  const next = readDate(req.body.next_due);
  if (!scheduled.ok || !done.ok || !next.ok) return fail(req, res, 'visites', 'Date invalide.');

  safety.createVisit({
    userId,
    kind: req.body.kind,
    scheduledOn: scheduled.value,
    doneOn: done.value,
    // L'avis du médecin se note en clair ; aucune donnée de santé n'est demandée ici.
    verdict: (req.body.verdict || '').trim().slice(0, 200),
    nextDue: next.value,
  });
  setFlash(req, 'success', 'Visite consignée.');
  res.redirect(back('visites'));
});

router.post('/visites/:id/supprimer', (req, res) => {
  safety.deleteVisit(Number(req.params.id));
  setFlash(req, 'success', 'Visite retirée.');
  res.redirect(back('visites'));
});

module.exports = router;
