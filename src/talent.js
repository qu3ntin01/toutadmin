const db = require('./db');

// Cycle de vie du salarié : documents opposables, formation, entretiens, recrutement.
const DOCUMENT_CATEGORIES = ['Règlement intérieur', 'Politique', 'Procédure', 'Sécurité', 'Note de service', 'Autre'];
const SESSION_STATUSES = ['Planifiée', 'Confirmée', 'Terminée', 'Annulée'];
const REGISTRATION_STATUSES = ['Demandée', 'Inscrite', 'Refusée', 'Terminée', 'Annulée'];
const REVIEW_STATUSES = ['Planifié', 'Réalisé', 'Annulé'];
const OPENING_STATUSES = ['Ouvert', 'En cours', 'Pourvu', 'Annulé'];
const CANDIDATE_STAGES = ['Reçue', 'Présélection', 'Entretien', 'Offre', 'Recruté', 'Refusé'];

// ---------- Documents d'entreprise ----------

function documents() {
  return db.prepare(`
    SELECT d.*,
      (SELECT COUNT(*) FROM document_acks a WHERE a.document_id = d.id) AS ack_count
    FROM company_documents d
    ORDER BY d.published_at DESC, d.title COLLATE NOCASE
  `).all();
}

function documentById(id) {
  return db.prepare('SELECT * FROM company_documents WHERE id = ?').get(id) || null;
}

function createDocument(data) {
  return db.prepare(`
    INSERT INTO company_documents (title, category, description, url, requires_ack, published_at, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(data.title, data.category || '', data.description || '', data.url || '',
         data.requiresAck ? 1 : 0, data.publishedAt || new Date().toISOString().slice(0, 10), data.createdBy).lastInsertRowid;
}

function deleteDocument(id) {
  db.prepare('DELETE FROM company_documents WHERE id = ?').run(id);
}

/** Vue salarié : chaque document, et s'il en a déjà accusé réception. */
function documentsFor(userId) {
  return db.prepare(`
    SELECT d.*, a.acked_at
    FROM company_documents d
    LEFT JOIN document_acks a ON a.document_id = d.id AND a.user_id = ?
    ORDER BY d.published_at DESC, d.title COLLATE NOCASE
  `).all(userId);
}

function pendingAckCount(userId) {
  return db.prepare(`
    SELECT COUNT(*) AS n FROM company_documents d
    WHERE d.requires_ack = 1
      AND NOT EXISTS (SELECT 1 FROM document_acks a WHERE a.document_id = d.id AND a.user_id = ?)
  `).get(userId).n;
}

/** L'accusé de réception est daté et définitif : on ne le retire pas. */
function acknowledge(documentId, userId) {
  const document = documentById(documentId);
  if (!document) return false;
  db.prepare('INSERT OR IGNORE INTO document_acks (document_id, user_id) VALUES (?, ?)').run(documentId, userId);
  return true;
}

function acksOf(documentId) {
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name
    FROM document_acks a JOIN users u ON u.id = a.user_id
    WHERE a.document_id = ? ORDER BY a.acked_at DESC
  `).all(documentId);
}

// ---------- Formation ----------

function trainings() {
  return db.prepare('SELECT * FROM trainings ORDER BY category COLLATE NOCASE, title COLLATE NOCASE').all();
}

function createTraining(data) {
  return db.prepare(`
    INSERT INTO trainings (title, category, provider, description, duration_hours, cost)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(data.title, data.category || '', data.provider || '', data.description || '',
         data.durationHours ?? null, data.cost ?? null).lastInsertRowid;
}

function deleteTraining(id) {
  db.prepare('DELETE FROM trainings WHERE id = ?').run(id);
}

function sessions() {
  return db.prepare(`
    SELECT s.*, t.title, t.category, t.provider, t.duration_hours, t.cost,
      (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Inscrite') AS taken,
      (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Demandée') AS pending
    FROM training_sessions s JOIN trainings t ON t.id = s.training_id
    ORDER BY s.start_date DESC
  `).all();
}

function sessionById(id) {
  return db.prepare(`
    SELECT s.*, t.title, t.category,
      (SELECT COUNT(*) FROM training_registrations r WHERE r.session_id = s.id AND r.status = 'Inscrite') AS taken
    FROM training_sessions s JOIN trainings t ON t.id = s.training_id
    WHERE s.id = ?
  `).get(id) || null;
}

function createSession(data) {
  return db.prepare(`
    INSERT INTO training_sessions (training_id, start_date, end_date, location, seats, status)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(data.trainingId, data.startDate, data.endDate || null, data.location || '',
         data.seats || 0, data.status || 'Planifiée').lastInsertRowid;
}

function setSessionStatus(id, status) {
  if (!SESSION_STATUSES.includes(status)) return false;
  return db.prepare('UPDATE training_sessions SET status = ? WHERE id = ?').run(status, id).changes > 0;
}

function deleteSession(id) {
  db.prepare('DELETE FROM training_sessions WHERE id = ?').run(id);
}

/** Un salarié demande sa place ; les RH la confirment ou la refusent. */
function requestSeat({ sessionId, employeeId }) {
  const session = sessionById(sessionId);
  if (!session) return { ok: false, reason: 'not-found' };
  if (['Terminée', 'Annulée'].includes(session.status)) return { ok: false, reason: 'closed' };

  const existing = db.prepare('SELECT * FROM training_registrations WHERE session_id = ? AND employee_id = ?').get(sessionId, employeeId);
  if (existing) return { ok: false, reason: 'already-registered' };

  db.prepare('INSERT INTO training_registrations (session_id, employee_id) VALUES (?, ?)').run(sessionId, employeeId);
  return { ok: true };
}

function reviewRegistration(id, status, reviewerId) {
  if (!REGISTRATION_STATUSES.includes(status)) return { ok: false, reason: 'bad-status' };
  const registration = db.prepare('SELECT * FROM training_registrations WHERE id = ?').get(id);
  if (!registration) return { ok: false, reason: 'not-found' };

  // Une session pleine ne prend pas d'inscrit de plus : la limite tient côté serveur.
  if (status === 'Inscrite') {
    const session = sessionById(registration.session_id);
    if (session.seats > 0 && session.taken >= session.seats) return { ok: false, reason: 'full' };
  }

  db.prepare('UPDATE training_registrations SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
    .run(status, reviewerId, new Date().toISOString(), id);
  return { ok: true };
}

function registrations({ sessionId, employeeId } = {}) {
  const clauses = [];
  const params = [];
  if (sessionId) { clauses.push('r.session_id = ?'); params.push(sessionId); }
  if (employeeId) { clauses.push('r.employee_id = ?'); params.push(employeeId); }

  return db.prepare(`
    SELECT r.*, u.first_name, u.last_name, t.title, s.start_date, s.end_date, s.location, s.status AS session_status
    FROM training_registrations r
    JOIN training_sessions s ON s.id = r.session_id
    JOIN trainings t ON t.id = s.training_id
    JOIN users u ON u.id = r.employee_id
    ${clauses.length ? 'WHERE ' + clauses.join(' AND ') : ''}
    ORDER BY s.start_date DESC
  `).all(...params);
}

function cancelOwnRegistration(id, employeeId) {
  return db.prepare("DELETE FROM training_registrations WHERE id = ? AND employee_id = ? AND status = 'Demandée'")
    .run(id, employeeId).changes > 0;
}

// ---------- Entretiens annuels ----------

function reviews({ employeeId } = {}) {
  const where = employeeId ? 'WHERE r.employee_id = ?' : '';
  const query = db.prepare(`
    SELECT r.*, u.first_name, u.last_name,
           m.first_name AS reviewer_first_name, m.last_name AS reviewer_last_name
    FROM reviews r
    JOIN users u ON u.id = r.employee_id
    LEFT JOIN users m ON m.id = r.reviewer_id
    ${where}
    ORDER BY r.period DESC, u.last_name COLLATE NOCASE
  `);
  return employeeId ? query.all(employeeId) : query.all();
}

function reviewById(id) {
  return db.prepare('SELECT * FROM reviews WHERE id = ?').get(id) || null;
}

function createReview(data) {
  return db.prepare(`
    INSERT INTO reviews (employee_id, reviewer_id, period, scheduled_on)
    VALUES (?, ?, ?, ?)
  `).run(data.employeeId, data.reviewerId || null, data.period, data.scheduledOn || null).lastInsertRowid;
}

function completeReview(id, data) {
  const review = reviewById(id);
  if (!review) return { ok: false, reason: 'not-found' };
  if (review.status === 'Annulé') return { ok: false, reason: 'cancelled' };
  if (data.rating != null && (data.rating < 1 || data.rating > 5)) return { ok: false, reason: 'bad-rating' };

  db.prepare(`
    UPDATE reviews SET strengths = ?, improvements = ?, objectives = ?, rating = ?, status = 'Réalisé', completed_at = ?
    WHERE id = ?
  `).run(data.strengths || '', data.improvements || '', data.objectives || '', data.rating ?? null,
         new Date().toISOString(), id);
  return { ok: true };
}

/** Le salarié ajoute son propre commentaire, et rien d'autre, sur son entretien. */
function addEmployeeComment(id, employeeId, comment) {
  return db.prepare('UPDATE reviews SET employee_comment = ? WHERE id = ? AND employee_id = ?')
    .run(comment, id, employeeId).changes > 0;
}

function cancelReview(id) {
  return db.prepare("UPDATE reviews SET status = 'Annulé' WHERE id = ?").run(id).changes > 0;
}

function deleteReview(id) {
  db.prepare('DELETE FROM reviews WHERE id = ?').run(id);
}

// ---------- Recrutement ----------

function openings() {
  return db.prepare(`
    SELECT o.*, d.name AS department_name, t.name AS team_name,
      (SELECT COUNT(*) FROM candidates c WHERE c.opening_id = o.id) AS candidate_count,
      (SELECT COUNT(*) FROM candidates c WHERE c.opening_id = o.id AND c.stage NOT IN ('Recruté','Refusé')) AS active_count
    FROM job_openings o
    LEFT JOIN departments d ON d.id = o.department_id
    LEFT JOIN teams t ON t.id = o.team_id
    ORDER BY o.opened_on DESC
  `).all();
}

function openingById(id) {
  return db.prepare('SELECT * FROM job_openings WHERE id = ?').get(id) || null;
}

function createOpening(data) {
  return db.prepare(`
    INSERT INTO job_openings (title, department_id, team_id, contract_type, description, created_by)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(data.title, data.departmentId || null, data.teamId || null, data.contractType || '',
         data.description || '', data.createdBy).lastInsertRowid;
}

function setOpeningStatus(id, status) {
  if (!OPENING_STATUSES.includes(status)) return false;
  const closed = ['Pourvu', 'Annulé'].includes(status);
  return db.prepare('UPDATE job_openings SET status = ?, closed_on = ? WHERE id = ?')
    .run(status, closed ? new Date().toISOString().slice(0, 10) : null, id).changes > 0;
}

function deleteOpening(id) {
  db.prepare('DELETE FROM job_openings WHERE id = ?').run(id);
}

function candidates(openingId) {
  return db.prepare('SELECT * FROM candidates WHERE opening_id = ? ORDER BY created_at DESC').all(openingId);
}

function createCandidate(data) {
  const opening = openingById(data.openingId);
  if (!opening) return { ok: false, reason: 'not-found' };
  if (['Pourvu', 'Annulé'].includes(opening.status)) return { ok: false, reason: 'closed' };

  db.prepare(`
    INSERT INTO candidates (opening_id, first_name, last_name, email, phone, source, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(data.openingId, data.firstName, data.lastName, data.email || '', data.phone || '',
         data.source || '', data.notes || '');
  return { ok: true };
}

function setCandidateStage(id, stage) {
  if (!CANDIDATE_STAGES.includes(stage)) return false;
  return db.prepare('UPDATE candidates SET stage = ? WHERE id = ?').run(stage, id).changes > 0;
}

function deleteCandidate(id) {
  db.prepare('DELETE FROM candidates WHERE id = ?').run(id);
}

module.exports = {
  DOCUMENT_CATEGORIES,
  SESSION_STATUSES,
  REGISTRATION_STATUSES,
  REVIEW_STATUSES,
  OPENING_STATUSES,
  CANDIDATE_STAGES,
  documents,
  documentById,
  createDocument,
  deleteDocument,
  documentsFor,
  pendingAckCount,
  acknowledge,
  acksOf,
  trainings,
  createTraining,
  deleteTraining,
  sessions,
  sessionById,
  createSession,
  setSessionStatus,
  deleteSession,
  requestSeat,
  reviewRegistration,
  registrations,
  cancelOwnRegistration,
  reviews,
  reviewById,
  createReview,
  completeReview,
  addEmployeeComment,
  cancelReview,
  deleteReview,
  openings,
  openingById,
  createOpening,
  setOpeningStatus,
  deleteOpening,
  candidates,
  createCandidate,
  setCandidateStage,
  deleteCandidate,
};
