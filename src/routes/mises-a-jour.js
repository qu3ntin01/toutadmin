const express = require('express');

const updates = require('../updates');
const settings = require('../settings');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAdmin);

/**
 * Mises à jour de l'instance.
 *
 * L'écran dit trois choses : en quelle version on tourne, ce que l'éditeur
 * propose, et ce qui s'est passé les fois précédentes. Le reste — la clé, le
 * canal, le mode — tient en un formulaire, parce qu'on ne le remplit qu'une
 * fois.
 */

function screen(res) {
  const config = updates.config();
  const marker = updates.readMarker();
  return {
    title: res.locals.t('updates.title'),
    config,
    // La clé d'instance n'est jamais réaffichée : elle se remplace, elle ne se
    // relit pas. Le formulaire dit seulement si elle est posée.
    keySet: Boolean(config.key),
    available: updates.available(),
    lastCheck: settings.get('update_last_check'),
    lastError: settings.get('update_last_error'),
    history: updates.history(),
    /*
     * Le code de cette instance s'écrit-il ?
     *
     * En image de conteneur, non : la version se change en changeant d'image.
     * L'écran garde alors la vérification — savoir qu'une version existe sert
     * toujours — et remplace les boutons par le geste qui convient.
     */
    immuable: updates.codeImmuable(),
    // La fenêtre affiche ces étapes-là : celles que la pose traverse vraiment.
    etapes: updates.ETAPES,
    restartPending: Boolean(marker && marker.step === 'awaiting-restart'),
    pendingVersion: marker ? marker.version : '',
  };
}

/**
 * Répond à la page, ou à la fenêtre.
 *
 * Les gestes sont les mêmes des deux côtés, et le code qui les exécute aussi :
 * seule la façon de rendre le résultat change. Deux routes parallèles auraient
 * fini par appliquer deux règles — c'est arrivé à tout le monde.
 *
 * Sans JavaScript, les formulaires continuent de fonctionner comme avant : la
 * fenêtre est un confort, pas une condition.
 */
const veutDuJson = (req) => req.get('X-Fenetre') === '1';

function repondre(req, res, { ok, message, detail = '', extra = {} }) {
  if (veutDuJson(req)) {
    return res.status(ok ? 200 : 409).json({ ok, message, detail, ...extra });
  }
  setFlash(req, ok ? 'success' : 'error', detail ? `${message} — ${detail}` : message);
  return res.redirect('/mises-a-jour');
}

router.get('/', (req, res) => {
  res.render('updates/index', screen(res));
});

/**
 * Où en est la pose, pour la fenêtre qui attend.
 *
 * Elle interroge pendant que l'autre requête travaille. L'étape vient de la
 * base, pas d'une variable : rafraîchir la page ne perd pas le fil.
 */
router.get('/avancement', (req, res) => {
  const marker = updates.readMarker();
  res.json({
    etapes: updates.ETAPES,
    enCours: updates.enCours(),
    attendRedemarrage: Boolean(marker && marker.step === 'awaiting-restart'),
    version: marker ? marker.version : '',
  });
});

router.post('/reglages', (req, res) => {
  updates.setConfig({
    url: req.body.url,
    key: req.body.cle,
    publicKey: req.body.clePublique,
    channel: req.body.canal,
    mode: req.body.mode,
    name: req.body.nom,
  });
  setFlash(req, 'success', res.locals.t('updates.saved'));
  res.redirect('/mises-a-jour');
});

router.post('/verifier', async (req, res) => {
  const result = await updates.check();
  if (!result.ok) {
    return repondre(req, res, { ok: false, message: res.locals.t(`updates.error.${result.reason}`) });
  }
  if (result.available) {
    return repondre(req, res, {
      ok: true,
      message: res.locals.t('updates.found', { version: result.version.numero }),
      extra: { disponible: { version: result.version.numero, notes: result.version.notes || '' } },
    });
  }
  return repondre(req, res, { ok: true, message: res.locals.t('updates.upToDate'), extra: { disponible: null } });
});

router.post('/appliquer', async (req, res) => {
  const result = await updates.apply({ by: req.session.user.id, mode: 'manual' });
  if (!result.ok) {
    // Le détail est affiché tel quel à côté du motif traduit : il dit souvent
    // tout, et le traduire reviendrait à le perdre.
    return repondre(req, res, {
      ok: false,
      message: res.locals.t(`updates.error.${result.reason}`),
      detail: result.detail || '',
      extra: { motif: result.reason },
    });
  }
  return repondre(req, res, {
    ok: true,
    message: res.locals.t('updates.applied', { version: result.version }),
    extra: { version: result.version, redemarrage: Boolean(result.restartNeeded) },
  });
});

router.post('/retour', (req, res) => {
  const result = updates.rollback({ by: req.session.user.id });
  if (!result.ok) {
    return repondre(req, res, { ok: false, message: res.locals.t(`updates.error.${result.reason}`), extra: { motif: result.reason } });
  }
  return repondre(req, res, {
    ok: true,
    message: res.locals.t('updates.rolledBack', { version: result.version }),
    extra: { version: result.version, redemarrage: true },
  });
});

module.exports = router;
