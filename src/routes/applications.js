const express = require('express');

const store = require('../store');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

/**
 * La boutique d'applications.
 *
 * Réservée à l'administration, parce qu'on y allume des modules. La regarder
 * sans pouvoir rien allumer n'aurait pas grand sens : le rail ne la montre
 * donc qu'aux administrateurs, et cette barrière-ci dit la même chose au
 * serveur.
 */
router.use(requireAdmin);

/**
 * Répond à la page, ou à la fenêtre.
 *
 * Le code qui allume est le même des deux côtés : seule la façon de rendre le
 * résultat change. Sans JavaScript, les formulaires partent comme avant.
 */
const veutDuJson = (req) => req.get('X-Fenetre') === '1';

function repondre(req, res, { ok, message, vers = '/applications', extra = {} }) {
  if (veutDuJson(req)) return res.status(ok ? 200 : 409).json({ ok, message, vers, ...extra });
  setFlash(req, ok ? 'success' : 'error', message);
  return res.redirect(vers);
}

router.get('/', async (req, res) => {
  const vue = await store.screen({ force: req.query.actualiser === '1' });
  res.render('applications/index', {
    title: res.locals.t('store.title'),
    // Vide quand l'éditeur n'a pas répondu : la vue n'affiche alors aucun
    // bouton d'achat, ce qui vaut mieux qu'un lien vers nulle part.
    espaceClient: vue.espaceClient || '',
    ...vue,
  });
});

router.post('/:cle/allumer', async (req, res) => {
  const issue = await store.enable(String(req.params.cle));
  if (!issue.ok) {
    return repondre(req, res, { ok: false, message: res.locals.t(`store.error.${issue.reason}`), extra: { motif: issue.reason } });
  }
  // Droit à l'écran qu'on vient d'ouvrir : c'est ce qu'on voulait, pas la
  // boutique une seconde fois.
  return repondre(req, res, {
    ok: true,
    message: res.locals.t('store.enabled'),
    vers: issue.href || '/applications',
  });
});

/**
 * Récupérer ses achats.
 *
 * Le client vient d'acheter dans son espace client. Sans ce bouton il
 * reviendrait ici, ne verrait rien de changé, et ouvrirait une demande
 * d'assistance : le catalogue a une minute de répit, et l'achat s'est fait
 * ailleurs.
 */
router.post('/recuperer', async (req, res) => {
  const issue = await store.fetchPurchases();
  if (!issue.ok) {
    return repondre(req, res, { ok: false, message: res.locals.t(`store.error.${issue.reason}`), extra: { motif: issue.reason } });
  }
  if (issue.allumes.length === 0) {
    return repondre(req, res, { ok: true, message: res.locals.t('store.fetchedNothing'), extra: { allumes: [] } });
  }
  return repondre(req, res, {
    ok: true,
    message: res.locals.t('store.fetched', { liste: issue.allumes.join(', ') }),
    extra: { allumes: issue.allumes },
  });
});

module.exports = router;
