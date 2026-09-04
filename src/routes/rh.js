const express = require('express');

const db = require('../db');
const hr = require('../hr');
const cse = require('../cse');
const timesheet = require('../timesheet');
const requestTypes = require('../request-types');
const { requireHR } = require('../middleware/auth');
const { setFlash, parseAmount, isValidDateString } = require('../utils');

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

  // La rémunération des freelances est un sujet RH : elle vit ici, plus dans la console admin.
  const freelancers = db
    .prepare("SELECT * FROM users WHERE role = 'employee' AND contract_type = 'Freelance' ORDER BY last_name COLLATE NOCASE")
    .all();

  const elections = cse.elections();
  const cseMandates = cse.mandates();

  res.render('rh', {
    requests,
    statusFilter,
    staff,
    payslips,
    requestTypes,
    freelancers,
    freelanceStats: Object.fromEntries(freelancers.map((e) => [e.id, timesheet.getStats(e.id, e.daily_rate)])),
    openEntriesMap: Object.fromEntries(freelancers.map((e) => [e.id, Boolean(timesheet.getOpenEntry(e.id))])),
    cse: {
      mandates: cseMandates,
      mandateRoles: cse.MANDATE_ROLES,
      elections,
      // Chaque élection porte ses candidatures, son taux de participation et, une fois close, ses résultats.
      candidaciesByElection: Object.fromEntries(elections.map((e) => [e.id, cse.candidacies(e.id)])),
      turnoutByElection: Object.fromEntries(elections.map((e) => [e.id, cse.turnout(e.id)])),
      resultsByElection: Object.fromEntries(elections.map((e) => [e.id, cse.results(e.id)])),
      meetings: cse.meetings(),
      // Seuls les salariés représentés par le comité peuvent y siéger.
      eligible: staff.filter((e) => e.active && !cseMandates.some((m) => m.user_id === e.id)),
      pendingCandidacies: elections.reduce(
        (n, e) => n + cse.candidacies(e.id).filter((c) => c.status === 'En attente').length,
        0
      ),
    },
    stats: {
      staffCount: staff.length,
      pendingCount,
      payslipsDue: payslips.filter((p) => p.status !== 'Payée').length,
      totalLeaveBalance: staff.reduce((sum, e) => sum + (e.leave_balance || 0), 0),
    },
  });
});

// ---------- CSE : mandats, élections, réunions ----------

router.post('/cse/mandats', (req, res) => {
  const userId = Number(req.body.user_id);
  const mandateRole = (req.body.mandate_role || '').trim();
  const startedOn = (req.body.started_on || '').trim();
  const endsOn = (req.body.ends_on || '').trim();

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/rh#cse');
  };

  const employee = findManagedEmployee(userId);
  if (!employee) return fail('Membre introuvable ou non représenté par le CSE.');
  if (!cse.MANDATE_ROLES.includes(mandateRole)) return fail('Rôle de mandat invalide.');
  if (!isValidDateString(startedOn)) return fail('Date de début de mandat invalide.');
  if (endsOn && !isValidDateString(endsOn)) return fail('Date de fin de mandat invalide.');
  if (endsOn && endsOn < startedOn) return fail('La fin du mandat précède son début.');

  cse.addMandate({ userId, mandateRole, startedOn, endsOn, createdBy: req.session.user.id });
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} siège désormais au CSE.`);
  res.redirect('/rh#cse');
});

router.post('/cse/mandats/:id/retirer', (req, res) => {
  cse.removeMandate(Number(req.params.id));
  setFlash(req, 'success', 'Mandat retiré.');
  res.redirect('/rh#cse');
});

router.post('/cse/elections', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const seats = Number(req.body.seats);
  const candidacyDeadline = (req.body.candidacy_deadline || '').trim();
  const voteStart = (req.body.vote_start || '').trim();
  const voteEnd = (req.body.vote_end || '').trim();

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/rh#cse');
  };

  if (!title) return fail("L'intitulé de l'élection est obligatoire.");
  if (!Number.isInteger(seats) || seats < 1 || seats > 100) return fail('Nombre de sièges invalide.');
  for (const [value, label] of [[candidacyDeadline, 'clôture des candidatures'], [voteStart, 'ouverture du vote'], [voteEnd, 'clôture du vote']]) {
    if (value && !isValidDateString(value)) return fail(`Date de ${label} invalide.`);
  }
  if (voteStart && voteEnd && voteEnd < voteStart) return fail('La clôture du vote précède son ouverture.');

  cse.createElection({
    title,
    description: (req.body.description || '').trim().slice(0, 2000),
    seats,
    candidacyDeadline,
    voteStart,
    voteEnd,
    createdBy: req.session.user.id,
  });

  setFlash(req, 'success', 'Élection créée : les candidatures sont ouvertes.');
  res.redirect('/rh#cse');
});

router.post('/cse/elections/:id/statut', (req, res) => {
  const result = cse.setElectionStatus(Number(req.params.id), (req.body.status || '').trim());
  const messages = {
    'no-candidate': "Aucune candidature validée : le scrutin serait vide.",
    closed: 'Cette élection est déjà close.',
    'not-found': 'Élection introuvable.',
    'bad-status': 'Phase invalide.',
  };

  if (!result.ok) setFlash(req, 'error', messages[result.reason] || 'Changement de phase impossible.');
  else setFlash(req, 'success', 'Phase du scrutin mise à jour.');

  res.redirect('/rh#cse');
});

router.post('/cse/elections/:id/supprimer', (req, res) => {
  cse.deleteElection(Number(req.params.id));
  setFlash(req, 'success', 'Élection supprimée.');
  res.redirect('/rh#cse');
});

router.post('/cse/candidatures/:id/statut', (req, res) => {
  const status = (req.body.status || '').trim();
  const result = cse.reviewCandidacy(Number(req.params.id), status, req.session.user.id);
  const messages = {
    closed: 'Les candidatures de cette élection ne sont plus modifiables.',
    'not-found': 'Candidature introuvable.',
    'bad-status': 'Décision invalide.',
  };

  if (!result.ok) setFlash(req, 'error', messages[result.reason] || 'Décision impossible.');
  else setFlash(req, 'success', status === 'Validée' ? 'Candidature validée.' : 'Candidature refusée.');

  res.redirect('/rh#cse');
});

router.post('/cse/reunions', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const meetingDate = (req.body.meeting_date || '').trim();
  const meetingTime = (req.body.meeting_time || '').trim();

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/rh#cse');
  };

  if (!title) return fail("L'intitulé de la réunion est obligatoire.");
  if (!isValidDateString(meetingDate)) return fail('Date de réunion invalide.');
  if (meetingTime && !/^([01]\d|2[0-3]):[0-5]\d$/.test(meetingTime)) return fail('Heure de réunion invalide.');

  cse.createMeeting({
    title,
    meetingDate,
    meetingTime,
    location: (req.body.location || '').trim().slice(0, 140),
    agenda: (req.body.agenda || '').trim().slice(0, 4000),
    createdBy: req.session.user.id,
  });

  setFlash(req, 'success', 'Réunion convoquée. Les élus pourront y déposer le compte-rendu.');
  res.redirect('/rh#cse');
});

router.post('/cse/reunions/:id/supprimer', (req, res) => {
  cse.deleteMeeting(Number(req.params.id));
  setFlash(req, 'success', 'Réunion supprimée.');
  res.redirect('/rh#cse');
});

// ---------- Pointage des freelances (supervision) ----------

router.get('/temps/:id', (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/rh#remuneration');
  }

  res.render('employee-timesheet', {
    employee,
    entries: timesheet.getEntries(id, 200),
    openEntry: timesheet.getOpenEntry(id),
    statsData: timesheet.getStats(id, employee.daily_rate),
  });
});

router.post('/temps/:id/cloturer', (req, res) => {
  const id = Number(req.params.id);
  const result = timesheet.clockOut(id);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? 'Pointage clôturé.' : 'Aucun pointage en cours pour ce membre.');
  res.redirect(`/rh/temps/${id}`);
});

router.post('/temps/:id/:entryId/supprimer', (req, res) => {
  const id = Number(req.params.id);
  db.prepare('DELETE FROM time_entries WHERE id = ? AND employee_id = ?').run(Number(req.params.entryId), id);
  setFlash(req, 'success', 'Entrée supprimée.');
  res.redirect(`/rh/temps/${id}`);
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
