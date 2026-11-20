const crypto = require('crypto');

const db = require('./db');
const cbor = require('./cbor');

/**
 * Les clés de sécurité et les clés d'accès (WebAuthn), en second facteur.
 *
 * Tout se vérifie ici, sans bibliothèque : la provenance (le défi émis par
 * cette session, l'origine, l'identifiant du site), la présence de la personne,
 * la signature de l'authentificateur, et son compteur — un compteur qui recule
 * trahit une clé copiée.
 *
 * Deux algorithmes sont acceptés, ceux que portent les clés du commerce :
 * ES256 (P-256) et RS256. L'attestation n'est pas demandée : elle dirait le
 * modèle de la clé, ce dont le produit n'a pas besoin, et elle exigerait une
 * liste de fabricants à tenir à jour.
 */

const ES256 = -7;
const RS256 = -257;
const DUREE_DEFI_MS = 5 * 60 * 1000;

const b64u = (buf) => Buffer.from(buf).toString('base64url');
const deB64u = (s) => Buffer.from(String(s || ''), 'base64url');

/** Le site tel que le navigateur le voit : son nom d'hôte, et son origine. */
function site(req) {
  const hote = String(req.get('host') || '');
  return { id: hote.replace(/:\d+$/, '').replace(/^\[|\]$/g, ''), origine: `${req.protocol}://${hote}` };
}

function defi() {
  return { valeur: b64u(crypto.randomBytes(32)), emis: Date.now() };
}

function poigneeDe(userId) {
  const u = db.prepare('SELECT webauthn_handle FROM users WHERE id = ?').get(userId);
  if (u && u.webauthn_handle) return u.webauthn_handle;
  const poignee = b64u(crypto.randomBytes(16));
  db.prepare('UPDATE users SET webauthn_handle = ? WHERE id = ? AND webauthn_handle IS NULL').run(poignee, userId);
  return db.prepare('SELECT webauthn_handle FROM users WHERE id = ?').get(userId).webauthn_handle;
}

function clesDe(userId) {
  return db.prepare('SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY id').all(userId);
}

function aDesCles(userId) {
  return Boolean(db.prepare('SELECT 1 AS ok FROM webauthn_credentials WHERE user_id = ?').get(userId));
}

/** Les options d'enregistrement, et le défi que la session doit garder. */
function optionsEnregistrement(user, s, nomDuSite) {
  const d = defi();
  return {
    defi: d,
    options: {
      challenge: d.valeur,
      rp: { id: s.id, name: nomDuSite },
      user: { id: poigneeDe(user.id), name: user.email, displayName: `${user.first_name || ''} ${user.last_name || ''}`.trim() || user.email },
      pubKeyCredParams: [{ type: 'public-key', alg: ES256 }, { type: 'public-key', alg: RS256 }],
      timeout: DUREE_DEFI_MS,
      attestation: 'none',
      authenticatorSelection: { userVerification: 'preferred', residentKey: 'discouraged' },
      excludeCredentials: clesDe(user.id).map((c) => ({ type: 'public-key', id: c.credential_id })),
    },
  };
}

function optionsConnexion(userId, s) {
  const d = defi();
  return {
    defi: d,
    options: {
      challenge: d.valeur,
      rpId: s.id,
      timeout: DUREE_DEFI_MS,
      userVerification: 'preferred',
      allowCredentials: clesDe(userId).map((c) => ({ type: 'public-key', id: c.credential_id })),
    },
  };
}

/** Le client a-t-il signé ce que nous attendions, là où nous l'attendions ? */
function lireClientData(brut, type, defiAttendu, s) {
  let client;
  try {
    client = JSON.parse(deB64u(brut).toString('utf8'));
  } catch {
    return { ok: false, reason: 'client-data' };
  }
  if (!client || client.type !== type) return { ok: false, reason: 'type' };
  if (!defiAttendu || Date.now() - defiAttendu.emis > DUREE_DEFI_MS) return { ok: false, reason: 'expired' };
  // Comparaison à temps constant : le défi est un secret de session.
  const recu = deB64u(client.challenge);
  const attendu = deB64u(defiAttendu.valeur);
  if (recu.length !== attendu.length || !crypto.timingSafeEqual(recu, attendu)) return { ok: false, reason: 'challenge' };
  if (client.origin !== s.origine) return { ok: false, reason: 'origin' };
  return { ok: true, hash: crypto.createHash('sha256').update(deB64u(brut)).digest() };
}

/** Les données d'authentification : site, drapeaux, compteur, et peut-être une clé. */
function lireAuthData(buf, s) {
  if (buf.length < 37) return { ok: false, reason: 'auth-data' };
  const rpIdHash = buf.subarray(0, 32);
  const attendu = crypto.createHash('sha256').update(s.id).digest();
  if (!crypto.timingSafeEqual(rpIdHash, attendu)) return { ok: false, reason: 'rp' };
  const flags = buf[32];
  if (!(flags & 0x01)) return { ok: false, reason: 'presence' };
  const compteur = buf.readUInt32BE(33);
  const sortie = { ok: true, flags, compteur };
  if (flags & 0x40) {
    if (buf.length < 55) return { ok: false, reason: 'auth-data' };
    const longueur = buf.readUInt16BE(53);
    if (longueur < 16 || longueur > 1023 || buf.length < 55 + longueur) return { ok: false, reason: 'auth-data' };
    sortie.credentialId = buf.subarray(55, 55 + longueur);
    try {
      sortie.cose = cbor.lire(buf, 55 + longueur).valeur;
    } catch {
      return { ok: false, reason: 'cose' };
    }
  }
  return sortie;
}

/** Une clé COSE en clé publique SPKI (PEM), avec son algorithme. */
function coseVersPem(cose) {
  if (!(cose instanceof Map)) return null;
  const kty = cose.get(1);
  const alg = cose.get(3);
  try {
    if (kty === 2 && alg === ES256 && cose.get(-1) === 1) {
      const x = cose.get(-2);
      const y = cose.get(-3);
      if (!Buffer.isBuffer(x) || !Buffer.isBuffer(y) || x.length !== 32 || y.length !== 32) return null;
      const cle = crypto.createPublicKey({ key: { kty: 'EC', crv: 'P-256', x: b64u(x), y: b64u(y) }, format: 'jwk' });
      return { pem: cle.export({ type: 'spki', format: 'pem' }), alg };
    }
    if (kty === 3 && alg === RS256) {
      const n = cose.get(-1);
      const e = cose.get(-2);
      if (!Buffer.isBuffer(n) || !Buffer.isBuffer(e) || n.length < 256) return null;
      const cle = crypto.createPublicKey({ key: { kty: 'RSA', n: b64u(n), e: b64u(e) }, format: 'jwk' });
      return { pem: cle.export({ type: 'spki', format: 'pem' }), alg };
    }
  } catch {
    return null;
  }
  return null;
}

function verifierEnregistrement({ clientData, attestation, defi: d, site: s }) {
  const client = lireClientData(clientData, 'webauthn.create', d, s);
  if (!client.ok) return client;
  let objet;
  try {
    objet = cbor.lire(deB64u(attestation)).valeur;
  } catch {
    return { ok: false, reason: 'attestation' };
  }
  if (!(objet instanceof Map) || !Buffer.isBuffer(objet.get('authData'))) return { ok: false, reason: 'attestation' };
  const auth = lireAuthData(objet.get('authData'), s);
  if (!auth.ok) return auth;
  if (!auth.credentialId) return { ok: false, reason: 'no-credential' };
  const cle = coseVersPem(auth.cose);
  if (!cle) return { ok: false, reason: 'algorithm' };
  return { ok: true, credentialId: b64u(auth.credentialId), pem: cle.pem, alg: cle.alg, compteur: auth.compteur };
}

function enregistrer(userId, nom, verdict) {
  if (db.prepare('SELECT 1 AS ok FROM webauthn_credentials WHERE credential_id = ?').get(verdict.credentialId)) {
    return { ok: false, reason: 'duplicate' };
  }
  const id = db.prepare(`
    INSERT INTO webauthn_credentials (user_id, credential_id, public_key, algorithm, sign_count, name)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(userId, verdict.credentialId, verdict.pem, verdict.alg, verdict.compteur, String(nom || '').trim().slice(0, 60) || 'Clé de sécurité').lastInsertRowid;
  return { ok: true, id };
}

/** Vérifier une connexion : la clé doit être à cette personne, et signer ce défi. */
function verifierConnexion({ userId, credentialId, clientData, authenticatorData, signature, defi: d, site: s }) {
  const cle = db.prepare('SELECT * FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?').get(String(credentialId || ''), userId);
  if (!cle) return { ok: false, reason: 'unknown-key' };
  const client = lireClientData(clientData, 'webauthn.get', d, s);
  if (!client.ok) return client;
  const authBrut = deB64u(authenticatorData);
  const auth = lireAuthData(authBrut, s);
  if (!auth.ok) return auth;
  const donnees = Buffer.concat([authBrut, client.hash]);
  let valide = false;
  try {
    valide = cle.algorithm === ES256
      ? crypto.verify('sha256', donnees, { key: cle.public_key, dsaEncoding: 'der' }, deB64u(signature))
      : crypto.verify('sha256', donnees, cle.public_key, deB64u(signature));
  } catch {
    valide = false;
  }
  if (!valide) return { ok: false, reason: 'signature' };
  // Un compteur qui ne monte pas alors qu'il montait : la clé a été copiée.
  if ((auth.compteur !== 0 || cle.sign_count !== 0) && auth.compteur <= cle.sign_count) return { ok: false, reason: 'counter' };
  db.prepare("UPDATE webauthn_credentials SET sign_count = ?, last_used_at = datetime('now') WHERE id = ?").run(auth.compteur, cle.id);
  return { ok: true, cleId: cle.id };
}

function supprimer(userId, id) {
  return db.prepare('DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?').run(Number(id) || 0, userId).changes > 0;
}

module.exports = {
  ES256, RS256, site, clesDe, aDesCles, optionsEnregistrement, optionsConnexion,
  verifierEnregistrement, enregistrer, verifierConnexion, supprimer, coseVersPem,
};
