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

// Accès à l'espace RH : administrateurs (supervision) et employés désignés RH par un administrateur.
function requireHR(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');
  if (req.session.user.role !== 'admin' && !req.session.user.isHr) {
    return res.status(403).render('error', { message: "Accès réservé aux membres de l'équipe RH." });
  }
  next();
}

// Espace CSE : réservé aux salariés que le comité représente (ni administrateurs, ni freelances).
function requireCseMember(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');

  const db = require('../db');
  const cse = require('../cse');
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
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

module.exports = { requireAuth, requireAdmin, requireEmployee, requireHR, requireManager, requireCseMember, requireCseElected };
