const db = require('./db');
const societes = require('./societes');

/** Le périmètre courant : une demande et un groupe appartiennent à une société. */
const ici = () => societes.courant();

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

/**
 * Les catégories qu'une personne peut traiter, rôles ET groupes réunis.
 *
 * C'est la fonction que les écrans appellent. « agentCategories » reste ce que
 * les rôles seuls donnent, et sert à afficher d'où vient un accès : sans cette
 * distinction, l'écran « qui a accès à quoi » ne pourrait pas dire à
 * l'administration si retirer quelqu'un d'un groupe lui ferme vraiment la porte.
 */
function categoriesFor(user) {
  if (!user) return [];
  const parRole = agentCategories(user);
  if (parRole.length === CATEGORIES.length) return CATEGORIES;
  const parGroupe = user.id ? groupCategoriesOf(user.id) : [];
  const tout = new Set([...parRole, ...parGroupe]);
  return CATEGORIES.filter((c) => tout.has(c));
}

// Délai de première réponse attendu, en heures, selon la priorité. Ce sont les
// valeurs d'origine ; chaque instance les règle dans la section « Relations »,
// parce qu'un délai de deux heures n'a pas le même sens pour un service
// interne de trois personnes et pour une hotline client.
const RESPONSE_HOURS = { Critique: 2, Haute: 8, Normale: 24, Basse: 72 };

// Le champ qui porte chaque priorité dans les réglages de la section.
const CHAMP_DELAI = { Critique: 'delai_critique', Haute: 'delai_haute', Normale: 'delai_normale', Basse: 'delai_basse' };

/**
 * Les heures accordées à une priorité.
 *
 * Demandé à l'appel plutôt qu'à l'import : « sections-reglages » lit la base,
 * et ce module est chargé avant qu'elle existe dans les outils en ligne de
 * commande.
 */
function responseHours(priority) {
  const champ = CHAMP_DELAI[priority];
  if (!champ) return RESPONSE_HOURS.Normale;
  // eslint-disable-next-line global-require
  return require('./sections-reglages').nombre('relation', champ);
}

function reference(id) {
  return `T-${String(id).padStart(5, '0')}`;
}

function dueFor(priority, from = new Date()) {
  const hours = responseHours(priority);
  return new Date(from.getTime() + hours * 60 * 60 * 1000).toISOString();
}

function create({ subject, body, category, priority, origin, requesterId, partnerId, groupId = undefined }) {
  /*
   * Le groupe est posé à l'ouverture, pas calculé à la lecture.
   *
   * Une demande déposée aujourd'hui garde sa file même si l'administration
   * change demain les catégories d'un groupe : c'est la différence entre une
   * file et une vue, et un agent qui retrouve la demande d'hier là où il l'a
   * laissée n'a pas à se demander si le produit l'a perdue.
   */
  const groupe = groupId === undefined ? groupForCategory(category) : (Number(groupId) || null);
  const info = db.prepare(`
    INSERT INTO tickets (company_id, subject, body, category, priority, origin, requester_id, partner_id, group_id, due_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), subject, body || '', category, priority, origin, requesterId || null, partnerId || null, groupe, dueFor(priority));

  const id = info.lastInsertRowid;
  db.prepare('UPDATE tickets SET reference = ? WHERE id = ? AND company_id = ?').run(reference(id), id, ici());
  require('./webhooks').emit('ticket.ouvert', { id, reference: reference(id), sujet: subject, priorite: priority, origine: origin });
  return id;
}

function byId(id) {
  return db.prepare(`
    SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name, r.email AS requester_email,
           a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
           p.name AS partner_name, g.name AS group_name
    FROM tickets tk
    LEFT JOIN users r ON r.id = tk.requester_id
    LEFT JOIN users a ON a.id = tk.assignee_id
    LEFT JOIN partners p ON p.id = tk.partner_id
    LEFT JOIN support_groups g ON g.id = tk.group_id
    WHERE tk.id = ? AND tk.company_id = ?
  `).get(Number(id) || 0, ici()) || null;
}

function list({ status = '', category = '', assigneeId = null, requesterId = null, openOnly = false, categories = null, groupId = null } = {}) {
  const clauses = ['tk.company_id = ?'];
  const params = [ici()];
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
  if (groupId) { clauses.push('tk.group_id = ?'); params.push(Number(groupId)); }

  const where = `WHERE ${clauses.join(' AND ')}`;
  return db.prepare(`
    SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name,
           a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
           p.name AS partner_name, g.name AS group_name,
           (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = tk.id) AS message_count
    FROM tickets tk
    LEFT JOIN users r ON r.id = tk.requester_id
    LEFT JOIN users a ON a.id = tk.assignee_id
    LEFT JOIN partners p ON p.id = tk.partner_id
    LEFT JOIN support_groups g ON g.id = tk.group_id
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
    db.prepare(`
      UPDATE tickets SET first_reply_at = COALESCE(first_reply_at, datetime('now'))
      WHERE id = ? AND company_id = ?
    `).run(ticketId, ici());
  }
}

function setStatus(id, status) {
  if (!STATUSES.includes(status)) return false;
  const closing = status === 'Résolu' || status === 'Clos';
  db.prepare('UPDATE tickets SET status = ?, closed_at = ? WHERE id = ? AND company_id = ?')
    .run(status, closing ? new Date().toISOString() : null, id, ici());
  return true;
}

function assign(id, userId) {
  db.prepare('UPDATE tickets SET assignee_id = ? WHERE id = ? AND company_id = ?').run(userId || null, id, ici());
}

function setPriority(id, priority) {
  if (!PRIORITIES.includes(priority)) return false;
  const ticket = db.prepare('SELECT created_at FROM tickets WHERE id = ? AND company_id = ?').get(id, ici());
  if (!ticket) return false;
  // Le délai suit la priorité : il se recalcule depuis l'ouverture, pas depuis maintenant.
  const from = new Date(ticket.created_at.replace(' ', 'T') + 'Z');
  db.prepare('UPDATE tickets SET priority = ?, due_at = ? WHERE id = ? AND company_id = ?')
    .run(priority, dueFor(priority, from), id, ici());
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
  const marques = OPEN_STATUSES.map(() => '?').join(',');
  const open = db.prepare(`SELECT COUNT(*) AS n FROM tickets WHERE company_id = ? AND status IN (${marques})`)
    .get(ici(), ...OPEN_STATUSES).n;
  const unassigned = db.prepare(`
    SELECT COUNT(*) AS n FROM tickets
    WHERE company_id = ? AND assignee_id IS NULL AND status IN (${marques})
  `).get(ici(), ...OPEN_STATUSES).n;
  const overdue = list({ openOnly: true }).filter((tk) => isOverdue(tk)).length;
  const closedThisMonth = db.prepare(`
    SELECT COUNT(*) AS n FROM tickets
    WHERE company_id = ? AND closed_at >= date('now', 'start of month')
  `).get(ici()).n;
  return { open, unassigned, overdue, closedThisMonth };
}

function remove(id) {
  db.prepare('DELETE FROM tickets WHERE id = ? AND company_id = ?').run(id, ici());
}

// ---------- Les groupes de support ----------

/*
 * Qui traite quoi, décidé par l'entreprise et non par le code.
 *
 * Un groupe porte un nom, des gens, et les catégories dont il a la charge.
 * L'administration le compose. Les rôles continuent de donner ce qu'ils
 * donnaient — un groupe ajoute, il ne retire jamais : sans cette règle, créer
 * un premier groupe fermerait d'un coup la file aux RH et à l'administration,
 * et l'entreprise découvrirait le jour même que plus personne ne répond.
 */

/** Les groupes, avec leurs catégories et le nombre de membres. */
function groups() {
  const rows = db.prepare(`
    SELECT g.*,
           (SELECT COUNT(*) FROM support_group_members m WHERE m.group_id = g.id) AS member_count
    FROM support_groups g WHERE g.company_id = ? ORDER BY g.name COLLATE NOCASE
  `).all(ici());
  for (const groupe of rows) groupe.categories = groupCategories(groupe.id);
  return rows;
}

function groupById(id) {
  const groupe = db.prepare('SELECT * FROM support_groups WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici());
  if (!groupe) return null;
  groupe.categories = groupCategories(groupe.id);
  groupe.members = groupMembers(groupe.id);
  return groupe;
}

function groupCategories(groupId) {
  return db.prepare('SELECT category FROM support_group_categories WHERE group_id = ? ORDER BY category')
    .all(groupId).map((r) => r.category);
}

function groupMembers(groupId) {
  return db.prepare(`
    SELECT u.id, u.first_name, u.last_name, u.email, u.active
    FROM support_group_members m JOIN users u ON u.id = m.user_id
    WHERE m.group_id = ? ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE
  `).all(groupId);
}

function createGroup({ name, description = '' }) {
  const propre = String(name || '').trim();
  if (!propre) return null;
  return db.prepare('INSERT INTO support_groups (company_id, name, description) VALUES (?, ?, ?)')
    .run(ici(), propre, String(description || '').trim()).lastInsertRowid;
}

function renameGroup(id, { name, description = '' }) {
  const propre = String(name || '').trim();
  if (!propre) return false;
  // Le témoin vient de la lecture, pas du nombre de lignes modifiées : MySQL
  // compte les lignes CHANGÉES, si bien qu'un renommage à l'identique se
  // rendrait comme « groupe introuvable ».
  if (!db.prepare('SELECT id FROM support_groups WHERE id = ? AND company_id = ?').get(Number(id) || 0, ici())) {
    return false;
  }
  db.prepare('UPDATE support_groups SET name = ?, description = ? WHERE id = ? AND company_id = ?')
    .run(propre, String(description || '').trim(), Number(id), ici());
  return true;
}

/**
 * Supprimer un groupe.
 *
 * Les tickets qu'il avait en charge ne disparaissent pas avec lui : leur groupe
 * repasse à nul, et ils redeviennent visibles par les rôles. Un ticket orphelin
 * qu'on ne verrait plus nulle part serait le pire des deux mondes.
 */
function deleteGroup(id) {
  const numero = Number(id) || 0;
  if (!db.prepare('SELECT id FROM support_groups WHERE id = ? AND company_id = ?').get(numero, ici())) return false;
  const retirer = db.transaction(() => {
    db.prepare('UPDATE tickets SET group_id = NULL WHERE group_id = ? AND company_id = ?').run(numero, ici());
    db.prepare('DELETE FROM support_group_categories WHERE group_id = ?').run(numero);
    db.prepare('DELETE FROM support_group_members WHERE group_id = ?').run(numero);
    db.prepare('DELETE FROM support_groups WHERE id = ? AND company_id = ?').run(numero, ici());
  });
  retirer();
  return true;
}

/** Les catégories d'un groupe, remplacées d'un bloc : une case décochée retire. */
function setGroupCategories(id, categories) {
  const numero = Number(id) || 0;
  if (!db.prepare('SELECT id FROM support_groups WHERE id = ? AND company_id = ?').get(numero, ici())) return false;
  const voulues = [...new Set([].concat(categories || []).map(String))].filter((c) => CATEGORIES.includes(c));
  const poser = db.transaction(() => {
    db.prepare('DELETE FROM support_group_categories WHERE group_id = ?').run(numero);
    const ajouter = db.prepare('INSERT INTO support_group_categories (group_id, category) VALUES (?, ?)');
    for (const categorie of voulues) ajouter.run(numero, categorie);
  });
  poser();
  return true;
}

function addGroupMember(groupId, userId) {
  const groupe = Number(groupId) || 0;
  const personne = Number(userId) || 0;
  if (!db.prepare('SELECT id FROM support_groups WHERE id = ? AND company_id = ?').get(groupe, ici())) return false;
  if (!db.prepare('SELECT id FROM users WHERE id = ? AND active = 1').get(personne)) return false;
  // Deux fois la même personne n'est pas une erreur à signaler : le résultat
  // voulu est atteint, et c'est tout ce que l'appelant demande.
  db.prepare('INSERT OR IGNORE INTO support_group_members (group_id, user_id) VALUES (?, ?)').run(groupe, personne);
  return true;
}

function removeGroupMember(groupId, userId) {
  return db.prepare('DELETE FROM support_group_members WHERE group_id = ? AND user_id = ?')
    .run(Number(groupId) || 0, Number(userId) || 0).changes > 0;
}

/** Les groupes d'une personne. */
function groupsOf(userId) {
  const rows = db.prepare(`
    SELECT g.* FROM support_group_members m JOIN support_groups g ON g.id = m.group_id
    WHERE m.user_id = ? AND g.company_id = ? ORDER BY g.name COLLATE NOCASE
  `).all(Number(userId) || 0, ici());
  for (const groupe of rows) groupe.categories = groupCategories(groupe.id);
  return rows;
}

/** Les catégories que les groupes d'une personne lui ouvrent. */
function groupCategoriesOf(userId) {
  return db.prepare(`
    SELECT DISTINCT c.category
    FROM support_group_members m
    JOIN support_group_categories c ON c.group_id = m.group_id
    WHERE m.user_id = ?
  `).all(Number(userId) || 0).map((r) => r.category).filter((c) => CATEGORIES.includes(c));
}

/**
 * Le groupe à qui adresser un ticket d'une catégorie donnée.
 *
 * Le premier par ordre alphabétique quand plusieurs la couvrent. Choisir le
 * premier plutôt que rien laisse la demande dans une file au lieu de la laisser
 * dans l'entre-deux, et le transfert existe pour la déplacer.
 */
function groupForCategory(category) {
  const ligne = db.prepare(`
    SELECT g.id FROM support_group_categories c JOIN support_groups g ON g.id = c.group_id
    WHERE c.category = ? AND g.company_id = ? ORDER BY g.name COLLATE NOCASE LIMIT 1
  `).get(String(category || ''), ici());
  return ligne ? ligne.id : null;
}

function setTicketGroup(ticketId, groupId) {
  const numero = Number(ticketId) || 0;
  if (!db.prepare('SELECT id FROM tickets WHERE id = ? AND company_id = ?').get(numero, ici())) return false;
  const groupe = Number(groupId) || 0;
  if (groupe && !db.prepare('SELECT id FROM support_groups WHERE id = ? AND company_id = ?').get(groupe, ici())) {
    return false;
  }
  db.prepare('UPDATE tickets SET group_id = ? WHERE id = ? AND company_id = ?').run(groupe || null, numero, ici());
  return true;
}

/**
 * Qui a accès à quoi — la vue que cherche l'administration.
 *
 * Une catégorie, et pour elle : ce que les rôles ouvrent, et les groupes qui la
 * prennent en charge avec leurs membres. C'est la réponse à « si Claire part
 * demain, qui lit les demandes informatiques ? », posée une fois au lieu d'être
 * reconstituée de tête à partir de trois écrans.
 */
function accessMap() {
  return CATEGORIES.map((categorie) => {
    const roles = [];
    if (agentCategories({ role: 'admin' }).includes(categorie)) roles.push('admin');
    if (agentCategories({ is_hr: 1 }).includes(categorie)) roles.push('hr');
    if (agentCategories({ is_finance: 1 }).includes(categorie)) roles.push('finance');
    const groupes = db.prepare(`
      SELECT g.id, g.name FROM support_group_categories c JOIN support_groups g ON g.id = c.group_id
      WHERE c.category = ? ORDER BY g.name COLLATE NOCASE
    `).all(categorie);
    for (const groupe of groupes) groupe.members = groupMembers(groupe.id);
    const nommees = groupes.reduce((n, g) => n + g.members.filter((m) => m.active).length, 0);
    return { category: categorie, roles, groups: groupes, named: nommees, restricted: categorie === RESTRICTED_CATEGORY };
  });
}

// ---------- Base de connaissances ----------

const KB_VISIBILITIES = ['Entreprise', 'Service', 'Équipe', 'Administration'];

function articles({ category = '', query = '', visibleTo = null } = {}) {
  const clauses = ['a.company_id = ?', 'published = 1'];
  const params = [ici()];
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
  return db.prepare('SELECT * FROM kb_articles WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()) || null;
}

function createArticle({ title, category, body, visibility, scopeId, authorId }) {
  return db.prepare(`
    INSERT INTO kb_articles (company_id, title, category, body, visibility, scope_id, author_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), title, category || 'Général', body || '', visibility || 'Entreprise', scopeId || null, authorId || null).lastInsertRowid;
}

function updateArticle(id, { title, category, body, visibility, scopeId, published }) {
  db.prepare(`
    UPDATE kb_articles SET title = ?, category = ?, body = ?, visibility = ?, scope_id = ?, published = ?, updated_at = datetime('now')
    WHERE id = ? AND company_id = ?
  `).run(title, category, body, visibility, scopeId || null, published ? 1 : 0, id, ici());
}

function deleteArticle(id) {
  db.prepare('DELETE FROM kb_articles WHERE id = ? AND company_id = ?').run(id, ici());
}

function noteRead(id) {
  db.prepare('UPDATE kb_articles SET views = views + 1 WHERE id = ? AND company_id = ?').run(id, ici());
}

function categories() {
  return db.prepare(`
    SELECT DISTINCT category FROM kb_articles WHERE company_id = ?
    ORDER BY category COLLATE NOCASE
  `).all(ici()).map((r) => r.category);
}

module.exports = {
  CATEGORIES, RESTRICTED_CATEGORY, agentCategories, PRIORITIES, STATUSES, ORIGINS, OPEN_STATUSES, RESPONSE_HOURS, responseHours, KB_VISIBILITIES,
  reference, dueFor, create, byId, list, messages, reply, setStatus, assign, setPriority, isOverdue, summary, remove,
  articles, articleById, canRead, createArticle, updateArticle, deleteArticle, noteRead, categories,
  categoriesFor, groups, groupById, groupCategories, groupMembers, createGroup, renameGroup, deleteGroup,
  setGroupCategories, addGroupMember, removeGroupMember, groupsOf, groupCategoriesOf, groupForCategory,
  setTicketGroup, accessMap,
};
