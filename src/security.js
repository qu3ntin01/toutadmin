const crypto = require('crypto');
const rateLimit = require('express-rate-limit');
const db = require('./db');
const logs = require('./logs');

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

function refuse(res, req = null) {
  // Un jeton refusé n'est pas toujours une attaque — une session expirée en
  // produit aussi. C'est justement pour pouvoir faire la différence, en
  // regardant s'il y en a un ou trois cents, que la ligne est journalisée.
  logs.warning('securite', 'csrf.refus', 'Requête refusée : jeton de session absent ou invalide.', {
    req,
    context: req ? { chemin: String(req.originalUrl || req.url || '').slice(0, 200), methode: req.method } : null,
  });
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

/*
 * La seule route postée sans jeton, et pourquoi.
 *
 * La réponse SAML arrive du fournisseur d'identité, par un formulaire qu'il
 * poste lui-même : elle ne peut pas porter le jeton d'une session qui, à cet
 * instant, n'existe pas encore — le cookie « Lax » ne voyage pas sur un POST
 * venu d'un autre site. Ce qui tient lieu de jeton est plus fort : une
 * assertion signée par le fournisseur, adressée à ce site, qui répond à une
 * demande émise ici il y a moins de dix minutes, consommée une seule fois
 * (src/saml.js).
 */
const SANS_JETON = new Set(['/connexion/saml/acs']);

function csrfMiddleware(req, res, next) {
  if (!req.session.csrfToken) {
    req.session.csrfToken = crypto.randomBytes(32).toString('hex');
  }
  res.locals.csrfToken = req.session.csrfToken;

  if (req.method === 'POST' && SANS_JETON.has(req.path)) return next();
  if (req.method === 'POST') {
    if (isMultipart(req)) {
      req.csrfDeferred = true;
      return next();
    }
    if (!tokenMatches(req.body ? req.body._csrf : null, req.session.csrfToken)) {
      return refuse(res, req);
    }
  }
  next();
}

// À placer immédiatement après le middleware d'upload d'une route multipart.
function verifyCsrf(req, res, next) {
  if (!tokenMatches(req.body ? req.body._csrf : null, req.session.csrfToken)) {
    return refuse(res, req);
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

/** Un plafond atteint ne se voit nulle part si personne ne l'écrit. */
const noteLimit = (event, message) => (req, res, next, options) => {
  logs.warning('securite', event, message, {
    req,
    context: { chemin: String(req.originalUrl || req.url || '').slice(0, 200), plafond: options.limit },
  });
  res.status(options.statusCode).send(options.message);
};

const loginLimiter = rateLimit({
  windowMs: 15 * 60 * 1000,
  limit: LOGIN_RATE_LIMIT,
  standardHeaders: true,
  legacyHeaders: false,
  message: 'Trop de tentatives de connexion depuis cette adresse. Merci de réessayer dans 15 minutes.',
  handler: noteLimit('plafond.connexion', 'Plafond de tentatives de connexion atteint pour cette adresse.'),
});

const globalLimiter = rateLimit({
  windowMs: 60 * 1000,
  limit: GLOBAL_RATE_LIMIT,
  standardHeaders: true,
  legacyHeaders: false,
  handler: noteLimit('plafond.requetes', 'Plafond de requêtes atteint pour cette adresse.'),
});

function isLocked(user) {
  return Boolean(user.locked_until && new Date(user.locked_until).getTime() > Date.now());
}

function registerFailedAttempt(user) {
  const attempts = user.failed_attempts + 1;
  if (attempts >= MAX_FAILED_ATTEMPTS) {
    const lockedUntil = new Date(Date.now() + LOCKOUT_MINUTES * 60 * 1000).toISOString();
    db.prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?').run(attempts, lockedUntil, user.id);
    logs.critical('authentification', 'compte.verrouille',
      `Compte verrouillé après ${attempts} échecs de connexion.`,
      { actor: user, context: { tentatives: attempts, jusqua: lockedUntil, minutes: LOCKOUT_MINUTES } });
  } else {
    db.prepare('UPDATE users SET failed_attempts = ? WHERE id = ?').run(attempts, user.id);
  }
}

function resetFailedAttempts(userId) {
  db.prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?').run(userId);
}

module.exports = {
  DUMMY_HASH,
  SANS_JETON,
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
