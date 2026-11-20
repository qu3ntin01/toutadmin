const express = require('express');

const audit = require('../audit');
const whistleblow = require('../whistleblow');
const security = require('../security');
const { requireAuth, requireReferent } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

// ---------------------------------------------------------------- suivi public
//
// Le suivi d'un signalement anonyme ne peut pas passer par un compte : se
// connecter pour lire la réponse, c'est signer son signalement. Ces deux routes
// sont donc les seules du produit ouvertes sans session — protégées par la
// référence et le code, et par une limite de tentatives.

const followLimiter = security.loginLimiter;

function renderFollow(res, { report = null, error = null, reference = '', code = '' } = {}) {
  res.render('alerte-suivi', {
    report,
    messages: report ? whistleblow.messages(report.id) : [],
    followError: error,
    reference,
    code,
    ackDays: whistleblow.ACK_DAYS,
    outcomeDays: whistleblow.OUTCOME_DAYS,
  });
}

router.get('/suivi', (req, res) => renderFollow(res));

router.post('/suivi', followLimiter, (req, res) => {
  const reference = (req.body.reference || '').trim();
  const code = (req.body.code || '').trim();
  const report = whistleblow.openFollow(reference, code);
  // La trace automatique nommerait la référence consultée dans un journal que
  // toute l'administration lit : elle est neutralisée ici.
  req.auditHandled = true;

  if (!report) return renderFollow(res, { error: true, reference });
  renderFollow(res, { report, reference, code });
});

router.post('/suivi/message', followLimiter, (req, res) => {
  const reference = (req.body.reference || '').trim();
  const code = (req.body.code || '').trim();
  const report = whistleblow.openFollow(reference, code);
  req.auditHandled = true;

  if (!report) return renderFollow(res, { error: true, reference });
  whistleblow.addMessage({ reportId: report.id, kind: 'auteur', body: req.body.body });
  renderFollow(res, { report: whistleblow.byId(report.id), reference, code });
});

// ---------------------------------------------------------------- dépôt

router.get('/', requireAuth, (req, res) => {
  const isReferent = Boolean(req.currentUser && req.currentUser.is_referent);

  // Le code est retiré de la session *avant* le rendu, jamais après. Selon que
  // le gabarit est déjà en cache, res.render() rend la main avant ou après
  // l'envoi de la réponse — et express-session décide d'enregistrer la session
  // à cet envoi. Une suppression écrite après le rendu n'est donc tenue qu'une
  // fois sur deux, et jamais en production, où le cache est actif : le code
  // resterait affiché à chaque rechargement.
  const issued = req.session.whistleblowIssued || null;
  delete req.session.whistleblowIssued;

  res.render('alertes', {
    isReferent,
    reportList: isReferent ? whistleblow.list() : [],
    summary: isReferent ? whistleblow.summary() : null,
    overdue: isReferent ? whistleblow.overdue() : null,
    categories: whistleblow.CATEGORIES,
    ackDays: whistleblow.ACK_DAYS,
    outcomeDays: whistleblow.OUTCOME_DAYS,
    referentCount: whistleblow.referents().length,
    // Rendu une seule fois, au retour du dépôt : le code n'existe nulle part ailleurs.
    issued,
    daysSince: whistleblow.daysSince,
  });
});

router.post('/signalements', requireAuth, (req, res) => {
  // Un signalement anonyme ne doit pas être rattrapé par la trace automatique,
  // qui nomme l'auteur de toute requête modifiant quelque chose.
  req.auditHandled = true;

  const subject = (req.body.subject || '').trim().slice(0, 200);
  if (!subject) {
    setFlash(req, 'error', "L'objet du signalement est obligatoire.");
    return res.redirect('/alertes');
  }
  if (!whistleblow.CATEGORIES.includes(req.body.category)) {
    setFlash(req, 'error', 'Catégorie invalide.');
    return res.redirect('/alertes');
  }
  if (!whistleblow.referents().length) {
    setFlash(req, 'error', "Aucun référent n'est désigné : le dispositif n'est pas encore ouvert.");
    return res.redirect('/alertes');
  }

  const anonymous = req.body.anonymous === '1';
  const { reference, code } = whistleblow.create({
    authorId: req.session.user.id,
    anonymous,
    category: req.body.category,
    subject,
    body: (req.body.body || '').trim().slice(0, 10000),
  });

  // Le journal général retient qu'un signalement est arrivé, jamais lequel ni
  // de qui : le compte est nécessaire au dispositif, le détail lui nuirait.
  audit.logSystem('alerte.deposee', 'whistleblow_reports', null, { anonyme: anonymous });

  req.session.whistleblowIssued = { reference, code };
  res.redirect('/alertes');
});

// ---------------------------------------------------------------- instruction

router.get('/signalements/:id', requireAuth, requireReferent, (req, res) => {
  const report = whistleblow.byId(req.params.id);
  if (!report) return res.status(404).render('error', { message: 'Signalement introuvable.' });

  whistleblow.noteAccess(report.id, req.session.user.id);
  res.render('alerte', {
    report,
    messages: whistleblow.messages(report.id),
    accessList: whistleblow.accessLog(report.id),
    statuses: whistleblow.REPORT_STATUSES,
    ackDays: whistleblow.ACK_DAYS,
    outcomeDays: whistleblow.OUTCOME_DAYS,
    daysSince: whistleblow.daysSince,
  });
});

function withReport(req, res, action) {
  const report = whistleblow.byId(req.params.id);
  if (!report) {
    setFlash(req, 'error', 'Signalement introuvable.');
    return res.redirect('/alertes');
  }
  // Aucune action sur un signalement n'entre au journal général : elle y
  // désignerait le signalement, donc l'affaire, à des yeux qui n'y ont pas droit.
  req.auditHandled = true;
  action(report);
  res.redirect(`/alertes/signalements/${report.id}`);
}

router.post('/signalements/:id/accuser', requireAuth, requireReferent, (req, res) => {
  withReport(req, res, (report) => {
    whistleblow.acknowledge(report.id);
    setFlash(req, 'success', 'Accusé de réception enregistré.');
  });
});

router.post('/signalements/:id/statut', requireAuth, requireReferent, (req, res) => {
  withReport(req, res, (report) => {
    if (!whistleblow.setStatus(report.id, req.body.status)) {
      return setFlash(req, 'error', 'Statut invalide.');
    }
    setFlash(req, 'success', 'Statut mis à jour.');
  });
});

router.post('/signalements/:id/suites', requireAuth, requireReferent, (req, res) => {
  withReport(req, res, (report) => {
    whistleblow.setOutcome(report.id, req.body.outcome);
    setFlash(req, 'success', 'Suites données enregistrées.');
  });
});

router.post('/signalements/:id/message', requireAuth, requireReferent, (req, res) => {
  withReport(req, res, (report) => {
    if (!whistleblow.addMessage({ reportId: report.id, kind: 'referent', referentId: req.session.user.id, body: req.body.body })) {
      return setFlash(req, 'error', 'Message vide.');
    }
    setFlash(req, 'success', "Message transmis à l'auteur du signalement.");
  });
});

module.exports = router;
