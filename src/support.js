const db = require('./db');

/**
 * Tickets et base de connaissances.
 *
 * Un même mécanisme sert les demandes internes (informatique, RH, moyens
 * généraux) et les demandes clients : ce qui change est l'origine et le
 * demandeur, pas le circuit. Le délai de traitement est calculé à partir de la
 * priorité, ce qui rend visible ce qui dérape sans qu'on ait à le déclarer.
 */

const CATEGORIES = ['Informatique', 'Ressources humaines', 'Moyens généraux', 'Client', 'Autre'];
const PRIORITIES = ['Basse', 'Normale', 'Haute', 'Critique'];
const STATUSES = ['Ouvert', 'En cours', 'En attente', 'Résolu', 'Clos'];
const ORIGINS = ['Interne', 'Client'];
const OPEN_STATUSES = ['Ouvert', 'En cours', 'En attente'];

// Une demande RH parle de paie, de contrat, parfois de santé : elle ne se
// traite pas par la même file que le remplacement d'un écran.
const RESTRICTED_CATEGORY = 'Ressources humaines';

/**
 * Les catégories qu'une personne peut traiter. Un manager d'équipe n'a pas à
 * lire les demandes RH de toute l'entreprise ; les RH, si.
 */
function agentCategories(user) {
  if (!user) return [];
  if (user.role === 'admin' || user.is_hr) return CATEGORIES;
  if (user.is_finance) return CATEGORIES.filter((c) => c !== RESTRICTED_CATEGORY);
  return [];
}

// Délai de première réponse attendu, en heures, selon la priorité.
const RESPONSE_HOURS = { Critique: 2, Haute: 8, Normale: 24, Basse: 72 };

function reference(id) {
  return `T-${String(id).padStart(5, '0')}`;
}

function dueFor(priority, from = new Date()) {
  const hours = RESPONSE_HOURS[priority] || 24;
  return new Date(from.getTime() + hours * 60 * 60 * 1000).toISOString();
}

function create({ subject, body, category, priority, origin, requesterId, partnerId }) {
  const info = db.prepare(`
    INSERT INTO tickets (subject, body, category, priority, origin, requester_id, partner_id, due_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(subject, body || '', category, priority, origin, requesterId || null, partnerId || null, dueFor(priority));

  const id = info.lastInsertRowid;
  db.prepare('UPDATE tickets SET reference = ? WHERE id = ?').run(reference(id), id);
  require('./webhooks').emit('ticket.ouvert', { id, reference: reference(id), sujet: subject, priorite: priority, origine: origin });
  return id;
}

function byId(id) {
  return db.prepare(`
    SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name, r.email AS requester_email,
           a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
           p.name AS partner_name
    FROM tickets tk
    LEFT JOIN users r ON r.id = tk.requester_id
    LEFT JOIN users a ON a.id = tk.assignee_id
    LEFT JOIN partners p ON p.id = tk.partner_id
    WHERE tk.id = ?
  `).get(Number(id) || 0) || null;
}

function list({ status = '', category = '', assigneeId = null, requesterId = null, openOnly = false, categories = null } = {}) {
  const clauses = [];
  const params = [];
  // Restriction par catégorie : posée en SQL, pas retirée à l'affichage.
  if (categories) {
    if (categories.length === 0) return [];
    clauses.push(`tk.category IN (${categories.map(() => '?').join(',')})`);
    params.push(...categories);
  }
  if (status) { clauses.push('tk.status = ?'); params.push(status); }
  if (openOnly) { clauses.push(`tk.status IN (${OPEN_STATUSES.map(() => '?').join(',')})`); params.push(...OPEN_STATUSES); }
  if (category) { clauses.push('tk.category = ?'); params.push(category); }
  if (assigneeId) { clauses.push('tk.assignee_id = ?'); params.push(assigneeId); }
  if (requesterId) { clauses.push('tk.requester_id = ?'); params.push(requesterId); }

  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  return db.prepare(`
    SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name,
           a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
           p.name AS partner_name,
           (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = tk.id) AS message_count
    FROM tickets tk
    LEFT JOIN users r ON r.id = tk.requester_id
    LEFT JOIN users a ON a.id = tk.assignee_id
    LEFT JOIN partners p ON p.id = tk.partner_id
    ${where}
    ORDER BY tk.status IN ('Résolu','Clos'),
             CASE tk.priority WHEN 'Critique' THEN 0 WHEN 'Haute' THEN 1 WHEN 'Normale' THEN 2 ELSE 3 END,
             tk.due_at
  `).all(...params);
}

function messages(ticketId, { includeInternal = true } = {}) {
  const clause = includeInternal ? '' : 'AND m.internal = 0';
  return db.prepare(`
    SELECT m.*, u.first_name, u.last_name
    FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_id
    WHERE m.ticket_id = ? ${clause}
    ORDER BY m.id
  `).all(ticketId);
}

/** Répondre marque la première réponse : c'est elle que mesure le délai. */
function reply({ ticketId, authorId, body, internal = false }) {
  db.prepare('INSERT INTO ticket_messages (ticket_id, author_id, body, internal) VALUES (?, ?, ?, ?)')
    .run(ticketId, authorId || null, body, internal ? 1 : 0);

  if (!internal) {
    db.prepare("UPDATE tickets SET first_reply_at = COALESCE(first_reply_at, datetime('now')) WHERE id = ?").run(ticketId);
  }
}

function setStatus(id, status) {
  if (!STATUSES.includes(status)) return false;
  const closing = status === 'Résolu' || status === 'Clos';
  db.prepare('UPDATE tickets SET status = ?, closed_at = ? WHERE id = ?')
    .run(status, closing ? new Date().toISOString() : null, id);
  return true;
}

function assign(id, userId) {
  db.prepare('UPDATE tickets SET assignee_id = ? WHERE id = ?').run(userId || null, id);
}

function setPriority(id, priority) {
  if (!PRIORITIES.includes(priority)) return false;
  const ticket = db.prepare('SELECT created_at FROM tickets WHERE id = ?').get(id);
  if (!ticket) return false;
  // Le délai suit la priorité : il se recalcule depuis l'ouverture, pas depuis maintenant.
  const from = new Date(ticket.created_at.replace(' ', 'T') + 'Z');
  db.prepare('UPDATE tickets SET priority = ?, due_at = ? WHERE id = ?').run(priority, dueFor(priority, from), id);
  return true;
}

/** Un ticket est en retard s'il n'a pas reçu de réponse dans son délai. */
function isOverdue(ticket, now = new Date()) {
  if (!ticket.due_at) return false;
  if (ticket.first_reply_at) return false;
  if (!OPEN_STATUSES.includes(ticket.status)) return false;
  return new Date(ticket.due_at) < now;
}

function summary() {
  const open = db.prepare(`SELECT COUNT(*) AS n FROM tickets WHERE status IN (${OPEN_STATUSES.map(() => '?').join(',')})`).get(...OPEN_STATUSES).n;
  const unassigned = db.prepare(`SELECT COUNT(*) AS n FROM tickets WHERE assignee_id IS NULL AND status IN (${OPEN_STATUSES.map(() => '?').join(',')})`).get(...OPEN_STATUSES).n;
  const overdue = list({ openOnly: true }).filter((tk) => isOverdue(tk)).length;
  const closedThisMonth = db.prepare("SELECT COUNT(*) AS n FROM tickets WHERE closed_at >= date('now', 'start of month')").get().n;
  return { open, unassigned, overdue, closedThisMonth };
}

function remove(id) {
  db.prepare('DELETE FROM tickets WHERE id = ?').run(id);
}

// ---------- Base de connaissances ----------

const KB_VISIBILITIES = ['Entreprise', 'Service', 'Équipe', 'Administration'];

function articles({ category = '', query = '', visibleTo = null } = {}) {
  const clauses = ['published = 1'];
  const params = [];
  if (category) { clauses.push('category = ?'); params.push(category); }
  if (query) {
    clauses.push('(title LIKE ? OR body LIKE ? OR category LIKE ?)');
    const like = `%${query}%`;
    params.push(like, like, like);
  }

  const rows = db.prepare(`
    SELECT a.*, u.first_name, u.last_name FROM kb_articles a
    LEFT JOIN users u ON u.id = a.author_id
    WHERE ${clauses.join(' AND ')}
    ORDER BY a.category COLLATE NOCASE, a.title COLLATE NOCASE
  `).all(...params);

  if (!visibleTo) return rows;
  return rows.filter((row) => canRead(row, visibleTo));
}

/**
 * Un article n'est lisible que dans sa portée. « Administration » couvre les
 * procédures internes qu'un salarié n'a pas à voir.
 */
function canRead(article, user) {
  if (user.role === 'admin') return true;
  if (article.visibility === 'Administration') return false;
  if (article.visibility === 'Service') return article.scope_id === user.department_id;
  if (article.visibility === 'Équipe') return article.scope_id === user.team_id;
  return true;
}

function articleById(id) {
  return db.prepare('SELECT * FROM kb_articles WHERE id = ?').get(Number(id) || 0) || null;
}

function createArticle({ title, category, body, visibility, scopeId, authorId }) {
  return db.prepare(`
    INSERT INTO kb_articles (title, category, body, visibility, scope_id, author_id)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(title, category || 'Général', body || '', visibility || 'Entreprise', scopeId || null, authorId || null).lastInsertRowid;
}

function updateArticle(id, { title, category, body, visibility, scopeId, published }) {
  db.prepare(`
    UPDATE kb_articles SET title = ?, category = ?, body = ?, visibility = ?, scope_id = ?, published = ?, updated_at = datetime('now')
    WHERE id = ?
  `).run(title, category, body, visibility, scopeId || null, published ? 1 : 0, id);
}

function deleteArticle(id) {
  db.prepare('DELETE FROM kb_articles WHERE id = ?').run(id);
}

function noteRead(id) {
  db.prepare('UPDATE kb_articles SET views = views + 1 WHERE id = ?').run(id);
}

function categories() {
  return db.prepare('SELECT DISTINCT category FROM kb_articles ORDER BY category COLLATE NOCASE').all().map((r) => r.category);
}

module.exports = {
  CATEGORIES, RESTRICTED_CATEGORY, agentCategories, PRIORITIES, STATUSES, ORIGINS, OPEN_STATUSES, RESPONSE_HOURS, KB_VISIBILITIES,
  reference, dueFor, create, byId, list, messages, reply, setStatus, assign, setPriority, isOverdue, summary, remove,
  articles, articleById, canRead, createArticle, updateArticle, deleteArticle, noteRead, categories,
};
