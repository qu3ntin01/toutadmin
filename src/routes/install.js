const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const install = require('../install');
const settings = require('../settings');
const i18n = require('../i18n');
const { setFlash, isValidEmail } = require('../utils');

const router = express.Router();

const STEPS = ['langue', 'prerequis', 'entreprise', 'administrateur', 'termine'];
const MIN_PASSWORD_LENGTH = 12;
const { version: APP_VERSION } = require('../../package.json');

function draft(req) {
  if (!req.session.install) req.session.install = {};
  return req.session.install;
}

function render(req, res, step, extra = {}) {
  res.render('install', {
    step,
    steps: STEPS,
    stepIndex: STEPS.indexOf(step),
    draft: draft(req),
    checks: step === 'prerequis' ? install.runChecks() : [],
    tokenRequired: install.tokenRequired(),
    version: APP_VERSION,
    ...extra,
  });
}

// L'assistant se referme définitivement dès que l'instance est installée.
router.use((req, res, next) => {
  if (install.isInstalled()) return res.redirect('/connexion');
  next();
});

router.get('/', (req, res) => res.redirect('/installation/langue'));

router.get('/:step', (req, res, next) => {
  const { step } = req.params;
  if (!STEPS.includes(step)) return next();

  // On ne saute pas d'étape : le récapitulatif exige les données des précédentes.
  const data = draft(req);
  if (step === 'administrateur' && !data.companyName) return res.redirect('/installation/entreprise');
  if (step === 'termine' && !data.adminEmail) return res.redirect('/installation/administrateur');

  render(req, res, step);
});

router.post('/langue', (req, res) => {
  const locale = (req.body.locale || '').trim();
  if (i18n.isSupported(locale)) {
    draft(req).locale = locale;
    res.cookie('locale', locale, { httpOnly: true, sameSite: 'lax', maxAge: 1000 * 60 * 60 * 24 * 365 });
  }
  res.redirect('/installation/prerequis');
});

router.post('/prerequis', (req, res) => {
  if (install.tokenRequired() && !install.tokenMatches(req.body.install_token)) {
    setFlash(req, 'error', "Jeton d'installation incorrect.");
    return res.redirect('/installation/prerequis');
  }

  const blocking = install.runChecks().filter((check) => check.blocking && !check.ok);
  if (blocking.length > 0) {
    setFlash(req, 'error', `Prérequis non satisfait : ${blocking.map((c) => c.label).join(', ')}.`);
    return res.redirect('/installation/prerequis');
  }

  draft(req).tokenAccepted = true;
  res.redirect('/installation/entreprise');
});

router.post('/entreprise', (req, res) => {
  const companyName = (req.body.company_name || '').trim().slice(0, 120);
  const leaveDays = Number(String(req.body.annual_leave_days || '').replace(',', '.'));

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/installation/entreprise');
  };

  if (!companyName) return fail("Le nom de l'organisation est obligatoire.");
  if (!Number.isFinite(leaveDays) || leaveDays < 0 || leaveDays > 365) {
    return fail('Nombre de jours de congés annuels invalide.');
  }

  Object.assign(draft(req), { companyName, leaveDays });
  res.redirect('/installation/administrateur');
});

router.post('/administrateur', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').toLowerCase().trim().slice(0, 254);
  const password = req.body.password || '';
  const confirm = req.body.confirm_password || '';

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/installation/administrateur');
  };

  if (!firstName || !lastName) return fail('Prénom et nom sont obligatoires.');
  if (!isValidEmail(email)) return fail('Adresse email invalide.');
  if (password.length < MIN_PASSWORD_LENGTH) return fail(`Le mot de passe doit faire au moins ${MIN_PASSWORD_LENGTH} caractères.`);
  if (password !== confirm) return fail('La confirmation ne correspond pas au mot de passe.');

  Object.assign(draft(req), { adminFirstName: firstName, adminLastName: lastName, adminEmail: email, adminPassword: password });
  res.redirect('/installation/termine');
});

// Dernière étape : tout est écrit d'un bloc, puis l'assistant se verrouille.
router.post('/terminer', (req, res) => {
  const data = draft(req);
  if (!data.companyName || !data.adminEmail || !data.adminPassword) {
    setFlash(req, 'error', "Installation incomplète : reprenez l'assistant depuis le début.");
    return res.redirect('/installation/langue');
  }

  if (install.hasAdmin()) {
    setFlash(req, 'error', 'Un administrateur existe déjà sur cette instance.');
    return res.redirect('/connexion');
  }

  const locale = i18n.isSupported(data.locale) ? data.locale : i18n.DEFAULT_LOCALE;
  const passwordHash = bcrypt.hashSync(data.adminPassword, 12);

  const commit = db.transaction(() => {
    db.prepare(`
      INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, locale, active)
      VALUES ('admin', ?, ?, ?, ?, 'Direction', 'Administration', ?, 1)
    `).run(data.adminEmail, passwordHash, data.adminFirstName, data.adminLastName, locale);

    settings.setMany({
      company_name: data.companyName,
      default_locale: locale,
      annual_leave_days: String(data.leaveDays ?? 25),
    });
  });
  commit();

  install.markInstalled(APP_VERSION);

  const adminEmail = data.adminEmail;
  delete req.session.install;

  // Le mot de passe ne transite plus : l'administrateur se connecte normalement.
  setFlash(req, 'success', `Installation terminée. Connectez-vous avec ${adminEmail}.`);
  res.redirect('/connexion');
});

module.exports = router;
module.exports.STEPS = STEPS;
