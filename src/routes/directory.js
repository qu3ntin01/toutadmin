const express = require('express');

const db = require('../db');
const org = require('../org');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();

router.use(requireAuth);

// L'annuaire montre le rattachement de chacun : son équipe, son service, ses managers.
const SELECT_PEOPLE = `
  SELECT u.*, t.name AS team_name, d.name AS department_name
  FROM users u
  LEFT JOIN teams t ON t.id = u.team_id
  LEFT JOIN departments d ON d.id = u.department_id
`;

// Les membres masqués par l'administration n'apparaissent pour personne, y compris eux-mêmes.
router.get('/', (req, res) => {
  const query = (req.query.q || '').trim().slice(0, 80);
  const like = `%${query.toLowerCase()}%`;

  // Filtres de navigation : par équipe ou par service.
  const teamFilter = Number(req.query.equipe) || null;
  const departmentFilter = Number(req.query.service) || null;

  const clauses = ["u.role = 'employee'", 'u.active = 1', 'u.directory_hidden = 0'];
  const params = [];

  if (query) {
    clauses.push(`(lower(u.first_name) LIKE ? OR lower(u.last_name) LIKE ? OR lower(u.grade) LIKE ?
                   OR lower(u.email) LIKE ? OR lower(COALESCE(t.name, '')) LIKE ? OR lower(COALESCE(d.name, '')) LIKE ?)`);
    params.push(like, like, like, like, like, like);
  }
  if (teamFilter) {
    clauses.push('u.team_id = ?');
    params.push(teamFilter);
  }
  if (departmentFilter) {
    clauses.push('u.department_id = ?');
    params.push(departmentFilter);
  }

  const people = db.prepare(`
    ${SELECT_PEOPLE}
    WHERE ${clauses.join(' AND ')}
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(...params);

  const me = db.prepare('SELECT directory_hidden FROM users WHERE id = ?').get(req.session.user.id);

  res.render('directory', {
    people,
    query,
    teamFilter,
    departmentFilter,
    teams: org.teams(),
    departments: org.departments(),
    managersFor: (person) => org.managersFor(person),
    viewerHidden: Boolean(me && me.directory_hidden),
  });
});

module.exports = router;
