const express = require('express');

const db = require('../db');
const payroll = require('../payroll');
const modules = require('../modules');
const { requireHR } = require('../middleware/auth');
const { setFlash, parseAmount } = require('../utils');

const router = express.Router();

// La paie est un sujet RH : elle suit les mêmes droits que le reste du dossier salarié.
router.use(modules.requireModule('paie'), requireHR);

const back = (anchor) => `/paie#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  const period = /^\d{4}-\d{2}$/.test(req.query.periode || '')
    ? req.query.periode
    : new Date().toISOString().slice(0, 7);

  const staff = db
    .prepare("SELECT * FROM users WHERE role = 'employee' AND contract_type != 'Freelance' ORDER BY last_name COLLATE NOCASE")
    .all();

  const simulationGross = Number(req.query.brut) || null;

  res.render('paie', {
    period,
    staff,
    rates: payroll.rates(),
    rateBases: payroll.RATE_BASES,
    ceiling: payroll.ceiling(),
    payslips: payroll.detailedPayslips(),
    linesOf: payroll.payslipLines,
    cost: payroll.payrollCost(period),
    simulationGross,
    simulation: simulationGross ? payroll.compute(simulationGross) : null,
    netOf: (gross) => payroll.compute(gross).net,
  });
});

// ---------- Barèmes ----------

router.post('/baremes', (req, res) => {
  const label = (req.body.label || '').trim().slice(0, 120);
  const base = (req.body.base || '').trim();
  const employeeRate = parseAmount(req.body.employee_rate || '0');
  const employerRate = parseAmount(req.body.employer_rate || '0');
  const sortOrder = Number(req.body.sort_order) || 0;

  if (!label) return fail(req, res, 'baremes', "L'intitulé de la cotisation est obligatoire.");

  const result = payroll.createRate({ label, base, employeeRate, employerRate, sortOrder });
  const messages = { 'bad-base': "Base invalide.", 'bad-rate': 'Taux invalide (0 à 100 %).' };
  if (!result.ok) return fail(req, res, 'baremes', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', 'Cotisation ajoutée au barème.');
  res.redirect(back('baremes'));
});

router.post('/baremes/:id/statut', (req, res) => {
  if (!payroll.toggleRate(Number(req.params.id))) return fail(req, res, 'baremes', 'Cotisation introuvable.');
  setFlash(req, 'success', 'Cotisation mise à jour.');
  res.redirect(back('baremes'));
});

router.post('/baremes/:id/supprimer', (req, res) => {
  payroll.deleteRate(Number(req.params.id));
  setFlash(req, 'success', 'Cotisation supprimée.');
  res.redirect(back('baremes'));
});

router.post('/plafond', (req, res) => {
  const value = parseAmount(req.body.ceiling || '');
  if (!Number.isFinite(value) || value <= 0 || value > 1e6) return fail(req, res, 'baremes', 'Plafond invalide.');

  payroll.setCeiling(Math.round(value * 100) / 100);
  setFlash(req, 'success', 'Plafond mis à jour.');
  res.redirect(back('baremes'));
});

// ---------- Salaires et bulletins ----------

router.post('/salaires/:id', (req, res) => {
  const gross = parseAmount(req.body.gross_salary || '');
  if (!Number.isFinite(gross) || gross < 0 || gross > 1e6) return fail(req, res, 'salaires', 'Salaire brut invalide.');

  payroll.setGrossSalary(Number(req.params.id), Math.round(gross * 100) / 100);
  setFlash(req, 'success', 'Salaire brut enregistré.');
  res.redirect(back('salaires'));
});

router.post('/bulletins', (req, res) => {
  const employeeId = Number(req.body.employee_id);
  const period = (req.body.period || '').trim();
  const gross = parseAmount(req.body.gross_salary || '');

  if (!/^\d{4}-\d{2}$/.test(period)) return fail(req, res, 'bulletins', 'Période invalide (AAAA-MM attendu).');

  const result = payroll.generatePayslip({
    employeeId, period, grossSalary: gross,
    note: (req.body.note || '').trim().slice(0, 300),
    createdBy: req.session.user.id,
  });

  const messages = {
    'no-employee': 'Membre introuvable.',
    freelance: "Un freelance est facturé, pas salarié : son bulletin n'a pas lieu d'être.",
    'bad-gross': 'Salaire brut invalide.',
    duplicate: 'Un bulletin existe déjà pour ce membre sur cette période.',
  };
  if (!result.ok) return fail(req, res, 'bulletins', messages[result.reason] || 'Calcul impossible.');

  setFlash(req, 'success', `Bulletin calculé : net à payer ${result.result.net.toFixed(2)} €, coût employeur ${result.result.employerCost.toFixed(2)} €.`);
  res.redirect(back('bulletins'));
});

/** Génère en une fois les bulletins de tous ceux dont le brut est renseigné. */
router.post('/bulletins/lot', (req, res) => {
  const period = (req.body.period || '').trim();
  if (!/^\d{4}-\d{2}$/.test(period)) return fail(req, res, 'bulletins', 'Période invalide (AAAA-MM attendu).');

  const staff = db
    .prepare("SELECT * FROM users WHERE role = 'employee' AND contract_type != 'Freelance' AND active = 1 AND gross_salary > 0")
    .all();

  let created = 0;
  let skipped = 0;
  for (const employee of staff) {
    const result = payroll.generatePayslip({
      employeeId: employee.id, period, grossSalary: employee.gross_salary,
      note: 'Génération en lot', createdBy: req.session.user.id,
    });
    if (result.ok) created++;
    else skipped++;
  }

  if (created === 0 && skipped === 0) return fail(req, res, 'bulletins', 'Aucun salarié avec un brut renseigné.');
  setFlash(req, 'success', `${created} bulletin(s) calculé(s)${skipped ? `, ${skipped} ignoré(s) (déjà émis)` : ''}.`);
  res.redirect(back('bulletins'));
});

module.exports = router;
