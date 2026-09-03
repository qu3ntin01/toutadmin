const express = require('express');

const db = require('../db');
const { requireAuth } = require('../middleware/auth');

const router = express.Router();

router.use(requireAuth);

// Les membres masqués par l'administration n'apparaissent pour personne, y compris eux-mêmes.
router.get('/', (req, res) => {
  const query = (req.query.q || '').trim().slice(0, 80);
  const like = `%${query.toLowerCase()}%`;

  const people = query
    ? db.prepare(`
        SELECT u.*, m.first_name AS manager_first_name, m.last_name AS manager_last_name
        FROM users u
        LEFT JOIN users m ON m.id = u.manager_id
        WHERE u.role = 'employee' AND u.active = 1 AND u.directory_hidden = 0
          AND (lower(u.first_name) LIKE ? OR lower(u.last_name) LIKE ? OR lower(u.department) LIKE ?
               OR lower(u.grade) LIKE ? OR lower(u.email) LIKE ?)
        ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
      `).all(like, like, like, like, like)
    : db.prepare(`
        SELECT u.*, m.first_name AS manager_first_name, m.last_name AS manager_last_name
        FROM users u
        LEFT JOIN users m ON m.id = u.manager_id
        WHERE u.role = 'employee' AND u.active = 1 AND u.directory_hidden = 0
        ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
      `).all();

  const me = db.prepare('SELECT directory_hidden FROM users WHERE id = ?').get(req.session.user.id);

  res.render('directory', { people, query, viewerHidden: Boolean(me && me.directory_hidden) });
});

module.exports = router;
