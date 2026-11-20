/**
 * Le paiement en ligne des factures client par Stripe Checkout.
 *
 * Le produit ne voit jamais un numéro de carte : il demande à Stripe une page
 * de paiement pour le montant TTC d'une facture, en donne l'adresse au client,
 * et Stripe le prévient quand c'est payé. Ce qu'il garde, c'est la session
 * Checkout qu'il a lui-même créée — identifiant, facture, montant. La société
 * est celle de la facture : la session lui est rattachée.
 *
 * L'avis de Stripe (le « webhook ») arrive sans session ni jeton CSRF : il
 * s'authentifie par sa signature, un HMAC-SHA256 du corps brut et de l'heure
 * d'émission, avec le secret de signature propre à ce point d'arrivée. Une
 * signature fausse, une heure trop éloignée, une session inconnue d'ici, un
 * montant ou une devise qui ne correspondent pas : la facture ne bouge pas.
 *
 * Les deux clés — la clé secrète de l'API et le secret de signature — sont
 * chiffrées en base et jamais réaffichées.
 */

const crypto = require('crypto');
const db = require('./db');
const settings = require('./settings');
const secrets = require('./secret-store');
const societes = require('./societes');
const { sansPerimetre } = require('./cloison');

const API = 'https://api.stripe.com';
const TOLERANCE = 300;
// Les devises sans décimales chez Stripe : le montant s'y donne en unités.
const SANS_DECIMALES = new Set(['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF']);

/**
 * Le transport vers l'API. Remplaçable par les tests, qui ne parlent pas à
 * Stripe : (méthode, chemin, champs, clé) → { status, corps }.
 */
let transport = async (methode, chemin, champs, cle) => {
  const reponse = await fetch(API + chemin, {
    method: methode,
    headers: { Authorization: `Bearer ${cle}`, 'Content-Type': 'application/x-www-form-urlencoded', 'Stripe-Version': '2024-06-20' },
    body: methode === 'POST' ? new URLSearchParams(champs).toString() : undefined,
    signal: AbortSignal.timeout(10000),
  });
  return { status: reponse.status, corps: await reponse.json().catch(() => ({})) };
};

function config() {
  const s = settings.all();
  return {
    enabled: s.stripe_enabled === '1',
    secretKey: s.stripe_secret_key ? secrets.decrypt(s.stripe_secret_key) : '',
    webhookSecret: s.stripe_webhook_secret ? secrets.decrypt(s.stripe_webhook_secret) : '',
    hasSecretKey: Boolean(s.stripe_secret_key),
    hasWebhookSecret: Boolean(s.stripe_webhook_secret),
  };
}

/** Prêt à servir : activé, et les deux clés connues. */
function pret() {
  const c = config();
  return c.enabled && c.secretKey !== '' && c.webhookSecret !== '';
}

/**
 * Enregistrer les réglages. Une clé laissée vide garde l'ancienne ; une clé
 * donnée doit avoir la forme que Stripe lui donne.
 */
function enregistrer({ enabled, secretKey, webhookSecret }) {
  const valeurs = { stripe_enabled: enabled ? '1' : '0' };
  const cle = String(secretKey || '').trim();
  const signature = String(webhookSecret || '').trim();
  if (cle !== '') {
    if (!/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/.test(cle)) return { ok: false, reason: 'secret-key' };
    valeurs.stripe_secret_key = secrets.encrypt(cle);
  }
  if (signature !== '') {
    if (!/^whsec_[A-Za-z0-9+/=]{10,}$/.test(signature)) return { ok: false, reason: 'webhook-secret' };
    valeurs.stripe_webhook_secret = secrets.encrypt(signature);
  }
  if (enabled) {
    const actuel = config();
    if (!valeurs.stripe_secret_key && !actuel.hasSecretKey) return { ok: false, reason: 'secret-key' };
    if (!valeurs.stripe_webhook_secret && !actuel.hasWebhookSecret) return { ok: false, reason: 'webhook-secret' };
  }
  settings.setMany(valeurs);
  return { ok: true };
}

/** En plus petite unité de la devise, sans erreur d'arrondi flottant. */
function centimes(montant, devise) {
  return SANS_DECIMALES.has(devise) ? Math.round(Number(montant)) : Math.round(Number(montant) * 100);
}

/** Le dernier lien de paiement d'une facture de la société courante. */
function lienDe(invoiceId) {
  return db.prepare(`SELECT s.session_id, s.url, s.status, s.amount_cents, s.currency, s.created_at FROM stripe_sessions s
    JOIN invoices i ON i.id = s.invoice_id
    WHERE s.invoice_id = ? AND i.company_id = ? ORDER BY s.created_at DESC, s.rowid DESC LIMIT 1`).get(invoiceId, societes.courant()) || null;
}

/**
 * Demander à Stripe une page de paiement pour une facture client émise. Le
 * montant est celui de la facture, TTC, figé à cet instant : l'avis de paiement
 * ne sera accepté que s'il dit le même.
 */
async function creerLien(invoice, origine) {
  if (!pret()) return { ok: false, reason: 'disabled' };
  if (!invoice || invoice.direction !== 'Client') return { ok: false, reason: 'not-found' };
  if (invoice.status !== 'Émise') return { ok: false, reason: 'status' };
  const devise = String(invoice.currency || 'EUR').toUpperCase();
  const montant = centimes(invoice.amount_ttc, devise);
  if (!(montant > 0)) return { ok: false, reason: 'amount' };

  const nom = (invoice.reference ? `Facture ${invoice.reference}` : `Facture n° ${invoice.id}`).slice(0, 120);
  const champs = {
    mode: 'payment',
    'line_items[0][quantity]': '1',
    'line_items[0][price_data][currency]': devise.toLowerCase(),
    'line_items[0][price_data][unit_amount]': String(montant),
    'line_items[0][price_data][product_data][name]': nom,
    client_reference_id: `facture-${invoice.id}`,
    'metadata[invoice_id]': String(invoice.id),
    success_url: `${origine}/paiement/merci.html`,
    cancel_url: `${origine}/paiement/annule.html`,
  };
  const { status, corps } = await transport('POST', '/v1/checkout/sessions', champs, config().secretKey);
  if (status !== 200 || typeof corps.id !== 'string' || typeof corps.url !== 'string' || !/^https:\/\//.test(corps.url)) {
    return { ok: false, reason: 'stripe', detail: corps && corps.error ? String(corps.error.code || corps.error.type || '') : String(status) };
  }
  db.prepare(`INSERT INTO stripe_sessions (session_id, invoice_id, amount_cents, currency, url, status, created_at)
    VALUES (?, ?, ?, ?, ?, 'open', ?)`).run(corps.id, invoice.id, montant, devise, corps.url, new Date().toISOString());
  return { ok: true, url: corps.url, sessionId: corps.id };
}

/**
 * Vérifier l'en-tête Stripe-Signature : « t=<heure>,v1=<hmac>[,v1=…] ». Le HMAC
 * porte sur « <heure>.<corps brut> » ; l'heure doit être à moins de cinq
 * minutes de la nôtre, pour qu'un avis capté ne se rejoue pas plus tard.
 */
function signatureValide(corps, entete, secret, maintenant = Math.floor(Date.now() / 1000)) {
  if (!secret || typeof entete !== 'string' || !Buffer.isBuffer(corps)) return 'signature';
  let t = null;
  const v1 = [];
  for (const morceau of entete.split(',')) {
    const [cle, valeur] = morceau.split('=', 2).map((x) => (x || '').trim());
    if (cle === 't' && /^\d{1,12}$/.test(valeur)) t = Number(valeur);
    if (cle === 'v1' && /^[0-9a-f]{64}$/.test(valeur)) v1.push(Buffer.from(valeur, 'hex'));
  }
  if (t === null || v1.length === 0) return 'signature';
  if (Math.abs(maintenant - t) > TOLERANCE) return 'timestamp';
  const attendu = crypto.createHmac('sha256', secret).update(`${t}.`).update(corps).digest();
  return v1.some((v) => crypto.timingSafeEqual(v, attendu)) ? true : 'signature';
}

/**
 * Recevoir un avis. Rend { status, reason } : 400 pour un avis qui n'est pas
 * de Stripe, 200 pour tout le reste — y compris ce qu'on ignore, sans quoi
 * Stripe le renverrait pendant trois jours.
 */
function recevoir(corps, entete, maintenant) {
  const verdict = signatureValide(corps, entete, config().webhookSecret, maintenant);
  if (verdict !== true) return { status: 400, reason: verdict };
  let evenement;
  try { evenement = JSON.parse(corps.toString('utf8')); } catch { return { status: 400, reason: 'json' }; }
  const type = evenement && evenement.type;
  if (type !== 'checkout.session.completed' && type !== 'checkout.session.async_payment_succeeded') {
    return { status: 200, reason: 'ignored' };
  }
  const objet = (evenement.data && evenement.data.object) || {};
  if (objet.payment_status !== 'paid') return { status: 200, reason: 'unpaid' };

  // Seule une session créée ici fait foi ; sa facture, sa société et son
  // montant sont ceux qu'on a gardés, pas ceux que l'avis raconte.
  const session = sansPerimetre(() => db.prepare('SELECT * FROM stripe_sessions WHERE session_id = ?').get(String(objet.id || '')));
  if (!session) return { status: 200, reason: 'unknown-session' };
  if (session.status === 'paid') return { status: 200, reason: 'duplicate' };
  if (Number(objet.amount_total) !== session.amount_cents || String(objet.currency || '').toUpperCase() !== session.currency) {
    return { status: 200, reason: 'amount', session };
  }
  const finance = require('./finance');
  const societe = sansPerimetre(() => db.prepare('SELECT company_id FROM invoices WHERE id = ?').get(session.invoice_id));
  const marquee = societe && societes.dans(societe.company_id, () => {
    const facture = finance.invoiceById(session.invoice_id);
    if (!facture || facture.status === 'Annulée') return false;
    if (facture.status !== 'Payée') finance.setInvoiceStatus(facture.id, 'Payée');
    return true;
  });
  sansPerimetre(() => db.prepare('UPDATE stripe_sessions SET status = ?, paid_at = ? WHERE session_id = ?')
    .run(marquee ? 'paid' : 'orphan', new Date().toISOString(), session.session_id));
  return { status: 200, reason: marquee ? 'paid' : 'invoice', session };
}

module.exports = {
  config, pret, enregistrer, creerLien, lienDe, recevoir, signatureValide, centimes,
  _transport: (f) => { transport = f; },
};
