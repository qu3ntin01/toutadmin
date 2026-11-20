const licence = require('../licence');

/**
 * Ce que la licence autorise, requête par requête.
 *
 * Un seul intergiciel, posé après l'authentification et avant les routes. Il ne
 * refuse que des écritures, et seulement au dernier palier : tant que
 * l'instance est en grâce ou en sursis, tout fonctionne et seul le bandeau
 * prévient.
 *
 * Deux paliers d'avertissement, qui ne mesurent pas la même chose : « grace »
 * court depuis l'échéance du jeton — un abonnement qui se termine, cela se
 * prévoit ; « sursis » court depuis la première vérification en échec — une
 * licence révoquée, un panneau injoignable, cela tombe sans prévenir. Le second
 * dure quarante-huit heures : assez pour qu'un humain soit prévenu, rappelle et
 * règle ; trop peu pour qu'une instance révoquée continue des mois.
 *
 * ── Ce qui reste toujours permis ─────────────────────────────────────────
 *
 * Toutes les lectures, sans exception. Et parmi les écritures : se connecter,
 * se déconnecter, changer son mot de passe, lancer un export, poser une
 * licence. C'est délibéré et ce n'est pas négociable : le client est
 * responsable de traitement au sens du RGPD, il doit pouvoir entrer chez lui
 * et ressortir avec ses données. Une licence impayée se règle par une
 * discussion commerciale, pas en séquestrant des dossiers du personnel et des
 * bulletins de paie.
 */

/**
 * Les chemins dont les écritures passent quoi qu'il arrive. Comparés en
 * préfixe : une route plus précise en dessous reste couverte.
 */
const TOUJOURS = [
  '/connexion',
  '/deconnexion',
  '/mot-de-passe',
  '/licence',
  // Sortir ses données : la contrepartie de tout ce qui précède.
  '/admin/export',
  '/admin/sauvegardes',
  '/coffre-fort',
];

function permisMalgreTout(chemin) {
  return TOUJOURS.some((prefixe) => chemin === prefixe || chemin.startsWith(`${prefixe}/`));
}

function middleware(req, res, next) {
  const etat = licence.etat();

  // Le bandeau, pour les gabarits. Il vaut pour toutes les phases : « valide »
  // ne l'affiche pas, mais l'objet est là et les vues n'ont pas à le tester.
  res.locals.licence = etat;

  if (etat.phase === licence.PHASES.VALIDE
    || etat.phase === licence.PHASES.SURSIS
    || etat.phase === licence.PHASES.GRACE) return next();
  if (req.method === 'GET' || req.method === 'HEAD') return next();
  if (permisMalgreTout(req.path)) return next();

  // On ne renvoie pas une erreur brute : l'administrateur doit comprendre ce
  // qui se passe et où aller le régler.
  if (req.accepts('html')) {
    req.session.flash = {
      type: 'error',
      message: res.locals.t
        ? res.locals.t('licence.readOnlyBlocked')
        : 'Cette instance est en lecture seule : sa licence n’est plus valable.',
    };
    return res.redirect(req.get('referer') || '/licence');
  }
  return res.status(402).json({ erreur: 'licence', phase: etat.phase, motif: etat.motif || '' });
}

module.exports = { middleware, TOUJOURS, permisMalgreTout };
