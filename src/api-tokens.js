const crypto = require('crypto');

const db = require('./db');
const societes = require('./societes');
const cloison = require('./cloison');

/**
 * Le périmètre courant.
 *
 * Un jeton porte la société dans laquelle il travaille : il est créé depuis
 * l'écran d'une société, et c'est celle-là qu'il voit. L'écran qui les liste et
 * les révoque ne montre donc que les siens.
 *
 * Sa résolution est la seule exception, et elle est nommée : on reconnaît un
 * jeton par son empreinte, avant qu'aucun périmètre ne soit connu — c'est le
 * jeton lui-même qui dit lequel appliquer ensuite.
 */
const ici = () => societes.courant();

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
 *   — il **lit** par défaut. L'écriture est une portée à part, que
 *     l'administrateur coche exprès, jamais offerte avec la lecture : un jeton
 *     donné à un tableau de bord n'a aucune raison de pouvoir supprimer un
 *     salarié, et la case décochée est ce qui le garantit.
 *
 * Une portée d'écriture s'écrit « domaine:ecriture » et **emporte la lecture du
 * même domaine** : on n'a jamais eu besoin d'écrire sans pouvoir se relire, et
 * exiger les deux cases n'aurait produit que des jetons à moitié cochés qui
 * répondent 403 sans qu'on comprenne pourquoi.
 *
 * Le hachage est un SHA-256 simple, non un bcrypt : un jeton de 256 bits tiré
 * au hasard n'a pas de dictionnaire à lui opposer, et l'API doit répondre vite.
 */

const SCOPES = [
  { key: 'annuaire', label: 'Annuaire et organisation', hint: 'Collaborateurs visibles, services, équipes.' },
  { key: 'rh', label: 'Ressources humaines', hint: 'Absences approuvées, effectifs, contrats.' },
  { key: 'gestion', label: 'Gestion et facturation', hint: 'Tiers, factures, abonnements, achats, trésorerie.' },
  { key: 'projets', label: 'Projets', hint: 'Projets, tâches, jalons, temps passé.' },
  { key: 'pilotage', label: 'Indicateurs de direction', hint: 'Chiffres consolidés du tableau de bord.' },
  { key: 'documents', label: 'Documents et bases', hint: 'Annonces, base de connaissances, salles, matériel.' },
  { key: 'exploitation', label: 'Exploitation de l\'instance', hint: 'Version, licence, modules, journaux, sauvegardes.' },
];

/**
 * Les portées d'écriture, une par domaine, et le domaine qu'elles ouvrent.
 *
 * Elles ne figurent pas dans la liste ci-dessus : l'écran les montre dans un
 * bloc séparé, décochées, sous un avertissement. Une case qu'on coche par
 * inadvertance parce qu'elle est voisine d'une autre n'est pas un choix.
 */
const WRITE_SUFFIX = ':ecriture';

const WRITE_SCOPES = [
  { key: `annuaire${WRITE_SUFFIX}`, reads: 'annuaire', label: 'Annuaire — écriture', hint: 'Créer un service ou une équipe, y déplacer quelqu\'un.' },
  { key: `rh${WRITE_SUFFIX}`, reads: 'rh', label: 'Ressources humaines — écriture', hint: 'Déposer une demande d\'absence pour un salarié.' },
  { key: `gestion${WRITE_SUFFIX}`, reads: 'gestion', label: 'Gestion — écriture', hint: 'Créer un tiers, émettre une facture, enregistrer un règlement.' },
  { key: `projets${WRITE_SUFFIX}`, reads: 'projets', label: 'Projets — écriture', hint: 'Créer un projet, une tâche, pointer du temps.' },
  { key: `documents${WRITE_SUFFIX}`, reads: 'documents', label: 'Documents — écriture', hint: 'Publier une annonce, réserver une salle, affecter du matériel.' },
];

const SCOPE_KEYS = [...SCOPES.map((s) => s.key), ...WRITE_SCOPES.map((s) => s.key)];

/** Le domaine qu'une portée d'écriture donne aussi à lire. */
const READ_OF = new Map(WRITE_SCOPES.map((s) => [s.key, s.reads]));

/** Vrai pour une portée qui autorise à modifier la base. */
const isWrite = (scope) => READ_OF.has(scope);
const PREFIX = 'sm_';
const DEFAULT_DAYS = 365;

const hash = (token) => crypto.createHash('sha256').update(String(token)).digest('hex');

function list() {
  return db.prepare(`
    SELECT t.*, u.first_name, u.last_name FROM api_tokens t
    LEFT JOIN users u ON u.id = t.created_by
    WHERE t.company_id = ?
    ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC
  `).all(ici()).map((row) => ({
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
    INSERT INTO api_tokens (company_id, label, prefix, token_hash, scopes, created_by, expires_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), name, token.slice(0, 11), hash(token), kept.join(','), createdBy, expires).lastInsertRowid;

  return { ok: true, id, token, expiresAt: expires, scopes: kept };
}

function revoke(id) {
  return db.prepare(`
    UPDATE api_tokens SET revoked_at = datetime('now')
    WHERE id = ? AND company_id = ? AND revoked_at IS NULL
  `).run(id, ici()).changes > 0;
}

function remove(id) {
  return db.prepare('DELETE FROM api_tokens WHERE id = ? AND company_id = ?')
    .run(id, ici()).changes > 0;
}

/**
 * Résout un jeton présenté. Rend la ligne, ou null : le motif n'est pas dit à
 * l'appelant — un jeton révoqué et un jeton inexistant se ressemblent, et c'est
 * bien ainsi.
 */
function resolve(token) {
  const value = String(token || '');
  if (!value.startsWith(PREFIX)) return null;

  /*
   * Hors périmètre, et c'est la seule exception : la requête n'a pas encore de
   * société, puisque c'est le jeton qui va la donner. Ce qui autorise ici, c'est
   * l'empreinte — et elle est unique dans toute l'instance.
   */
  const row = cloison.sansPerimetre(() => db
    .prepare('SELECT * FROM api_tokens WHERE token_hash = ?').get(hash(value)));
  if (!row || row.revoked_at) return null;
  if (row.expires_at && row.expires_at < new Date().toISOString().slice(0, 10)) return null;

  return { ...row, scopeList: row.scopes ? row.scopes.split(',') : [] };
}

/**
 * Compte un appel. Appelée juste après la résolution, donc avant que le
 * périmètre du jeton soit posé : la société vient de la ligne résolue, pas du
 * contexte.
 */
function touch(id, ip = '', companyId = null) {
  db.prepare(`
    UPDATE api_tokens SET last_used_at = datetime('now'), last_ip = ?, calls = calls + 1
    WHERE id = ? AND company_id = ?
  `).run(String(ip).slice(0, 60), id, companyId);
}

/**
 * Ce jeton a-t-il cette portée ?
 *
 * « gestion:ecriture » répond oui à « gestion » : écrire sans pouvoir se
 * relire n'a jamais servi personne, et le contraire est faux — « gestion »
 * ne répond jamais oui à « gestion:ecriture ».
 */
function allows(token, scope) {
  if (!token) return false;
  if (token.scopeList.includes(scope)) return true;
  return token.scopeList.some((held) => READ_OF.get(held) === scope);
}

/** Ce jeton peut-il modifier quoi que ce soit ? Sert au journal et à l'écran. */
const canWrite = (token) => Boolean(token) && token.scopeList.some(isWrite);

module.exports = {
  SCOPES, WRITE_SCOPES, SCOPE_KEYS, WRITE_SUFFIX, PREFIX, DEFAULT_DAYS,
  hash, list, create, revoke, remove, resolve, touch, allows, isWrite, canWrite,
};
