const express = require('express');

const db = require('../db');
const audit = require('../audit');
const modules = require('../modules');
const treasury = require('../treasury');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('tresorerie'), requireFinance);

const back = (anchor) => `/tresorerie#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

/** Un mouvement peut être négatif : c'est une sortie. Le signe porte le sens. */
function readSignedAmount(raw, { max = 1e9 } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: false };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || Math.abs(value) > max || value === 0) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

router.get('/', (req, res) => {
  const accountId = Number(req.query.compte) || null;
  res.render('tresorerie', {
    accountList: treasury.accounts(),
    total: treasury.totalBalance(),
    movements: treasury.transactions({ accountId }),
    pending: treasury.unreconciled(),
    openInvoices: db.prepare("SELECT * FROM invoices WHERE status != 'Payée' ORDER BY due_date IS NULL, due_date").all(),
    forecastList: treasury.forecasts(),
    projection: treasury.projection(),
    certainties: treasury.CERTAINTIES,
    selectedAccount: accountId,
    today: new Date().toISOString().slice(0, 10),
  });
});

router.post('/comptes', (req, res) => {
  const label = (req.body.label || '').trim();
  if (!label || label.length > 120) return fail(req, res, 'comptes', 'Intitulé de compte invalide.');

  const opening = (req.body.opening_balance || '').trim();
  const balance = opening ? parseAmount(opening) : 0;
  if (!Number.isFinite(balance) || Math.abs(balance) > 1e9) return fail(req, res, 'comptes', 'Solde initial invalide.');

  // Seuls les quatre derniers chiffres de l'IBAN sont conservés : le reste ne
  // sert à rien ici et n'a pas à traîner en base.
  const last4 = (req.body.iban_last4 || '').replace(/\D/g, '').slice(-4);

  treasury.createAccount({
    label, bank: (req.body.bank || '').trim().slice(0, 80),
    ibanLast4: last4, openingBalance: Math.round(balance * 100) / 100,
  });
  setFlash(req, 'success', 'Compte ajouté.');
  res.redirect(back('comptes'));
});

router.post('/comptes/:id/cloturer', (req, res) => {
  treasury.closeAccount(Number(req.params.id));
  setFlash(req, 'success', 'Compte clôturé : il sort des soldes sans perdre son historique.');
  res.redirect(back('comptes'));
});

router.post('/comptes/:id/supprimer', (req, res) => {
  const account = treasury.accountById(req.params.id);
  if (!account) return fail(req, res, 'comptes', 'Compte introuvable.');
  treasury.deleteAccount(account.id);
  audit.log(req, 'tresorerie.compte_supprime', 'bank_accounts', account.id, { libelle: account.label });
  setFlash(req, 'success', 'Compte supprimé, avec ses mouvements.');
  res.redirect(back('comptes'));
});

router.post('/mouvements', (req, res) => {
  const account = treasury.accountById(req.body.account_id);
  if (!account) return fail(req, res, 'mouvements', 'Compte introuvable.');

  const date = (req.body.value_date || '').trim();
  if (!isValidDateString(date)) return fail(req, res, 'mouvements', 'Date de valeur invalide.');

  const label = (req.body.label || '').trim();
  if (!label) return fail(req, res, 'mouvements', 'Libellé requis.');

  const amount = readSignedAmount(req.body.amount);
  if (!amount.ok) return fail(req, res, 'mouvements', 'Montant invalide : il doit être non nul, négatif pour une sortie.');

  treasury.addTransaction({
    accountId: account.id, valueDate: date, label: label.slice(0, 160),
    amount: amount.value, category: (req.body.category || '').trim().slice(0, 60),
  });
  setFlash(req, 'success', 'Mouvement enregistré.');
  res.redirect(back('mouvements'));
});

router.post('/mouvements/:id/supprimer', (req, res) => {
  treasury.deleteTransaction(Number(req.params.id));
  setFlash(req, 'success', 'Mouvement supprimé.');
  res.redirect(back('mouvements'));
});

router.post('/mouvements/:id/rapprocher', (req, res) => {
  const verdict = treasury.reconcile(Number(req.params.id), Number(req.body.invoice_id));
  if (!verdict.ok) return fail(req, res, 'rapprochement', verdict.message);
  audit.log(req, 'tresorerie.rapprochement', 'bank_transactions', Number(req.params.id), { facture: Number(req.body.invoice_id) });
  setFlash(req, 'success', 'Mouvement rapproché : la facture passe en payée.');
  res.redirect(back('rapprochement'));
});

router.post('/previsions', (req, res) => {
  const label = (req.body.label || '').trim();
  const date = (req.body.expected_on || '').trim();
  const amount = readSignedAmount(req.body.amount);

  if (!label) return fail(req, res, 'previsions', 'Libellé requis.');
  if (!isValidDateString(date)) return fail(req, res, 'previsions', 'Date invalide.');
  if (!amount.ok) return fail(req, res, 'previsions', 'Montant invalide.');
  if (!treasury.CERTAINTIES.includes(req.body.certainty)) return fail(req, res, 'previsions', 'Degré de certitude invalide.');

  treasury.addForecast({
    label: label.slice(0, 160), expectedOn: date, amount: amount.value,
    certainty: req.body.certainty, note: (req.body.note || '').trim().slice(0, 300),
  });
  setFlash(req, 'success', 'Échéance prévue enregistrée.');
  res.redirect(back('previsions'));
});

router.post('/previsions/:id/supprimer', (req, res) => {
  treasury.deleteForecast(Number(req.params.id));
  setFlash(req, 'success', 'Prévision retirée.');
  res.redirect(back('previsions'));
});

module.exports = router;
