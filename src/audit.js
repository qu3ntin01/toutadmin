const crypto = require('crypto');

const db = require('./db');
const logs = require('./logs');

/**
 * Journal d'audit.
 *
 * Un CMS qui administre une entreprise doit pouvoir répondre à « qui a supprimé
 * ce membre ? », « qui a consulté ce CV ? », « qui a activé ce module ? ». Le
 * journal s'écrit en base, ne s'édite pas depuis l'interface, et survit à la
 * suppression de l'objet visé : c'est la trace de la suppression qui compte.
 */

const insert = db.prepare(`
  INSERT INTO audit_log (occurred_at, actor_id, actor_label, action, entity, entity_id, detail, ip, prev_hash, hash)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
`);

const lastHash = db.prepare('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1');

/**
 * Scellement du journal.
 *
 * Un journal d'audit que l'on peut réécrire ne prouve rien. Chaque entrée porte
 * donc l'empreinte de la précédente : modifier une ligne, ou en retirer une du
 * milieu, casse la chaîne à cet endroit précis, et la vérification le dit.
 *
 * Ce que cela ne fait pas : empêcher la réécriture. Qui tient le fichier de la
 * base peut tout recalculer. Le scellement rend l'altération *visible*, ce qui
 * suffit à ce qu'on attend d'un journal — et c'est aussi loin qu'on puisse
 * aller sans autorité d'horodatage extérieure.
 */
function fingerprint(row, previous) {
  return crypto
    .createHash('sha256')
    .update([
      previous,
      row.occurred_at,
      row.actor_id == null ? '' : row.actor_id,
      row.actor_label,
      row.action,
      row.entity,
      row.entity_id == null ? '' : row.entity_id,
      row.detail,
      row.ip,
    ].join('\u0000'))
    .digest('hex');
}

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
 * Le canal du centre de journaux qui correspond à une action.
 *
 * Les deux journaux ne se remplacent pas : celui-ci est scellé et fait preuve,
 * l'autre se cherche et s'exporte. Recopier l'acte dans le second évite d'avoir
 * à ouvrir les deux pour reconstituer une soirée.
 */
const CHANNEL_BY_PREFIX = [
  [/^(connexion|deconnexion|session|mot_de_passe|double_authentification|coffre)/, 'authentification'],
  [/^(droit|securite|jeton|api)/, 'securite'],
  [/^(rgpd|donnees|import|export|sauvegarde|restauration|purge|journal)/, 'donnees'],
  [/^(webhook|messagerie|integration|plateforme|relance)/, 'integration'],
];

function channelFor(action) {
  const found = CHANNEL_BY_PREFIX.find(([pattern]) => pattern.test(action));
  return found ? found[1] : 'administration';
}

/**
 * Un échec se lit comme un avertissement, un acte ordinaire comme une
 * information — et la trace automatique de toute requête qui modifie quelque
 * chose (dont l'action est un chemin, « /admin/employes/:id/supprimer ») se lit
 * comme du débogage : elle double un acte déjà nommé, et noierait le journal si
 * elle s'affichait au même niveau que lui.
 */
function levelFor(action) {
  // La trace automatique des requêtes porte le chemin pour nom : elle reste au
  // débogage, sinon elle noierait les actes nommés sous son propre volume.
  if (action.startsWith('/')) return 7;
  if (/(echec|refus|refusee|invalide|verrouill|interdit)/.test(action)) return 4;
  if (/(supprim|efface|desactiv|revoqu|purge)/.test(action)) return 5;
  return 6;
}

/**
 * Consigne une action. Le journal ne doit jamais empêcher l'action elle-même :
 * une écriture qui échoue est signalée en console, pas propagée.
 */
/**
 * Pendant un emprunt d'identité, qui répond de l'acte ?
 *
 * L'administrateur, et non la personne dont il voit l'écran : c'est lui qui a
 * cliqué. L'auteur consigné est donc son identifiant, et le libellé nomme les
 * deux — « Claire Moreau <…> (au nom de Ali Nadir <…>) ». Consigner la personne
 * empruntée reviendrait à lui imputer un acte qu'elle n'a pas fait, et chercher
 * « ce qu'a fait l'administrateur » ne le trouverait pas.
 *
 * Le détail garde l'identifiant de la personne empruntée, pour que l'autre
 * question — « que s'est-il passé sur son compte ? » — ait aussi sa réponse.
 */
function auteurDe(req) {
  const user = req && req.session ? req.session.user : null;
  const emprunt = req && req.session ? req.session.emprunt : null;
  if (!emprunt || !user) return { id: user ? user.id : null, label: labelOf(user), pour: null };
  return {
    id: Number(emprunt.parId) || null,
    label: `${emprunt.parLabel} (au nom de ${labelOf(user)})`.slice(0, 200),
    pour: user.id,
  };
}

function log(req, action, entity = '', entityId = null, detail = null) {
  try {
    const user = req && req.session ? req.session.user : null;
    const auteur = auteurDe(req);
    /* Un détail qui n'est pas un objet simple n'est pas fusionné : l'appelant
       reste maître de ce qu'il a écrit, et le libellé dit déjà l'emprunt. */
    const detailFinal = auteur.pour !== null && (detail === null || (typeof detail === 'object' && !Array.isArray(detail)))
      ? { ...(detail || {}), emprunt_pour: auteur.pour }
      : detail;
    // L'horodatage entre dans l'empreinte : il est donc calculé ici, pas laissé
    // à la valeur par défaut de la colonne, qui ne serait pas connue à temps.
    const row = {
      occurred_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
      actor_id: auteur.id,
      actor_label: auteur.label,
      action: String(action).slice(0, 120),
      entity: String(entity).slice(0, 60),
      entity_id: entityId == null ? null : Number(entityId),
      detail: detailFinal == null ? '' : JSON.stringify(detailFinal).slice(0, 2000),
      ip: ipOf(req),
    };
    const previous = (lastHash.get() || {}).hash || '';
    insert.run(
      row.occurred_at, row.actor_id, row.actor_label, row.action, row.entity,
      row.entity_id, row.detail, row.ip, previous, fingerprint(row, previous)
    );

    // Écho dans le centre de journaux : même acte, autre usage. L'écriture y
    // est indépendante — si elle échoue, la preuve reste scellée ici.
    logs.record({
      level: levelFor(row.action),
      channel: channelFor(row.action),
      event: row.action,
      message: `${row.actor_label} — ${row.action}${row.entity ? ` (${row.entity}${row.entity_id ? ` #${row.entity_id}` : ''})` : ''}`,
      context: detailFinal,
      actor: user,
      // L'emprunt d'identité : les deux journaux doivent nommer le même auteur.
      actorId: auteur.id,
      actorLabel: auteur.pour !== null ? auteur.label : '',
      ip: row.ip,
    });
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

/**
 * Purge des entrées trop anciennes : le journal ne se conserve pas indéfiniment.
 *
 * Une purge retire le début de la chaîne, ce qui est légitime — mais ne doit pas
 * pouvoir se confondre avec un effacement discret. Elle laisse donc sa propre
 * entrée, scellée comme les autres, disant combien de lignes sont parties et
 * jusqu'à quand.
 */
function purgeOlderThan(days) {
  const cutoff = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString().slice(0, 19).replace('T', ' ');
  const removed = db.prepare('DELETE FROM audit_log WHERE occurred_at < ?').run(cutoff).changes;
  if (removed) logSystem('journal.purge', 'audit_log', null, { supprimees: removed, avant: cutoff });
  return removed;
}

/**
 * Vérifie le scellement, et dit où il casse plutôt que de rendre un simple non.
 *
 * La chaîne est lue depuis l'entrée la plus ancienne encore présente : son
 * empreinte précédente désigne une ligne purgée, et n'est donc pas contrôlée.
 * Autrement dit, la vérification garantit que rien n'a été altéré ni retiré
 * *entre* la plus ancienne entrée conservée et la plus récente.
 */
function verifySeal({ limit = 100000 } = {}) {
  const rows = db.prepare('SELECT * FROM audit_log ORDER BY id LIMIT ?').all(limit);
  if (!rows.length) return { ok: true, checked: 0, sealed: 0, unsealed: 0, broken: null };

  let previous = null;
  let sealed = 0;
  let unsealed = 0;

  for (const row of rows) {
    // Les entrées écrites avant la mise en place du scellement n'ont pas
    // d'empreinte : elles sont comptées à part, pas déclarées fausses.
    if (!row.hash) {
      unsealed += 1;
      previous = null;
      continue;
    }

    if (previous && row.prev_hash !== previous.hash) {
      return { ok: false, checked: rows.length, sealed, unsealed, broken: { row, reason: 'chaine', previous } };
    }
    if (fingerprint(row, row.prev_hash) !== row.hash) {
      return { ok: false, checked: rows.length, sealed, unsealed, broken: { row, reason: 'contenu', previous } };
    }
    sealed += 1;
    previous = row;
  }
  return { ok: true, checked: rows.length, sealed, unsealed, broken: null };
}

module.exports = { log, logSystem, list, knownActions, toCsv, purgeOlderThan, verifySeal, channelFor, levelFor, ENTRIES_PER_PAGE };
