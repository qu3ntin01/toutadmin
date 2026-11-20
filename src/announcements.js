const db = require('./db');
const societes = require('./societes');

/*
 * Les actualités, cloisonnées par société.
 *
 * Le périmètre vient du contexte de la requête, et non d'un argument : voir
 * src/societes.js, « courant ». Il est exigé pour lire comme pour écrire — une
 * actualité publiée dans une entité d'un groupe n'a pas à s'afficher dans
 * l'autre, et le fil du salarié est le premier endroit où cela se verrait.
 */

// Une actualité vise l'entreprise entière, un service, ou une équipe.
const SCOPES = ['company', 'department', 'team'];

function perimetre() {
  const id = societes.courant();
  if (!id) throw new Error('cloison.err.perimetre');
  return id;
}

function create({ authorId, scope, scopeId, title, body }) {
  return db
    .prepare('INSERT INTO announcements (company_id, author_id, scope, scope_id, title, body) VALUES (?, ?, ?, ?, ?, ?)')
    .run(perimetre(), authorId, scope, scope === 'company' ? null : scopeId, title, body).lastInsertRowid;
}

function remove(id) {
  return db.prepare('DELETE FROM announcements WHERE id = ? AND company_id = ?')
    .run(id, perimetre()).changes > 0;
}

/** Un manager ne peut retirer qu'une actualité publiée sur un périmètre qu'il encadre. */
function removeWithinScopes(id, scopes) {
  const announcement = db.prepare('SELECT * FROM announcements WHERE id = ? AND company_id = ?')
    .get(id, perimetre());
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
    WHERE a.company_id = ?
      AND (a.scope = 'company'
       OR (a.scope = 'department' AND a.scope_id = ?)
       OR (a.scope = 'team' AND a.scope_id = ?))
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(perimetre(), employee.department_id || -1, employee.team_id || -1, limit);
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
    WHERE a.company_id = ? AND (${clauses.join(' OR ')})
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(perimetre(), ...params, limit);
}

/** Toutes les actualités de la société, tous périmètres : la vue d'administration. */
function all(limit = 60) {
  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    WHERE a.company_id = ?
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(perimetre(), limit);
}

function companyWide(limit = 20) {
  return db.prepare(`
    ${SELECT_WITH_AUTHOR}
    WHERE a.company_id = ? AND a.scope = 'company'
    ORDER BY a.created_at DESC
    LIMIT ?
  `).all(perimetre(), limit);
}

module.exports = { SCOPES, create, remove, removeWithinScopes, forEmployee, forScopes, all, companyWide };
