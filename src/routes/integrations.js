const express = require('express');

const audit = require('../audit');
const tokens = require('../api-tokens');
const webhooks = require('../webhooks');
const { requireAdmin } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

// Ouvrir une porte d'entrée sur les données de l'entreprise relève de
// l'administration seule, jamais d'un droit délégué.
router.use(requireAdmin);

const back = (anchor) => `/integrations#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function render(req, res, extra = {}) {
  res.render('integrations', {
    tokenList: tokens.list(),
    scopes: tokens.SCOPES,
    defaultDays: tokens.DEFAULT_DAYS,
    webhookList: webhooks.list(),
    events: webhooks.EVENTS,
    deliveries: webhooks.deliveries({ limit: 40 }),
    stats: webhooks.summary(),
    maxAttempts: webhooks.MAX_ATTEMPTS,
    failureLimit: webhooks.FAILURE_LIMIT,
    created: null,
    ...extra,
  });
}

router.get('/', (req, res) => render(req, res));

// ---------- Jetons d'API ----------

router.post('/jetons', (req, res) => {
  const verdict = tokens.create({
    label: req.body.label,
    scopes: [].concat(req.body.scopes || []),
    days: Number(req.body.days),
    createdBy: req.session.user.id,
  });
  if (!verdict.ok) return fail(req, res, 'jetons', verdict.message);

  audit.log(req, 'api.jeton_cree', 'api_tokens', verdict.id, { intitule: req.body.label, portees: verdict.scopes.join(',') });

  // La valeur en clair n'existe qu'ici : elle est rendue avec la page, jamais
  // déposée en session ni écrite au journal.
  return render(req, res, {
    created: { kind: 'token', label: req.body.label, value: verdict.token, expiresAt: verdict.expiresAt },
    flash: { type: 'success', message: 'Jeton créé. Copiez-le maintenant : il ne sera plus affiché.' },
  });
});

router.post('/jetons/:id/revoquer', (req, res) => {
  if (!tokens.revoke(Number(req.params.id))) return fail(req, res, 'jetons', 'Jeton déjà révoqué ou introuvable.');

  audit.log(req, 'api.jeton_revoque', 'api_tokens', Number(req.params.id), {});
  setFlash(req, 'success', 'Jeton révoqué : il ne répond plus, immédiatement.');
  return res.redirect(back('jetons'));
});

router.post('/jetons/:id/supprimer', (req, res) => {
  tokens.remove(Number(req.params.id));
  audit.log(req, 'api.jeton_supprime', 'api_tokens', Number(req.params.id), {});
  setFlash(req, 'success', 'Jeton supprimé.');
  return res.redirect(back('jetons'));
});

// ---------- Webhooks ----------

router.post('/webhooks', (req, res) => {
  const verdict = webhooks.create({
    label: req.body.label,
    url: req.body.url,
    events: [].concat(req.body.events || []),
    allowPrivate: req.body.allow_private === '1',
    createdBy: req.session.user.id,
  });
  if (!verdict.ok) return fail(req, res, 'webhooks', verdict.message);

  audit.log(req, 'webhook.cree', 'webhooks', verdict.id, { intitule: req.body.label, url: req.body.url });

  return render(req, res, {
    created: { kind: 'secret', label: req.body.label, value: verdict.secret },
    flash: { type: 'success', message: 'Webhook enregistré. Copiez le secret de signature : il ne sera plus affiché.' },
  });
});

router.post('/webhooks/:id/etat', (req, res) => {
  const active = req.body.active === '1';
  if (!webhooks.setActive(Number(req.params.id), active)) return fail(req, res, 'webhooks', 'Webhook introuvable.');

  audit.log(req, 'webhook.etat', 'webhooks', Number(req.params.id), { actif: active });
  setFlash(req, 'success', active ? 'Webhook réactivé, compteur d\'échecs remis à zéro.' : 'Webhook suspendu.');
  return res.redirect(back('webhooks'));
});

router.post('/webhooks/:id/supprimer', (req, res) => {
  webhooks.remove(Number(req.params.id));
  audit.log(req, 'webhook.supprime', 'webhooks', Number(req.params.id), {});
  setFlash(req, 'success', 'Webhook supprimé, avec son journal de livraisons.');
  return res.redirect(back('webhooks'));
});

/** Envoi d'essai : c'est la seule façon de savoir que le tuyau est branché. */
router.post('/webhooks/:id/tester', async (req, res) => {
  const hook = webhooks.byId(req.params.id);
  if (!hook) return fail(req, res, 'webhooks', 'Webhook introuvable.');

  webhooks.emit(hook.eventList[0], { essai: true, envoye_par: req.session.user.id });
  const result = await webhooks.flush({ limit: 10 });

  audit.log(req, 'webhook.teste', 'webhooks', hook.id, { livres: result.delivered, echecs: result.failed });
  setFlash(req, result.failed ? 'error' : 'success', result.failed
    ? `Essai en échec : ${webhooks.byId(hook.id).last_status}`
    : 'Essai livré.');
  return res.redirect(back('webhooks'));
});

module.exports = router;
