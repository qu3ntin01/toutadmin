const crypto = require('crypto');

/**
 * Double authentification par code temporaire (TOTP, RFC 6238).
 *
 * Implémentée ici plutôt qu'empruntée : l'algorithme tient en quelques lignes,
 * et une dépendance de moins sur le chemin de l'authentification est une surface
 * d'attaque de moins.
 */

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const PERIOD = 30;
const DIGITS = 6;
// Une fenêtre de part et d'autre : les horloges dérivent, et l'utilisateur tape.
const TOLERANCE = 1;

function base32Encode(buffer) {
  let bits = 0;
  let value = 0;
  let output = '';
  for (const byte of buffer) {
    value = (value << 8) | byte;
    bits += 8;
    while (bits >= 5) {
      output += ALPHABET[(value >>> (bits - 5)) & 31];
      bits -= 5;
    }
  }
  if (bits > 0) output += ALPHABET[(value << (5 - bits)) & 31];
  return output;
}

function base32Decode(input) {
  const clean = String(input).toUpperCase().replace(/[^A-Z2-7]/g, '');
  let bits = 0;
  let value = 0;
  const bytes = [];
  for (const char of clean) {
    value = (value << 5) | ALPHABET.indexOf(char);
    bits += 5;
    if (bits >= 8) {
      bytes.push((value >>> (bits - 8)) & 0xff);
      bits -= 8;
    }
  }
  return Buffer.from(bytes);
}

/** 20 octets aléatoires, la taille recommandée pour HMAC-SHA1. */
function generateSecret() {
  return base32Encode(crypto.randomBytes(20));
}

function codeFor(secret, counter) {
  const key = base32Decode(secret);
  const buffer = Buffer.alloc(8);
  buffer.writeBigUInt64BE(BigInt(counter));

  const digest = crypto.createHmac('sha1', key).update(buffer).digest();
  const offset = digest[digest.length - 1] & 0x0f;
  const binary = ((digest[offset] & 0x7f) << 24) | (digest[offset + 1] << 16) | (digest[offset + 2] << 8) | digest[offset + 3];
  return String(binary % 10 ** DIGITS).padStart(DIGITS, '0');
}

function currentCode(secret, at = Date.now()) {
  return codeFor(secret, Math.floor(at / 1000 / PERIOD));
}

/**
 * Vérifie un code. La comparaison est à temps constant, et la fenêtre de
 * tolérance couvre une dérive d'horloge d'une période de part et d'autre.
 */
function verify(secret, submitted, at = Date.now()) {
  const code = String(submitted || '').replace(/\s/g, '');
  if (!/^\d{6}$/.test(code) || !secret) return false;

  const counter = Math.floor(at / 1000 / PERIOD);
  for (let drift = -TOLERANCE; drift <= TOLERANCE; drift++) {
    const expected = codeFor(secret, counter + drift);
    if (crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(code))) return true;
  }
  return false;
}

/** L'URI que lisent Google Authenticator, Authy, FreeOTP et les gestionnaires de mots de passe. */
function otpauthUri({ secret, account, issuer }) {
  const label = encodeURIComponent(`${issuer}:${account}`);
  const params = new URLSearchParams({ secret, issuer, algorithm: 'SHA1', digits: String(DIGITS), period: String(PERIOD) });
  return `otpauth://totp/${label}?${params.toString()}`;
}

/** Codes de secours : à usage unique, stockés hachés, pour un téléphone perdu. */
function generateRecoveryCodes(count = 8) {
  return Array.from({ length: count }, () => {
    const raw = crypto.randomBytes(5).toString('hex').toUpperCase();
    return `${raw.slice(0, 5)}-${raw.slice(5)}`;
  });
}

function hashRecoveryCode(code) {
  return crypto.createHash('sha256').update(String(code).toUpperCase().replace(/[^A-Z0-9]/g, '')).digest('hex');
}

module.exports = {
  PERIOD, DIGITS,
  base32Encode, base32Decode,
  generateSecret, currentCode, verify, otpauthUri,
  generateRecoveryCodes, hashRecoveryCode,
};
