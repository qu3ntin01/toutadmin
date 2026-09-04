const db = require('./db');
const hr = require('./hr');

// Rôles siégeant au comité. Le président est l'employeur : il n'est pas élu, donc absent d'ici.
const MANDATE_ROLES = ['Titulaire', 'Suppléant', 'Secrétaire', 'Trésorier', 'Référent harcèlement'];
const ELECTION_STATUSES = ['Candidatures', 'Vote', 'Clôturée'];
const BENEFIT_CATEGORIES = ['Billetterie', 'Voyages', 'Sport & loisirs', 'Culture', 'Commerces', 'Restauration', 'Famille', 'Autre'];

/** Le CSE représente les salariés : ni les administrateurs, ni les freelances non salariés. */
function isEligible(user) {
  return hr.isEligibleForHrFeatures(user);
}

// ---------- Mandats ----------

function mandates() {
  return db.prepare(`
    SELECT m.*, u.first_name, u.last_name, u.email, u.grade, u.avatar_file,
           d.name AS department_name, t.name AS team_name
    FROM cse_mandates m
    JOIN users u ON u.id = m.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN teams t ON t.id = u.team_id
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all();
}

function mandateFor(userId) {
  return db.prepare('SELECT * FROM cse_mandates WHERE user_id = ?').get(userId) || null;
}

/** Un mandat échu ne donne plus accès à l'espace de gestion. */
function isElected(userId) {
  const today = new Date().toISOString().slice(0, 10);
  const row = db
    .prepare("SELECT 1 AS ok FROM cse_mandates WHERE user_id = ? AND (ends_on IS NULL OR ends_on = '' OR ends_on >= ?)")
    .get(userId, today);
  return Boolean(row);
}

function addMandate({ userId, mandateRole, startedOn, endsOn, createdBy }) {
  db.prepare(`
    INSERT INTO cse_mandates (user_id, mandate_role, started_on, ends_on, created_by)
    VALUES (?, ?, ?, ?, ?)
    ON CONFLICT(user_id) DO UPDATE SET mandate_role = excluded.mandate_role, started_on = excluded.started_on, ends_on = excluded.ends_on
  `).run(userId, mandateRole, startedOn, endsOn || null, createdBy);
}

function removeMandate(userId) {
  db.prepare('DELETE FROM cse_mandates WHERE user_id = ?').run(userId);
}

// ---------- Élections ----------

function elections() {
  return db.prepare('SELECT * FROM cse_elections ORDER BY created_at DESC').all();
}

function electionById(id) {
  return db.prepare('SELECT * FROM cse_elections WHERE id = ?').get(id) || null;
}

/** L'élection que voit un salarié : la dernière encore ouverte. */
function openElection() {
  return db.prepare("SELECT * FROM cse_elections WHERE status != 'Clôturée' ORDER BY created_at DESC LIMIT 1").get() || null;
}

function createElection({ title, description, seats, candidacyDeadline, voteStart, voteEnd, createdBy }) {
  return db.prepare(`
    INSERT INTO cse_elections (title, description, seats, candidacy_deadline, vote_start, vote_end, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(title, description || '', seats, candidacyDeadline || null, voteStart || null, voteEnd || null, createdBy).lastInsertRowid;
}

function setElectionStatus(id, status) {
  if (!ELECTION_STATUSES.includes(status)) return { ok: false, reason: 'bad-status' };
  const election = electionById(id);
  if (!election) return { ok: false, reason: 'not-found' };
  if (election.status === 'Clôturée') return { ok: false, reason: 'closed' };

  // Ouvrir le vote sans candidat validé produirait un scrutin vide.
  if (status === 'Vote' && candidacies(id, { validatedOnly: true }).length === 0) {
    return { ok: false, reason: 'no-candidate' };
  }

  db.prepare('UPDATE cse_elections SET status = ?, closed_at = ? WHERE id = ?')
    .run(status, status === 'Clôturée' ? new Date().toISOString() : null, id);
  return { ok: true };
}

function deleteElection(id) {
  db.prepare('DELETE FROM cse_elections WHERE id = ?').run(id);
}

// ---------- Candidatures ----------

function candidacies(electionId, { validatedOnly = false } = {}) {
  const where = validatedOnly ? "AND c.status = 'Validée'" : '';
  return db.prepare(`
    SELECT c.*, u.first_name, u.last_name, u.grade, u.avatar_file, d.name AS department_name
    FROM cse_candidacies c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE c.election_id = ? ${where}
    ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(electionId);
}

function candidacyFor(electionId, userId) {
  return db.prepare('SELECT * FROM cse_candidacies WHERE election_id = ? AND user_id = ?').get(electionId, userId) || null;
}

function applyForElection({ electionId, userId, statement }) {
  const election = electionById(electionId);
  if (!election) return { ok: false, reason: 'not-found' };
  if (election.status !== 'Candidatures') return { ok: false, reason: 'closed' };
  if (candidacyFor(electionId, userId)) return { ok: false, reason: 'already-applied' };

  db.prepare('INSERT INTO cse_candidacies (election_id, user_id, statement) VALUES (?, ?, ?)')
    .run(electionId, userId, statement || '');
  return { ok: true };
}

function withdrawCandidacy(electionId, userId) {
  const election = electionById(electionId);
  // Retirer sa candidature une fois le vote ouvert fausserait le scrutin en cours.
  if (!election || election.status !== 'Candidatures') return { ok: false, reason: 'closed' };
  const result = db.prepare('DELETE FROM cse_candidacies WHERE election_id = ? AND user_id = ?').run(electionId, userId);
  return result.changes > 0 ? { ok: true } : { ok: false, reason: 'not-found' };
}

function reviewCandidacy(id, status, reviewerId) {
  if (!['Validée', 'Refusée'].includes(status)) return { ok: false, reason: 'bad-status' };
  const candidacy = db.prepare('SELECT * FROM cse_candidacies WHERE id = ?').get(id);
  if (!candidacy) return { ok: false, reason: 'not-found' };

  const election = electionById(candidacy.election_id);
  if (!election || election.status !== 'Candidatures') return { ok: false, reason: 'closed' };

  db.prepare('UPDATE cse_candidacies SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
    .run(status, reviewerId, new Date().toISOString(), id);
  return { ok: true };
}

// ---------- Scrutin ----------

function hasVoted(electionId, userId) {
  return Boolean(db.prepare('SELECT 1 AS ok FROM cse_voters WHERE election_id = ? AND user_id = ?').get(electionId, userId));
}

/** Le bulletin et l'émargement sont écrits ensemble, mais restent deux lignes sans lien entre elles. */
function castBallot({ electionId, userId, candidacyId }) {
  const election = electionById(electionId);
  if (!election) return { ok: false, reason: 'not-found' };
  if (election.status !== 'Vote') return { ok: false, reason: 'not-open' };
  if (hasVoted(electionId, userId)) return { ok: false, reason: 'already-voted' };

  const candidacy = db
    .prepare("SELECT * FROM cse_candidacies WHERE id = ? AND election_id = ? AND status = 'Validée'")
    .get(candidacyId, electionId);
  if (!candidacy) return { ok: false, reason: 'bad-candidate' };

  const commit = db.transaction(() => {
    db.prepare('INSERT INTO cse_voters (election_id, user_id) VALUES (?, ?)').run(electionId, userId);
    db.prepare('INSERT INTO cse_ballots (election_id, candidacy_id) VALUES (?, ?)').run(electionId, candidacyId);
  });
  commit();
  return { ok: true };
}

function turnout(electionId) {
  const voters = db.prepare('SELECT COUNT(*) AS n FROM cse_voters WHERE election_id = ?').get(electionId).n;
  const electorate = db
    .prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'employee' AND active = 1 AND contract_type != 'Freelance'")
    .get().n;
  return { voters, electorate, rate: electorate > 0 ? Math.round((voters / electorate) * 1000) / 10 : 0 };
}

/** Résultats : les candidats validés, du plus au moins voté. */
function results(electionId) {
  return db.prepare(`
    SELECT c.id, c.user_id, u.first_name, u.last_name, u.grade, d.name AS department_name,
           (SELECT COUNT(*) FROM cse_ballots b WHERE b.candidacy_id = c.id) AS votes
    FROM cse_candidacies c
    JOIN users u ON u.id = c.user_id
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE c.election_id = ? AND c.status = 'Validée'
    ORDER BY votes DESC, u.last_name COLLATE NOCASE
  `).all(electionId);
}

// ---------- Réunions ----------

function meetings(limit = 60) {
  return db.prepare('SELECT * FROM cse_meetings ORDER BY meeting_date DESC LIMIT ?').all(limit);
}

/** Vue salarié : les réunions à venir, et les précédentes dont le compte-rendu est publié. */
function meetingsForStaff(limit = 40) {
  const today = new Date().toISOString().slice(0, 10);
  return db.prepare(`
    SELECT * FROM cse_meetings
    WHERE meeting_date >= ? OR minutes_published = 1
    ORDER BY meeting_date DESC LIMIT ?
  `).all(today, limit);
}

function upcomingMeetings(limit = 5) {
  const today = new Date().toISOString().slice(0, 10);
  return db.prepare('SELECT * FROM cse_meetings WHERE meeting_date >= ? ORDER BY meeting_date LIMIT ?').all(today, limit);
}

function createMeeting({ title, meetingDate, meetingTime, location, agenda, createdBy }) {
  return db.prepare(`
    INSERT INTO cse_meetings (title, meeting_date, meeting_time, location, agenda, created_by)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(title, meetingDate, meetingTime || '', location || '', agenda || '', createdBy).lastInsertRowid;
}

function saveMinutes(id, minutes, publish) {
  const result = db.prepare('UPDATE cse_meetings SET minutes = ?, minutes_published = ? WHERE id = ?')
    .run(minutes || '', publish ? 1 : 0, id);
  return result.changes > 0;
}

function deleteMeeting(id) {
  db.prepare('DELETE FROM cse_meetings WHERE id = ?').run(id);
}

// ---------- Avantages et réductions ----------

function benefits({ activeOnly = false } = {}) {
  const today = new Date().toISOString().slice(0, 10);
  // Un avantage périmé disparaît de la vue salarié sans que personne ait à le désactiver.
  const where = activeOnly ? "WHERE active = 1 AND (valid_until IS NULL OR valid_until = '' OR valid_until >= ?)" : '';
  const sql = `SELECT * FROM cse_benefits ${where} ORDER BY category COLLATE NOCASE, title COLLATE NOCASE`;
  return activeOnly ? db.prepare(sql).all(today) : db.prepare(sql).all();
}

function benefitById(id) {
  return db.prepare('SELECT * FROM cse_benefits WHERE id = ?').get(id) || null;
}

function createBenefit(data) {
  return db.prepare(`
    INSERT INTO cse_benefits (title, category, partner, description, discount, code, url, valid_until, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(data.title, data.category || '', data.partner || '', data.description || '', data.discount || '',
         data.code || '', data.url || '', data.validUntil || null, data.createdBy).lastInsertRowid;
}

function updateBenefit(id, data) {
  db.prepare(`
    UPDATE cse_benefits
    SET title = ?, category = ?, partner = ?, description = ?, discount = ?, code = ?, url = ?, valid_until = ?, active = ?
    WHERE id = ?
  `).run(data.title, data.category || '', data.partner || '', data.description || '', data.discount || '',
         data.code || '', data.url || '', data.validUntil || null, data.active ? 1 : 0, id);
}

function deleteBenefit(id) {
  db.prepare('DELETE FROM cse_benefits WHERE id = ?').run(id);
}

module.exports = {
  MANDATE_ROLES,
  ELECTION_STATUSES,
  BENEFIT_CATEGORIES,
  isEligible,
  mandates,
  mandateFor,
  isElected,
  addMandate,
  removeMandate,
  elections,
  electionById,
  openElection,
  createElection,
  setElectionStatus,
  deleteElection,
  candidacies,
  candidacyFor,
  applyForElection,
  withdrawCandidacy,
  reviewCandidacy,
  hasVoted,
  castBallot,
  turnout,
  results,
  meetings,
  meetingsForStaff,
  upcomingMeetings,
  createMeeting,
  saveMinutes,
  deleteMeeting,
  benefits,
  benefitById,
  createBenefit,
  updateBenefit,
  deleteBenefit,
};
