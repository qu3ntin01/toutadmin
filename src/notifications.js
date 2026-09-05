const db = require('./db');

/**
 * Notifications personnelles.
 *
 * Une même échéance ne doit alerter qu'une fois : chaque notification porte une
 * clé de déduplication (par exemple « habilitation:12:2027-04-10 »), et l'index
 * unique en base fait le reste. Le balayage peut donc tourner toutes les heures
 * sans jamais empiler de doublons.
 */

const insert = db.prepare(`
  INSERT INTO notifications (user_id, kind, title, body, link, dedupe_key)
  VALUES (?, ?, ?, ?, ?, ?)
  -- L'index unique est partiel (il ignore les clés vides) : la cible du conflit
  -- doit reprendre sa condition, sinon SQLite ne la reconnaît pas.
  ON CONFLICT(user_id, dedupe_key) WHERE dedupe_key != '' DO NOTHING
`);

function push({ userId, kind = 'echeance', title, body = '', link = '', dedupeKey = '' }) {
  if (!userId || !title) return false;
  return insert.run(userId, kind, title.slice(0, 200), body.slice(0, 500), link.slice(0, 200), dedupeKey.slice(0, 160)).changes > 0;
}

function forUser(userId, { limit = 50, unreadOnly = false } = {}) {
  const clause = unreadOnly ? 'AND read_at IS NULL' : '';
  return db.prepare(`
    SELECT * FROM notifications WHERE user_id = ? ${clause}
    ORDER BY read_at IS NOT NULL, id DESC LIMIT ?
  `).all(userId, limit);
}

function unreadCount(userId) {
  return db.prepare('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ? AND read_at IS NULL').get(userId).n;
}

function markRead(id, userId) {
  return db.prepare("UPDATE notifications SET read_at = datetime('now') WHERE id = ? AND user_id = ? AND read_at IS NULL")
    .run(id, userId).changes > 0;
}

function markAllRead(userId) {
  return db.prepare("UPDATE notifications SET read_at = datetime('now') WHERE user_id = ? AND read_at IS NULL").run(userId).changes;
}

function remove(id, userId) {
  return db.prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?').run(id, userId).changes > 0;
}

/** Efface les notifications lues d'un certain âge : la boîte ne gonfle pas sans fin. */
function purgeRead(days = 60) {
  return db.prepare("DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < datetime('now', ?)")
    .run(`-${Number(days) || 60} days`).changes;
}

module.exports = { push, forUser, unreadCount, markRead, markAllRead, remove, purgeRead };
