const db = require('./db');

// Une actualité vise l'entreprise entière, un service, ou une équipe.
const SCOPES = ['company', 'department', 'team'];

function create({ authorId, scope, scopeId, title, body }) {
  return db
    .prepare('INSERT INTO announcements (author_id, scope, scope_id, title, body) VALUES (?, ?, ?, ?, ?)')
    .run(authorId, scope, scope === 'company' ? null : scopeId, title, body).lastInsertRowid;
}

function remove(id) {
  db.prepare('DELETE FROM announcements WHERE id = ?').run(id);
}

/** Un manager ne peut retirer qu'une actualité publiée sur un périmètre qu'il encadre. */
function removeWithinScopes(id, scopes) {
  const announcement = db.prepare('SELECT * FROM announcements WHERE id = ?').get(id);
  if (!announcement || announcement.scope === 'company') return false;

  const allowed = announcement.scope === 'team' ? scopes.teams : scopes.departments;
  if (!allowed.includes(announcement.scope_id)) return false;

  remove(id);
  return true;
}

const SELECT_WITH_AUTHOR = `
  SELECT a.*, u.first_name, u.last_name,
    CASE a.scope WHEN 'team' THEN (SELECT t.name FROM teams t WHERE t.id = a.scope_id)
                 WHEN 'department' THEN (SELECT d.name FROM departments d WHERE d.id = a.scope_id)
                 ELSE NULL END AS scope_name
  FROM announcements a
  LEFT JOIN users u ON u.id = a.author_id
`;

/** Fil d'un collaborateur : l'entreprise, son service et son équipe. */
function forEmployee(employee, limit = 12) {
  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    WHERE a.scope = 'company'
       OR (a.scope = 'department' AND a.scope_id = ?)
       OR (a.scope = 'team' AND a.scope_id = ?)
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(employee.department_id || -1, employee.team_id || -1, limit);
}

/** Fil d'un manager : ce qu'il a publié sur les périmètres qu'il encadre. */
function forScopes(scopes, limit = 30) {
  if (scopes.teams.length === 0 && scopes.departments.length === 0) return [];

  const clauses = [];
  const params = [];
  if (scopes.teams.length) {
    clauses.push(`(a.scope = 'team' AND a.scope_id IN (${scopes.teams.map(() => '?').join(',')}))`);
    params.push(...scopes.teams);
  }
  if (scopes.departments.length) {
    clauses.push(`(a.scope = 'department' AND a.scope_id IN (${scopes.departments.map(() => '?').join(',')}))`);
    params.push(...scopes.departments);
  }

  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    WHERE ${clauses.join(' OR ')}
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(...params, limit);
}

/** Toutes les actualités, tous périmètres : la vue d'administration. */
function all(limit = 60) {
  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(limit);
}

function companyWide(limit = 20) {
  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    WHERE a.scope = 'company'
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(limit);
}

module.exports = { SCOPES, create, remove, removeWithinScopes, forEmployee, forScopes, all, companyWide };
