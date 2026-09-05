const db = require('./db');
const totp = require('./totp');
const settings = require('./settings');

/**
 * Double authentification : mise en service, vérification, codes de secours.
 *
 * Le secret n'est marqué actif qu'après qu'un premier code a été validé : sans
 * cette étape, une application mal configurée enfermerait la personne dehors.
 */

function stateOf(user) {
  return {
    enabled: Boolean(user.totp_enabled),
    pending: Boolean(user.totp_secret) && !user.totp_enabled,
    remainingCodes: db.prepare('SELECT COUNT(*) AS n FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL').get(user.id).n,
  };
}

/** Prépare un secret, sans encore l'activer. */
function beginEnrolment(userId) {
  const secret = totp.generateSecret();
  db.prepare('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?').run(secret, userId);
  return secret;
}

function uri(user, issuer) {
  return totp.otpauthUri({ secret: user.totp_secret, account: user.email, issuer: issuer || 'Salarié Member' });
}

/** Active la double authentification si le code saisi correspond au secret en attente. */
function confirmEnrolment(userId, code) {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
  if (!user || !user.totp_secret) return { ok: false, message: "Aucune mise en service n'est en cours." };
  if (!totp.verify(user.totp_secret, code)) return { ok: false, message: 'Code incorrect. Vérifiez l\'heure de votre téléphone.' };

  db.prepare('UPDATE users SET totp_enabled = 1 WHERE id = ?').run(userId);
  return { ok: true, recoveryCodes: regenerateRecoveryCodes(userId) };
}

/** Remplace les codes de secours ; les anciens cessent aussitôt d'être valables. */
function regenerateRecoveryCodes(userId) {
  const codes = totp.generateRecoveryCodes();
  const insert = db.prepare('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)');
  db.transaction(() => {
    db.prepare('DELETE FROM totp_recovery_codes WHERE user_id = ?').run(userId);
    for (const code of codes) insert.run(userId, totp.hashRecoveryCode(code));
  })();
  return codes;
}

/**
 * Vérifie un code de connexion : d'abord le code temporaire, puis, à défaut, un
 * code de secours — qui est alors consommé.
 */
function verifyLogin(user, submitted) {
  if (!user.totp_enabled) return { ok: true, usedRecovery: false };
  if (totp.verify(user.totp_secret, submitted)) return { ok: true, usedRecovery: false };

  const hash = totp.hashRecoveryCode(submitted);
  const match = db.prepare('SELECT id FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL').get(user.id, hash);
  if (!match) return { ok: false, usedRecovery: false };

  db.prepare("UPDATE totp_recovery_codes SET used_at = datetime('now') WHERE id = ?").run(match.id);
  return { ok: true, usedRecovery: true };
}

function disable(userId) {
  db.transaction(() => {
    db.prepare('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?').run(userId);
    db.prepare('DELETE FROM totp_recovery_codes WHERE user_id = ?').run(userId);
  })();
}

/**
 * L'instance peut exiger la double authentification des administrateurs : c'est
 * eux qui peuvent tout, leur compte est la cible qui vaut la peine.
 */
function requiredFor(user) {
  if (settings.get('require_2fa_all') === '1') return true;
  return user.role === 'admin' && settings.get('require_2fa_admin') === '1';
}

module.exports = { stateOf, beginEnrolment, uri, confirmEnrolment, regenerateRecoveryCodes, verifyLogin, disable, requiredFor };
