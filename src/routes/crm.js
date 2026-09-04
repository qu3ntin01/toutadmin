const express = require('express');

const db = require('../db');
const crm = require('../crm');
const finance = require('../finance');
const modules = require('../modules');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidEmail, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('crm'), requireFinance);

const back = (anchor) => `/crm#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  res.render('crm', {
    clients: finance.partners({ kind: 'Client' }),
    contacts: crm.contacts(),
    opportunities: crm.opportunities(),
    stages: crm.STAGES,
    openStages: crm.OPEN_STAGES,
    pipeline: crm.pipeline(),
    quotes: crm.quotes(),
    quoteStatuses: crm.QUOTE_STATUSES,
    activities: crm.activities(),
    activityKinds: crm.ACTIVITY_KINDS,
    employees: db.prepare("SELECT * FROM users WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE").all(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Contacts ----------

router.post('/contacts', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').trim().slice(0, 254);

  if (!firstName || !lastName) return fail(req, res, 'contacts', 'Prénom et nom sont obligatoires.');
  if (email && !isValidEmail(email)) return fail(req, res, 'contacts', 'Adresse email invalide.');

  const result = crm.createContact({
    partnerId: Number(req.body.partner_id),
    firstName, lastName, email,
    role: (req.body.role || '').trim().slice(0, 100),
    phone: (req.body.phone || '').trim().slice(0, 40),
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  if (!result.ok) return fail(req, res, 'contacts', 'Client introuvable.');

  setFlash(req, 'success', 'Contact enregistré.');
  res.redirect(back('contacts'));
});

router.post('/contacts/:id/supprimer', (req, res) => {
  crm.deleteContact(Number(req.params.id));
  setFlash(req, 'success', 'Contact supprimé.');
  res.redirect(back('contacts'));
});

// ---------- Opportunités ----------

router.post('/opportunites', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const amount = parseAmount(req.body.amount || '0');
  const probability = Number(req.body.probability);
  const expectedClose = (req.body.expected_close || '').trim();

  if (!title) return fail(req, res, 'pipeline', "L'intitulé de l'affaire est obligatoire.");
  if (expectedClose && !isValidDateString(expectedClose)) return fail(req, res, 'pipeline', 'Date de clôture prévue invalide.');

  const result = crm.createOpportunity({
    partnerId: Number(req.body.partner_id),
    title, amount, probability, expectedClose,
    ownerId: Number(req.body.owner_id) || null,
    notes: (req.body.notes || '').trim().slice(0, 2000),
  });

  const messages = {
    'no-partner': 'Client introuvable.',
    'bad-amount': 'Montant invalide.',
    'bad-probability': 'Probabilité invalide (0 à 100 %).',
  };
  if (!result.ok) return fail(req, res, 'pipeline', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', 'Affaire ajoutée au pipeline.');
  res.redirect(back('pipeline'));
});

router.post('/opportunites/:id/etape', (req, res) => {
  const result = crm.setStage(Number(req.params.id), (req.body.stage || '').trim());
  if (!result.ok) return fail(req, res, 'pipeline', 'Étape invalide ou affaire introuvable.');

  setFlash(req, 'success', 'Étape mise à jour.');
  res.redirect(back('pipeline'));
});

router.post('/opportunites/:id/supprimer', (req, res) => {
  crm.deleteOpportunity(Number(req.params.id));
  setFlash(req, 'success', 'Affaire supprimée.');
  res.redirect(back('pipeline'));
});

// ---------- Devis ----------

router.post('/devis', (req, res) => {
  const label = (req.body.label || '').trim().slice(0, 160);
  const issueDate = (req.body.issue_date || '').trim();
  const validUntil = (req.body.valid_until || '').trim();

  if (!label) return fail(req, res, 'devis', "L'intitulé du devis est obligatoire.");
  if (!isValidDateString(issueDate)) return fail(req, res, 'devis', "Date d'émission invalide.");
  if (validUntil && !isValidDateString(validUntil)) return fail(req, res, 'devis', 'Date de validité invalide.');

  const result = crm.createQuote({
    partnerId: Number(req.body.partner_id),
    opportunityId: Number(req.body.opportunity_id) || null,
    reference: (req.body.reference || '').trim().slice(0, 60),
    label, issueDate, validUntil,
    amountHt: parseAmount(req.body.amount_ht || '0'),
    vatRate: Number(req.body.vat_rate),
    createdBy: req.session.user.id,
  });

  const messages = {
    'no-partner': 'Client introuvable.',
    'bad-amount': 'Montant HT invalide.',
    'bad-vat': 'Taux de TVA invalide.',
    'bad-validity': "La date de validité précède l'émission.",
  };
  if (!result.ok) return fail(req, res, 'devis', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', 'Devis enregistré.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/statut', (req, res) => {
  if (!crm.setQuoteStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return fail(req, res, 'devis', 'Statut invalide ou devis introuvable.');
  }
  setFlash(req, 'success', 'Devis mis à jour.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/facturer', (req, res) => {
  const result = crm.convertToInvoice(Number(req.params.id), req.session.user.id);
  const messages = {
    'not-found': 'Devis introuvable.',
    'not-accepted': "Seul un devis accepté se transforme en facture.",
    'already-invoiced': 'Ce devis a déjà été facturé.',
  };
  if (!result.ok) return fail(req, res, 'devis', messages[result.reason] || 'Facturation impossible.');

  setFlash(req, 'success', 'Facture client créée depuis le devis. Elle est visible dans la gestion.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/supprimer', (req, res) => {
  crm.deleteQuote(Number(req.params.id));
  setFlash(req, 'success', 'Devis supprimé.');
  res.redirect(back('devis'));
});

// ---------- Relances ----------

router.post('/relances', (req, res) => {
  const dueOn = (req.body.due_on || '').trim();
  if (!isValidDateString(dueOn)) return fail(req, res, 'relances', "Date d'échéance invalide.");

  const result = crm.createActivity({
    partnerId: Number(req.body.partner_id) || null,
    opportunityId: Number(req.body.opportunity_id) || null,
    kind: (req.body.kind || 'Relance').trim(),
    dueOn,
    note: (req.body.note || '').trim().slice(0, 1000),
    ownerId: Number(req.body.owner_id) || req.session.user.id,
  });
  if (!result.ok) return fail(req, res, 'relances', 'Une relance vise un client ou une affaire.');

  setFlash(req, 'success', 'Relance planifiée.');
  res.redirect(back('relances'));
});

router.post('/relances/:id/faite', (req, res) => {
  if (!crm.completeActivity(Number(req.params.id))) return fail(req, res, 'relances', 'Relance introuvable ou déjà faite.');
  setFlash(req, 'success', 'Relance marquée comme faite.');
  res.redirect(back('relances'));
});

router.post('/relances/:id/supprimer', (req, res) => {
  crm.deleteActivity(Number(req.params.id));
  setFlash(req, 'success', 'Relance supprimée.');
  res.redirect(back('relances'));
});

module.exports = router;
