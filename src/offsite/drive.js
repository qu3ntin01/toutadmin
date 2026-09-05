const crypto = require('crypto');

/**
 * Destination Google Drive, par compte de service.
 *
 * Pas de dépendance : l'échange tient en un jeton JWT signé et deux appels
 * HTTPS. Un compte de service convient mieux qu'un consentement utilisateur
 * pour une sauvegarde qui doit partir sans personne devant l'écran — il n'y a
 * pas de jeton de rafraîchissement à voir expirer ni de consentement à
 * renouveler.
 *
 * Un compte de service ne possède aucun espace : le dossier de destination doit
 * lui être partagé depuis un compte Drive, ce qui borne son accès à ce seul
 * dossier. C'est la propriété qu'on recherche.
 */

const TOKEN_URL = 'https://oauth2.googleapis.com/token';
const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';
const FILES_URL = 'https://www.googleapis.com/drive/v3/files';
const SCOPE = 'https://www.googleapis.com/auth/drive';

const DEFAULT_TIMEOUT_MS = 60000;
// Une marge : un jeton qui expire pendant l'envoi ferait échouer la sauvegarde.
const TOKEN_LIFETIME_S = 3600;
const TOKEN_MARGIN_MS = 60000;

function base64url(input) {
  return Buffer.from(input).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/**
 * La clé d'un compte de service arrive collée depuis un fichier JSON : les
 * retours à la ligne y sont souvent échappés en « \n ». On accepte les deux.
 */
function normalizeKey(privateKey) {
  return String(privateKey || '').replace(/\\n/g, '\n').trim();
}

function buildAssertion({ clientEmail, privateKey }, now = Date.now()) {
  const issued = Math.floor(now / 1000);
  const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const claims = base64url(JSON.stringify({
    iss: clientEmail,
    scope: SCOPE,
    aud: TOKEN_URL,
    iat: issued,
    exp: issued + TOKEN_LIFETIME_S,
  }));

  const signature = crypto.createSign('RSA-SHA256')
    .update(`${header}.${claims}`)
    .sign(normalizeKey(privateKey));

  return `${header}.${claims}.${base64url(signature)}`;
}

// Un jeton reste valable une heure : le regagner à chaque envoi serait inutile.
const tokenCache = new Map();

async function accessToken(config, { now = Date.now(), fetchImpl = fetch } = {}) {
  const cacheKey = config.clientEmail;
  const cached = tokenCache.get(cacheKey);
  if (cached && cached.expiresAt - TOKEN_MARGIN_MS > now) return { ok: true, token: cached.token };

  let response;
  try {
    response = await fetchImpl(TOKEN_URL, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        assertion: buildAssertion(config, now),
      }).toString(),
      signal: AbortSignal.timeout(DEFAULT_TIMEOUT_MS),
    });
  } catch (err) {
    return { ok: false, message: `Google inaccessible : ${err.message}` };
  }

  const body = await response.text();
  if (!response.ok) {
    return { ok: false, message: `Authentification refusée par Google (${response.status}) : ${body.slice(0, 300)}` };
  }

  let payload;
  try {
    payload = JSON.parse(body);
  } catch {
    return { ok: false, message: 'Réponse illisible du service de jetons Google.' };
  }
  if (!payload.access_token) return { ok: false, message: 'Google n\'a pas délivré de jeton.' };

  tokenCache.set(cacheKey, { token: payload.access_token, expiresAt: now + (payload.expires_in || TOKEN_LIFETIME_S) * 1000 });
  return { ok: true, token: payload.access_token };
}

/** Vide le cache de jetons : à faire dès que la configuration change. */
function forgetTokens() {
  tokenCache.clear();
}

async function call(config, url, options, deps) {
  const auth = await accessToken(config, deps);
  if (!auth.ok) return auth;

  try {
    const fetchImpl = (deps && deps.fetchImpl) || fetch;
    const response = await fetchImpl(url, {
      ...options,
      headers: { ...(options.headers || {}), authorization: `Bearer ${auth.token}` },
      signal: AbortSignal.timeout(DEFAULT_TIMEOUT_MS),
    });
    const text = await response.text();
    if (!response.ok) return { ok: false, message: `Drive a refusé (${response.status}) : ${text.slice(0, 300)}` };
    return { ok: true, body: text ? JSON.parse(text) : {} };
  } catch (err) {
    return { ok: false, message: err.message };
  }
}

/** Envoi en une requête « multipart/related » : métadonnées puis contenu. */
async function upload(config, fileName, buffer, deps = {}) {
  const boundary = `salarie-member-${crypto.randomBytes(12).toString('hex')}`;
  const metadata = JSON.stringify({ name: fileName, parents: [config.folderId] });

  const body = Buffer.concat([
    Buffer.from(`--${boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n${metadata}\r\n`),
    Buffer.from(`--${boundary}\r\nContent-Type: application/gzip\r\n\r\n`),
    buffer,
    Buffer.from(`\r\n--${boundary}--\r\n`),
  ]);

  const result = await call(config, `${UPLOAD_URL}?uploadType=multipart&supportsAllDrives=true&fields=id,name,size`, {
    method: 'POST',
    headers: { 'content-type': `multipart/related; boundary=${boundary}` },
    body,
  }, deps);

  if (!result.ok) return result;
  return { ok: true, message: `${fileName} déposé sur Drive (${buffer.length} octets).`, id: result.body.id };
}

async function list(config, { prefix = 'sauvegarde-' } = {}, deps = {}) {
  const query = `'${config.folderId}' in parents and trashed = false`;
  const url = `${FILES_URL}?q=${encodeURIComponent(query)}`
    + '&fields=files(id,name,size,createdTime)&orderBy=createdTime desc&pageSize=200'
    + '&supportsAllDrives=true&includeItemsFromAllDrives=true';

  const result = await call(config, url, { method: 'GET' }, deps);
  if (!result.ok) return result;

  return {
    ok: true,
    files: (result.body.files || [])
      .filter((file) => file.name.startsWith(prefix))
      .map((file) => ({ id: file.id, name: file.name, bytes: Number(file.size) || 0, modifiedAt: file.createdTime })),
  };
}

async function remove(config, fileId, deps = {}) {
  const result = await call(config, `${FILES_URL}/${encodeURIComponent(fileId)}?supportsAllDrives=true`, { method: 'DELETE' }, deps);
  return result.ok ? { ok: true } : result;
}

/** Vérifie l'authentification et l'accès en écriture au dossier. */
async function test(config, deps = {}) {
  const probe = await upload(config, `.essai-salarie-member-${Date.now()}.txt`, Buffer.from('essai'), deps);
  if (!probe.ok) return probe;

  const cleaned = await remove(config, probe.id, deps);
  return {
    ok: true,
    message: cleaned.ok
      ? 'Authentification acceptée, écriture et suppression vérifiées.'
      : `Écriture vérifiée, mais le fichier d'essai n'a pas pu être supprimé : ${cleaned.message}`,
  };
}

module.exports = {
  SCOPE, TOKEN_URL, UPLOAD_URL, FILES_URL,
  base64url, normalizeKey, buildAssertion, accessToken, forgetTokens,
  upload, list, remove, test,
};
