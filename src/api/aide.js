const db = require('../db');

/**
 * Les gestes que chaque fonction de l'API refait : pagination, enveloppe de
 * réponse, lecture du corps, refus.
 *
 * Ils tiennent ici pour une raison simple — une pagination qui se recopie
 * d'une route à l'autre finit par différer d'une route à l'autre, et c'est
 * l'appelant qui découvre la différence.
 */

const MAX_PAGE = 200;

/** `?limite=` et `?depuis=`, bornés. Au-delà de 200, on rend 200 sans le dire. */
function page(req) {
  const limit = Math.min(MAX_PAGE, Math.max(1, Number(req.query.limite) || 50));
  const offset = Math.max(0, Number(req.query.depuis) || 0);
  return { limit, offset };
}

/** L'enveloppe de toute liste. Le total dit s'il reste des pages à demander. */
const wrap = (rows, { limit, offset }, total = null) => ({
  count: rows.length,
  limit,
  offset,
  total: total === null ? undefined : total,
  data: rows,
});

/** Une seule forme d'erreur, pour que l'appelant n'ait qu'un cas à traiter. */
const erreur = (res, code, message, extra = {}) => res.status(code).json({ error: message, ...extra });

/** 404 nommé : « facture 12 » se cherche mieux que « ressource inconnue ». */
const absent = (res, quoi) => erreur(res, 404, `${quoi} introuvable.`);

/**
 * Un champ du corps, en texte. Une chaîne vide et un champ absent sont la même
 * chose : l'appelant qui envoie `""` voulait dire « rien ».
 */
function texte(corps, nom, max = 200) {
  const valeur = corps == null ? '' : corps[nom];
  if (valeur === undefined || valeur === null) return '';
  return String(valeur).trim().slice(0, max);
}

/** Un entier du corps ou du chemin, ou null si ce n'en est pas un. */
function entier(valeur) {
  const n = Number(valeur);
  return Number.isInteger(n) ? n : null;
}

/** Une date ISO, ou '' : une date approximative vaut moins que pas de date. */
const date = (valeur) => (/^\d{4}-\d{2}-\d{2}$/.test(String(valeur || '')) ? String(valeur) : '');

/** Le nombre de lignes d'une table, pour le `total` des listes paginées. */
const compter = (sql, ...params) => db.prepare(sql).get(...params).n;

module.exports = { MAX_PAGE, page, wrap, erreur, absent, texte, entier, date, compter };
