const crypto = require('crypto');

const db = require('./db');

/**
 * Jetons d'accès à l'API.
 *
 * Un jeton ouvre une porte sans mot de passe ni double authentification : il
 * est donc traité comme un secret de première catégorie.
 *
 *   — il n'est **jamais conservé en clair**. Seule son empreinte SHA-256 vit
 *     en base, avec le préfixe qui permet de le reconnaître dans une liste.
 *     Perdu, il se révoque et se recrée ; il ne se relit pas ;
 *   — sa **portée est explicite** : un jeton donné à un outil de paie n'a rien
 *     à faire dans les projets ;
 *   — il **expire**, et se révoque d'un clic ;
 *   — l'API qu'il ouvre est en **lecture seule**. Un jeton volé permet de lire,
 *     jamais d'écrire, de supprimer ou de payer.
 *
 * Le hachage est un SHA-256 simple, non un bcrypt : un jeton de 256 bits tiré
 * au hasard n'a pas de dictionnaire à lui opposer, et l'API doit répondre vite.
 */

const SCOPES = [
  { key: 'annuaire', label: 'Annuaire et organisation', hint: 'Collaborateurs visibles, services, équipes.' },
  { key: 'rh', label: 'Ressources humaines', hint: 'Absences approuvées, effectifs, contrats.' },
  { key: 'gestion', label: 'Gestion et facturation', hint: 'Tiers, factures, abonnements.' },
  { key: 'projets', label: 'Projets', hint: 'Projets, tâches, temps passé.' },
  { key: 'pilotage', label: 'Indicateurs de direction', hint: 'Chiffres consolidés du tableau de bord.' },
];

const SCOPE_KEYS = SCOPES.map((s) => s.key);
const PREFIX = 'sm_';
const DEFAULT_DAYS = 365;

const hash = (token) => crypto.createHash('sha256').update(String(token)).digest('hex');

function list() {
  return db.prepare(`
    SELECT t.*, u.first_name, u.last_name FROM api_tokens t
    LEFT JOIN users u ON u.id = t.created_by
    ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC
  `).all().map((row) => ({
    ...row,
    scopeList: row.scopes ? row.scopes.split(',') : [],
    expired: Boolean(row.expires_at && row.expires_at < new Date().toISOString().slice(0, 10)),
    revoked: Boolean(row.revoked_at),
  }));
}

/**
 * Crée un jeton. La valeur en clair n'est rendue qu'ici, une seule fois :
 * c'est le seul moment où elle existe hors de la mémoire de l'appelant.
 */
function create({ label, scopes = [], days = DEFAULT_DAYS, createdBy = null }) {
  const name = String(label || '').trim().slice(0, 120);
  if (!name) return { ok: false, message: 'Un intitulé est requis.' };

  const kept = [...new Set(scopes.filter((scope) => SCOPE_KEYS.includes(scope)))];
  if (!kept.length) return { ok: false, message: 'Choisissez au moins une portée.' };

  const lifetime = Number(days);
  if (!Number.isInteger(lifetime) || lifetime < 1 || lifetime > 3650) {
    return { ok: false, message: 'La durée de vie tient entre 1 et 3650 jours.' };
  }

  const secret = crypto.randomBytes(32).toString('base64url');
  const token = `${PREFIX}${secret}`;
  const expires = new Date(Date.now() + lifetime * 86400000).toISOString().slice(0, 10);

  const id = db.prepare(`
    INSERT INTO api_tokens (label, prefix, token_hash, scopes, created_by, expires_at)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(name, token.slice(0, 11), hash(token), kept.join(','), createdBy, expires).lastInsertRowid;

  return { ok: true, id, token, expiresAt: expires, scopes: kept };
}

function revoke(id) {
  return db.prepare("UPDATE api_tokens SET revoked_at = datetime('now') WHERE id = ? AND revoked_at IS NULL").run(id).changes > 0;
}

function remove(id) {
  db.prepare('DELETE FROM api_tokens WHERE id = ?').run(id);
}

/**
 * Résout un jeton présenté. Rend la ligne, ou null : le motif n'est pas dit à
 * l'appelant — un jeton révoqué et un jeton inexistant se ressemblent, et c'est
 * bien ainsi.
 */
function resolve(token) {
  const value = String(token || '');
  if (!value.startsWith(PREFIX)) return null;

  const row = db.prepare('SELECT * FROM api_tokens WHERE token_hash = ?').get(hash(value));
  if (!row || row.revoked_at) return null;
  if (row.expires_at && row.expires_at < new Date().toISOString().slice(0, 10)) return null;

  return { ...row, scopeList: row.scopes ? row.scopes.split(',') : [] };
}

function touch(id, ip = '') {
  db.prepare("UPDATE api_tokens SET last_used_at = datetime('now'), last_ip = ?, calls = calls + 1 WHERE id = ?")
    .run(String(ip).slice(0, 60), id);
}

const allows = (token, scope) => Boolean(token) && token.scopeList.includes(scope);

module.exports = { SCOPES, SCOPE_KEYS, PREFIX, DEFAULT_DAYS, hash, list, create, revoke, remove, resolve, touch, allows };
