const express = require('express');

const db = require('../db');
const announcements = require('../announcements');
const { requireManager } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireManager);

function teamOf(managerId) {
  return db
    .prepare("SELECT * FROM users WHERE manager_id = ? ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE")
    .all(managerId);
}

router.get('/', (req, res) => {
  const managerId = req.session.user.id;
  const team = teamOf(managerId);
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

  res.render('manager', {
    team,
    requests,
    upcoming,
    teamNews: announcements.forTeam(managerId),
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

  if (!title) {
    setFlash(req, 'error', "Le titre de l'actualité est obligatoire.");
    return res.redirect('/mon-equipe#actualites');
  }

  announcements.create({
    authorId: req.session.user.id,
    scope: 'team',
    teamManagerId: req.session.user.id,
    title,
    body,
  });

  setFlash(req, 'success', 'Actualité publiée pour votre équipe.');
  res.redirect('/mon-equipe#actualites');
});

router.post('/actualites/:id/supprimer', (req, res) => {
  announcements.removeForManager(Number(req.params.id), req.session.user.id);
  setFlash(req, 'success', 'Actualité supprimée.');
  res.redirect('/mon-equipe#actualites');
});

module.exports = router;
