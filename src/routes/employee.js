const express = require('express');

const db = require('../db');
const timesheet = require('../timesheet');
const hr = require('../hr');
const announcements = require('../announcements');
const org = require('../org');
const finance = require('../finance');
const resources = require('../resources');
const talent = require('../talent');
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

  res.render('employee', {
    employee,
    // Un salarié peut relever de plusieurs managers : ceux de son équipe et ceux de son service.
    managers: org.managersFor(employee),
    team: employee.team_id ? org.teamById(employee.team_id) : null,
    department: employee.department_id ? org.departmentById(employee.department_id) : null,
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
    // Ce que le salarié suit de son côté : frais, matériel, formations, documents, entretiens.
    myClaims: finance.claimsFor(employeeId),
    expenseCategories: finance.EXPENSE_CATEGORIES,
    myAssets: resources.assetsOf(employeeId),
    myTrainings: talent.registrations({ employeeId }),
    openSessions: talent.sessions().filter((session) => !['Terminée', 'Annulée'].includes(session.status)),
    myDocuments: talent.documentsFor(employeeId),
    myReviews: talent.reviews({ employeeId }),
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

// ---------- Notes de frais ----------

router.post('/frais', (req, res) => {
  const employee = currentEmployee(req);
  if (!employee) return res.redirect('/mon-espace');

  const spentOn = (req.body.spent_on || '').trim();
  const category = (req.body.category || '').trim();
  const amount = Number((req.body.amount || '').replace(',', '.'));

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/mon-espace#frais');
  };

  if (!isValidDateString(spentOn)) return fail('Date de dépense invalide.');
  if (spentOn > new Date().toISOString().slice(0, 10)) return fail('Une dépense ne peut pas être datée du futur.');
  if (!finance.EXPENSE_CATEGORIES.includes(category)) return fail('Catégorie invalide.');
  if (!Number.isFinite(amount) || amount <= 0 || amount > 100000) return fail('Montant invalide.');

  finance.createClaim({
    employeeId: employee.id,
    spentOn,
    category,
    description: (req.body.description || '').trim().slice(0, 500),
    amount: Math.round(amount * 100) / 100,
  });
  setFlash(req, 'success', 'Note de frais envoyée. Elle sera examinée par la gestion.');
  res.redirect('/mon-espace#frais');
});

router.post('/frais/:id/annuler', (req, res) => {
  if (!finance.cancelOwnClaim(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', "Cette note n'est plus retirable : elle a déjà été examinée.");
  } else {
    setFlash(req, 'success', 'Note de frais retirée.');
  }
  res.redirect('/mon-espace#frais');
});

// ---------- Formation ----------

router.post('/formations/:id/inscription', (req, res) => {
  const result = talent.requestSeat({ sessionId: Number(req.params.id), employeeId: req.session.user.id });
  const messages = {
    'not-found': 'Session introuvable.',
    closed: "Cette session n'accepte plus d'inscription.",
    'already-registered': 'Vous êtes déjà inscrit à cette session.',
  };

  if (!result.ok) setFlash(req, 'error', messages[result.reason] || 'Inscription impossible.');
  else setFlash(req, 'success', 'Demande envoyée. Les ressources humaines la confirmeront.');

  res.redirect('/mon-espace#formations');
});

router.post('/formations/:id/annuler', (req, res) => {
  if (!talent.cancelOwnRegistration(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', "Cette demande n'est plus annulable : elle a déjà été traitée.");
  } else {
    setFlash(req, 'success', 'Demande de formation retirée.');
  }
  res.redirect('/mon-espace#formations');
});

// ---------- Documents ----------

router.post('/documents/:id/accuser', (req, res) => {
  if (!talent.acknowledge(Number(req.params.id), req.session.user.id)) {
    setFlash(req, 'error', 'Document introuvable.');
  } else {
    setFlash(req, 'success', 'Accusé de réception enregistré.');
  }
  res.redirect('/mon-espace#documents');
});

// ---------- Entretien annuel ----------

router.post('/entretiens/:id/commentaire', (req, res) => {
  const comment = (req.body.employee_comment || '').trim().slice(0, 2000);
  if (!talent.addEmployeeComment(Number(req.params.id), req.session.user.id, comment)) {
    setFlash(req, 'error', "Cet entretien n'est pas le vôtre.");
  } else {
    setFlash(req, 'success', 'Votre commentaire est enregistré.');
  }
  res.redirect('/mon-espace#entretiens');
});

module.exports = router;
