const express = require('express');

const audit = require('../audit');
const masse = require('../actions-masse');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();
router.use(requireAuth);

/**
 * Le seul point d'entrée des actions de masse.
 *
 * Une route par objet aurait semblé plus naturelle, et aurait dispersé la
 * vérification : trois routes, trois occasions d'oublier le plafond ou le
 * compte rendu. Ici, le même chemin sert les tickets et les demandes, et le
 * module décide — c'est lui qui sait quels droits et quelles valeurs.
 *
 * La route ne porte aucun droit propre, et ce n'est pas un oubli : un droit posé
 * ici serait le droit d'« utiliser les actions de masse », qui ne veut rien
 * dire. Ce qui compte est le droit sur chaque ligne, et il est vérifié ligne par
 * ligne, exactement comme à l'unité.
 */
router.post('/', (req, res) => {
  const objet = String(req.body.objet || '');
  const action = String(req.body.action || '');
  const retour = masse.retourDe(objet);

  const parametre = masse.parametreDe(objet, action);
  const valeur = parametre ? req.body[`valeur_${parametre}`] ?? null : null;

  const verdict = masse.appliquer({
    objet, action, ids: req.body.ids, valeur, user: req.currentUser,
  });

  if (!verdict.ok) {
    setFlash(req, 'error', res.locals.t(verdict.raison, { plafond: masse.PLAFOND }));
    return res.redirect(req.get('referer') || retour);
  }

  /*
   * Une entrée au journal pour la demande elle-même, en plus de celles que les
   * actions à l'unité ont déjà écrites. Elle porte des nombres et non des
   * identifiants : ce qui a changé est déjà tracé ligne par ligne, et recopier
   * la liste ici ferait du journal un second exemplaire des données.
   */
  audit.log(req, 'masse.appliquee', objet, null, {
    action,
    faites: verdict.faites.length,
    refusees: verdict.refusees.length,
  });

  /* Les refus sont comptés par motif, et non listés un par un : une liste de
     cinquante identifiants ne dit rien, « hors de votre charge : 2 » dit tout. */
  const parMotif = new Map();
  for (const refusee of verdict.refusees) {
    parMotif.set(refusee.raison, (parMotif.get(refusee.raison) || 0) + 1);
  }
  const motifs = [...parMotif].map(([cle, n]) => `${res.locals.t(cle)} : ${n}`).join(', ');

  const message = verdict.refusees.length
    ? res.locals.t('masse.partiel', {
      faites: verdict.faites.length, refusees: verdict.refusees.length, motifs,
    })
    : res.locals.t('masse.fait', { faites: verdict.faites.length });
  setFlash(req, verdict.faites.length ? 'success' : 'error', message);
  return res.redirect(req.get('referer') || retour);
});

module.exports = router;
