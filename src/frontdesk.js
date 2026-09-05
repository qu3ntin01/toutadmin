const db = require('./db');

/**
 * Accueil : registre des visiteurs et courrier.
 *
 * Deux cahiers qui traînent encore sur un comptoir dans la plupart des
 * entreprises. Le registre des visiteurs n'est pas un formalisme : en cas
 * d'évacuation, il répond à « qui est dans les murs ? », ce qu'aucune liste de
 * salariés ne sait faire. Le courrier, lui, se perd entre l'accueil et le
 * destinataire — d'où un état « à remettre » qui reste visible tant que
 * personne n'a signé la remise.
 */

const MAIL_DIRECTIONS = ['Entrant', 'Sortant'];
const MAIL_KINDS = ['Lettre', 'Recommandé', 'Recommandé avec AR', 'Colis', 'Pli administratif'];
const MAIL_STATUSES = ['À remettre', 'Remis', 'Archivé'];

const today = () => new Date().toISOString().slice(0, 10);
const now = () => new Date().toTimeString().slice(0, 5);

// ---------- Visiteurs ----------

function visitors({ day = null, limit = 200 } = {}) {
  const clause = day ? 'WHERE v.visited_on = ?' : '';
  const params = day ? [day, limit] : [limit];
  return db.prepare(`
    SELECT v.*, u.first_name AS host_first, u.last_name AS host_last
    FROM visitors v LEFT JOIN users u ON u.id = v.host_id
    ${clause}
    ORDER BY v.visited_on DESC, v.arrived_at DESC, v.id DESC LIMIT ?
  `).all(...params);
}

/** Ceux qui sont entrés et n'ont pas été rendus : la liste d'évacuation. */
function present() {
  return db.prepare(`
    SELECT v.*, u.first_name AS host_first, u.last_name AS host_last
    FROM visitors v LEFT JOIN users u ON u.id = v.host_id
    WHERE v.visited_on = date('now') AND v.arrived_at != '' AND v.departed_at = ''
    ORDER BY v.arrived_at
  `).all();
}

function checkIn({ firstName, lastName, company, purpose, hostId, badge, notes, createdBy, visitedOn, arrivedAt }) {
  return db.prepare(`
    INSERT INTO visitors (visited_on, arrived_at, first_name, last_name, company, purpose, host_id, badge, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(visitedOn || today(), arrivedAt || now(), firstName || '', lastName, company || '', purpose || '',
    hostId || null, badge || '', notes || '', createdBy || null).lastInsertRowid;
}

function checkOut(id, at = now()) {
  return db.prepare("UPDATE visitors SET departed_at = ? WHERE id = ? AND departed_at = ''").run(at, id).changes > 0;
}

function deleteVisitor(id) {
  db.prepare('DELETE FROM visitors WHERE id = ?').run(id);
}

// ---------- Courrier ----------

function mail({ direction = null, status = null, limit = 300 } = {}) {
  const clauses = [];
  const params = [];
  if (direction) { clauses.push('m.direction = ?'); params.push(direction); }
  if (status) { clauses.push('m.status = ?'); params.push(status); }
  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  params.push(limit);

  return db.prepare(`
    SELECT m.*, u.first_name, u.last_name FROM mail_items m
    LEFT JOIN users u ON u.id = m.recipient_id
    ${where}
    ORDER BY m.logged_on DESC, m.id DESC LIMIT ?
  `).all(...params);
}

function logMail(fields) {
  return db.prepare(`
    INSERT INTO mail_items (direction, logged_on, kind, correspondent, recipient_id, recipient_label, tracking, subject, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(fields.direction, fields.loggedOn || today(), fields.kind, fields.correspondent || '',
    fields.recipientId || null, fields.recipientLabel || '', fields.tracking || '',
    fields.subject || '', fields.notes || '').lastInsertRowid;
}

/** La remise est datée et signée d'un nom : c'est tout l'intérêt du registre. */
function handOver(id, handedBy) {
  return db.prepare("UPDATE mail_items SET status = 'Remis', handed_on = date('now'), handed_by = ? WHERE id = ? AND status = 'À remettre'")
    .run(handedBy || null, id).changes > 0;
}

function archiveMail(id) {
  return db.prepare("UPDATE mail_items SET status = 'Archivé' WHERE id = ?").run(id).changes > 0;
}

function deleteMail(id) {
  db.prepare('DELETE FROM mail_items WHERE id = ?').run(id);
}

/** Le courrier d'une personne : ce qui l'attend à l'accueil. */
function mailFor(userId) {
  return db.prepare(`
    SELECT * FROM mail_items WHERE recipient_id = ? AND status = 'À remettre' ORDER BY logged_on
  `).all(userId);
}

function summary() {
  return {
    presentNow: present().length,
    visitorsToday: db.prepare('SELECT COUNT(*) AS n FROM visitors WHERE visited_on = date(\'now\')').get().n,
    visitorsMonth: db.prepare("SELECT COUNT(*) AS n FROM visitors WHERE visited_on >= date('now', '-30 days')").get().n,
    pendingMail: db.prepare("SELECT COUNT(*) AS n FROM mail_items WHERE status = 'À remettre'").get().n,
    registered: db.prepare("SELECT COUNT(*) AS n FROM mail_items WHERE kind LIKE 'Recommandé%' AND status = 'À remettre'").get().n,
  };
}

module.exports = {
  MAIL_DIRECTIONS, MAIL_KINDS, MAIL_STATUSES,
  visitors, present, checkIn, checkOut, deleteVisitor,
  mail, logMail, handOver, archiveMail, deleteMail, mailFor, summary,
};
