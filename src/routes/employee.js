const express = require('express');

const db = require('../db');
const timesheet = require('../timesheet');
const hr = require('../hr');
const announcements = require('../announcements');
const requestTypes = require('../request-types');
const { requireEmployee } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

router.use(requireEmployee);

function currentEmployee(req) {
  return db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
}

router.get('/', (req, res) => {
  const employee = currentEmployee(req);
  const employeeId = employee.id;

  const tools = db.prepare(`
    SELECT t.*, a.assigned_at, a.note, a.username
    FROM assignments a
    JOIN tools t ON t.id = a.tool_id
    WHERE a.employee_id = ?
    ORDER BY a.assigned_at DESC
  `).all(employeeId);

  const isFreelance = employee.contract_type === 'Freelance';
  const hrEligible = hr.isEligibleForHrFeatures(employee);

  const manager = employee.manager_id
    ? db.prepare('SELECT id, first_name, last_name, email, grade, avatar_file FROM users WHERE id = ?').get(employee.manager_id)
    : null;

  res.render('employee', {
    employee,
    manager,
    news: announcements.forEmployee(employee),
    tools,
    isFreelance,
    openEntry: isFreelance ? timesheet.getOpenEntry(employeeId) : null,
    entries: isFreelance ? timesheet.getEntries(employeeId, 30) : [],
    statsData: isFreelance ? timesheet.getStats(employeeId, employee.daily_rate) : null,
    hrEligible,
    myRequests: hrEligible ? hr.getRequestsForEmployee(employeeId) : [],
    myPayslips: hrEligible ? hr.getPayslipsForEmployee(employeeId) : [],
    requestTypes,
  });
});

// ---------- Demandes RH (personnel non-freelance) ----------

router.post('/demandes', (req, res) => {
  const employee = currentEmployee(req);
  if (!employee || !hr.isEligibleForHrFeatures(employee)) return res.redirect('/mon-espace');

  const type = (req.body.type || '').trim();
  const startDate = (req.body.start_date || '').trim();
  const endDate = (req.body.end_date || '').trim();
  const reason = (req.body.reason || '').trim().slice(0, 500);

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/mon-espace');
  };

  if (!requestTypes.includes(type)) return fail('Type de demande invalide.');
  if (!isValidDateString(startDate) || !isValidDateString(endDate)) return fail('Dates invalides.');

  const days = hr.countBusinessDays(startDate, endDate);
  if (days === null || days === 0) {
    return fail('La période sélectionnée est invalide (la date de fin doit suivre la date de début, jours ouvrés).');
  }

  hr.createRequest({ employeeId: employee.id, type, startDate, endDate, days, reason });
  setFlash(req, 'success', `Demande envoyée (${days} jour${days > 1 ? 's' : ''} ouvré${days > 1 ? 's' : ''}).`);
  res.redirect('/mon-espace');
});

router.post('/demandes/:id/annuler', (req, res) => {
  const result = hr.cancelOwnRequest(Number(req.params.id), req.session.user.id);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? 'Demande annulée.' : 'Cette demande ne peut plus être annulée.');
  res.redirect('/mon-espace');
});

// ---------- Pointage (freelances uniquement) ----------

router.post('/pointage/commencer', (req, res) => {
  const employee = currentEmployee(req);
  if (!employee || employee.contract_type !== 'Freelance') return res.redirect('/mon-espace');

  if (!timesheet.clockIn(employee.id).ok) setFlash(req, 'error', 'Un pointage est déjà en cours.');
  res.redirect('/mon-espace');
});

router.post('/pointage/terminer', (req, res) => {
  const employee = currentEmployee(req);
  if (!employee || employee.contract_type !== 'Freelance') return res.redirect('/mon-espace');

  if (!timesheet.clockOut(employee.id).ok) setFlash(req, 'error', 'Aucun pointage en cours.');
  res.redirect('/mon-espace');
});

module.exports = router;
