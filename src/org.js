const db = require('./db');

// Un service regroupe des équipes ; une équipe appartient à un service.
// L'encadrement est une relation : plusieurs managers par service comme par équipe.
const SCOPES = ['department', 'team'];

// ---------- Services ----------

function departments() {
  return db.prepare(`
    SELECT d.*,
      (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS member_count,
      (SELECT COUNT(*) FROM teams t WHERE t.department_id = d.id) AS team_count
    FROM departments d
    ORDER BY d.name COLLATE NOCASE
  `).all();
}

function departmentById(id) {
  return db.prepare('SELECT * FROM departments WHERE id = ?').get(id) || null;
}

function createDepartment({ name, description }) {
  return db.prepare('INSERT INTO departments (name, description) VALUES (?, ?)').run(name, description || '').lastInsertRowid;
}

function updateDepartment(id, { name, description }) {
  db.prepare('UPDATE departments SET name = ?, description = ? WHERE id = ?').run(name, description || '', id);
}

/** Supprimer un service détache ses membres et ses équipes plutôt que de les effacer. */
function deleteDepartment(id) {
  const remove = db.transaction(() => {
    db.prepare("DELETE FROM org_managers WHERE scope = 'department' AND scope_id = ?").run(id);
    db.prepare('DELETE FROM departments WHERE id = ?').run(id);
  });
  remove();
}

// ---------- Équipes ----------

function teams() {
  return db.prepare(`
    SELECT t.*, d.name AS department_name,
      (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS member_count
    FROM teams t
    LEFT JOIN departments d ON d.id = t.department_id
    ORDER BY d.name COLLATE NOCASE, t.name COLLATE NOCASE
  `).all();
}

function teamById(id) {
  return db.prepare(`
    SELECT t.*, d.name AS department_name
    FROM teams t LEFT JOIN departments d ON d.id = t.department_id
    WHERE t.id = ?
  `).get(id) || null;
}

function createTeam({ name, departmentId, description }) {
  return db.prepare('INSERT INTO teams (name, department_id, description) VALUES (?, ?, ?)')
    .run(name, departmentId || null, description || '').lastInsertRowid;
}

function updateTeam(id, { name, departmentId, description }) {
  db.prepare('UPDATE teams SET name = ?, department_id = ?, description = ? WHERE id = ?')
    .run(name, departmentId || null, description || '', id);
}

function deleteTeam(id) {
  const remove = db.transaction(() => {
    db.prepare("DELETE FROM org_managers WHERE scope = 'team' AND scope_id = ?").run(id);
    db.prepare('DELETE FROM teams WHERE id = ?').run(id);
  });
  remove();
}

// ---------- Encadrement ----------

function managersOf(scope, scopeId) {
  return db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.avatar_file
    FROM org_managers m JOIN users u ON u.id = m.user_id
    WHERE m.scope = ? AND m.scope_id = ?
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(scope, scopeId);
}

function addManager(scope, scopeId, userId) {
  if (!SCOPES.includes(scope)) return { ok: false, reason: 'bad-scope' };
  const target = scope === 'team' ? teamById(scopeId) : departmentById(scopeId);
  if (!target) return { ok: false, reason: 'not-found' };

  const user = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(userId);
  if (!user) return { ok: false, reason: 'no-user' };

  db.prepare('INSERT OR IGNORE INTO org_managers (scope, scope_id, user_id) VALUES (?, ?, ?)').run(scope, scopeId, userId);
  return { ok: true, user };
}

function removeManager(scope, scopeId, userId) {
  db.prepare('DELETE FROM org_managers WHERE scope = ? AND scope_id = ? AND user_id = ?').run(scope, scopeId, userId);
}

/** Les périmètres qu'un salarié encadre, services et équipes confondus. */
function scopesManagedBy(userId) {
  const rows = db.prepare('SELECT scope, scope_id FROM org_managers WHERE user_id = ?').all(userId);
  return {
    departments: rows.filter((r) => r.scope === 'department').map((r) => r.scope_id),
    teams: rows.filter((r) => r.scope === 'team').map((r) => r.scope_id),
  };
}

function isManager(userId) {
  return Boolean(db.prepare('SELECT 1 AS ok FROM org_managers WHERE user_id = ? LIMIT 1').get(userId));
}

/**
 * Les collaborateurs encadrés : membres des équipes dirigées, plus membres des
 * services dirigés. Un manager de service encadre donc aussi les équipes qu'il contient.
 */
function membersManagedBy(userId) {
  const { departments: deps, teams: tms } = scopesManagedBy(userId);
  if (deps.length === 0 && tms.length === 0) return [];

  const clauses = [];
  const params = [];
  if (tms.length) {
    clauses.push(`u.team_id IN (${tms.map(() => '?').join(',')})`);
    params.push(...tms);
  }
  if (deps.length) {
    clauses.push(`u.department_id IN (${deps.map(() => '?').join(',')})`);
    params.push(...deps);
  }

  return db.prepare(`
    SELECT u.*, t.name AS team_name, d.name AS department_name
    FROM users u
    LEFT JOIN teams t ON t.id = u.team_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.role = 'employee' AND u.id != ? AND (${clauses.join(' OR ')})
    ORDER BY d.name COLLATE NOCASE, t.name COLLATE NOCASE, u.last_name COLLATE NOCASE
  `).all(userId, ...params);
}

/** Les managers d'un salarié : ceux de son équipe et ceux de son service. */
function managersFor(user) {
  const rows = [];
  if (user.team_id) rows.push(...managersOf('team', user.team_id).map((m) => ({ ...m, scope: 'team' })));
  if (user.department_id) rows.push(...managersOf('department', user.department_id).map((m) => ({ ...m, scope: 'department' })));

  // Un même manager peut encadrer l'équipe et le service : il n'apparaît qu'une fois.
  const seen = new Set();
  return rows.filter((m) => (seen.has(m.id) || m.id === user.id ? false : seen.add(m.id)));
}

/** Les collègues d'équipe d'un salarié, lui excepté. */
function teammates(user) {
  if (!user.team_id) return [];
  return db.prepare(`
    SELECT u.* FROM users u
    WHERE u.team_id = ? AND u.id != ? AND u.role = 'employee'
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(user.team_id, user.id);
}

function membersOfDepartment(departmentId) {
  return db.prepare(`
    SELECT u.* FROM users u WHERE u.department_id = ? AND u.role = 'employee'
    ORDER BY u.last_name COLLATE NOCASE
  `).all(departmentId);
}

/** Rattache un salarié à une équipe et/ou un service, en refusant les identifiants inconnus. */
function assignMembership(userId, { departmentId, teamId }) {
  const team = teamId ? teamById(teamId) : null;
  if (teamId && !team) return { ok: false, reason: 'no-team' };
  if (departmentId && !departmentById(departmentId)) return { ok: false, reason: 'no-department' };

  // Une équipe porte son service : le rattachement suit, plutôt que de diverger.
  const resolvedDepartment = team && team.department_id ? team.department_id : departmentId || null;

  db.prepare('UPDATE users SET department_id = ?, team_id = ? WHERE id = ?')
    .run(resolvedDepartment, teamId || null, userId);
  return { ok: true };
}

/**
 * L'organigramme : services, équipes qu'ils contiennent, et personnes.
 *
 * Trois choses qu'un organigramme doit dire et que les listes séparées ne
 * disent pas : qui encadre quoi, où se trouve chacun, et qui n'est rattaché
 * nulle part — c'est ce dernier point qu'on découvre en le dessinant.
 *
 * `includeHidden` suit la règle de l'annuaire : un membre que l'administration
 * en a retiré n'apparaît pas non plus ici, sauf pour l'administration et les RH,
 * qui doivent voir l'effectif entier.
 */
function chart({ includeHidden = false } = {}) {
  const visible = includeHidden ? '' : 'AND u.directory_hidden = 0';
  const people = db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.grade, u.role, u.contract_type,
           u.department_id, u.team_id, u.avatar_file, u.directory_hidden
    FROM users u WHERE u.active = 1 ${visible}
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all();

  const managerRows = db.prepare(`
    SELECT m.scope, m.scope_id, u.id, u.first_name, u.last_name, u.grade
    FROM org_managers m JOIN users u ON u.id = m.user_id WHERE u.active = 1
  `).all();
  const managersOfScope = (scope, id) => managerRows.filter((m) => m.scope === scope && m.scope_id === id);

  const allTeams = teams();
  const structure = departments().map((department) => {
    const departmentTeams = allTeams.filter((team) => team.department_id === department.id).map((team) => ({
      ...team,
      managers: managersOfScope('team', team.id),
      members: people.filter((person) => person.team_id === team.id),
    }));

    return {
      ...department,
      managers: managersOfScope('department', department.id),
      teams: departmentTeams,
      // Rattaché au service sans équipe : la place existe, elle se voit.
      loose: people.filter((person) => person.department_id === department.id && !person.team_id),
    };
  });

  // Les équipes sans service ne doivent pas disparaître de l'organigramme :
  // elles sont le signe d'un rattachement oublié, pas une raison de les cacher.
  const orphanTeams = allTeams.filter((team) => !team.department_id).map((team) => ({
    ...team,
    managers: managersOfScope('team', team.id),
    members: people.filter((person) => person.team_id === team.id),
  }));

  return {
    departments: structure,
    orphanTeams,
    unassigned: people.filter((person) => !person.department_id && !person.team_id && person.role === 'employee'),
    headcount: people.filter((person) => person.role === 'employee').length,
    managerCount: new Set(managerRows.map((m) => m.id)).size,
  };
}

/** Décrit le rattachement d'un salarié en une ligne, pour les listes et l'annuaire. */
function membershipLabel(user) {
  return [user.team_name, user.department_name].filter(Boolean).join(' · ');
}

module.exports = {
  SCOPES,
  chart,
  departments,
  departmentById,
  createDepartment,
  updateDepartment,
  deleteDepartment,
  teams,
  teamById,
  createTeam,
  updateTeam,
  deleteTeam,
  managersOf,
  addManager,
  removeManager,
  scopesManagedBy,
  isManager,
  membersManagedBy,
  managersFor,
  teammates,
  membersOfDepartment,
  assignMembership,
  membershipLabel,
};
