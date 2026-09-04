const express = require('express');

const db = require('../db');
const org = require('../org');
const announcements = require('../announcements');
const { requireManager } = require('../middleware/auth');
const { setFlash } = require('../utils');

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

module.exports = router;
