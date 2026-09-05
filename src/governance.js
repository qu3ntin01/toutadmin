const db = require('./db');

/**
 * Gouvernance : réunions, décisions et risques de l'entreprise.
 *
 * Trois manques qui se répondent. Une réunion sans relevé s'oublie ; une
 * décision sans registre se rejoue tous les six mois ; un risque connu de deux
 * personnes n'est pas un risque géré. Le dirigeant doit pouvoir répondre à
 * « qui a décidé quoi, quand, et où en est-on ? » sans fouiller sa messagerie.
 *
 * Le registre des risques est volontairement distinct du document unique, qui
 * ne traite que la santé des personnes : la dépendance à un client ou la panne
 * du système d'information n'y ont pas leur place, et se perdraient au milieu.
 */

const MEETING_KINDS = ['Comité de direction', "Réunion d'équipe", 'Revue de projet', 'Comité social', 'Revue de direction', 'Autre'];
const MEETING_STATUSES = ['Planifiée', 'Tenue', 'Annulée'];
const ATTENDANCES = ['Attendu', 'Présent', 'Excusé', 'Absent'];
const DECISION_SCOPES = ['Entreprise', 'Service', 'Équipe', 'Projet'];
const DECISION_STATUSES = ['En vigueur', 'En cours', 'Suspendue', 'Abandonnée'];
const ACTION_STATUSES = ['À faire', 'En cours', 'Faite', 'Abandonnée'];

const RISK_CATEGORIES = ['Stratégique', 'Financier', 'Opérationnel', 'Juridique et conformité', 'Informatique', 'Ressources humaines', 'Réputation', 'Environnement'];
const TREATMENTS = ['Éviter', 'Réduire', 'Transférer', 'Accepter'];
const RISK_STATUSES = ['Ouvert', 'Maîtrisé', 'Clos'];
const SCALE = [1, 2, 3, 4, 5];

// Au-delà de ce produit probabilité × impact, le risque remonte à la direction.
const CRITICAL_THRESHOLD = 12;

// ---------- Réunions ----------

function meetings({ limit = 200, status = null } = {}) {
  const clause = status ? 'WHERE m.status = ?' : '';
  const params = status ? [status, limit] : [limit];
  return db.prepare(`
    SELECT m.*, u.first_name AS chair_first, u.last_name AS chair_last,
           (SELECT COUNT(*) FROM meeting_attendees a WHERE a.meeting_id = m.id) AS attendee_count,
           (SELECT COUNT(*) FROM decisions d WHERE d.meeting_id = m.id) AS decision_count,
           (SELECT COUNT(*) FROM meeting_actions x WHERE x.meeting_id = m.id AND x.status NOT IN ('Faite','Abandonnée')) AS open_actions
    FROM meetings m
    LEFT JOIN users u ON u.id = m.chair_id
    ${clause}
    ORDER BY m.held_on DESC, m.id DESC LIMIT ?
  `).all(...params);
}

function meetingById(id) {
  return db.prepare('SELECT * FROM meetings WHERE id = ?').get(Number(id) || 0) || null;
}

function createMeeting({ title, kind, heldOn, startsAt, endsAt, location, agenda, chairId, createdBy }) {
  return db.prepare(`
    INSERT INTO meetings (title, kind, held_on, starts_at, ends_at, location, agenda, chair_id, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(title, kind, heldOn, startsAt || '', endsAt || '', location || '', agenda || '', chairId || null, createdBy || null).lastInsertRowid;
}

function updateMeeting(id, fields) {
  db.prepare(`
    UPDATE meetings SET title = ?, kind = ?, held_on = ?, starts_at = ?, ends_at = ?,
           location = ?, agenda = ?, chair_id = ?, status = ? WHERE id = ?
  `).run(fields.title, fields.kind, fields.heldOn, fields.startsAt || '', fields.endsAt || '',
    fields.location || '', fields.agenda || '', fields.chairId || null, fields.status, id);
}

/** Le compte rendu se saisit après coup : il ne suit pas le sort de l'ordre du jour. */
function setMinutes(id, minutes) {
  db.prepare("UPDATE meetings SET minutes = ?, status = 'Tenue' WHERE id = ?").run(minutes, id);
}

function deleteMeeting(id) {
  db.prepare('DELETE FROM meetings WHERE id = ?').run(id);
}

function attendees(meetingId) {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name, u.grade FROM meeting_attendees a
    JOIN users u ON u.id = a.user_id WHERE a.meeting_id = ?
    ORDER BY u.last_name COLLATE NOCASE
  `).all(meetingId);
}

function invite(meetingId, userId) {
  return db.prepare('INSERT OR IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES (?, ?)')
    .run(meetingId, userId).changes > 0;
}

function setAttendance(meetingId, userId, attendance) {
  if (!ATTENDANCES.includes(attendance)) return false;
  return db.prepare('UPDATE meeting_attendees SET attendance = ? WHERE meeting_id = ? AND user_id = ?')
    .run(attendance, meetingId, userId).changes > 0;
}

function removeAttendee(meetingId, userId) {
  db.prepare('DELETE FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?').run(meetingId, userId);
}

// ---------- Décisions ----------

function decisions({ meetingId = null, status = null, limit = 300 } = {}) {
  const clauses = [];
  const params = [];
  if (meetingId) { clauses.push('d.meeting_id = ?'); params.push(meetingId); }
  if (status) { clauses.push('d.status = ?'); params.push(status); }
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  params.push(limit);

  return db.prepare(`
    SELECT d.*, u.first_name, u.last_name, m.title AS meeting_title, m.held_on AS meeting_date,
           (SELECT COUNT(*) FROM meeting_actions x WHERE x.decision_id = d.id AND x.status NOT IN ('Faite','Abandonnée')) AS open_actions
    FROM decisions d
    LEFT JOIN users u ON u.id = d.decided_by
    LEFT JOIN meetings m ON m.id = d.meeting_id
    ${where}
    ORDER BY d.decided_on DESC, d.id DESC LIMIT ?
  `).all(...params);
}

function createDecision({ meetingId, title, body, rationale, decidedOn, decidedBy, scope, reviewOn }) {
  return db.prepare(`
    INSERT INTO decisions (meeting_id, title, body, rationale, decided_on, decided_by, scope, review_on)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(meetingId || null, title, body || '', rationale || '', decidedOn, decidedBy || null, scope, reviewOn || null).lastInsertRowid;
}

function setDecisionStatus(id, status) {
  if (!DECISION_STATUSES.includes(status)) return false;
  return db.prepare('UPDATE decisions SET status = ? WHERE id = ?').run(status, id).changes > 0;
}

function deleteDecision(id) {
  db.prepare('DELETE FROM decisions WHERE id = ?').run(id);
}

// ---------- Actions ----------

function actions({ meetingId = null, openOnly = false, assigneeId = null } = {}) {
  const clauses = [];
  const params = [];
  if (meetingId) { clauses.push('a.meeting_id = ?'); params.push(meetingId); }
  if (assigneeId) { clauses.push('a.assignee_id = ?'); params.push(assigneeId); }
  if (openOnly) clauses.push("a.status NOT IN ('Faite','Abandonnée')");
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';

  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name, m.title AS meeting_title, d.title AS decision_title
    FROM meeting_actions a
    LEFT JOIN users u ON u.id = a.assignee_id
    LEFT JOIN meetings m ON m.id = a.meeting_id
    LEFT JOIN decisions d ON d.id = a.decision_id
    ${where}
    ORDER BY a.due_date IS NULL, a.due_date, a.id
  `).all(...params);
}

function createAction({ meetingId, decisionId, label, assigneeId, dueDate }) {
  return db.prepare(`
    INSERT INTO meeting_actions (meeting_id, decision_id, label, assignee_id, due_date)
    VALUES (?, ?, ?, ?, ?)
  `).run(meetingId || null, decisionId || null, label, assigneeId || null, dueDate || null).lastInsertRowid;
}

function setActionStatus(id, status) {
  if (!ACTION_STATUSES.includes(status)) return false;
  const done = status === 'Faite' ? new Date().toISOString().slice(0, 10) : null;
  return db.prepare('UPDATE meeting_actions SET status = ?, done_on = ? WHERE id = ?').run(status, done, id).changes > 0;
}

function deleteAction(id) {
  db.prepare('DELETE FROM meeting_actions WHERE id = ?').run(id);
}

// ---------- Registre des risques ----------

const score = (likelihood, impact) => Number(likelihood || 0) * Number(impact || 0);

/**
 * La criticité résiduelle est celle qui compte : elle dit ce qu'il reste une
 * fois le traitement en place. Tant qu'elle n'est pas cotée, on retient la
 * criticité brute plutôt que de faire comme si le risque était traité.
 */
function decorate(row) {
  const gross = score(row.likelihood, row.impact);
  const hasResidual = row.residual_likelihood && row.residual_impact;
  const residual = hasResidual ? score(row.residual_likelihood, row.residual_impact) : null;
  const retained = residual === null ? gross : residual;
  return { ...row, gross, residual, retained, critical: retained >= CRITICAL_THRESHOLD };
}

function risks({ status = null } = {}) {
  const clause = status ? 'WHERE r.status = ?' : '';
  const params = status ? [status] : [];
  return db.prepare(`
    SELECT r.*, u.first_name, u.last_name FROM enterprise_risks r
    LEFT JOIN users u ON u.id = r.owner_id
    ${clause}
  `).all(...params).map(decorate).sort((a, b) => b.retained - a.retained || a.title.localeCompare(b.title));
}

function riskById(id) {
  const row = db.prepare('SELECT * FROM enterprise_risks WHERE id = ?').get(Number(id) || 0);
  return row ? decorate(row) : null;
}

function createRisk(fields) {
  return db.prepare(`
    INSERT INTO enterprise_risks (reference, category, title, description, likelihood, impact, owner_id,
                                  treatment, action_plan, residual_likelihood, residual_impact, identified_on, next_review)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(fields.reference || '', fields.category, fields.title, fields.description || '',
    fields.likelihood, fields.impact, fields.ownerId || null, fields.treatment, fields.actionPlan || '',
    fields.residualLikelihood || null, fields.residualImpact || null,
    fields.identifiedOn || new Date().toISOString().slice(0, 10), fields.nextReview || null).lastInsertRowid;
}

function updateRisk(id, fields) {
  db.prepare(`
    UPDATE enterprise_risks SET reference = ?, category = ?, title = ?, description = ?, likelihood = ?, impact = ?,
           owner_id = ?, treatment = ?, action_plan = ?, residual_likelihood = ?, residual_impact = ?,
           status = ?, next_review = ? WHERE id = ?
  `).run(fields.reference || '', fields.category, fields.title, fields.description || '',
    fields.likelihood, fields.impact, fields.ownerId || null, fields.treatment, fields.actionPlan || '',
    fields.residualLikelihood || null, fields.residualImpact || null, fields.status, fields.nextReview || null, id);
}

function deleteRisk(id) {
  db.prepare('DELETE FROM enterprise_risks WHERE id = ?').run(id);
}

/** La matrice probabilité × impact : ce qu'on montre au comité, d'un coup d'œil. */
function matrix() {
  const grid = SCALE.map(() => SCALE.map(() => []));
  for (const risk of risks({ status: 'Ouvert' }).concat(risks({ status: 'Maîtrisé' }))) {
    const likelihood = risk.residual_likelihood || risk.likelihood;
    const impact = risk.residual_impact || risk.impact;
    const row = grid[SCALE.length - likelihood];
    if (row && row[impact - 1]) row[impact - 1].push(risk);
  }
  return grid;
}

function summary() {
  const open = risks({ status: 'Ouvert' });
  const openActions = actions({ openOnly: true });
  const overdue = openActions.filter((a) => a.due_date && a.due_date < new Date().toISOString().slice(0, 10));
  return {
    meetings: db.prepare("SELECT COUNT(*) AS n FROM meetings WHERE held_on >= date('now', '-90 days')").get().n,
    decisions: db.prepare("SELECT COUNT(*) AS n FROM decisions WHERE status = 'En vigueur'").get().n,
    openActions: openActions.length,
    overdueActions: overdue.length,
    risks: open.length,
    criticalRisks: open.filter((r) => r.critical).length,
  };
}

module.exports = {
  MEETING_KINDS, MEETING_STATUSES, ATTENDANCES, DECISION_SCOPES, DECISION_STATUSES, ACTION_STATUSES,
  RISK_CATEGORIES, TREATMENTS, RISK_STATUSES, SCALE, CRITICAL_THRESHOLD,
  meetings, meetingById, createMeeting, updateMeeting, setMinutes, deleteMeeting,
  attendees, invite, setAttendance, removeAttendee,
  decisions, createDecision, setDecisionStatus, deleteDecision,
  actions, createAction, setActionStatus, deleteAction,
  score, risks, riskById, createRisk, updateRisk, deleteRisk, matrix, summary,
};
