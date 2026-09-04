const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const i18n = require('../i18n');
const org = require('../org');
const security = require('../security');
const { avatarUpload, saveAvatar, removeAvatar } = require('../uploads');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();
const MIN_PASSWORD_LENGTH = 12;

router.use(requireAuth);

function currentUser(req) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
}

router.get('/', (req, res) => {
  const user = currentUser(req);
  res.render('profile', {
    profile: user,
    managers: org.managersFor(user),
    team: user.team_id ? org.teamById(user.team_id) : null,
    department: user.department_id ? org.departmentById(user.department_id) : null,
  });
});

// Présentation, téléphone et langue : les seuls champs que le membre pilote lui-même.
router.post('/informations', (req, res) => {
  const bio = (req.body.bio || '').trim().slice(0, 800);
  const phone = (req.body.phone || '').trim().slice(0, 40);
  const locale = (req.body.locale || '').trim();

  if (locale && !i18n.isSupported(locale)) {
    setFlash(req, 'error', 'Langue non prise en charge.');
    return res.redirect('/mon-profil');
  }

  const chosenLocale = locale || req.session.user.locale || i18n.DEFAULT_LOCALE;
  db.prepare('UPDATE users SET bio = ?, phone = ?, locale = ? WHERE id = ?')
    .run(bio, phone, chosenLocale, req.session.user.id);

  req.session.user.locale = chosenLocale;
  setFlash(req, 'success', 'Profil mis à jour.');
  res.redirect('/mon-profil');
});

// upload() enchaîne la réception du fichier puis le contrôle du jeton CSRF, que
// le corps multipart ne rend lisible qu'à ce moment-là.
router.post('/photo', ...security.upload(avatarUpload.single('avatar')), (req, res) => {
  if (!req.file) {
    setFlash(req, 'error', 'Image invalide : formats acceptés JPEG, PNG ou WebP, 2 Mo maximum.');
    return res.redirect('/mon-profil');
  }

  const user = currentUser(req);
  removeAvatar(user.avatar_file);
  const fileName = saveAvatar(req.file);
  db.prepare('UPDATE users SET avatar_file = ? WHERE id = ?').run(fileName, user.id);

  req.session.user.avatarFile = fileName;
  setFlash(req, 'success', 'Photo de profil mise à jour.');
  res.redirect('/mon-profil');
});

router.post('/photo/supprimer', (req, res) => {
  const user = currentUser(req);
  removeAvatar(user.avatar_file);
  db.prepare('UPDATE users SET avatar_file = NULL WHERE id = ?').run(user.id);

  req.session.user.avatarFile = null;
  setFlash(req, 'success', 'Photo de profil retirée.');
  res.redirect('/mon-profil');
});

router.post('/mot-de-passe', (req, res) => {
  const user = currentUser(req);
  const current = req.body.current_password || '';
  const next = req.body.new_password || '';
  const confirm = req.body.confirm_password || '';

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/mon-profil');
  };

  if (!bcrypt.compareSync(current, user.password_hash)) return fail('Mot de passe actuel incorrect.');
  if (next.length < MIN_PASSWORD_LENGTH) return fail(`Le nouveau mot de passe doit faire au moins ${MIN_PASSWORD_LENGTH} caractères.`);
  if (next !== confirm) return fail('La confirmation ne correspond pas au nouveau mot de passe.');
  if (next === current) return fail("Le nouveau mot de passe doit être différent de l'actuel.");

  db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(bcrypt.hashSync(next, 12), user.id);
  setFlash(req, 'success', 'Mot de passe mis à jour.');
  res.redirect('/mon-profil');
});

module.exports = router;
