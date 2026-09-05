const express = require('express');

const db = require('../db');
const audit = require('../audit');
const frontdesk = require('../frontdesk');
const { requireHR } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

// Le registre des visiteurs et celui du courrier tiennent des données
// personnelles de tiers : ils restent entre les mains de l'administration et
// des RH, comme les autres registres de l'entreprise.
router.use(requireHR);

const back = (anchor) => `/accueil#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

const text = (raw, max) => (raw || '').trim().slice(0, max);
const isTime = (value) => /^([01]\d|2[0-3]):[0-5]\d$/.test(value || '');

router.get('/', (req, res) => {
  const filterDay = isValidDateString(req.query.jour) ? req.query.jour : null;
  res.render('accueil', {
    stats: frontdesk.summary(),
    presentList: frontdesk.present(),
    visitorList: frontdesk.visitors({ day: filterDay }),
    filterDay,
    incoming: frontdesk.mail({ direction: 'Entrant' }),
    outgoing: frontdesk.mail({ direction: 'Sortant' }),
    mailKinds: frontdesk.MAIL_KINDS,
    mailDirections: frontdesk.MAIL_DIRECTIONS,
    employees: db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all(),
    today: new Date().toISOString().slice(0, 10),
    now: new Date().toTimeString().slice(0, 5),
  });
});

// ---------- Visiteurs ----------

router.post('/visiteurs', (req, res) => {
  const lastName = text(req.body.last_name, 120);
  if (!lastName) return fail(req, res, 'visiteurs', 'Le nom du visiteur est requis.');

  const visitedOn = isValidDateString(req.body.visited_on) ? req.body.visited_on : new Date().toISOString().slice(0, 10);
  const arrivedAt = isTime(req.body.arrived_at) ? req.body.arrived_at : new Date().toTimeString().slice(0, 5);

  const id = frontdesk.checkIn({
    firstName: text(req.body.first_name, 120),
    lastName,
    company: text(req.body.company, 160),
    purpose: text(req.body.purpose, 300),
    hostId: Number(req.body.host_id) || null,
    badge: text(req.body.badge, 40),
    notes: text(req.body.notes, 500),
    createdBy: req.session.user.id,
    visitedOn,
    arrivedAt,
  });
  audit.log(req, 'accueil.visiteur_entre', 'visitors', id, { nom: lastName, societe: text(req.body.company, 160) });
  setFlash(req, 'success', 'Visiteur inscrit au registre.');
  res.redirect(back('visiteurs'));
});

router.post('/visiteurs/:id/sortie', (req, res) => {
  const at = isTime(req.body.departed_at) ? req.body.departed_at : new Date().toTimeString().slice(0, 5);
  if (!frontdesk.checkOut(Number(req.params.id), at)) {
    return fail(req, res, 'visiteurs', 'Ce visiteur est déjà sorti, ou n\'existe pas.');
  }
  audit.log(req, 'accueil.visiteur_sorti', 'visitors', Number(req.params.id), { heure: at });
  setFlash(req, 'success', 'Sortie enregistrée.');
  res.redirect(back('visiteurs'));
});

router.post('/visiteurs/:id/supprimer', (req, res) => {
  frontdesk.deleteVisitor(Number(req.params.id));
  audit.log(req, 'accueil.visiteur_supprime', 'visitors', Number(req.params.id), {});
  setFlash(req, 'success', 'Visite retirée du registre.');
  res.redirect(back('visiteurs'));
});

// ---------- Courrier ----------

router.post('/courrier', (req, res) => {
  if (!frontdesk.MAIL_DIRECTIONS.includes(req.body.direction)) return fail(req, res, 'courrier', 'Sens inconnu.');
  if (!frontdesk.MAIL_KINDS.includes(req.body.kind)) return fail(req, res, 'courrier', 'Nature de courrier inconnue.');

  const loggedOn = isValidDateString(req.body.logged_on) ? req.body.logged_on : new Date().toISOString().slice(0, 10);
  const subject = text(req.body.subject, 300);
  const correspondent = text(req.body.correspondent, 200);
  if (!subject && !correspondent) return fail(req, res, 'courrier', 'Indiquez au moins un objet ou un correspondant.');

  const id = frontdesk.logMail({
    direction: req.body.direction,
    loggedOn,
    kind: req.body.kind,
    correspondent,
    recipientId: Number(req.body.recipient_id) || null,
    recipientLabel: text(req.body.recipient_label, 200),
    tracking: text(req.body.tracking, 80),
    subject,
    notes: text(req.body.notes, 500),
  });
  audit.log(req, 'accueil.courrier_enregistre', 'mail_items', id, { sens: req.body.direction, nature: req.body.kind });
  setFlash(req, 'success', 'Courrier enregistré.');
  res.redirect(back('courrier'));
});

router.post('/courrier/:id/remise', (req, res) => {
  if (!frontdesk.handOver(Number(req.params.id), req.session.user.id)) {
    return fail(req, res, 'courrier', 'Ce courrier a déjà été remis.');
  }
  audit.log(req, 'accueil.courrier_remis', 'mail_items', Number(req.params.id), {});
  setFlash(req, 'success', 'Remise enregistrée, datée et signée.');
  res.redirect(back('courrier'));
});

router.post('/courrier/:id/archiver', (req, res) => {
  frontdesk.archiveMail(Number(req.params.id));
  res.redirect(back('courrier'));
});

router.post('/courrier/:id/supprimer', (req, res) => {
  frontdesk.deleteMail(Number(req.params.id));
  audit.log(req, 'accueil.courrier_supprime', 'mail_items', Number(req.params.id), {});
  setFlash(req, 'success', 'Courrier retiré du registre.');
  res.redirect(back('courrier'));
});

module.exports = router;
