const express = require('express');

const db = require('../db');
const org = require('../org');
const announcements = require('../announcements');
const oneonone = require('../oneonone');
const { requireManager } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

router.use(requireManager);

router.get('/', (req, res) => {
  const managerId = req.session.user.id;
  const scopes = org.scopesManagedBy(managerId);
  const team = org.membersManagedBy(managerId);
  const ids = team.map((m) => m.id);

  // Aucun collaborateur : pas de requête IN () invalide.
  const placeholders = ids.map(() => '?').join(',');
  const requests = ids.length
    ? db.prepare(`
        SELECT r.*, u.first_name, u.last_name
        FROM hr_requests r JOIN users u ON u.id = r.employee_id
        WHERE r.employee_id IN (${placeholders})
        ORDER BY r.created_at DESC LIMIT 50
      `).all(...ids)
    : [];

  const today = new Date().toISOString().slice(0, 10);
  const upcoming = requests.filter((r) => r.status === 'Approuvée' && r.end_date >= today);

  // Les périmètres encadrés, nommés : ils servent aussi de destinataires d'actualité.
  const managedScopes = [
    ...scopes.departments.map((id) => ({ scope: 'department', id, name: (org.departmentById(id) || {}).name })),
    ...scopes.teams.map((id) => ({ scope: 'team', id, name: (org.teamById(id) || {}).name })),
  ].filter((s) => s.name);

  res.render('manager', {
    team,
    requests,
    upcoming,
    managedScopes,
    teamNews: announcements.forScopes(scopes),
    points: oneonone.forManager(managerId),
    cadence: oneonone.cadence(managerId),
    pointStatuses: oneonone.STATUSES,
    pointStats: oneonone.summary(managerId),
    today,
    stats: {
      teamSize: team.length,
      pending: requests.filter((r) => r.status === 'En attente').length,
      upcoming: upcoming.length,
      leaveTotal: team.reduce((sum, m) => sum + (m.leave_balance || 0), 0),
    },
  });
});

router.post('/actualites', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 150);
  const body = (req.body.body || '').trim().slice(0, 2000);
  const [scope, rawId] = (req.body.target || '').split(':');
  const scopeId = Number(rawId);

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/mon-equipe#actualites');
  };

  if (!title) return fail("Le titre de l'actualité est obligatoire.");

  // Un manager ne publie que sur un périmètre qu'il encadre effectivement.
  const scopes = org.scopesManagedBy(req.session.user.id);
  const allowed = scope === 'team' ? scopes.teams : scope === 'department' ? scopes.departments : [];
  if (!allowed.includes(scopeId)) return fail("Vous n'encadrez pas ce périmètre.");

  announcements.create({ authorId: req.session.user.id, scope, scopeId, title, body });
  setFlash(req, 'success', 'Actualité publiée.');
  res.redirect('/mon-equipe#actualites');
});

router.post('/actualites/:id/supprimer', (req, res) => {
  const scopes = org.scopesManagedBy(req.session.user.id);
  if (!announcements.removeWithinScopes(Number(req.params.id), scopes)) {
    setFlash(req, 'error', "Cette actualité ne relève pas d'un périmètre que vous encadrez.");
  } else {
    setFlash(req, 'success', 'Actualité supprimée.');
  }
  res.redirect('/mon-equipe#actualites');
});

// ---------------------------------------------------------------- points individuels

const backToPoints = '/mon-equipe#points';

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function readMood(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const value = Number(trimmed);
  if (!Number.isInteger(value) || value < 1 || value > 5) return { ok: false };
  return { ok: true, value };
}

router.post('/points', (req, res) => {
  const scheduled = readDate(req.body.scheduled_on, { required: true });
  if (!scheduled.ok) {
    setFlash(req, 'error', 'Date invalide.');
    return res.redirect(backToPoints);
  }

  const result = oneonone.create({
    managerId: req.session.user.id,
    employeeId: Number(req.body.employee_id),
    scheduledOn: scheduled.value,
    topics: (req.body.topics || '').trim(),
  });
  if (!result.ok) {
    setFlash(req, 'error', "Vous n'encadrez pas cette personne.");
    return res.redirect(backToPoints);
  }
  setFlash(req, 'success', 'Point individuel planifié.');
  res.redirect(backToPoints);
});

router.post('/points/:id/modifier', (req, res) => {
  const scheduled = readDate(req.body.scheduled_on, { required: true });
  const held = readDate(req.body.held_on);
  const next = readDate(req.body.next_on);
  const mood = readMood(req.body.mood);
  if (!scheduled.ok || !held.ok || !next.ok || !mood.ok) {
    setFlash(req, 'error', 'Saisie invalide.');
    return res.redirect(backToPoints);
  }
  if (!oneonone.STATUSES.includes(req.body.status)) {
    setFlash(req, 'error', 'Statut invalide.');
    return res.redirect(backToPoints);
  }
  // Un point « tenu » sans date de tenue ne compte dans aucune cadence : il
  // disparaîtrait du suivi tout en paraissant fait.
  if (req.body.status === 'Tenu' && !held.value) {
    setFlash(req, 'error', 'Renseignez la date à laquelle le point a été tenu.');
    return res.redirect(backToPoints);
  }

  const done = oneonone.update(Number(req.params.id), req.session.user.id, {
    scheduledOn: scheduled.value,
    heldOn: held.value,
    topics: (req.body.topics || '').trim(),
    sharedNote: (req.body.shared_note || '').trim(),
    privateNote: (req.body.private_note || '').trim(),
    mood: mood.value,
    nextOn: next.value,
    status: req.body.status,
  });
  if (!done) {
    setFlash(req, 'error', "Ce point ne vous appartient pas, ou vous n'encadrez plus cette personne.");
    return res.redirect(backToPoints);
  }
  setFlash(req, 'success', 'Point individuel enregistré.');
  res.redirect(backToPoints);
});

router.post('/points/:id/supprimer', (req, res) => {
  if (!oneonone.remove(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', 'Ce point ne vous appartient pas.');
  } else {
    setFlash(req, 'success', 'Point individuel supprimé.');
  }
  res.redirect(backToPoints);
});

module.exports = router;
