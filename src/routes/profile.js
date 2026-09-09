const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const i18n = require('../i18n');
const org = require('../org');
const security = require('../security');
const { avatarUpload, saveAvatar, removeAvatar } = require('../uploads');
const { requireAuth } = require('../middleware/auth');
const audit = require('../audit');
const twoFactor = require('../two-factor');
const settings = require('../settings');
const qrcode = require('qrcode');
const sessionStore = require('../session-store');
const { setFlash, checkPassword, MIN_PASSWORD_LENGTH } = require('../utils');

const router = express.Router();

router.use(requireAuth);

function currentUser(req) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
}

router.get('/', async (req, res) => {
  const user = currentUser(req);
  const state = twoFactor.stateOf(user);

  // Le QR n'est produit que pendant la mise en service, et jamais mis en cache :
  // il porte le secret.
  let qr = null;
  if (state.pending) {
    const uri = twoFactor.uri(user, settings.get('company_name'));
    qr = await qrcode.toDataURL(uri, { margin: 1, width: 220 });
  }

  // Retiré de la session avant le rendu, jamais après : res.render() peut
  // rendre la main une fois la réponse déjà envoyée, et express-session
  // décide alors d'enregistrer la session sans voir la suppression.
  const recoveryCodes = req.session.recoveryCodes || null;
  delete req.session.recoveryCodes;

  res.render('profile', {
    profile: user,
    managers: org.managersFor(user),
    team: user.team_id ? org.teamById(user.team_id) : null,
    department: user.department_id ? org.departmentById(user.department_id) : null,
    twoFactorState: state,
    twoFactorRequired: twoFactor.requiredFor(user),
    twoFactorSecret: state.pending ? user.totp_secret : null,
    twoFactorQr: qr,
    // Affichés une seule fois, juste après l'activation.
    recoveryCodes,
  });
});

// ---------- Double authentification ----------

router.post('/2fa/preparer', (req, res) => {
  const user = currentUser(req);
  if (user.totp_enabled) {
    setFlash(req, 'error', 'La double authentification est déjà active.');
    return res.redirect('/mon-profil');
  }
  twoFactor.beginEnrolment(user.id);
  setFlash(req, 'success', "Scannez le QR code, puis saisissez le code affiché pour confirmer.");
  res.redirect('/mon-profil#securite');
});

router.post('/2fa/activer', (req, res) => {
  const user = currentUser(req);
  const verdict = twoFactor.confirmEnrolment(user.id, req.body.code || '');
  if (!verdict.ok) {
    setFlash(req, 'error', verdict.message);
    return res.redirect('/mon-profil#securite');
  }

  req.session.recoveryCodes = verdict.recoveryCodes;
  req.auditHandled = true;
  audit.log(req, '2fa.activee', 'users', user.id);
  setFlash(req, 'success', 'Double authentification activée. Conservez les codes de secours ci-dessous.');
  res.redirect('/mon-profil#securite');
});

router.post('/2fa/desactiver', (req, res) => {
  const user = currentUser(req);
  // Le mot de passe est redemandé : désactiver le second facteur depuis une
  // session déjà ouverte serait sinon gratuit pour qui passe derrière un écran.
  if (!bcrypt.compareSync(req.body.current_password || '', user.password_hash)) {
    setFlash(req, 'error', 'Mot de passe incorrect.');
    return res.redirect('/mon-profil#securite');
  }
  if (twoFactor.requiredFor(user)) {
    setFlash(req, 'error', "La double authentification est exigée par l'entreprise pour votre rôle.");
    return res.redirect('/mon-profil#securite');
  }

  twoFactor.disable(user.id);
  req.auditHandled = true;
  audit.log(req, '2fa.desactivee', 'users', user.id);
  setFlash(req, 'success', 'Double authentification désactivée.');
  res.redirect('/mon-profil#securite');
});

router.post('/2fa/codes', (req, res) => {
  const user = currentUser(req);
  if (!user.totp_enabled) return res.redirect('/mon-profil#securite');

  req.session.recoveryCodes = twoFactor.regenerateRecoveryCodes(user.id);
  req.auditHandled = true;
  audit.log(req, '2fa.codes_regeneres', 'users', user.id);
  setFlash(req, 'success', 'Nouveaux codes de secours. Les précédents ne valent plus.');
  res.redirect('/mon-profil#securite');
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

  const fileName = saveAvatar(req.file);
  if (!fileName) {
    setFlash(req, 'error', "Ce fichier n'est pas une image : son contenu ne correspond pas au format annoncé.");
    return res.redirect('/mon-profil');
  }

  const user = currentUser(req);
  removeAvatar(user.avatar_file);
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

/**
 * Changement de mot de passe, partagé par le profil et la page de premier accès.
 * Rendu commun pour que la politique et la révocation des sessions s'appliquent
 * de la même façon, quel que soit le point d'entrée.
 */
function changePassword(req, res, { redirectTo, successMessage }) {
  const user = currentUser(req);
  const current = req.body.current_password || '';
  const next = req.body.new_password || '';
  const confirm = req.body.confirm_password || '';

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(redirectTo);
  };

  if (!bcrypt.compareSync(current, user.password_hash)) return fail('Mot de passe actuel incorrect.');
  if (next !== confirm) return fail('La confirmation ne correspond pas au nouveau mot de passe.');
  if (next === current) return fail("Le nouveau mot de passe doit être différent de l'actuel.");

  const verdict = checkPassword(next, { email: user.email, firstName: user.first_name, lastName: user.last_name });
  if (!verdict.ok) return fail(verdict.message);

  db.prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = datetime('now') WHERE id = ?")
    .run(bcrypt.hashSync(next, 12), user.id);

  // Un mot de passe changé doit fermer les sessions ouvertes ailleurs : c'est le
  // geste qu'on fait quand on soupçonne que quelqu'un d'autre est entré.
  sessionStore.store().revokeUser(user.id);
  req.auditHandled = true;
  audit.log(req, 'mot_de_passe.change', 'users', user.id);

  req.session.regenerate((err) => {
    if (err) return res.redirect('/connexion');
    req.session.openedAt = Date.now();
    req.session.flash = { type: 'success', message: successMessage };
    res.redirect('/connexion');
  });
  return undefined;
}

router.post('/mot-de-passe', (req, res) => changePassword(req, res, {
  redirectTo: '/mon-profil',
  successMessage: 'Mot de passe mis à jour. Les autres sessions ont été fermées : reconnectez-vous.',
}));

// Premier accès : tant que le mot de passe temporaire est en place, c'est la
// seule page accessible (voir requirePasswordChange).
router.get('/premier-acces', (req, res) => {
  if (!currentUser(req).must_change_password) return res.redirect('/mon-profil');
  res.render('first-access', { minPasswordLength: MIN_PASSWORD_LENGTH });
});

router.post('/premier-acces', (req, res) => changePassword(req, res, {
  redirectTo: '/mon-profil/premier-acces',
  successMessage: 'Mot de passe enregistré. Connectez-vous avec celui que vous venez de choisir.',
}));

module.exports = router;
