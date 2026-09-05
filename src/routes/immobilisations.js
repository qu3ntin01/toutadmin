const express = require('express');

const audit = require('../audit');
const modules = require('../modules');
const treasury = require('../treasury');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('immobilisations'), requireFinance);

const back = (anchor) => `/immobilisations#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  res.render('immobilisations', {
    assetList: treasury.assets(),
    summary: treasury.assetSummary(),
    methods: treasury.DEPRECIATION_METHODS,
    today: new Date().toISOString().slice(0, 10),
    currentYear: new Date().getUTCFullYear(),
  });
});

router.post('/', (req, res) => {
  const label = (req.body.label || '').trim();
  if (!label || label.length > 160) return fail(req, res, 'registre', 'Intitulé invalide.');

  const acquired = (req.body.acquired_on || '').trim();
  if (!isValidDateString(acquired)) return fail(req, res, 'registre', "Date d'acquisition invalide.");

  const amount = parseAmount(req.body.amount || '');
  if (!Number.isFinite(amount) || amount <= 0 || amount > 1e9) return fail(req, res, 'registre', 'Montant invalide.');

  const duration = Number(req.body.duration_years);
  if (!Number.isFinite(duration) || duration < 1 || duration > 50) {
    return fail(req, res, 'registre', "La durée d'amortissement doit tenir entre 1 et 50 ans.");
  }
  if (!treasury.DEPRECIATION_METHODS.includes(req.body.method)) return fail(req, res, 'registre', 'Mode invalide.');

  const id = treasury.createAsset({
    label, category: (req.body.category || 'Matériel').trim().slice(0, 60),
    acquiredOn: acquired, amount: Math.round(amount * 100) / 100,
    durationYears: Math.round(duration), method: req.body.method,
    note: (req.body.note || '').trim().slice(0, 500),
  });
  audit.log(req, 'immobilisation.enregistree', 'fixed_assets', id, { libelle: label, montant: amount });
  setFlash(req, 'success', "Immobilisation enregistrée, avec son tableau d'amortissement.");
  res.redirect(back('registre'));
});

router.post('/:id/ceder', (req, res) => {
  const asset = treasury.assetById(req.params.id);
  if (!asset) return fail(req, res, 'registre', 'Immobilisation introuvable.');

  const on = (req.body.disposed_on || '').trim();
  if (!isValidDateString(on)) return fail(req, res, 'registre', 'Date de cession invalide.');
  if (on < asset.acquired_on) return fail(req, res, 'registre', "Une cession ne précède pas l'acquisition.");

  treasury.disposeAsset(asset.id, on);
  audit.log(req, 'immobilisation.cedee', 'fixed_assets', asset.id, { le: on });
  setFlash(req, 'success', 'Cession enregistrée.');
  res.redirect(back('registre'));
});

router.post('/:id/supprimer', (req, res) => {
  treasury.deleteAsset(Number(req.params.id));
  setFlash(req, 'success', 'Immobilisation supprimée.');
  res.redirect(back('registre'));
});

module.exports = router;
