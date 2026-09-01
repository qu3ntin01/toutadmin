const express = require('express');

const db = require('../db');
const hr = require('../hr');
const requestTypes = require('../request-types');
const { requireHR } = require('../middleware/auth');
const { setFlash, parseAmount } = require('../utils');

const router = express.Router();

const STATUSES = ['En attente', 'Approuvée', 'Refusée', 'Annulée'];

// Espace RH : administrateurs (supervision) et employés désignés RH par un administrateur.
router.use(requireHR);

function managedStaff() {
  return db
    .prepare("SELECT * FROM users WHERE role = 'employee' AND contract_type != 'Freelance' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE")
    .all();
}

function findManagedEmployee(id) {
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  return employee && employee.contract_type !== 'Freelance' ? employee : null;
}

router.get('/', (req, res) => {
  const statusFilter = STATUSES.includes(req.query.statut) ? req.query.statut : null;
  const requests = hr.getAllRequests(statusFilter ? { status: statusFilter } : {});
  const staff = managedStaff();
  const payslips = hr.getAllPayslips();

  // Le compteur d'en-attente doit rester global, même quand la liste est filtrée.
  const pendingCount = db.prepare("SELECT COUNT(*) AS n FROM hr_requests WHERE status = 'En attente'").get().n;

  res.render('rh', {
    requests,
    statusFilter,
    staff,
    payslips,
    requestTypes,
    stats: {
      staffCount: staff.length,
      pendingCount,
      payslipsDue: payslips.filter((p) => p.status !== 'Payée').length,
      totalLeaveBalance: staff.reduce((sum, e) => sum + (e.leave_balance || 0), 0),
    },
  });
});

// ---------- Traitement des demandes ----------

router.post('/demandes/:id/approuver', (req, res) => {
  const note = (req.body.note || '').trim().slice(0, 500);
  const result = hr.approveRequest(Number(req.params.id), req.session.user.id, note);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? 'Demande approuvée.' : "Cette demande n'est plus en attente.");
  res.redirect('/rh#demandes');
});

router.post('/demandes/:id/refuser', (req, res) => {
  const note = (req.body.note || '').trim().slice(0, 500);
  const result = hr.rejectRequest(Number(req.params.id), req.session.user.id, note);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? 'Demande refusée.' : "Cette demande n'est plus en attente.");
  res.redirect('/rh#demandes');
});

router.post('/demandes/:id/annuler', (req, res) => {
  const note = (req.body.note || '').trim().slice(0, 500);
  const result = hr.revokeRequest(Number(req.params.id), req.session.user.id, note);
  setFlash(
    req,
    result.ok ? 'success' : 'error',
    result.ok ? 'Demande annulée, solde recrédité si nécessaire.' : 'Cette demande ne peut plus être annulée.'
  );
  res.redirect('/rh#demandes');
});

// ---------- Soldes de congés ----------

router.post('/solde/:id/ajuster', (req, res) => {
  const id = Number(req.params.id);
  const employee = findManagedEmployee(id);
  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/rh#personnel');
  };

  if (!employee) return fail('Membre introuvable ou non éligible.');

  const amount = parseAmount(req.body.amount);
  const reason = (req.body.reason || '').trim().slice(0, 300);
  if (!Number.isFinite(amount) || amount === 0 || Math.abs(amount) > 365) return fail('Ajustement invalide.');

  hr.adjustBalance(id, amount, reason, req.session.user.id);
  setFlash(req, 'success', `Solde de ${employee.first_name} ${employee.last_name} ajusté de ${amount > 0 ? '+' : ''}${amount} j.`);
  res.redirect('/rh#personnel');
});

// ---------- Fiches de paie ----------

router.post('/paie', (req, res) => {
  const employeeId = Number(req.body.employee_id);
  const period = (req.body.period || '').trim();
  const grossAmount = parseAmount(req.body.gross_amount);
  const netAmount = parseAmount(req.body.net_amount);
  const note = (req.body.note || '').trim().slice(0, 300);

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/rh#paie');
  };

  const employee = findManagedEmployee(employeeId);
  if (!employee) return fail('Membre introuvable ou non éligible.');
  if (!/^\d{4}-\d{2}$/.test(period)) return fail('Période invalide (format attendu : AAAA-MM).');
  if (!Number.isFinite(grossAmount) || !Number.isFinite(netAmount) || grossAmount < 0 || netAmount < 0 || netAmount > grossAmount) {
    return fail('Montants invalides (le net ne peut pas dépasser le brut).');
  }

  hr.createPayslip({ employeeId, period, grossAmount, netAmount, note, createdBy: req.session.user.id });
  setFlash(req, 'success', `Fiche de paie ${period} créée pour ${employee.first_name} ${employee.last_name}.`);
  res.redirect('/rh#paie');
});

router.post('/paie/:id/marquer-payee', (req, res) => {
  hr.markPayslipPaid(Number(req.params.id));
  setFlash(req, 'success', 'Fiche de paie marquée comme payée.');
  res.redirect('/rh#paie');
});

router.post('/paie/:id/supprimer', (req, res) => {
  hr.deletePayslip(Number(req.params.id));
  setFlash(req, 'success', 'Fiche de paie supprimée.');
  res.redirect('/rh#paie');
});

module.exports = router;
