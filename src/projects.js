const db = require('./db');

/**
 * Projets, jalons, tâches et temps passé.
 *
 * Le temps est la matière première d'une entreprise de services : sans
 * imputation par projet, aucune rentabilité n'est calculable. Le pointage des
 * freelances existe déjà pour la facturation ; ce module-ci répond à une autre
 * question, « ce projet a-t-il coûté plus que prévu ? », et vaut pour tout le
 * monde, salariés compris.
 */

const STATUSES = ['Cadrage', 'En cours', 'En pause', 'Livré', 'Clôturé'];
const TASK_STATUSES = ['À faire', 'En cours', 'En revue', 'Terminée'];
const PRIORITIES = ['Basse', 'Normale', 'Haute', 'Critique'];
const OPEN_STATUSES = ['Cadrage', 'En cours', 'En pause'];

const MAX_HOURS_PER_ENTRY = 24;

// ---------- Projets ----------

function list({ includeArchived = false } = {}) {
  const where = includeArchived ? '' : 'WHERE p.archived = 0';
  return db.prepare(`
    SELECT p.*, d.name AS department_name, t.name AS team_name,
           par.name AS partner_name,
           u.first_name AS lead_first_name, u.last_name AS lead_last_name
    FROM projects p
    LEFT JOIN departments d ON d.id = p.department_id
    LEFT JOIN teams t ON t.id = p.team_id
    LEFT JOIN partners par ON par.id = p.partner_id
    LEFT JOIN users u ON u.id = p.lead_id
    ${where}
    ORDER BY p.archived, p.due_date IS NULL, p.due_date, p.name COLLATE NOCASE
  `).all();
}

function byId(id) {
  return db.prepare('SELECT * FROM projects WHERE id = ?').get(Number(id) || 0) || null;
}

function create(fields) {
  const info = db.prepare(`
    INSERT INTO projects (code, name, partner_id, department_id, team_id, lead_id, status, start_date, due_date, budget_amount, hourly_rate, description)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.code || '', fields.name, fields.partnerId || null, fields.departmentId || null,
    fields.teamId || null, fields.leadId || null, fields.status || 'Cadrage',
    fields.startDate || null, fields.dueDate || null,
    fields.budgetAmount == null ? null : fields.budgetAmount,
    fields.hourlyRate == null ? null : fields.hourlyRate,
    fields.description || ''
  );
  // Le responsable est membre de fait : sans cela il ne verrait pas son propre projet.
  if (fields.leadId) addMember(info.lastInsertRowid, fields.leadId, 'Responsable');
  return info.lastInsertRowid;
}

function update(id, fields) {
  db.prepare(`
    UPDATE projects SET code = ?, name = ?, partner_id = ?, department_id = ?, team_id = ?, lead_id = ?,
           status = ?, start_date = ?, due_date = ?, budget_amount = ?, hourly_rate = ?, description = ?
    WHERE id = ?
  `).run(
    fields.code || '', fields.name, fields.partnerId || null, fields.departmentId || null,
    fields.teamId || null, fields.leadId || null, fields.status, fields.startDate || null,
    fields.dueDate || null, fields.budgetAmount == null ? null : fields.budgetAmount,
    fields.hourlyRate == null ? null : fields.hourlyRate, fields.description || '', id
  );
}

function archive(id, archived = true) {
  db.prepare('UPDATE projects SET archived = ? WHERE id = ?').run(archived ? 1 : 0, id);
}

function remove(id) {
  db.prepare('DELETE FROM projects WHERE id = ?').run(id);
}

// ---------- Équipe projet ----------

function members(projectId) {
  return db.prepare(`
    SELECT pm.*, u.first_name, u.last_name, u.email, u.grade
    FROM project_members pm JOIN users u ON u.id = pm.user_id
    WHERE pm.project_id = ? ORDER BY u.last_name COLLATE NOCASE
  `).all(projectId);
}

function addMember(projectId, userId, role = '') {
  db.prepare('INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)')
    .run(projectId, userId, role);
}

function removeMember(projectId, userId) {
  db.prepare('DELETE FROM project_members WHERE project_id = ? AND user_id = ?').run(projectId, userId);
}

/** Les projets qu'une personne voit : ceux dont elle est membre ou responsable. */
function forUser(userId) {
  return db.prepare(`
    SELECT DISTINCT p.* FROM projects p
    LEFT JOIN project_members pm ON pm.project_id = p.id
    WHERE p.archived = 0 AND (pm.user_id = ? OR p.lead_id = ?)
    ORDER BY p.due_date IS NULL, p.due_date
  `).all(userId, userId);
}

// ---------- Jalons ----------

function milestones(projectId) {
  return db.prepare('SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date IS NULL, due_date, id').all(projectId);
}

function createMilestone(projectId, title, dueDate) {
  return db.prepare('INSERT INTO project_milestones (project_id, title, due_date) VALUES (?, ?, ?)')
    .run(projectId, title, dueDate || null).lastInsertRowid;
}

function toggleMilestone(id) {
  const row = db.prepare('SELECT * FROM project_milestones WHERE id = ?').get(id);
  if (!row) return null;
  db.prepare('UPDATE project_milestones SET reached_on = ? WHERE id = ?')
    .run(row.reached_on ? null : new Date().toISOString().slice(0, 10), id);
  return row.project_id;
}

function deleteMilestone(id) {
  const row = db.prepare('SELECT project_id FROM project_milestones WHERE id = ?').get(id);
  db.prepare('DELETE FROM project_milestones WHERE id = ?').run(id);
  return row ? row.project_id : null;
}

// ---------- Tâches ----------

function tasks(projectId) {
  return db.prepare(`
    SELECT t.*, u.first_name, u.last_name, m.title AS milestone_title,
           (SELECT COALESCE(SUM(hours), 0) FROM project_time WHERE task_id = t.id) AS spent_hours
    FROM project_tasks t
    LEFT JOIN users u ON u.id = t.assignee_id
    LEFT JOIN project_milestones m ON m.id = t.milestone_id
    WHERE t.project_id = ?
    ORDER BY t.done_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.id
  `).all(projectId);
}

/** Les tâches regroupées par colonne, dans l'ordre des statuts. */
function board(projectId) {
  const all = tasks(projectId);
  return TASK_STATUSES.map((status) => ({ status, items: all.filter((t) => t.status === status) }));
}

function createTask(fields) {
  return db.prepare(`
    INSERT INTO project_tasks (project_id, milestone_id, title, description, assignee_id, status, priority, estimate_hours, due_date, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.projectId, fields.milestoneId || null, fields.title, fields.description || '',
    fields.assigneeId || null, fields.status || 'À faire', fields.priority || 'Normale',
    fields.estimateHours == null ? null : fields.estimateHours, fields.dueDate || null, fields.createdBy || null
  ).lastInsertRowid;
}

function taskById(id) {
  return db.prepare('SELECT * FROM project_tasks WHERE id = ?').get(Number(id) || 0) || null;
}

/** Changer de statut renseigne (ou efface) la date de fin : elle ne se saisit pas à la main. */
function setTaskStatus(id, status) {
  if (!TASK_STATUSES.includes(status)) return null;
  const task = taskById(id);
  if (!task) return null;

  const doneAt = status === 'Terminée' ? new Date().toISOString().slice(0, 10) : null;
  db.prepare('UPDATE project_tasks SET status = ?, done_at = ? WHERE id = ?').run(status, doneAt, id);
  return task.project_id;
}

function assignTask(id, userId) {
  const task = taskById(id);
  if (!task) return null;
  db.prepare('UPDATE project_tasks SET assignee_id = ? WHERE id = ?').run(userId || null, id);
  return task.project_id;
}

function deleteTask(id) {
  const task = taskById(id);
  db.prepare('DELETE FROM project_tasks WHERE id = ?').run(id);
  return task ? task.project_id : null;
}

/** Les tâches ouvertes d'une personne, tous projets confondus. */
function tasksOf(userId) {
  return db.prepare(`
    SELECT t.*, p.name AS project_name
    FROM project_tasks t JOIN projects p ON p.id = t.project_id
    WHERE t.assignee_id = ? AND t.status != 'Terminée' AND p.archived = 0
    ORDER BY t.due_date IS NULL, t.due_date
  `).all(userId);
}

// ---------- Temps passé ----------

function logTime({ projectId, taskId, userId, spentOn, hours, note, billable = true }) {
  if (!(hours > 0) || hours > MAX_HOURS_PER_ENTRY) {
    return { ok: false, message: `Une saisie porte entre 0 et ${MAX_HOURS_PER_ENTRY} heures.` };
  }
  db.prepare(`
    INSERT INTO project_time (project_id, task_id, user_id, spent_on, hours, note, billable)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(projectId, taskId || null, userId, spentOn, hours, note || '', billable ? 1 : 0);
  return { ok: true };
}

function timeEntries(projectId, { limit = 200 } = {}) {
  return db.prepare(`
    SELECT pt.*, u.first_name, u.last_name, t.title AS task_title
    FROM project_time pt
    JOIN users u ON u.id = pt.user_id
    LEFT JOIN project_tasks t ON t.id = pt.task_id
    WHERE pt.project_id = ?
    ORDER BY pt.spent_on DESC, pt.id DESC LIMIT ?
  `).all(projectId, limit);
}

function timeOf(userId, { from, to } = {}) {
  const clauses = ['pt.user_id = ?'];
  const params = [userId];
  if (from) { clauses.push('pt.spent_on >= ?'); params.push(from); }
  if (to) { clauses.push('pt.spent_on <= ?'); params.push(to); }

  return db.prepare(`
    SELECT pt.*, p.name AS project_name, t.title AS task_title
    FROM project_time pt
    JOIN projects p ON p.id = pt.project_id
    LEFT JOIN project_tasks t ON t.id = pt.task_id
    WHERE ${clauses.join(' AND ')}
    ORDER BY pt.spent_on DESC, pt.id DESC
  `).all(...params);
}

function deleteTimeEntry(id, { userId = null } = {}) {
  // Une saisie ne s'efface que par son auteur, sauf appel sans restriction (RH, gestion).
  const clause = userId ? 'AND user_id = ?' : '';
  const params = userId ? [id, userId] : [id];
  const row = db.prepare(`SELECT project_id FROM project_time WHERE id = ? ${clause}`).get(...params);
  if (!row) return null;
  db.prepare('DELETE FROM project_time WHERE id = ?').run(id);
  return row.project_id;
}

/**
 * Rentabilité d'un projet : heures passées, coût estimé au taux horaire du
 * projet, et écart au budget. Sans taux horaire renseigné, on rend les heures
 * sans inventer un coût.
 */
function profitability(project) {
  const totals = db.prepare(`
    SELECT COALESCE(SUM(hours), 0) AS hours,
           COALESCE(SUM(CASE WHEN billable = 1 THEN hours ELSE 0 END), 0) AS billable_hours
    FROM project_time WHERE project_id = ?
  `).get(project.id);

  const rate = project.hourly_rate;
  const cost = rate == null ? null : Math.round(totals.hours * rate * 100) / 100;
  const budget = project.budget_amount;
  const margin = cost == null || budget == null ? null : Math.round((budget - cost) * 100) / 100;

  return {
    hours: Math.round(totals.hours * 100) / 100,
    billableHours: Math.round(totals.billable_hours * 100) / 100,
    cost,
    budget,
    margin,
    // Part du budget consommée : ce qui alerte avant que le dépassement soit acquis.
    consumed: cost == null || !budget ? null : Math.min(999, Math.round((cost / budget) * 100)),
  };
}

/** Répartition du temps par personne, pour comprendre où il est parti. */
function timeByMember(projectId) {
  return db.prepare(`
    SELECT u.id, u.first_name, u.last_name, SUM(pt.hours) AS hours
    FROM project_time pt JOIN users u ON u.id = pt.user_id
    WHERE pt.project_id = ?
    GROUP BY u.id ORDER BY hours DESC
  `).all(projectId);
}

function summary() {
  const open = db.prepare(`SELECT COUNT(*) AS n FROM projects WHERE archived = 0 AND status IN (${OPEN_STATUSES.map(() => '?').join(',')})`).get(...OPEN_STATUSES).n;
  const late = db.prepare(`
    SELECT COUNT(*) AS n FROM projects
    WHERE archived = 0 AND due_date IS NOT NULL AND due_date < date('now') AND status NOT IN ('Livré', 'Clôturé')
  `).get().n;
  const openTasks = db.prepare("SELECT COUNT(*) AS n FROM project_tasks WHERE status != 'Terminée'").get().n;
  const hours = db.prepare("SELECT COALESCE(SUM(hours), 0) AS h FROM project_time WHERE spent_on >= date('now', '-30 days')").get().h;
  return { open, late, openTasks, hours: Math.round(hours * 10) / 10 };
}

module.exports = {
  STATUSES, TASK_STATUSES, PRIORITIES, MAX_HOURS_PER_ENTRY,
  list, byId, create, update, archive, remove,
  members, addMember, removeMember, forUser,
  milestones, createMilestone, toggleMilestone, deleteMilestone,
  tasks, board, createTask, taskById, setTaskStatus, assignTask, deleteTask, tasksOf,
  logTime, timeEntries, timeOf, deleteTimeEntry,
  profitability, timeByMember, summary,
};
