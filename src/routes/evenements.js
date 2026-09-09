const express = require('express');

const db = require('../db');
const audit = require('../audit');
const events = require('../events');
const org = require('../org');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

// Tout le monde voit les événements qui le concernent ; seuls l'administration
// et les ressources humaines en créent. Un séminaire engage un budget et le
// temps de travail de l'entreprise : ce n'est pas une invitation entre collègues.
router.use(requireAuth);

function canManage(req) {
  return req.session.user.role === 'admin' || Boolean(req.currentUser && req.currentUser.is_hr);
}

function requireOrganizer(req, res, next) {
  if (!canManage(req)) {
    return res.status(403).render('error', { message: "La création d'événements est réservée à l'administration et aux ressources humaines." });
  }
  next();
}

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

function readMoment(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  const match = trimmed.match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})$/);
  if (!match || !isValidDateString(match[1])) return { ok: false };
  if (Number(match[2]) > 23 || Number(match[3]) > 59) return { ok: false };
  return { ok: true, value: `${match[1]} ${match[2]}:${match[3]}` };
}

function readAmount(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || value < 0 || value > 1e8) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

function eventFields(body) {
  const title = (body.title || '').trim().slice(0, 150);
  if (!title) return { ok: false, message: "Intitulé de l'événement obligatoire." };
  if (!events.KINDS.includes(body.kind)) return { ok: false, message: "Nature d'événement invalide." };

  const scope = body.scope === 'department' || body.scope === 'team' ? body.scope : 'company';
  const scopeId = Number(body.scope_id) || null;
  if (scope !== 'company' && !scopeId) return { ok: false, message: 'Choisissez le service ou l\'équipe visé.' };
  if (scope === 'department' && !org.departmentById(scopeId)) return { ok: false, message: 'Service introuvable.' };
  if (scope === 'team' && !org.teamById(scopeId)) return { ok: false, message: 'Équipe introuvable.' };

  const starts = readMoment(body.starts_at, { required: true });
  const ends = readMoment(body.ends_at);
  if (!starts.ok || !ends.ok) return { ok: false, message: 'Date invalide.' };
  if (ends.value && ends.value < starts.value) return { ok: false, message: 'La fin précède le début.' };

  const closes = (body.registration_closes_on || '').trim();
  if (closes && !isValidDateString(closes)) return { ok: false, message: 'Date de clôture invalide.' };
  // Clore les inscriptions après l'événement n'aurait aucun effet : autant le dire.
  if (closes && closes > starts.value.slice(0, 10)) {
    return { ok: false, message: 'La clôture des inscriptions doit précéder l\'événement.' };
  }

  const capacity = (body.capacity || '').trim();
  const capacityValue = capacity ? Number(capacity) : 0;
  if (!Number.isInteger(capacityValue) || capacityValue < 0 || capacityValue > 100000) {
    return { ok: false, message: 'Capacité invalide.' };
  }

  const budget = readAmount(body.budget);
  const cost = readAmount(body.cost);
  if (!budget.ok || !cost.ok) return { ok: false, message: 'Montant invalide.' };

  return {
    ok: true,
    fields: {
      title,
      kind: body.kind,
      description: (body.description || '').trim().slice(0, 4000),
      location: (body.location || '').trim().slice(0, 200),
      startsAt: starts.value,
      endsAt: ends.value,
      scope,
      scopeId,
      capacity: capacityValue,
      registrationClosesOn: closes || null,
      budget: budget.value,
      cost: cost.value,
    },
  };
}

// ---------------------------------------------------------------- écrans

router.get('/', (req, res) => {
  const manage = canManage(req);
  res.render('evenements', {
    eventList: manage ? events.all() : events.visibleTo(req.currentUser),
    mine: events.visibleTo(req.currentUser, { limit: 200 }).filter((e) => e.my_status && e.my_status !== 'Annulée'),
    canManage: manage,
    summary: events.summary(),
    kinds: events.KINDS,
    statuses: events.EVENT_STATUSES,
    departments: org.departments(),
    teams: org.teams(),
    employees: db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all(),
  });
});

router.get('/:id', (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return res.status(404).render('error', { message: 'Événement introuvable.' });

  const manage = canManage(req);
  // Un brouillon n'existe pas encore pour l'entreprise ; hors de son périmètre,
  // un événement n'est pas non plus une information publique.
  if (!manage && (event.status === 'Brouillon' || !events.concerns(event, req.currentUser))) {
    return res.status(404).render('error', { message: 'Événement introuvable.' });
  }

  res.render('evenement', {
    event,
    // La liste nominative des participants reste à l'organisateur : l'annuaire
    // laisse chacun se retirer, un émargement ne doit pas le contourner.
    registrationList: manage ? events.registrations(event.id) : [],
    myRegistration: events.registrationOf(event.id, req.session.user.id),
    seatsLeft: events.seatsLeft(event),
    canManage: manage,
    concerned: events.concerns(event, req.currentUser),
    kinds: events.KINDS,
    statuses: events.EVENT_STATUSES,
    departments: org.departments(),
    teams: org.teams(),
    employees: db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------------------------------------------------------------- organisation

router.post('/', requireOrganizer, (req, res) => {
  const parsed = eventFields(req.body);
  if (!parsed.ok) return fail(req, res, '/evenements#nouveau', parsed.message);

  const id = events.create({ ...parsed.fields, organizerId: req.session.user.id, status: 'Brouillon' });
  audit.log(req, 'evenements.cree', 'company_events', id, { titre: parsed.fields.title });
  setFlash(req, 'success', 'Événement créé en brouillon : ouvrez les inscriptions quand il est prêt.');
  res.redirect(`/evenements/${id}`);
});

router.post('/:id/modifier', requireOrganizer, (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return fail(req, res, '/evenements', 'Événement introuvable.');

  const parsed = eventFields(req.body);
  if (!parsed.ok) return fail(req, res, `/evenements/${event.id}`, parsed.message);
  if (!events.EVENT_STATUSES.includes(req.body.status)) return fail(req, res, `/evenements/${event.id}`, 'Statut invalide.');
  // Réduire la capacité sous le nombre d'inscrits reviendrait à décider en
  // silence qui reste dehors.
  if (parsed.fields.capacity > 0 && parsed.fields.capacity < event.taken) {
    return fail(req, res, `/evenements/${event.id}`,
      `${event.taken} personnes sont inscrites : la capacité ne peut pas descendre à ${parsed.fields.capacity}.`);
  }

  const wasCancelled = event.status === 'Annulé';
  events.update(event.id, {
    ...parsed.fields,
    organizerId: Number(req.body.organizer_id) || event.organizer_id,
    status: req.body.status,
  });
  if (req.body.status === 'Annulé' && !wasCancelled) events.notifyCancellation(events.byId(event.id));
  events.syncFullness(event.id);

  setFlash(req, 'success', 'Événement mis à jour.');
  res.redirect(`/evenements/${event.id}`);
});

router.post('/:id/supprimer', requireOrganizer, (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return fail(req, res, '/evenements', 'Événement introuvable.');

  events.remove(event.id);
  audit.log(req, 'evenements.supprime', 'company_events', event.id, { titre: event.title });
  setFlash(req, 'success', 'Événement supprimé, avec ses inscriptions.');
  res.redirect('/evenements');
});

/** L'organisateur inscrit quelqu'un : la file s'applique de la même façon. */
router.post('/:id/inscrire', requireOrganizer, (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return fail(req, res, '/evenements', 'Événement introuvable.');

  const userId = Number(req.body.user_id);
  if (!db.prepare('SELECT 1 FROM users WHERE id = ? AND active = 1').get(userId)) {
    return fail(req, res, `/evenements/${event.id}`, 'Membre introuvable.');
  }

  const result = events.register(event.id, userId);
  if (!result.ok) {
    const messages = {
      ferme: 'Les inscriptions ne sont pas ouvertes.',
      cloture: 'Les inscriptions sont closes.',
      deja: 'Cette personne est déjà inscrite.',
    };
    return fail(req, res, `/evenements/${event.id}`, messages[result.reason] || 'Inscription impossible.');
  }
  setFlash(req, 'success', result.status === 'Inscrit' ? 'Inscription enregistrée.' : "Ajouté à la liste d'attente.");
  res.redirect(`/evenements/${event.id}`);
});

router.post('/inscriptions/:id/emargement', requireOrganizer, (req, res) => {
  const row = db.prepare('SELECT event_id FROM event_registrations WHERE id = ?').get(Number(req.params.id));
  if (!row) return fail(req, res, '/evenements', 'Inscription introuvable.');
  if (!events.markAttendance(Number(req.params.id), req.body.presence)) {
    return fail(req, res, `/evenements/${row.event_id}`, 'Émargement invalide.');
  }
  setFlash(req, 'success', 'Émargement enregistré.');
  res.redirect(`/evenements/${row.event_id}`);
});

// ---------------------------------------------------------------- participation

router.post('/:id/inscription', (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return fail(req, res, '/evenements', 'Événement introuvable.');
  if (event.status === 'Brouillon' || !events.concerns(event, req.currentUser)) {
    return res.status(403).render('error', { message: "Cet événement ne vous est pas ouvert." });
  }

  const result = events.register(event.id, req.session.user.id);
  if (!result.ok) {
    const messages = {
      ferme: 'Les inscriptions ne sont pas ouvertes.',
      cloture: 'Les inscriptions sont closes.',
      deja: 'Vous êtes déjà inscrit.',
    };
    return fail(req, res, `/evenements/${event.id}`, messages[result.reason] || 'Inscription impossible.');
  }
  setFlash(req, 'success', result.status === 'Inscrit'
    ? 'Vous êtes inscrit.'
    : "L'événement est complet : vous êtes en liste d'attente, et vous serez prévenu si une place se libère.");
  res.redirect(`/evenements/${event.id}`);
});

router.post('/:id/desistement', (req, res) => {
  const event = events.byId(req.params.id);
  if (!event) return fail(req, res, '/evenements', 'Événement introuvable.');

  const result = events.cancel(event.id, req.session.user.id);
  if (!result.ok) return fail(req, res, `/evenements/${event.id}`, "Vous n'êtes pas inscrit.");
  setFlash(req, 'success', 'Votre inscription est annulée.');
  res.redirect(`/evenements/${event.id}`);
});

module.exports = router;
