const crypto = require('crypto');

const install = require('./install');

/**
 * Chiffrement des secrets rangés en base.
 *
 * Un mot de passe FTP ou une clé de compte de service Google, écrits en clair
 * dans la table des réglages, feraient d'une copie de la base la clé de la
 * sauvegarde externalisée — donc de tout. La clé de chiffrement dérive du
 * secret de session, qui vit dans un fichier à part (data/session.key) : voler
 * la base seule ne suffit plus.
 *
 * AES-256-GCM : le déchiffrement échoue si le message a été modifié, ce qu'un
 * simple chiffrement ne dirait pas.
 */

const PREFIX = 'enc.v1:';

function key() {
  // Une dérivation, pas le secret brut : la clé de session sert déjà ailleurs.
  return crypto.hkdfSync('sha256', Buffer.from(install.sessionSecret()), Buffer.alloc(0), Buffer.from('secrets-instance'), 32);
}

function encrypt(plain) {
  const value = String(plain == null ? '' : plain);
  if (value === '') return '';

  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(key()), iv);
  const body = Buffer.concat([cipher.update(value, 'utf8'), cipher.final()]);
  return PREFIX + Buffer.concat([iv, cipher.getAuthTag(), body]).toString('base64');
}

/** Rend la valeur en clair, ou '' si elle est illisible — jamais une exception. */
function decrypt(stored) {
  const value = String(stored == null ? '' : stored);
  if (value === '') return '';
  if (!value.startsWith(PREFIX)) return value; // valeur écrite avant le chiffrement

  try {
    const raw = Buffer.from(value.slice(PREFIX.length), 'base64');
    const decipher = crypto.createDecipheriv('aes-256-gcm', Buffer.from(key()), raw.subarray(0, 12));
    decipher.setAuthTag(raw.subarray(12, 28));
    return Buffer.concat([decipher.update(raw.subarray(28)), decipher.final()]).toString('utf8');
  } catch {
    // Secret de session changé, ou valeur altérée : le secret est perdu, pas
    // deviné. L'appelant redemandera la saisie.
    return '';
  }
}

function isEncrypted(stored) {
  return typeof stored === 'string' && stored.startsWith(PREFIX);
}

/** Ce qu'on montre à l'écran : la présence d'un secret, jamais sa valeur. */
function mask(stored) {
  return decrypt(stored) ? '•••••••• (défini)' : '';
}

module.exports = { PREFIX, encrypt, decrypt, isEncrypted, mask };
