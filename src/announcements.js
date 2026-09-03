const db = require('./db');

function create({ authorId, scope, teamManagerId, title, body }) {
  return db
    .prepare('INSERT INTO announcements (author_id, scope, team_manager_id, title, body) VALUES (?, ?, ?, ?, ?)')
    .run(authorId, scope, scope === 'team' ? teamManagerId : null, title, body).lastInsertRowid;
}

function remove(id) {
  db.prepare('DELETE FROM announcements WHERE id = ?').run(id);
}

function removeForManager(id, managerId) {
  db.prepare("DELETE FROM announcements WHERE id = ? AND scope = 'team' AND team_manager_id = ?").run(id, managerId);
}

/** Fil d'un collaborateur : les annonces de l'entreprise et celles de son équipe. */
function forEmployee(employee, limit = 12) {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE a.scope = 'company' OR (a.scope = 'team' AND a.team_manager_id = ?)
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(employee.manager_id || -1, limit);
}

function forTeam(managerId, limit = 20) {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE a.scope = 'team' AND a.team_manager_id = ?
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(managerId, limit);
}

function companyWide(limit = 20) {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name
    FROM announcements a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE a.scope = 'company'
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(limit);
}

module.exports = { create, remove, removeForManager, forEmployee, forTeam, companyWide };
