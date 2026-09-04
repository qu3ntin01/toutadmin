const express = require('express');

const accounting = require('../accounting');
const finance = require('../finance');
const modules = require('../modules');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('comptabilite'), requireFinance);

const back = (anchor) => `/comptabilite#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  const year = Number(req.query.annee) || new Date().getUTCFullYear();
  const period = { from: `${year}-01-01`, to: `${year}-12-31` };
  const accountId = Number(req.query.compte) || null;

  res.render('comptabilite', {
    year,
    years: [year + 1, year, year - 1, year - 2],
    accounts: accounting.accounts(),
    accountKinds: accounting.ACCOUNT_KINDS,
    journals: accounting.journals(),
    entries: accounting.entries(),
    linesOf: accounting.linesOf,
    balance: accounting.balance(period),
    income: accounting.income(period),
    selectedAccount: accountId ? accounting.accounts().find((a) => a.id === accountId) : null,
    ledger: accountId ? accounting.ledger(accountId, period) : [],
    // Les factures pas encore passées en écriture : le lien entre gestion et compta.
    unposted: finance.invoices().filter((i) => i.status !== 'Annulée' && !accounting.entries({ limit: 5000 }).some((e) => e.invoice_id === i.id)),
  });
});

// ---------- Plan comptable ----------

router.post('/comptes', (req, res) => {
  const code = (req.body.code || '').trim().slice(0, 20);
  const label = (req.body.label || '').trim().slice(0, 160);
  const kind = (req.body.kind || '').trim();

  if (!/^\d{2,10}$/.test(code)) return fail(req, res, 'plan', 'Le numéro de compte doit être composé de 2 à 10 chiffres.');
  if (!label) return fail(req, res, 'plan', "L'intitulé du compte est obligatoire.");

  const result = accounting.createAccount({ code, label, kind });
  const messages = { 'bad-kind': 'Type de compte invalide.', duplicate: 'Ce numéro de compte existe déjà.' };
  if (!result.ok) return fail(req, res, 'plan', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', `Compte ${code} créé.`);
  res.redirect(back('plan'));
});

router.post('/comptes/:id/supprimer', (req, res) => {
  const result = accounting.deleteAccount(Number(req.params.id));
  setFlash(req, 'success', result.deactivated
    ? 'Compte mouvementé : il a été désactivé plutôt que supprimé.'
    : 'Compte supprimé.');
  res.redirect(back('plan'));
});

router.post('/journaux', (req, res) => {
  const code = (req.body.code || '').trim().toUpperCase().slice(0, 6);
  const label = (req.body.label || '').trim().slice(0, 120);

  if (!/^[A-Z0-9]{2,6}$/.test(code)) return fail(req, res, 'plan', 'Code journal invalide (2 à 6 caractères).');
  if (!label) return fail(req, res, 'plan', "L'intitulé du journal est obligatoire.");

  if (!accounting.createJournal({ code, label }).ok) return fail(req, res, 'plan', 'Ce code journal existe déjà.');
  setFlash(req, 'success', `Journal ${code} créé.`);
  res.redirect(back('plan'));
});

// ---------- Écritures ----------

router.post('/ecritures', (req, res) => {
  const journalId = Number(req.body.journal_id);
  const entryDate = (req.body.entry_date || '').trim();
  const label = (req.body.label || '').trim().slice(0, 160);

  if (!isValidDateString(entryDate)) return fail(req, res, 'ecritures', "Date d'écriture invalide.");
  if (!label) return fail(req, res, 'ecritures', "L'intitulé de l'écriture est obligatoire.");

  // Le formulaire envoie des colonnes parallèles : on les recompose en lignes.
  const accountIds = [].concat(req.body.account_id || []);
  const debits = [].concat(req.body.debit || []);
  const credits = [].concat(req.body.credit || []);
  const labels = [].concat(req.body.line_label || []);

  const lines = accountIds.map((accountId, index) => ({
    accountId,
    label: labels[index] || '',
    debit: parseAmount(debits[index] || '0'),
    credit: parseAmount(credits[index] || '0'),
  }));

  const result = accounting.createEntry({
    journalId, entryDate, label,
    reference: (req.body.reference || '').trim().slice(0, 60),
    lines,
    createdBy: req.session.user.id,
  });

  const messages = {
    'no-journal': 'Journal introuvable.',
    'too-few-lines': 'Une écriture demande au moins deux lignes mouvementées.',
    'both-sides': 'Une ligne porte un débit ou un crédit, pas les deux.',
    negative: 'Les montants ne peuvent pas être négatifs.',
    'no-account': 'Compte introuvable sur une des lignes.',
    empty: 'Une écriture à zéro ne veut rien dire.',
  };

  if (!result.ok) {
    if (result.reason === 'unbalanced') {
      return fail(req, res, 'ecritures',
        `Écriture déséquilibrée : ${result.totalDebit.toFixed(2)} € au débit contre ${result.totalCredit.toFixed(2)} € au crédit.`);
    }
    return fail(req, res, 'ecritures', messages[result.reason] || 'Écriture refusée.');
  }

  setFlash(req, 'success', 'Écriture enregistrée.');
  res.redirect(back('ecritures'));
});

router.post('/ecritures/:id/supprimer', (req, res) => {
  accounting.deleteEntry(Number(req.params.id));
  setFlash(req, 'success', 'Écriture supprimée.');
  res.redirect(back('ecritures'));
});

// Passe une facture de la gestion en écriture comptable.
router.post('/factures/:id/comptabiliser', (req, res) => {
  const invoice = finance.invoiceById(Number(req.params.id));
  if (!invoice) return fail(req, res, 'ecritures', 'Facture introuvable.');

  const result = accounting.entryFromInvoice(invoice, req.session.user.id);
  const messages = {
    'already-posted': 'Cette facture est déjà comptabilisée.',
    'no-journal': 'Journal des ventes ou des achats manquant.',
    'missing-accounts': 'Comptes 401/411, 606/706 ou TVA manquants dans le plan.',
  };
  if (!result.ok) return fail(req, res, 'ecritures', messages[result.reason] || 'Comptabilisation impossible.');

  setFlash(req, 'success', 'Facture comptabilisée.');
  res.redirect(back('ecritures'));
});

// ---------- Export ----------

router.get('/balance.csv', (req, res) => {
  const year = Number(req.query.annee) || new Date().getUTCFullYear();
  const csv = accounting.balanceCsv({ from: `${year}-01-01`, to: `${year}-12-31` });

  res.setHeader('Content-Type', 'text/csv; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="balance-${year}.csv"`);
  // Le BOM évite qu'un tableur ouvre les accents de travers.
  res.send('﻿' + csv);
});

module.exports = router;
