const session = require('express-session');
const db = require('./db');

const PRUNE_INTERVAL_MS = 10 * 60 * 1000;

/**
 * Magasin de sessions adossé à SQLite.
 *
 * Le magasin par défaut d'express-session vit en mémoire : toutes les sessions
 * disparaissent au redémarrage, la mémoire ne se libère jamais, et un second
 * processus ne voit pas les sessions du premier. Pour une instance vendue à une
 * entreprise, aucun des trois n'est acceptable.
 *
 * Le compte est enregistré à part du blob : c'est ce qui permet de révoquer d'un
 * seul geste toutes les sessions d'une personne — changement de mot de passe,
 * départ, ou compromission soupçonnée.
 */
class SqliteSessionStore extends session.Store {
  constructor({ prune = true } = {}) {
    super();
    this.stmt = {
      get: db.prepare('SELECT data FROM sessions WHERE sid = ? AND expires_at > ?'),
      set: db.prepare(`
        INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)
        ON CONFLICT(sid) DO UPDATE SET user_id = excluded.user_id, data = excluded.data, expires_at = excluded.expires_at
      `),
      touch: db.prepare('UPDATE sessions SET expires_at = ? WHERE sid = ?'),
      destroy: db.prepare('DELETE FROM sessions WHERE sid = ?'),
      all: db.prepare('SELECT data FROM sessions WHERE expires_at > ?'),
      count: db.prepare('SELECT COUNT(*) AS n FROM sessions WHERE expires_at > ?'),
      clear: db.prepare('DELETE FROM sessions'),
      prune: db.prepare('DELETE FROM sessions WHERE expires_at <= ?'),
      forUser: db.prepare('DELETE FROM sessions WHERE user_id = ?'),
    };

    if (prune) {
      this.pruneTimer = setInterval(() => this.prune(), PRUNE_INTERVAL_MS);
      // Le balayage ne doit pas retenir le processus au moment de s'arrêter.
      this.pruneTimer.unref();
    }
  }

  #expiry(sess) {
    const ttl = (sess && sess.cookie && sess.cookie.originalMaxAge) || 60 * 60 * 1000;
    return Date.now() + ttl;
  }

  get(sid, callback) {
    try {
      const row = this.stmt.get.get(sid, Date.now());
      callback(null, row ? JSON.parse(row.data) : null);
    } catch (err) {
      callback(err);
    }
  }

  set(sid, sess, callback) {
    try {
      const userId = sess && sess.user ? sess.user.id : null;
      this.stmt.set.run(sid, userId, JSON.stringify(sess), this.#expiry(sess));
      callback(null);
    } catch (err) {
      callback(err);
    }
  }

  touch(sid, sess, callback) {
    try {
      this.stmt.touch.run(this.#expiry(sess), sid);
      callback(null);
    } catch (err) {
      callback(err);
    }
  }

  destroy(sid, callback) {
    try {
      this.stmt.destroy.run(sid);
      callback(null);
    } catch (err) {
      callback(err);
    }
  }

  all(callback) {
    try {
      callback(null, this.stmt.all.all(Date.now()).map((row) => JSON.parse(row.data)));
    } catch (err) {
      callback(err);
    }
  }

  length(callback) {
    try {
      callback(null, this.stmt.count.get(Date.now()).n);
    } catch (err) {
      callback(err);
    }
  }

  clear(callback) {
    try {
      this.stmt.clear.run();
      callback(null);
    } catch (err) {
      callback(err);
    }
  }

  prune() {
    this.stmt.prune.run(Date.now());
  }

  /** Ferme toutes les sessions ouvertes d'un compte, y compris sur d'autres appareils. */
  revokeUser(userId) {
    return this.stmt.forUser.run(userId).changes;
  }
}

let shared = null;
function store() {
  if (!shared) shared = new SqliteSessionStore();
  return shared;
}

module.exports = { SqliteSessionStore, store };
