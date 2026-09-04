const crypto = require('crypto');
const rateLimit = require('express-rate-limit');
const db = require('./db');

const MAX_FAILED_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

// Precomputed bcrypt hash of a random value — never matches a real password.
// Used to keep the login response time constant whether the email exists or not,
// so an attacker can't enumerate accounts by measuring response latency.
const DUMMY_HASH = '$2a$12$yztgRTN3RnAyNh3.buKzkuqYPz1ESHeRuOD4TFbLjE6MdyFLKeL5e';

function nonceMiddleware(req, res, next) {
  res.locals.nonce = crypto.randomBytes(16).toString('base64');
  next();
}

function tokenMatches(submitted, expected) {
  return (
    typeof submitted === 'string' &&
    typeof expected === 'string' &&
    submitted.length === expected.length &&
    crypto.timingSafeEqual(Buffer.from(submitted), Buffer.from(expected))
  );
}

function refuse(res) {
  return res.status(403).render('error', {
    message: 'Session expirée ou requête invalide. Merci de recharger la page et de réessayer.',
  });
}

// Vérification différée : sur un envoi multipart, le corps n'est décodé que par
// multer, à l'intérieur de la route. Le jeton n'y est donc pas encore lisible.
// La route doit alors se déclarer avec upload(), qui enchaîne l'uploader et
// verifyCsrf ; aucun fichier n'est écrit avant ce contrôle puisque les
// uploaders travaillent en mémoire.
function isMultipart(req) {
  const type = req.headers['content-type'] || '';
  return type.toLowerCase().startsWith('multipart/form-data');
}

function csrfMiddleware(req, res, next) {
  if (!req.session.csrfToken) {
    req.session.csrfToken = crypto.randomBytes(32).toString('hex');
  }
  res.locals.csrfToken = req.session.csrfToken;

  if (req.method === 'POST') {
    if (isMultipart(req)) {
      req.csrfDeferred = true;
      return next();
    }
    if (!tokenMatches(req.body ? req.body._csrf : null, req.session.csrfToken)) {
      return refuse(res);
    }
  }
  next();
}

// À placer immédiatement après le middleware d'upload d'une route multipart.
function verifyCsrf(req, res, next) {
  if (!tokenMatches(req.body ? req.body._csrf : null, req.session.csrfToken)) {
    return refuse(res);
  }
  req.csrfDeferred = false;
  next();
}

// Rend le couple indissociable : une route multipart se déclare avec
// ...security.upload(monUploader), jamais avec l'uploader seul, si bien qu'on ne
// peut pas déclarer un envoi de fichier sans son contrôle de jeton.
function upload(middleware) {
  return [middleware, verifyCsrf];
}

// Les plafonds restent configurables : la suite de tests joue des dizaines de
// connexions d'affilée et doit pouvoir desserrer la limite réseau sans la désactiver.
const LOGIN_RATE_LIMIT = Number(process.env.LOGIN_RATE_LIMIT) || 10;
const GLOBAL_RATE_LIMIT = Number(process.env.GLOBAL_RATE_LIMIT) || 300;

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: LOGIN_RATE_LIMIT,
  standardHeaders: true,
  legacyHeaders: false,
  message: 'Trop de tentatives de connexion depuis cette adresse. Merci de réessayer dans 15 minutes.',
});

const globalLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: GLOBAL_RATE_LIMIT,
  standardHeaders: true,
  legacyHeaders: false,
});

function isLocked(user) {
  return Boolean(user.locked_until && new Date(user.locked_until).getTime() > Date.now());
}

function registerFailedAttempt(user) {
  const attempts = user.failed_attempts + 1;
  if (attempts >= MAX_FAILED_ATTEMPTS) {
    const lockedUntil = new Date(Date.now() + LOCKOUT_MINUTES * 60 * 1000).toISOString();
    db.prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?').run(attempts, lockedUntil, user.id);
  } else {
    db.prepare('UPDATE users SET failed_attempts = ? WHERE id = ?').run(attempts, user.id);
  }
}

function resetFailedAttempts(userId) {
  db.prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?').run(userId);
}

module.exports = {
  DUMMY_HASH,
  nonceMiddleware,
  csrfMiddleware,
  verifyCsrf,
  upload,
  loginLimiter,
  globalLimiter,
  isLocked,
  registerFailedAttempt,
  resetFailedAttempts,
  LOCKOUT_MINUTES,
};
