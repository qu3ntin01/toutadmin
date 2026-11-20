const crypto = require('crypto');

const settings = require('./settings');
const secrets = require('./secret-store');

/**
 * La connexion unique par OpenID Connect (Entra ID, Google Workspace, Okta,
 * Keycloak…), en flux « code d'autorisation » avec PKCE.
 *
 * Ce que le produit vérifie lui-même, sans bibliothèque :
 * - l'état (« state ») qui lie le retour à la session qui est partie, et le
 *   « nonce » qui lie le jeton à cette même demande ;
 * - le jeton d'identité : algorithme RS256 ou ES256 seulement — jamais « none »,
 *   jamais un HMAC dont le secret serait celui du client —, clé désignée par
 *   son « kid » dans le jeu de clés publié par le fournisseur, signature,
 *   émetteur, audience, expiration ;
 * - l'adresse : vérifiée par le fournisseur, et dans un domaine autorisé si une
 *   liste est posée.
 *
 * La connexion unique n'ouvre que des comptes qui existent déjà, rattachés par
 * leur adresse : elle n'en crée jamais. Et un compte qui porte un second facteur
 * local le garde — le fournisseur ne le remplace pas.
 */

const DUREE_ETAT_MS = 10 * 60 * 1000;
const CACHE_MS = 60 * 60 * 1000;
const DECALAGE_S = 120;
const ALGOS = { RS256: 'RS256', ES256: 'ES256' };

const cache = new Map();

function normaliser(url) {
  return String(url || '').trim().replace(/\/+$/, '');
}

/** Un émetteur doit être servi en HTTPS ; seul un poste local y échappe, pour les essais. */
function issuerValide(url) {
  try {
    const u = new URL(url);
    if (u.username || u.password || u.hash || u.search) return false;
    if (u.protocol === 'https:') return true;
    return u.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(u.hostname);
  } catch {
    return false;
  }
}

function config() {
  const s = settings.all();
  return {
    enabled: s.sso_oidc_enabled === '1',
    issuer: normaliser(s.sso_oidc_issuer),
    clientId: String(s.sso_oidc_client_id || ''),
    clientSecret: s.sso_oidc_client_secret ? secrets.decrypt(s.sso_oidc_client_secret) : '',
    hasSecret: Boolean(s.sso_oidc_client_secret),
    label: String(s.sso_oidc_label || '') || 'SSO',
    domains: String(s.sso_oidc_domains || '').split(/[\s,;]+/).map((d) => d.trim().toLowerCase()).filter(Boolean),
  };
}

/** Ce que la page de connexion montre : un bouton, ou rien. */
function bouton() {
  const c = config();
  return c.enabled && c.issuer && c.clientId ? { label: c.label } : null;
}

function enregistrer({ enabled, issuer, clientId, clientSecret, label, domains }) {
  const iss = normaliser(issuer);
  if (enabled && !issuerValide(iss)) return { ok: false, reason: 'issuer' };
  if (iss && !issuerValide(iss)) return { ok: false, reason: 'issuer' };
  const id = String(clientId || '').trim();
  if (enabled && !id) return { ok: false, reason: 'client' };
  const valeurs = {
    sso_oidc_enabled: enabled ? '1' : '0',
    sso_oidc_issuer: iss,
    sso_oidc_client_id: id.slice(0, 255),
    sso_oidc_label: String(label || '').trim().slice(0, 60),
    sso_oidc_domains: String(domains || '').trim().slice(0, 500),
  };
  // Un champ de secret laissé vide garde le secret enregistré : il ne
  // s'affiche jamais, on ne le redemande donc pas à chaque réglage.
  if (String(clientSecret || '') !== '') valeurs.sso_oidc_client_secret = secrets.encrypt(String(clientSecret));
  if (enabled && !valeurs.sso_oidc_client_secret && !settings.get('sso_oidc_client_secret')) return { ok: false, reason: 'secret' };
  settings.setMany(valeurs);
  cache.clear();
  return { ok: true };
}

async function obtenirJson(url, options = {}) {
  const controle = new AbortController();
  const minuteur = setTimeout(() => controle.abort(), 10000);
  try {
    const res = await fetch(url, { ...options, signal: controle.signal, redirect: 'error' });
    const texte = await res.text();
    if (texte.length > 1024 * 1024) throw new Error('réponse trop grande');
    let corps = null;
    try { corps = JSON.parse(texte); } catch { corps = null; }
    return { status: res.status, corps };
  } finally {
    clearTimeout(minuteur);
  }
}

async function decouverte(issuer) {
  const cle = `decouverte:${issuer}`;
  const deja = cache.get(cle);
  if (deja && Date.now() - deja.t < CACHE_MS) return deja.v;
  const { status, corps } = await obtenirJson(`${issuer}/.well-known/openid-configuration`);
  if (status !== 200 || !corps) throw new Error('découverte');
  // L'émetteur annoncé doit être exactement celui qu'on a configuré : sinon un
  // document de découverte pourrait faire accepter les jetons d'un autre.
  if (normaliser(corps.issuer) !== issuer) throw new Error('émetteur');
  for (const champ of ['authorization_endpoint', 'token_endpoint', 'jwks_uri']) {
    if (typeof corps[champ] !== 'string' || !issuerValide(new URL(corps[champ]).origin)) throw new Error(champ);
  }
  cache.set(cle, { t: Date.now(), v: corps });
  return corps;
}

async function jeuDeCles(uri, { force = false } = {}) {
  const cle = `jwks:${uri}`;
  const deja = cache.get(cle);
  if (!force && deja && Date.now() - deja.t < CACHE_MS) return deja.v;
  const { status, corps } = await obtenirJson(uri);
  if (status !== 200 || !corps || !Array.isArray(corps.keys)) throw new Error('jwks');
  cache.set(cle, { t: Date.now(), v: corps.keys });
  return corps.keys;
}

const b64u = (buf) => Buffer.from(buf).toString('base64url');
const sha256 = (s) => crypto.createHash('sha256').update(s).digest();

/** Le départ vers le fournisseur : l'adresse, et ce que la session doit garder. */
async function demarrer(redirectUri) {
  const c = config();
  if (!bouton()) return { ok: false, reason: 'disabled' };
  const doc = await decouverte(c.issuer);
  const etat = {
    state: b64u(crypto.randomBytes(24)),
    nonce: b64u(crypto.randomBytes(24)),
    verifier: b64u(crypto.randomBytes(48)),
    emis: Date.now(),
  };
  const url = new URL(doc.authorization_endpoint);
  url.search = new URLSearchParams({
    response_type: 'code',
    client_id: c.clientId,
    redirect_uri: redirectUri,
    scope: 'openid email profile',
    state: etat.state,
    nonce: etat.nonce,
    code_challenge: b64u(sha256(etat.verifier)),
    code_challenge_method: 'S256',
  }).toString();
  return { ok: true, url: url.toString(), etat };
}

function egal(a, b) {
  const x = Buffer.from(String(a || ''));
  const y = Buffer.from(String(b || ''));
  return x.length === y.length && x.length > 0 && crypto.timingSafeEqual(x, y);
}

/** Vérifier un jeton d'identité contre le jeu de clés du fournisseur. */
async function verifierJeton(jwt, { jwksUri, issuer, clientId, nonce, maintenant = Date.now() }) {
  const parties = String(jwt || '').split('.');
  if (parties.length !== 3) return { ok: false, reason: 'format' };
  let entete;
  let charge;
  try {
    entete = JSON.parse(Buffer.from(parties[0], 'base64url').toString('utf8'));
    charge = JSON.parse(Buffer.from(parties[1], 'base64url').toString('utf8'));
  } catch {
    return { ok: false, reason: 'format' };
  }
  if (!ALGOS[entete.alg]) return { ok: false, reason: 'alg' };
  const ktyAttendu = entete.alg === 'RS256' ? 'RSA' : 'EC';
  const trouver = (cles) => cles.find((k) => k.kty === ktyAttendu && (!entete.kid || k.kid === entete.kid)
    && (!k.use || k.use === 'sig') && (!k.alg || k.alg === entete.alg));
  let jwk = trouver(await jeuDeCles(jwksUri));
  // Une clé inconnue peut être une rotation récente : on relit le jeu une fois.
  if (!jwk) jwk = trouver(await jeuDeCles(jwksUri, { force: true }));
  if (!jwk) return { ok: false, reason: 'kid' };
  let valide = false;
  try {
    const cle = crypto.createPublicKey({ key: jwk, format: 'jwk' });
    const donnees = Buffer.from(`${parties[0]}.${parties[1]}`);
    const signature = Buffer.from(parties[2], 'base64url');
    valide = entete.alg === 'RS256'
      ? crypto.verify('sha256', donnees, cle, signature)
      : crypto.verify('sha256', donnees, { key: cle, dsaEncoding: 'ieee-p1363' }, signature);
  } catch {
    valide = false;
  }
  if (!valide) return { ok: false, reason: 'signature' };

  const s = Math.floor(maintenant / 1000);
  if (normaliser(charge.iss) !== issuer) return { ok: false, reason: 'iss' };
  const audiences = Array.isArray(charge.aud) ? charge.aud : [charge.aud];
  if (!audiences.includes(clientId)) return { ok: false, reason: 'aud' };
  if (audiences.length > 1 && charge.azp !== clientId) return { ok: false, reason: 'azp' };
  if (typeof charge.exp !== 'number' || charge.exp + DECALAGE_S < s) return { ok: false, reason: 'exp' };
  if (typeof charge.iat === 'number' && charge.iat - DECALAGE_S > s) return { ok: false, reason: 'iat' };
  if (typeof charge.nbf === 'number' && charge.nbf - DECALAGE_S > s) return { ok: false, reason: 'nbf' };
  if (!egal(charge.nonce, nonce)) return { ok: false, reason: 'nonce' };
  return { ok: true, claims: charge };
}

/**
 * Le retour du fournisseur : l'état, l'échange du code, le jeton, l'adresse.
 * Rend l'adresse vérifiée ; c'est à l'appelant de trouver le compte.
 */
async function terminer({ code, state, etat, redirectUri }) {
  const c = config();
  if (!bouton()) return { ok: false, reason: 'disabled' };
  if (!etat || Date.now() - etat.emis > DUREE_ETAT_MS) return { ok: false, reason: 'expired' };
  if (!egal(state, etat.state)) return { ok: false, reason: 'state' };
  if (!code) return { ok: false, reason: 'code' };
  const doc = await decouverte(c.issuer);

  const corps = new URLSearchParams({
    grant_type: 'authorization_code', code: String(code), redirect_uri: redirectUri, code_verifier: etat.verifier,
  });
  const entetes = { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' };
  const methodes = Array.isArray(doc.token_endpoint_auth_methods_supported) ? doc.token_endpoint_auth_methods_supported : ['client_secret_basic'];
  if (methodes.includes('client_secret_basic') || !methodes.includes('client_secret_post')) {
    const id = encodeURIComponent(c.clientId);
    const secret = encodeURIComponent(c.clientSecret);
    entetes.Authorization = `Basic ${Buffer.from(`${id}:${secret}`).toString('base64')}`;
  } else {
    corps.set('client_id', c.clientId);
    corps.set('client_secret', c.clientSecret);
  }
  const { status, corps: jetons } = await obtenirJson(doc.token_endpoint, { method: 'POST', headers: entetes, body: corps.toString() });
  if (status !== 200 || !jetons || typeof jetons.id_token !== 'string') return { ok: false, reason: 'token' };

  const verdict = await verifierJeton(jetons.id_token, { jwksUri: doc.jwks_uri, issuer: c.issuer, clientId: c.clientId, nonce: etat.nonce });
  if (!verdict.ok) return verdict;
  const email = String(verdict.claims.email || '').trim().toLowerCase();
  if (!email) return { ok: false, reason: 'no-email' };
  if (verdict.claims.email_verified === false || verdict.claims.email_verified === 'false') return { ok: false, reason: 'unverified' };
  if (c.domains.length && !c.domains.includes(email.split('@').pop())) return { ok: false, reason: 'domain' };
  return { ok: true, email, sub: String(verdict.claims.sub || '') };
}

module.exports = { config, bouton, enregistrer, demarrer, terminer, verifierJeton, issuerValide, _cache: cache };
