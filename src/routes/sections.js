const express = require('express');

const sections = require('../sections');
const reglagesSection = require('../sections-reglages');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

/** Les trois temps de l'assistant : on découvre, on règle, on récapitule. */
const ETAPES = ['decouverte', 'reglages', 'fin'];

/**
 * La section demandée, ou null.
 *
 * Trois refus distincts, et il faut les distinguer : une section inventée
 * n'existe pas ; une section que le client n'a pas installée n'existe pas non
 * plus pour lui — le dire autrement lui apprendrait ce qu'il a refusé ; une
 * section dont ce compte ne verrait aucun service se refuse comme une adresse
 * inventée, plutôt que par un « accès refusé » qui apprendrait son existence.
 */
function sectionDe(req, res) {
  const groupe = sections.groupe(req.params.cle);
  if (!groupe || !reglagesSection.estActive(groupe.cle)) return null;
  const services = sections.servicesDe(groupe.cle, res.locals.droits);
  return services.length ? { groupe, services } : null;
}

function introuvable(req, res) {
  return res.status(404).render('error', { message: res.locals.t('sections.unknown') });
}

/** Régler une section est une décision d'instance : elle appartient à l'administration. */
function exigerAdmin(req, res, next) {
  if (req.session.user && req.session.user.role === 'admin') return next();
  res.status(403).render('error', { message: res.locals.t('sec.adminOnly') });
}

/*
 * Le tableau de bord d'une section.
 *
 * Le rail dit où l'on peut aller ; il ne dit pas ce qu'on y fait. Replié, il ne
 * montre qu'un nom de métier, et déplié, sept mots par ligne. Cet écran ouvre
 * le métier : chaque service y a sa tuile, son icône — la même que dans le rail,
 * pour qu'on la reconnaisse — et une ligne qui dit ce qu'il sert à faire.
 *
 * C'est la disposition d'un panneau d'hébergeur, et pour la même raison : on
 * cherche « ce que je veux faire », pas « où c'est rangé ».
 */
router.get('/:cle', (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  res.render('section', {
    title: res.locals.t(trouve.groupe.titre),
    groupe: trouve.groupe,
    services: trouve.services,
    // Le lien vers les réglages ne s'affiche que pour qui peut les ouvrir.
    peutRegler: req.session.user.role === 'admin',
    champs: req.session.user.role === 'admin' ? reglagesSection.champsDe(trouve.groupe.cle) : [],
  });
});

// ------------------------------------------------------------ l'assistant

/**
 * L'assistant de première ouverture.
 *
 * Trois temps : ce que la section contient, ce qu'elle demande, et ce qu'on
 * vient de poser. Le premier n'est pas du remplissage — c'est le seul moment
 * où quelqu'un lit ce qu'un métier regroupe, avant d'avoir à le deviner d'un
 * rail replié.
 *
 * Il est accessible à tout moment, pas seulement la première fois : « Revoir
 * l'assistant » depuis les réglages y ramène. Il ne défait rien et repart des
 * valeurs en place.
 */
router.get('/:cle/assistant', exigerAdmin, (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  const etape = ETAPES.includes(req.query.etape) ? req.query.etape : ETAPES[0];
  res.render('section-assistant', {
    title: res.locals.t(trouve.groupe.titre),
    groupe: trouve.groupe,
    services: trouve.services,
    champs: reglagesSection.champsDe(trouve.groupe.cle),
    etapes: ETAPES,
    etape,
    etapeIndex: ETAPES.indexOf(etape),
    dejaConfiguree: reglagesSection.estConfiguree(trouve.groupe.cle),
  });
});

router.post('/:cle/assistant', exigerAdmin, (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  const cle = trouve.groupe.cle;

  /*
   * « Garder les valeurs par défaut » n'est pas un abandon : c'est une
   * réponse, et elle vaut pour toujours. On marque donc la section
   * configurée — sans quoi l'assistant se rouvrirait à chaque visite, et
   * l'administrateur qui a dit « ça me va » devrait le redire tous les jours.
   */
  if (req.body.passer === '1') {
    reglagesSection.marquerConfiguree(cle);
    setFlash(req, 'success', res.locals.t('sec.wizard.skipped'));
    return res.redirect(`/section/${cle}`);
  }

  const refuses = reglagesSection.enregistrer(cle, req.body);
  if (refuses.length > 0) {
    setFlash(req, 'error', res.locals.t('sec.settings.rejected', {
      champs: refuses.map((c) => res.locals.t(`sec.${cle}.${c}`)).join(', '),
    }));
    return res.redirect(`/section/${cle}/assistant?etape=reglages`);
  }

  reglagesSection.marquerConfiguree(cle);
  res.redirect(`/section/${cle}/assistant?etape=fin`);
});

// ------------------------------------------------------------- les réglages

router.get('/:cle/reglages', exigerAdmin, (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  res.render('section-reglages', {
    title: res.locals.t(trouve.groupe.titre),
    groupe: trouve.groupe,
    champs: reglagesSection.champsDe(trouve.groupe.cle),
    configuree: reglagesSection.estConfiguree(trouve.groupe.cle),
  });
});

router.post('/:cle/reglages', exigerAdmin, (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  const cle = trouve.groupe.cle;

  const refuses = reglagesSection.enregistrer(cle, req.body);
  if (refuses.length > 0) {
    setFlash(req, 'error', res.locals.t('sec.settings.rejected', {
      champs: refuses.map((c) => res.locals.t(`sec.${cle}.${c}`)).join(', '),
    }));
    return res.redirect(`/section/${cle}/reglages`);
  }

  setFlash(req, 'success', res.locals.t('sec.settings.saved'));
  res.redirect(`/section/${cle}/reglages`);
});

/** Rouvrir l'assistant : il relit les valeurs en place, il ne les efface pas. */
router.post('/:cle/assistant/rouvrir', exigerAdmin, (req, res) => {
  const trouve = sectionDe(req, res);
  if (!trouve) return introuvable(req, res);
  reglagesSection.rouvrir(trouve.groupe.cle);
  res.redirect(`/section/${trouve.groupe.cle}/assistant`);
});

module.exports = router;
