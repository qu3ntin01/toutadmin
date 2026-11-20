/**
 * L'heure, en temps universel.
 *
 * Les dates du produit sont écrites en UTC — c'est ce que rend
 * « datetime('now') » de SQLite, et ce que rend UTC_TIMESTAMP() de MySQL. Les
 * calculer ici plutôt que dans la requête a deux avantages : le SQL reste
 * portable, et le décalage demandé se lit en clair au lieu d'être une chaîne
 * liée en paramètre — que MySQL ne saurait de toute façon pas interpréter, une
 * unité de temps n'étant pas une valeur.
 *
 * Jumeau de app/Core/Horloge.php dans l'édition PHP.
 */

const UNITES = {
  second: 1000, seconds: 1000,
  minute: 60000, minutes: 60000,
  hour: 3600000, hours: 3600000,
  day: 86400000, days: 86400000,
};

/** « -30 days », « +2 hours », « -6 months », « +1 year » → millisecondes. */
function quand(decalage) {
  const maintenant = Date.now();
  if (!decalage) return new Date(maintenant);

  const m = String(decalage).trim().match(/^([+-]?)\s*(\d+)\s+(\w+)$/);
  if (!m) throw new Error('Décalage de temps incompréhensible : ' + decalage);

  const signe = m[1] === '-' ? -1 : 1;
  const nombre = Number(m[2]);
  const unite = m[3].toLowerCase();

  if (UNITES[unite]) return new Date(maintenant + signe * nombre * UNITES[unite]);

  // Mois et années n'ont pas de durée fixe : on les compte au calendrier, comme
  // le fait SQLite, plutôt que de les approcher en jours.
  const d = new Date(maintenant);
  if (unite === 'month' || unite === 'months') {
    d.setUTCMonth(d.getUTCMonth() + signe * nombre);
    return d;
  }
  if (unite === 'year' || unite === 'years') {
    d.setUTCFullYear(d.getUTCFullYear() + signe * nombre);
    return d;
  }
  throw new Error('Unité de temps inconnue : ' + unite);
}

/** Un instant, « 2026-09-14 08:30:00 », éventuellement décalé. */
function instant(decalage = '') {
  return quand(decalage).toISOString().slice(0, 19).replace('T', ' ');
}

/** Un jour, « 2026-09-14 », éventuellement décalé. */
function jour(decalage = '') {
  return quand(decalage).toISOString().slice(0, 10);
}

/** Dans n jours — ou il y a n jours, si le nombre est négatif. */
function dansJours(jours) {
  return jour(`${jours >= 0 ? '+' : ''}${jours} days`);
}

/** Dans n minutes, à la seconde près. */
function dansMinutes(minutes) {
  return instant(`${minutes >= 0 ? '+' : ''}${minutes} minutes`);
}

module.exports = { instant, jour, dansJours, dansMinutes };
