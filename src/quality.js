const db = require('./db');

/**
 * Qualité : non-conformités, actions correctives et audits internes.
 *
 * La boucle est toujours la même — constater, traiter, vérifier que ça a servi.
 * C'est la dernière étape qu'on saute : une action « faite » dont personne n'a
 * mesuré l'effet laisse le même écart revenir six mois plus tard. Le module
 * réclame donc une vérification d'efficacité, à une date qu'on se fixe, et
 * compte séparément ce qui est fait de ce qui est efficace.
 */

const SOURCES = ['Interne', 'Client', 'Fournisseur', 'Audit', 'Réglementaire'];
const SEVERITIES = ['Mineure', 'Majeure', 'Critique'];
const NC_STATUSES = ['Ouverte', 'En traitement', 'Clôturée'];
const ACTION_KINDS = ['Corrective', 'Préventive', 'Amélioration'];
const ACTION_STATUSES = ['À faire', 'En cours', 'Faite'];
const EFFECTIVENESS = ['Non vérifiée', 'Efficace', 'Inefficace'];
const AUDIT_STATUSES = ['Planifié', 'Réalisé', 'Clos'];
const FINDING_KINDS = ['Non-conformité', 'Remarque', 'Point fort'];

/** Une référence lisible, séquentielle par année : NC-2026-014. */
function nextReference(prefix, table) {
  const year = new Date().getFullYear();
  const count = db.prepare(`SELECT COUNT(*) AS n FROM ${table} WHERE reference LIKE ?`).get(`${prefix}-${year}-%`).n;
  return `${prefix}-${year}-${String(count + 1).padStart(3, '0')}`;
}

// ---------- Non-conformités ----------

function nonconformities({ status = null, limit = 300 } = {}) {
  const clause = status ? 'WHERE n.status = ?' : '';
  const params = status ? [status, limit] : [limit];
  return db.prepare(`
    SELECT n.*, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM quality_actions a WHERE a.nonconformity_id = n.id) AS action_count,
           (SELECT COUNT(*) FROM quality_actions a WHERE a.nonconformity_id = n.id AND a.status != 'Faite') AS open_actions
    FROM nonconformities n
    LEFT JOIN users u ON u.id = n.detected_by
    ${clause}
    ORDER BY n.detected_on DESC, n.id DESC LIMIT ?
  `).all(...params);
}

function nonconformityById(id) {
  return db.prepare('SELECT * FROM nonconformities WHERE id = ?').get(Number(id) || 0) || null;
}

function createNonconformity(fields) {
  return db.prepare(`
    INSERT INTO nonconformities (reference, title, description, source, severity, detected_on, detected_by,
                                 subject, immediate_action, root_cause, cost)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(fields.reference || nextReference('NC', 'nonconformities'), fields.title, fields.description || '',
    fields.source, fields.severity, fields.detectedOn, fields.detectedBy || null,
    fields.subject || '', fields.immediateAction || '', fields.rootCause || '', fields.cost || 0).lastInsertRowid;
}

/**
 * Clôturer n'est possible qu'une fois les actions faites : une non-conformité
 * fermée avec du travail en cours est une fermeture de façade.
 */
function closeNonconformity(id) {
  const open = db.prepare("SELECT COUNT(*) AS n FROM quality_actions WHERE nonconformity_id = ? AND status != 'Faite'").get(id).n;
  if (open > 0) return { ok: false, message: `${open} action(s) encore en cours : la non-conformité ne peut pas être clôturée.` };

  db.prepare("UPDATE nonconformities SET status = 'Clôturée', closed_on = date('now') WHERE id = ?").run(id);
  return { ok: true };
}

function setNonconformityStatus(id, status) {
  if (!NC_STATUSES.includes(status)) return { ok: false, message: 'Statut inconnu.' };
  if (status === 'Clôturée') return closeNonconformity(id);
  db.prepare('UPDATE nonconformities SET status = ?, closed_on = NULL WHERE id = ?').run(status, id);
  return { ok: true };
}

function setRootCause(id, rootCause) {
  db.prepare('UPDATE nonconformities SET root_cause = ? WHERE id = ?').run(rootCause, id);
}

function deleteNonconformity(id) {
  db.prepare('DELETE FROM nonconformities WHERE id = ?').run(id);
}

// ---------- Actions ----------

function actions({ nonconformityId = null, auditId = null, openOnly = false, ownerId = null } = {}) {
  const clauses = [];
  const params = [];
  if (nonconformityId) { clauses.push('a.nonconformity_id = ?'); params.push(nonconformityId); }
  if (auditId) { clauses.push('a.audit_id = ?'); params.push(auditId); }
  if (ownerId) { clauses.push('a.owner_id = ?'); params.push(ownerId); }
  if (openOnly) clauses.push("a.status != 'Faite'");
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';

  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name, n.reference AS nc_reference, n.title AS nc_title, i.reference AS audit_reference
    FROM quality_actions a
    LEFT JOIN users u ON u.id = a.owner_id
    LEFT JOIN nonconformities n ON n.id = a.nonconformity_id
    LEFT JOIN internal_audits i ON i.id = a.audit_id
    ${where}
    ORDER BY a.due_date IS NULL, a.due_date, a.id
  `).all(...params);
}

function createAction(fields) {
  return db.prepare(`
    INSERT INTO quality_actions (nonconformity_id, audit_id, kind, label, owner_id, due_date)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(fields.nonconformityId || null, fields.auditId || null, fields.kind, fields.label,
    fields.ownerId || null, fields.dueDate || null).lastInsertRowid;
}

function setActionStatus(id, status) {
  if (!ACTION_STATUSES.includes(status)) return false;
  const done = status === 'Faite' ? new Date().toISOString().slice(0, 10) : null;
  return db.prepare('UPDATE quality_actions SET status = ?, done_on = ? WHERE id = ?').run(status, done, id).changes > 0;
}

/** La vérification d'efficacité : ce qui distingue une action d'une intention. */
function verifyAction(id, { effectiveness, verifiedBy }) {
  if (!EFFECTIVENESS.includes(effectiveness) || effectiveness === 'Non vérifiée') {
    return { ok: false, message: 'Verdict attendu : efficace ou inefficace.' };
  }
  const action = db.prepare('SELECT * FROM quality_actions WHERE id = ?').get(id);
  if (!action) return { ok: false, message: 'Action introuvable.' };
  if (action.status !== 'Faite') return { ok: false, message: "Une action encore en cours ne peut pas être jugée efficace." };

  db.prepare("UPDATE quality_actions SET effectiveness = ?, verified_on = date('now'), verified_by = ? WHERE id = ?")
    .run(effectiveness, verifiedBy || null, id);
  return { ok: true };
}

function deleteAction(id) {
  db.prepare('DELETE FROM quality_actions WHERE id = ?').run(id);
}

// ---------- Audits internes ----------

function audits() {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM audit_findings f WHERE f.audit_id = a.id) AS finding_count,
           (SELECT COUNT(*) FROM audit_findings f WHERE f.audit_id = a.id AND f.kind = 'Non-conformité') AS nc_count
    FROM internal_audits a LEFT JOIN users u ON u.id = a.auditor_id
    ORDER BY COALESCE(a.done_on, a.planned_on) DESC, a.id DESC
  `).all();
}

function auditById(id) {
  return db.prepare('SELECT * FROM internal_audits WHERE id = ?').get(Number(id) || 0) || null;
}

function createAudit(fields) {
  return db.prepare(`
    INSERT INTO internal_audits (reference, scope, standard, planned_on, auditor_id, summary)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(fields.reference || nextReference('AUD', 'internal_audits'), fields.scope, fields.standard || '',
    fields.plannedOn || null, fields.auditorId || null, fields.summary || '').lastInsertRowid;
}

function completeAudit(id, { doneOn, summary }) {
  db.prepare("UPDATE internal_audits SET status = 'Réalisé', done_on = ?, summary = ? WHERE id = ?")
    .run(doneOn, summary || '', id);
}

function deleteAudit(id) {
  db.prepare('DELETE FROM internal_audits WHERE id = ?').run(id);
}

function findings(auditId) {
  return db.prepare('SELECT * FROM audit_findings WHERE audit_id = ? ORDER BY id').all(auditId);
}

function addFinding(auditId, { kind, clause, statement }) {
  return db.prepare('INSERT INTO audit_findings (audit_id, kind, clause, statement) VALUES (?, ?, ?, ?)')
    .run(auditId, kind, clause || '', statement).lastInsertRowid;
}

function deleteFinding(id) {
  db.prepare('DELETE FROM audit_findings WHERE id = ?').run(id);
}

/**
 * Un constat d'audit qui reste un constat ne sert à rien : il se transforme en
 * non-conformité, avec sa référence propre, et les deux restent liés par le
 * texte du constat.
 */
function promoteFinding(findingId, { detectedBy = null } = {}) {
  const finding = db.prepare('SELECT f.*, a.reference AS audit_reference, a.scope FROM audit_findings f JOIN internal_audits a ON a.id = f.audit_id WHERE f.id = ?').get(findingId);
  if (!finding) return { ok: false, message: 'Constat introuvable.' };
  if (finding.kind !== 'Non-conformité') return { ok: false, message: 'Seul un constat de non-conformité se transforme ainsi.' };

  const id = createNonconformity({
    title: finding.statement.slice(0, 200),
    description: `Constat de l'audit ${finding.audit_reference}${finding.clause ? ` (exigence ${finding.clause})` : ''} — ${finding.scope}.`,
    source: 'Audit',
    severity: 'Majeure',
    detectedOn: new Date().toISOString().slice(0, 10),
    detectedBy,
    subject: finding.scope,
  });
  return { ok: true, id };
}

// ---------- Indicateurs ----------

function summary() {
  const open = db.prepare("SELECT COUNT(*) AS n FROM nonconformities WHERE status != 'Clôturée'").get().n;
  const critical = db.prepare("SELECT COUNT(*) AS n FROM nonconformities WHERE status != 'Clôturée' AND severity = 'Critique'").get().n;
  const openActions = db.prepare("SELECT COUNT(*) AS n FROM quality_actions WHERE status != 'Faite'").get().n;
  const awaiting = db.prepare("SELECT COUNT(*) AS n FROM quality_actions WHERE status = 'Faite' AND effectiveness = 'Non vérifiée'").get().n;
  const ineffective = db.prepare("SELECT COUNT(*) AS n FROM quality_actions WHERE effectiveness = 'Inefficace'").get().n;
  const cost = db.prepare("SELECT COALESCE(SUM(cost), 0) AS total FROM nonconformities WHERE detected_on >= date('now', '-1 year')").get().total;

  return { open, critical, openActions, awaitingVerification: awaiting, ineffective, cost: Math.round(cost * 100) / 100 };
}

module.exports = {
  SOURCES, SEVERITIES, NC_STATUSES, ACTION_KINDS, ACTION_STATUSES, EFFECTIVENESS, AUDIT_STATUSES, FINDING_KINDS,
  nextReference,
  nonconformities, nonconformityById, createNonconformity, closeNonconformity, setNonconformityStatus, setRootCause, deleteNonconformity,
  actions, createAction, setActionStatus, verifyAction, deleteAction,
  audits, auditById, createAudit, completeAudit, deleteAudit,
  findings, addFinding, deleteFinding, promoteFinding,
  summary,
};
