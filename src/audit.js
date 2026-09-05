const db = require('./db');

/**
 * Journal d'audit.
 *
 * Un CMS qui administre une entreprise doit pouvoir répondre à « qui a supprimé
 * ce membre ? », « qui a consulté ce CV ? », « qui a activé ce module ? ». Le
 * journal s'écrit en base, ne s'édite pas depuis l'interface, et survit à la
 * suppression de l'objet visé : c'est la trace de la suppression qui compte.
 */

const insert = db.prepare(`
  INSERT INTO audit_log (actor_id, actor_label, action, entity, entity_id, detail, ip)
  VALUES (?, ?, ?, ?, ?, ?, ?)
`);

/** L'IP réelle derrière un proxy, quand TRUST_PROXY est configuré. */
function ipOf(req) {
  return String((req && req.ip) || '').slice(0, 64);
}

function labelOf(user) {
  if (!user) return 'système';
  const name = [user.firstName || user.first_name, user.lastName || user.last_name].filter(Boolean).join(' ');
  return (name ? `${name} <${user.email}>` : user.email || `#${user.id}`).slice(0, 200);
}

/**
 * Consigne une action. Le journal ne doit jamais empêcher l'action elle-même :
 * une écriture qui échoue est signalée en console, pas propagée.
 */
function log(req, action, entity = '', entityId = null, detail = null) {
  try {
    const user = req && req.session ? req.session.user : null;
    insert.run(
      user ? user.id : null,
      labelOf(user),
      String(action).slice(0, 120),
      String(entity).slice(0, 60),
      entityId == null ? null : Number(entityId),
      detail == null ? '' : JSON.stringify(detail).slice(0, 2000),
      ipOf(req)
    );
  } catch (err) {
    console.error("Journal d'audit indisponible :", err.message);
  }
}

/** Variante hors requête (tâches planifiées, démarrage). */
function logSystem(action, entity = '', entityId = null, detail = null) {
  log(null, action, entity, entityId, detail);
}

const ENTRIES_PER_PAGE = 50;

function list({ page = 1, action = '', actorId = null, entity = '', from = '', to = '' } = {}) {
  const clauses = [];
  const params = [];
  if (action) { clauses.push('action LIKE ?'); params.push(`${action}%`); }
  if (actorId) { clauses.push('actor_id = ?'); params.push(actorId); }
  if (entity) { clauses.push('entity = ?'); params.push(entity); }
  if (from) { clauses.push('occurred_at >= ?'); params.push(from); }
  if (to) { clauses.push('occurred_at <= ?'); params.push(`${to} 23:59:59`); }

  const where = clauses.length ? `WHERE ${clauses.join(' AND ')}` : '';
  const total = db.prepare(`SELECT COUNT(*) AS n FROM audit_log ${where}`).get(...params).n;
  const pages = Math.max(1, Math.ceil(total / ENTRIES_PER_PAGE));
  const current = Math.min(Math.max(1, Number(page) || 1), pages);

  const rows = db.prepare(`
    SELECT * FROM audit_log ${where} ORDER BY id DESC LIMIT ? OFFSET ?
  `).all(...params, ENTRIES_PER_PAGE, (current - 1) * ENTRIES_PER_PAGE);

  return { rows, total, page: current, pages };
}

/** Les actions déjà rencontrées, pour alimenter le filtre sans les coder en dur. */
function knownActions() {
  return db.prepare('SELECT DISTINCT action FROM audit_log ORDER BY action').all().map((r) => r.action);
}

function toCsv(rows) {
  const escape = (value) => `"${String(value == null ? '' : value).replace(/"/g, '""')}"`;
  const head = ['Date', 'Auteur', 'Action', 'Objet', 'Identifiant', 'Détail', 'IP'];
  const lines = rows.map((r) => [r.occurred_at, r.actor_label, r.action, r.entity, r.entity_id, r.detail, r.ip].map(escape).join(';'));
  return [head.map(escape).join(';'), ...lines].join('\n');
}

/** Purge des entrées trop anciennes : le journal ne se conserve pas indéfiniment. */
function purgeOlderThan(days) {
  const cutoff = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');
  return db.prepare('DELETE FROM audit_log WHERE occurred_at < ?').run(cutoff).changes;
}

module.exports = { log, logSystem, list, knownActions, toCsv, purgeOlderThan, ENTRIES_PER_PAGE };
