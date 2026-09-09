const db = require('../db');
const security = require('../security');

// Durée de vie absolue d'une session, indépendante de l'activité : au-delà, il
// faut se réauthentifier même devant un écran resté ouvert toute la journée.
const MAX_SESSION_HOURS = Number(process.env.SESSION_MAX_HOURS) || 12;

// Colonnes de droit relues à chaque requête. Liste fermée : le nom d'une colonne
// ne doit jamais venir d'ailleurs que d'ici, il entre dans le SQL.
const FLAG_COLUMNS = new Set(['is_hr', 'is_finance', 'is_it', 'is_referent']);

/**
 * Une session ne fige pas les droits qu'elle avait à la connexion.
 *
 * Sans ce contrôle, désactiver un compte, mettre fin à son contrat, le
 * verrouiller ou rétrograder un administrateur ne prend effet qu'à la
 * reconnexion : la personne garde son accès, et ses droits, pendant toute la
 * durée du cookie. Un départ de l'entreprise doit couper l'accès tout de suite.
 */
function revalidateSession(req, res, next) {
  if (!req.session || !req.session.user) return next();

  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
  const today = new Date().toISOString().slice(0, 10);
  const contractOver = Boolean(user && user.contract_end_date && user.contract_end_date < today);
  const openedAt = Number(req.session.openedAt) || 0;
  const tooOld = openedAt > 0 && Date.now() - openedAt > MAX_SESSION_HOURS * 60 * 60 * 1000;

  /**
   * Session de coffre-fort : ouverte précisément parce que le compte est fermé.
   * Le compte désactivé n'est donc pas un motif de révocation ici — seuls le
   * comptent la disparition du compte, son verrouillage, l'expiration de la
   * session, et la disparition du coffre lui-même.
   */
  if (req.session.vaultOnly) {
    if (!user || tooOld || security.isLocked(user) || !require('../vault').hasDocuments(user.id)) {
      return req.session.regenerate((err) => {
        if (err) return next(err);
        req.session.flash = { type: 'error', message: 'Accès au coffre-fort expiré. Reconnectez-vous.' };
        res.redirect('/connexion');
      });
    }
    req.currentUser = user;
    return next();
  }

  if (!user || !user.active || contractOver || tooOld || security.isLocked(user)) {
    if (user && user.active && contractOver) {
      db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(user.id);
    }
    // On régénère plutôt que de détruire : la session repart vierge, mais il
    // reste de quoi expliquer la déconnexion.
    return req.session.regenerate((err) => {
      if (err) return next(err);
      req.session.flash = tooOld
        ? { type: 'error', message: 'Session expirée pour raison de sécurité. Merci de vous reconnecter.' }
        : { type: 'error', message: "Votre accès a été révoqué ou suspendu. Rapprochez-vous de l'administration." };
      res.redirect('/connexion');
    });
  }

  // Les droits sont relus, pas rejoués depuis la session : une promotion, une
  // rétrogradation ou une désignation valent dès la requête suivante.
  req.session.user.role = user.role;
  req.session.user.email = user.email;
  req.session.user.firstName = user.first_name;
  req.session.user.lastName = user.last_name;
  req.session.user.grade = user.grade;
  req.session.user.isHr = Boolean(user.is_hr);
  req.session.user.avatarFile = user.avatar_file;

  // Mise à disposition des routeurs : évite de relire la même ligne plus loin.
  req.currentUser = user;
  next();
}

/**
 * Tant que le mot de passe temporaire est en place, rien d'autre n'est
 * accessible : il a été transmis de vive voix ou par courriel, hors de
 * l'application, et ne doit pas servir de mot de passe durable.
 */
const PASSWORD_CHANGE_ALLOWED = new Set(['/mon-profil/premier-acces', '/deconnexion', '/langue']);

function requirePasswordChange(req, res, next) {
  if (!req.session || !req.session.user || !req.currentUser) return next();
  // Un ancien salarié vient chercher ses bulletins, pas ouvrir une session de travail.
  if (req.session.vaultOnly) return next();
  if (!req.currentUser.must_change_password) return next();
  if (PASSWORD_CHANGE_ALLOWED.has(req.path)) return next();
  res.redirect('/mon-profil/premier-acces');
}

/**
 * Verrou de la session de coffre-fort : hors du coffre, rien n'est atteignable.
 * Posé en amont des routeurs, il ferme aussi ce qui serait ajouté demain sans
 * qu'on y pense.
 */
const VAULT_ALLOWED_PREFIXES = ['/coffre-fort', '/deconnexion', '/langue'];

function restrictToVault(req, res, next) {
  if (!req.session || !req.session.vaultOnly) return next();
  if (VAULT_ALLOWED_PREFIXES.some((prefix) => req.path === prefix || req.path.startsWith(`${prefix}/`))) return next();
  if (req.method !== 'GET') {
    return res.status(403).render('error', { message: 'Cet accès ne permet que la consultation de votre coffre-fort.' });
  }
  res.redirect('/coffre-fort');
}

function requireAuth(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  next();
}

function requireAdmin(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'admin') return res.status(403).render('error', { message: "Accès réservé à l'administration." });
  next();
}

function requireEmployee(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'employee') return res.redirect('/admin');
  next();
}

// Les rôles désignés par l'administration sont relus à chaque requête : une
// désignation prend effet immédiatement, sans que la personne ait à se reconnecter.
function hasFlag(req, column) {
  if (!FLAG_COLUMNS.has(column)) throw new Error(`Colonne de droit inconnue : ${column}`);
  const row = req.currentUser || db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
  return Boolean(row && row[column]);
}

// Accès à l'espace RH : administrateurs (supervision) et employés désignés RH par un administrateur.
function requireHR(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'admin' && !hasFlag(req, 'is_hr')) {
    return res.status(403).render('error', { message: "Accès réservé aux membres de l'équipe RH." });
  }
  next();
}

// Gestion administrative et financière : administrateurs et membres désignés par eux.
function requireFinance(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'admin' && !hasFlag(req, 'is_finance')) {
    return res.status(403).render('error', { message: "Accès réservé à la gestion administrative et financière." });
  }
  next();
}

// Service informatique : administrateurs et membres qu'ils ont désignés. Le parc
// logiciel, les accès applicatifs et les incidents du SI n'ont pas à être ouverts
// à la gestion financière, qui n'en répond pas.
function requireIt(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'admin' && !hasFlag(req, 'is_it')) {
    return res.status(403).render('error', { message: "Accès réservé au service informatique." });
  }
  next();
}

/**
 * Référent du dispositif d'alerte. Seul rôle du produit qui n'inclut pas
 * l'administration : un signalement peut viser un administrateur, et un canal
 * que la personne visée peut lire n'est pas un canal. L'administration désigne
 * les référents — cela, c'est tracé — mais ne lit pas les signalements.
 */
function requireReferent(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (!hasFlag(req, 'is_referent')) {
    return res.status(403).render('error', {
      message: "Les signalements ne sont lisibles que par les référents désignés du dispositif d'alerte.",
    });
  }
  next();
}

// Espace CSE : réservé aux salariés que le comité représente (ni administrateurs, ni freelances).
function requireCseMember(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');

  const cse = require('../cse');
  const user = req.currentUser;
  if (!user || !cse.isEligible(user)) {
    return res.status(403).render('error', { message: 'Le CSE représente les salariés de l\'entreprise ; cet espace ne vous est pas ouvert.' });
  }
  next();
}

// Gestion du CSE : réservée aux élus dont le mandat court encore.
function requireCseElected(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');

  const cse = require('../cse');
  if (!cse.isElected(req.session.user.id)) {
    return res.status(403).render('error', { message: 'Espace réservé aux membres élus du CSE.' });
  }
  next();
}

// Est manager quiconque encadre au moins une équipe ou un service : aucun rôle à gérer en plus.
function requireManager(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');

  const org = require('../org');
  if (!org.isManager(req.session.user.id)) {
    return res.status(403).render('error', { message: "Cet espace est réservé aux managers d'une équipe ou d'un service." });
  }
  next();
}

module.exports = { revalidateSession, requirePasswordChange, restrictToVault, requireAuth, requireAdmin, requireEmployee, requireHR, requireFinance, requireIt, requireReferent, requireManager, requireCseMember, requireCseElected };
