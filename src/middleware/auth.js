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

// Est manager quiconque a au moins un collaborateur rattaché : aucun rôle à gérer en plus.
function requireManager(req, res, next) {
  if (!req.session.user) return res.redirect('/connexion');

  const db = require('../db');
  const reports = db.prepare('SELECT COUNT(*) AS n FROM users WHERE manager_id = ?').get(req.session.user.id).n;
  if (reports === 0) {
    return res.status(403).render('error', { message: "Cet espace est réservé aux managers ayant des collaborateurs rattachés." });
  }
  next();
}

module.exports = { requireAuth, requireAdmin, requireEmployee, requireHR, requireManager };
