const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const security = require('../security');
const i18n = require('../i18n');
const { setFlash } = require('../utils');

const router = express.Router();

function homeFor(user) {
  return user.role === 'admin' ? '/admin' : '/mon-espace';
}

router.get('/', (req, res) => {
  if (!req.session.user) return res.redirect('/connexion');
  return res.redirect(homeFor(req.session.user));
});

router.get('/connexion', (req, res) => {
  if (req.session.user) return res.redirect(homeFor(req.session.user));
  res.render('login');
});

router.post('/connexion', security.loginLimiter, (req, res) => {
  const email = (req.body.email || '').toLowerCase().trim();
  const password = req.body.password || '';
  const genericError = () => {
    setFlash(req, 'error', 'Identifiants incorrects.');
    return res.redirect('/connexion');
  };

  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);

  if (!user) {
    bcrypt.compareSync(password, security.DUMMY_HASH); // temps constant : ne révèle pas l'existence du compte
    return genericError();
  }

  if (security.isLocked(user)) {
    setFlash(req, 'error', `Compte temporairement verrouillé suite à plusieurs échecs. Réessayez dans ${security.LOCKOUT_MINUTES} minutes.`);
    return res.redirect('/connexion');
  }

  const today = new Date().toISOString().slice(0, 10);
  if (user.contract_end_date && user.contract_end_date < today) {
    if (user.active) db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(user.id);
    setFlash(req, 'error', 'Ce compte est arrivé au terme de son contrat et a été désactivé.');
    return res.redirect('/connexion');
  }

  if (!user.active || !bcrypt.compareSync(password, user.password_hash)) {
    if (user.active) security.registerFailedAttempt(user);
    return genericError();
  }

  security.resetFailedAttempts(user.id);

  req.session.regenerate((err) => {
    if (err) return genericError();
    req.session.user = {
      id: user.id,
      role: user.role,
      email: user.email,
      firstName: user.first_name,
      lastName: user.last_name,
      grade: user.grade,
      isHr: Boolean(user.is_hr),
      locale: user.locale,
      avatarFile: user.avatar_file,
    };
    res.redirect(homeFor(user));
  });
});

router.post('/deconnexion', (req, res) => {
  req.session.destroy(() => res.redirect('/connexion'));
});

// Choix de la langue depuis l'écran de connexion : mémorisé en cookie tant qu'aucun
// compte n'est ouvert, puis repris par la préférence du compte une fois connecté.
router.post('/langue', (req, res) => {
  const locale = (req.body.locale || '').trim();
  const back = typeof req.body.retour === 'string' && req.body.retour.startsWith('/') ? req.body.retour : '/connexion';

  if (i18n.isSupported(locale)) {
    res.cookie('locale', locale, {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production',
      maxAge: 1000 * 60 * 60 * 24 * 365,
    });

    if (req.session.user) {
      db.prepare('UPDATE users SET locale = ? WHERE id = ?').run(locale, req.session.user.id);
      req.session.user.locale = locale;
    }
  }

  res.redirect(back);
});

module.exports = router;
