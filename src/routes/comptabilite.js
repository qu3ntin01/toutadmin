const express = require('express');

const accounting = require('../accounting');
const fec = require('../fec');
const db = require('../db');
const societes = require('../societes');
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
  const lettrageId = Number(req.query.lettrage) || null;
  const selectedAccount = accountId ? accounting.accounts().find((a) => a.id === accountId) || null : null;

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
    selectedAccount,
    // Le lettrage : les comptes de tiers, et celui qu'on regarde.
    lettrables: accounting.lettrables(),
    compteLettrage: lettrageId ? accounting.lettrables().find((a) => a.id === lettrageId) || null : null,
    lettrage: lettrageId ? accounting.lettrage(lettrageId) : null,
    ledger: selectedAccount ? accounting.ledger(selectedAccount.id, period) : [],
    // La clôture : jusqu'où la comptabilité est validée, ce qui reste au
    // brouillard, et si le FEC peut se nommer (il lui faut le SIREN).
    lockedUntil: accounting.lockedUntil(),
    drafts: accounting.draftCount(),
    siren: fec.siren((db.prepare('SELECT registration FROM companies WHERE id = ?').get(societes.courant()) || {}).registration),
    today: new Date().toISOString().slice(0, 10),
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
  if (!result.ok) return fail(req, res, 'plan', 'Compte introuvable.');

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
  if (!result.ok && result.reason === 'locked') {
    return fail(req, res, 'ecritures', `La période est validée jusqu'au ${result.lockedUntil} : saisissez l'écriture à une date postérieure.`);
  }

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
  const result = accounting.deleteEntry(Number(req.params.id));
  if (!result.ok) {
    const messages = {
      validated: 'Une écriture validée ne se supprime pas : contrepassez-la.',
      lettered: "Une ligne de l'écriture est lettrée : retirez d'abord ce lettrage.",
    };
    return fail(req, res, 'ecritures', messages[result.reason] || 'Écriture introuvable.');
  }
  setFlash(req, 'success', 'Écriture supprimée du brouillard.');
  res.redirect(back('ecritures'));
});

// Annuler une écriture validée par son écriture inverse, à une date ouverte.
router.post('/ecritures/:id/contrepasser', (req, res) => {
  const entryDate = (req.body.entry_date || '').trim();
  if (!isValidDateString(entryDate)) return fail(req, res, 'ecritures', 'Date de contrepassation invalide.');
  const result = accounting.reverseEntry(Number(req.params.id), entryDate, req.session.user.id);
  const messages = {
    'not-found': 'Écriture introuvable.',
    draft: 'Une écriture du brouillard se supprime ; seule une écriture validée se contrepasse.',
    'already-reversed': 'Cette écriture est déjà contrepassée.',
  };
  if (!result.ok) {
    if (result.reason === 'locked') {
      return fail(req, res, 'ecritures', `La période est validée jusqu'au ${result.lockedUntil} : choisissez une date postérieure.`);
    }
    return fail(req, res, 'ecritures', messages[result.reason] || 'Contrepassation impossible.');
  }
  setFlash(req, 'success', 'Contrepassation enregistrée au brouillard.');
  res.redirect(back('ecritures'));
});

// ---------- Clôture et FEC ----------

// ---------- Lettrage ----------

const versLettrage = (compte) => `/comptabilite?lettrage=${Number(compte) || ''}#lettrage`;

router.post('/lettrage', (req, res) => {
  const compte = Number(req.body.account_id);
  const result = accounting.lettrer(compte, req.body.lignes);
  if (!result.ok) {
    const messages = {
      account: 'Seul un compte de tiers (classe 4) se lettre.',
      selection: 'Cochez au moins deux lignes du compte.',
      already: 'Une des lignes est déjà lettrée.',
      unbalanced: `Les lignes cochées ne se soldent pas : il reste ${Math.abs(result.ecart || 0).toFixed(2)} € ${result.ecart > 0 ? 'au débit' : 'au crédit'}.`,
    };
    setFlash(req, 'error', messages[result.reason] || 'Lettrage impossible.');
    return res.redirect(versLettrage(compte));
  }
  setFlash(req, 'success', `${result.lignes} ligne(s) lettrée(s) sous le code ${result.code}.`);
  res.redirect(versLettrage(compte));
});

router.post('/lettrage/retirer', (req, res) => {
  const compte = Number(req.body.account_id);
  const code = String(req.body.code || '').trim().toUpperCase();
  const result = accounting.delettrer(compte, code);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? `Lettrage ${code} retiré.` : 'Lettrage introuvable.');
  res.redirect(versLettrage(compte));
});

// Reporter au 1er janvier les soldes de bilan de l'exercice clos.
router.post('/a-nouveaux', (req, res) => {
  const annee = Number(req.body.annee);
  const result = accounting.reporterANouveaux(annee, req.session.user.id);
  if (!result.ok) {
    const messages = {
      'bad-year': 'Année invalide.',
      'not-closed': `Validez d'abord l'exercice qui se termine, jusqu'au ${result.fin} : un solde reporté ne doit plus pouvoir changer.`,
      locked: `La comptabilité est validée jusqu'au ${result.lockedUntil} : le 1er janvier ${annee} est déjà fermé.`,
      already: `Les à-nouveaux de ${annee} existent déjà.`,
      empty: 'Aucun solde de bilan à reporter.',
      unbalanced: "Les soldes reportés ne s'équilibrent pas : une écriture de l'exercice est à vérifier.",
    };
    return fail(req, res, 'cloture', messages[result.reason] || 'À-nouveaux impossibles.');
  }
  setFlash(req, 'success', `À-nouveaux de ${annee} passés au brouillard : ${result.lignes} ligne(s), à relire puis valider.`);
  res.redirect(back('cloture'));
});

// Valider le brouillard jusqu'à une date : numéroter, puis verrouiller.
router.post('/valider', (req, res) => {
  const date = (req.body.date || '').trim();
  if (!isValidDateString(date)) return fail(req, res, 'cloture', 'Date de validation invalide.');
  const result = accounting.validateUntil(date);
  if (!result.ok) {
    return fail(req, res, 'cloture', result.reason === 'future'
      ? 'On ne valide pas une date à venir.'
      : `La comptabilité est déjà validée jusqu'au ${result.lockedUntil}.`);
  }
  setFlash(req, 'success', `${result.validated} écriture(s) validée(s) et numérotée(s) ; période verrouillée jusqu'au ${result.lockedUntil}.`);
  res.redirect(back('cloture'));
});

// Le fichier des écritures comptables, au format de l'article A47 A-1 du LPF.
router.get('/fec', (req, res) => {
  const from = (req.query.du || '').trim();
  const to = (req.query.au || '').trim();
  if (!isValidDateString(from) || !isValidDateString(to)) return fail(req, res, 'cloture', 'Période invalide.');
  const result = fec.generer({ from, to });
  if (!result.ok) {
    const messages = {
      'no-siren': "Le FEC porte le SIREN dans son nom : renseignez l'immatriculation de la société (écran Sociétés).",
      'bad-period': 'Période invalide.',
      drafts: `${result.drafts} écriture(s) de la période sont encore au brouillard : validez la période avant d'en tirer le FEC.`,
      unbalanced: "Une écriture de la période n'est pas équilibrée : le FEC n'est pas produit.",
    };
    return fail(req, res, 'cloture', messages[result.reason] || 'FEC impossible.');
  }
  res.setHeader('Content-Type', 'text/plain; charset=iso-8859-15');
  res.setHeader('Content-Disposition', `attachment; filename="${result.nom}"`);
  res.send(result.contenu);
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
