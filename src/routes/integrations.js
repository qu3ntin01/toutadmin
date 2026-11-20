const express = require('express');

const ai = require('../ai');
const audit = require('../audit');
const tokens = require('../api-tokens');
const webhooks = require('../webhooks');
const stripe = require('../stripe');
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
    writeScopes: tokens.WRITE_SCOPES,
    writeSuffix: tokens.WRITE_SUFFIX,
    defaultDays: tokens.DEFAULT_DAYS,
    webhookList: webhooks.list(),
    events: webhooks.EVENTS,
    deliveries: webhooks.deliveries({ limit: 40 }),
    stats: webhooks.summary(),
    maxAttempts: webhooks.MAX_ATTEMPTS,
    failureLimit: webhooks.FAILURE_LIMIT,
    stripeConfig: (({ secretKey, webhookSecret, ...reste }) => reste)(stripe.config()),
    stripeWebhook: `${req.protocol}://${req.get('host')}/stripe/webhook`,
    aiConfig: ai.displayConfig(),
    aiFonctions: ai.fonctionsAffichees(),
    aiProviders: ai.PROVIDERS,
    aiEfforts: ai.EFFORTS,
    aiStatus: ai.status(),
    aiReady: ai.isReady(),
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

  const ecrit = verdict.scopes.some((portee) => portee.endsWith(tokens.WRITE_SUFFIX));
  // Un jeton qui écrit n'est pas un jeton comme un autre : le journal le dit,
  // pour qu'une relecture ne les confonde pas.
  audit.log(req, ecrit ? 'api.jeton_ecriture_cree' : 'api.jeton_cree', 'api_tokens', verdict.id, {
    intitule: req.body.label, portees: verdict.scopes.join(','), ecrit,
  });

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
  const id = Number(req.params.id);
  if (!tokens.remove(id)) return fail(req, res, 'jetons', 'Jeton introuvable.');

  audit.log(req, 'api.jeton_supprime', 'api_tokens', id, {});
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
  const id = Number(req.params.id);
  if (!webhooks.remove(id)) return fail(req, res, 'webhooks', 'Webhook introuvable.');

  audit.log(req, 'webhook.supprime', 'webhooks', id, {});
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

// ---------- Assistance par modèle ----------

/*
 * Le réglage a sa place ici, et non dans un onglet comptable.
 *
 * Il a commencé sa vie dans « Pièces reçues », du temps où lire une facture
 * était la seule chose qu'un modèle faisait ici. Il en sert quatre — la
 * comptabilité, le support, la direction, la rédaction — et un réglage qui
 * décide de ce qui sort de l'entreprise appartient au même écran que les jetons
 * d'API et les webhooks : celui des portes vers le dehors.
 */
// Le paiement en ligne : deux clés, chiffrées en base, jamais réaffichées.
router.post('/paiement', (req, res) => {
  const result = stripe.enregistrer({
    enabled: Boolean(req.body.enabled),
    secretKey: req.body.secret_key,
    webhookSecret: req.body.webhook_secret,
  });
  if (!result.ok) {
    return fail(req, res, 'paiement', result.reason === 'secret-key'
      ? 'La clé secrète de Stripe est attendue (sk_live_…, sk_test_… ou une clé restreinte rk_…).'
      : 'Le secret de signature du webhook est attendu (whsec_…) : Stripe le donne quand on déclare le point d’arrivée.');
  }
  // Le journal dit qui a changé quoi, jamais les clés.
  audit.log(req, 'stripe.modifie', 'settings', null, { active: Boolean(req.body.enabled) });
  setFlash(req, 'success', 'Paiement en ligne enregistré.');
  return res.redirect(back('paiement'));
});

router.post('/assistance/reglages', (req, res) => {
  const verdict = ai.setConfig({
    provider: req.body.provider,
    model: req.body.model,
    baseUrl: req.body.base_url,
    effort: req.body.effort,
    key: req.body.key,
    enabled: req.body.enabled === '1',
  });
  if (!verdict.ok) return fail(req, res, 'assistance', verdict.message);

  /*
   * Les interrupteurs, un par fonction.
   *
   * Une case décochée éteint : c'est un formulaire HTML, une case non cochée
   * n'est pas envoyée, et l'absence doit donc valoir « non ». Le contraire
   * rendrait une fonction impossible à éteindre depuis l'écran.
   */
  const demandees = [].concat(req.body.fonctions || []).map(String);
  for (const fonction of ai.FONCTIONS) {
    ai.activerFonction(fonction.cle, demandees.includes(fonction.cle));
  }

  audit.log(req, 'ia.service_configure', 'settings', null, {
    service: req.body.provider, modele: req.body.model, actif: req.body.enabled === '1',
    fonctions: ai.FONCTIONS.filter((f) => demandees.includes(f.cle)).map((f) => f.cle),
  });
  setFlash(req, 'success', req.body.enabled === '1'
    ? res.locals.t('ia.set.savedOn')
    : res.locals.t('ia.set.savedOff'));
  return res.redirect(back('assistance'));
});

router.post('/assistance/tester', async (req, res) => {
  const verdict = await ai.test();
  audit.log(req, 'ia.service_teste', 'settings', null, { ok: verdict.ok });
  setFlash(req, verdict.ok ? 'success' : 'error', verdict.message);
  return res.redirect(back('assistance'));
});

module.exports = router;
