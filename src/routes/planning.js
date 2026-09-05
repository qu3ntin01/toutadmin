const express = require('express');

const db = require('../db');
const audit = require('../audit');
const planning = require('../planning');
const org = require('../org');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

router.use(requireAuth);

/**
 * Planifier engage les journées d'autrui : c'est réservé à ceux qui encadrent,
 * aux RH et à l'administration. Consulter, en revanche, est ouvert à tous —
 * un planning que l'on ne peut pas lire ne sert à personne.
 */
function canPlan(req) {
  const user = req.currentUser || req.session.user;
  return user.role === 'admin' || Boolean(user.is_hr) || org.isManager(user.id);
}

function requirePlanner(req, res, next) {
  if (!canPlan(req)) {
    return res.status(403).render('error', { message: "Seuls les managers, les RH et l'administration modifient le planning." });
  }
  next();
}

const back = (start, anchor = 'semaine') => `/planning?semaine=${start}#${anchor}`;

function fail(req, res, start, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(start));
}

const text = (raw, max) => (raw || '').trim().slice(0, max);

/** « 2026-09-07T09:00 » : la minute suffit, la seconde n'apporte rien ici. */
function readMoment(day, time) {
  if (!isValidDateString(day) || !/^([01]\d|2[0-3]):[0-5]\d$/.test(time || '')) return null;
  return `${day}T${time}`;
}

function plannablePeople(req) {
  const user = req.currentUser || req.session.user;
  if (user.role === 'admin' || user.is_hr) {
    return db.prepare("SELECT id, first_name, last_name, team_id FROM users WHERE active = 1 AND role = 'employee' ORDER BY last_name COLLATE NOCASE").all();
  }
  // Un manager ne planifie que les siens : la liste est la barrière.
  return org.membersManagedBy(user.id);
}

router.get('/', (req, res) => {
  const requested = isValidDateString(req.query.semaine) ? req.query.semaine : new Date().toISOString().slice(0, 10);
  const start = planning.weekStart(requested);
  const end = planning.addDays(start, 6);
  const planner = canPlan(req);
  const user = req.currentUser || req.session.user;

  const teamId = Number(req.query.equipe) || null;
  // Sans droit de planification, on ne voit que son propre planning publié —
  // et le sien en entier, brouillon compris, puisqu'il le concerne.
  const view = planner
    ? planning.grid(start, { teamId })
    : planning.grid(start, { userIds: [user.id] });

  res.render('planning', {
    planner,
    week: view,
    start,
    end,
    previousWeek: planning.addDays(start, -7),
    nextWeek: planning.addDays(start, 7),
    teamId,
    teams: org.teams(),
    kinds: planning.KINDS,
    weekdays: planning.WEEKDAYS,
    templateList: planning.templates(),
    onCallList: planning.onCall(start, end),
    onCallNow: planning.whoIsOnCall(),
    loadList: planner ? planning.load(start, end, { teamId }) : [],
    people: planner ? plannablePeople(req) : [],
    mine: planning.between(start, end, { userId: user.id }),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Créneaux ----------

router.post('/creneaux', requirePlanner, (req, res) => {
  const start = planning.weekStart(isValidDateString(req.body.week) ? req.body.week : new Date().toISOString().slice(0, 10));
  const userId = Number(req.body.user_id) || 0;
  if (!plannablePeople(req).some((p) => p.id === userId)) return fail(req, res, start, 'Cette personne n\'est pas dans votre périmètre.');
  if (!planning.KINDS.includes(req.body.kind)) return fail(req, res, start, 'Type de créneau inconnu.');

  const day = (req.body.day || '').trim();
  const startsAt = readMoment(day, req.body.start_time);
  const endsAt = readMoment(req.body.end_day && req.body.end_day.trim() ? req.body.end_day.trim() : day, req.body.end_time);
  if (!startsAt || !endsAt) return fail(req, res, start, 'Date ou horaire invalide.');
  if (endsAt <= startsAt) return fail(req, res, start, 'Le créneau finit avant de commencer.');

  const clash = planning.conflicts({ userId, startsAt, endsAt });
  if (clash.blocked) {
    return fail(req, res, start, clash.leave.length
      ? 'Absence déjà accordée sur ce créneau : le planning ne peut pas la contredire.'
      : 'Cette personne a déjà un créneau qui chevauche celui-ci.');
  }

  const id = planning.create({
    userId,
    startsAt,
    endsAt,
    kind: req.body.kind,
    label: text(req.body.label, 160),
    location: text(req.body.location, 160),
    teamId: Number(req.body.team_id) || null,
    published: req.body.published === '1',
    createdBy: req.session.user.id,
  });
  audit.log(req, 'planning.creneau_ajoute', 'shifts', id, { personne: userId, debut: startsAt, type: req.body.kind });
  setFlash(req, 'success', 'Créneau posé.');
  res.redirect(back(start));
});

router.post('/creneaux/:id/supprimer', requirePlanner, (req, res) => {
  const shift = planning.byId(req.params.id);
  const start = planning.weekStart(shift ? shift.starts_at.slice(0, 10) : new Date().toISOString().slice(0, 10));
  if (!shift) return fail(req, res, start, 'Créneau introuvable.');
  if (!plannablePeople(req).some((p) => p.id === shift.user_id)) return fail(req, res, start, 'Hors de votre périmètre.');

  planning.remove(shift.id);
  audit.log(req, 'planning.creneau_retire', 'shifts', shift.id, { personne: shift.user_id, debut: shift.starts_at });
  setFlash(req, 'success', 'Créneau retiré.');
  res.redirect(back(start));
});

router.post('/publier', requirePlanner, (req, res) => {
  const start = planning.weekStart(isValidDateString(req.body.week) ? req.body.week : new Date().toISOString().slice(0, 10));
  const teamId = Number(req.body.team_id) || null;

  const published = planning.publishWeek(start, { teamId });
  audit.log(req, 'planning.publie', 'shifts', null, { semaine: start, creneaux: published });
  setFlash(req, published ? 'success' : 'error', published
    ? `${published} créneau(x) publié(s) : la semaine est annoncée.`
    : 'Rien à publier sur cette semaine.');
  res.redirect(back(start));
});

// ---------- Roulements ----------

router.post('/roulements', requirePlanner, (req, res) => {
  const start = planning.weekStart(isValidDateString(req.body.week) ? req.body.week : new Date().toISOString().slice(0, 10));
  const name = text(req.body.name, 120);
  if (!name) return fail(req, res, start, 'Un nom est requis.');
  if (!planning.KINDS.includes(req.body.kind)) return fail(req, res, start, 'Type inconnu.');

  const weekdays = [].concat(req.body.weekdays || []).map(Number).filter((d) => d >= 1 && d <= 7);
  if (!weekdays.length) return fail(req, res, start, 'Choisissez au moins un jour.');
  if (!/^([01]\d|2[0-3]):[0-5]\d$/.test(req.body.start_time || '') || !/^([01]\d|2[0-3]):[0-5]\d$/.test(req.body.end_time || '')) {
    return fail(req, res, start, 'Horaire invalide.');
  }

  const id = planning.createTemplate({
    name,
    kind: req.body.kind,
    startTime: req.body.start_time,
    endTime: req.body.end_time,
    weekdays,
    location: text(req.body.location, 160),
  });
  audit.log(req, 'planning.roulement_cree', 'shift_templates', id, { nom: name });
  setFlash(req, 'success', 'Roulement enregistré.');
  res.redirect(back(start, 'roulements'));
});

router.post('/roulements/:id/appliquer', requirePlanner, (req, res) => {
  const start = planning.weekStart(isValidDateString(req.body.week) ? req.body.week : new Date().toISOString().slice(0, 10));
  const userId = Number(req.body.user_id) || 0;
  if (!plannablePeople(req).some((p) => p.id === userId)) return fail(req, res, start, 'Cette personne n\'est pas dans votre périmètre.');

  const from = (req.body.from || '').trim();
  const to = (req.body.to || '').trim();
  if (!isValidDateString(from) || !isValidDateString(to)) return fail(req, res, start, 'Période invalide.');
  // Un roulement appliqué sur deux ans remplirait la base sans que personne
  // ne l'ait voulu : on borne à un trimestre par application.
  if (planning.addDays(from, 92) < to) return fail(req, res, start, 'Appliquez un roulement sur trois mois au plus.');

  const verdict = planning.applyTemplate(Number(req.params.id), {
    userId, from, to, createdBy: req.session.user.id, published: req.body.published === '1',
  });
  if (!verdict.ok) return fail(req, res, start, verdict.message);

  audit.log(req, 'planning.roulement_applique', 'shift_templates', Number(req.params.id), {
    personne: userId, du: from, au: to, poses: verdict.created.length, sautes: verdict.skipped.length,
  });
  setFlash(req, 'success', verdict.skipped.length
    ? `${verdict.created.length} créneau(x) posé(s), ${verdict.skipped.length} jour(s) sauté(s) : ${verdict.skipped.map((s) => `${s.day} (${s.reason})`).join(', ')}.`
    : `${verdict.created.length} créneau(x) posé(s).`);
  res.redirect(back(start, 'roulements'));
});

router.post('/roulements/:id/supprimer', requirePlanner, (req, res) => {
  const start = planning.weekStart(isValidDateString(req.body.week) ? req.body.week : new Date().toISOString().slice(0, 10));
  planning.deleteTemplate(Number(req.params.id));
  setFlash(req, 'success', 'Roulement supprimé. Les créneaux déjà posés restent.');
  res.redirect(back(start, 'roulements'));
});

module.exports = router;
