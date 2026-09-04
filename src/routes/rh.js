const express = require('express');

const db = require('../db');
const hr = require('../hr');
const cse = require('../cse');
const talent = require('../talent');
const ats = require('../ats');
const cv = require('../cv');
const security = require('../security');
const org = require('../org');
const timesheet = require('../timesheet');
const requestTypes = require('../request-types');
const contractTypes = require('../contract-types');
const { requireHR } = require('../middleware/auth');
const { setFlash, parseAmount, isValidDateString, isValidEmail, isValidUrl } = require('../utils');

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

  const sessions = talent.sessions();
  const openings = talent.openings();

  res.render('rh', {
    requests,
    talent: {
      documents: talent.documents(),
      documentCategories: talent.DOCUMENT_CATEGORIES,
      trainings: talent.trainings(),
      sessions,
      sessionStatuses: talent.SESSION_STATUSES,
      registrations: talent.registrations(),
      pendingSeats: talent.registrations().filter((r) => r.status === 'Demandée').length,
      reviews: talent.reviews(),
      openings,
      openingStatuses: talent.OPENING_STATUSES,
      candidateStages: talent.CANDIDATE_STAGES,
      candidatesByOpening: Object.fromEntries(openings.map((o) => [o.id, ats.rankedCandidates(o.id)])),
      departments: org.departments(),
      teams: org.teams(),
    },
    // Le filtrage ATS est une préoccupation à part : il ne se mélange pas au
    // reste du dossier RH.
    criteriaByOpening: Object.fromEntries(openings.map((o) => [o.id, ats.criteriaOf(o.id)])),
    criterionKinds: ats.CRITERION_KINDS,
    maxWeight: ats.MAX_WEIGHT,
    cvQuery: (req.query.cv || '').trim().slice(0, 120),
    cvResults: (req.query.cv || '').trim() ? ats.searchCvs(req.query.cv.trim()) : [],
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

// ---------- Documents d'entreprise ----------

const backTalent = (anchor) => `/rh#${anchor}`;

function talentFail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(backTalent(anchor));
}

router.post('/documents', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const category = (req.body.category || '').trim();
  const url = (req.body.url || '').trim().slice(0, 500);

  if (!title) return talentFail(req, res, 'documents', "L'intitulé du document est obligatoire.");
  if (category && !talent.DOCUMENT_CATEGORIES.includes(category)) return talentFail(req, res, 'documents', 'Catégorie invalide.');
  if (url && !isValidUrl(url)) return talentFail(req, res, 'documents', 'Lien invalide : une adresse http(s) est attendue.');

  talent.createDocument({
    title, category, url,
    description: (req.body.description || '').trim().slice(0, 2000),
    requiresAck: req.body.requires_ack === 'on',
    createdBy: req.session.user.id,
  });
  setFlash(req, 'success', 'Document publié.');
  res.redirect(backTalent('documents'));
});

router.post('/documents/:id/supprimer', (req, res) => {
  talent.deleteDocument(Number(req.params.id));
  setFlash(req, 'success', 'Document supprimé, avec ses accusés de réception.');
  res.redirect(backTalent('documents'));
});

// ---------- Formation ----------

router.post('/formations', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const duration = req.body.duration_hours ? parseAmount(req.body.duration_hours) : null;
  const cost = req.body.cost ? parseAmount(req.body.cost) : null;

  if (!title) return talentFail(req, res, 'formations', "L'intitulé de la formation est obligatoire.");
  if (duration !== null && (!Number.isFinite(duration) || duration < 0 || duration > 2000)) {
    return talentFail(req, res, 'formations', 'Durée invalide.');
  }
  if (cost !== null && (!Number.isFinite(cost) || cost < 0)) return talentFail(req, res, 'formations', 'Coût invalide.');

  talent.createTraining({
    title,
    category: (req.body.category || '').trim().slice(0, 80),
    provider: (req.body.provider || '').trim().slice(0, 120),
    description: (req.body.description || '').trim().slice(0, 2000),
    durationHours: duration,
    cost,
  });
  setFlash(req, 'success', 'Formation ajoutée au catalogue.');
  res.redirect(backTalent('formations'));
});

router.post('/formations/:id/supprimer', (req, res) => {
  talent.deleteTraining(Number(req.params.id));
  setFlash(req, 'success', 'Formation supprimée, avec ses sessions.');
  res.redirect(backTalent('formations'));
});

router.post('/sessions', (req, res) => {
  const trainingId = Number(req.body.training_id);
  const startDate = (req.body.start_date || '').trim();
  const endDate = (req.body.end_date || '').trim();
  const seats = Number(req.body.seats) || 0;

  if (!talent.trainings().some((t) => t.id === trainingId)) return talentFail(req, res, 'formations', 'Formation introuvable.');
  if (!isValidDateString(startDate)) return talentFail(req, res, 'formations', 'Date de début invalide.');
  if (endDate && !isValidDateString(endDate)) return talentFail(req, res, 'formations', 'Date de fin invalide.');
  if (endDate && endDate < startDate) return talentFail(req, res, 'formations', 'La fin précède le début.');
  if (seats < 0 || seats > 1000) return talentFail(req, res, 'formations', 'Nombre de places invalide.');

  talent.createSession({
    trainingId, startDate, endDate, seats,
    location: (req.body.location || '').trim().slice(0, 140),
  });
  setFlash(req, 'success', 'Session programmée.');
  res.redirect(backTalent('formations'));
});

router.post('/sessions/:id/statut', (req, res) => {
  if (!talent.setSessionStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return talentFail(req, res, 'formations', 'Statut invalide ou session introuvable.');
  }
  setFlash(req, 'success', 'Session mise à jour.');
  res.redirect(backTalent('formations'));
});

router.post('/sessions/:id/supprimer', (req, res) => {
  talent.deleteSession(Number(req.params.id));
  setFlash(req, 'success', 'Session supprimée.');
  res.redirect(backTalent('formations'));
});

router.post('/inscriptions/:id/statut', (req, res) => {
  const result = talent.reviewRegistration(Number(req.params.id), (req.body.status || '').trim(), req.session.user.id);
  const messages = {
    'not-found': 'Inscription introuvable.',
    full: 'La session est complète : libérez une place ou augmentez le quota.',
    'bad-status': 'Décision invalide.',
  };
  if (!result.ok) return talentFail(req, res, 'formations', messages[result.reason] || 'Décision impossible.');

  setFlash(req, 'success', 'Inscription mise à jour.');
  res.redirect(backTalent('formations'));
});

// ---------- Entretiens annuels ----------

router.post('/entretiens', (req, res) => {
  const employeeId = Number(req.body.employee_id);
  const period = (req.body.period || '').trim().slice(0, 40);
  const scheduledOn = (req.body.scheduled_on || '').trim();
  const reviewerId = Number(req.body.reviewer_id) || null;

  if (!findManagedEmployee(employeeId)) return talentFail(req, res, 'entretiens', 'Membre introuvable.');
  if (!period) return talentFail(req, res, 'entretiens', "La période de l'entretien est obligatoire.");
  if (scheduledOn && !isValidDateString(scheduledOn)) return talentFail(req, res, 'entretiens', 'Date invalide.');

  talent.createReview({ employeeId, reviewerId, period, scheduledOn });
  setFlash(req, 'success', 'Entretien planifié.');
  res.redirect(backTalent('entretiens'));
});

router.post('/entretiens/:id/conclure', (req, res) => {
  const rating = req.body.rating ? Number(req.body.rating) : null;
  const result = talent.completeReview(Number(req.params.id), {
    strengths: (req.body.strengths || '').trim().slice(0, 2000),
    improvements: (req.body.improvements || '').trim().slice(0, 2000),
    objectives: (req.body.objectives || '').trim().slice(0, 2000),
    rating,
  });

  const messages = {
    'not-found': 'Entretien introuvable.',
    cancelled: 'Cet entretien est annulé.',
    'bad-rating': 'Appréciation invalide (1 à 5).',
  };
  if (!result.ok) return talentFail(req, res, 'entretiens', messages[result.reason] || 'Enregistrement impossible.');

  setFlash(req, 'success', 'Compte-rendu d\'entretien enregistré.');
  res.redirect(backTalent('entretiens'));
});

router.post('/entretiens/:id/annuler', (req, res) => {
  talent.cancelReview(Number(req.params.id));
  setFlash(req, 'success', 'Entretien annulé.');
  res.redirect(backTalent('entretiens'));
});

router.post('/entretiens/:id/supprimer', (req, res) => {
  talent.deleteReview(Number(req.params.id));
  setFlash(req, 'success', 'Entretien supprimé.');
  res.redirect(backTalent('entretiens'));
});

// ---------- Recrutement ----------

router.post('/postes', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const departmentId = Number(req.body.department_id) || null;
  const teamId = Number(req.body.team_id) || null;
  const contractType = (req.body.contract_type || '').trim();

  if (!title) return talentFail(req, res, 'recrutement', "L'intitulé du poste est obligatoire.");
  if (departmentId && !org.departmentById(departmentId)) return talentFail(req, res, 'recrutement', 'Service introuvable.');
  if (teamId && !org.teamById(teamId)) return talentFail(req, res, 'recrutement', 'Équipe introuvable.');
  if (contractType && !contractTypes.includes(contractType)) return talentFail(req, res, 'recrutement', 'Type de contrat invalide.');

  talent.createOpening({
    title, departmentId, teamId, contractType,
    description: (req.body.description || '').trim().slice(0, 4000),
    createdBy: req.session.user.id,
  });
  setFlash(req, 'success', 'Poste ouvert.');
  res.redirect(backTalent('recrutement'));
});

router.post('/postes/:id/statut', (req, res) => {
  if (!talent.setOpeningStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return talentFail(req, res, 'recrutement', 'Statut invalide ou poste introuvable.');
  }
  setFlash(req, 'success', 'Poste mis à jour.');
  res.redirect(backTalent('recrutement'));
});

router.post('/postes/:id/supprimer', (req, res) => {
  talent.deleteOpening(Number(req.params.id));
  setFlash(req, 'success', 'Poste supprimé, avec ses candidatures.');
  res.redirect(backTalent('recrutement'));
});

router.post('/candidats', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').trim().slice(0, 254);

  if (!firstName || !lastName) return talentFail(req, res, 'recrutement', 'Prénom et nom sont obligatoires.');
  if (email && !isValidEmail(email)) return talentFail(req, res, 'recrutement', 'Adresse email invalide.');

  const result = talent.createCandidate({
    openingId: Number(req.body.opening_id),
    firstName, lastName, email,
    phone: (req.body.phone || '').trim().slice(0, 40),
    source: (req.body.source || '').trim().slice(0, 80),
    notes: (req.body.notes || '').trim().slice(0, 2000),
  });

  const messages = { 'not-found': 'Poste introuvable.', closed: "Ce poste n'accepte plus de candidature." };
  if (!result.ok) return talentFail(req, res, 'recrutement', messages[result.reason] || 'Candidature impossible.');

  setFlash(req, 'success', 'Candidature enregistrée.');
  res.redirect(backTalent('recrutement'));
});

router.post('/candidats/:id/etape', (req, res) => {
  if (!talent.setCandidateStage(Number(req.params.id), (req.body.stage || '').trim())) {
    return talentFail(req, res, 'recrutement', 'Étape invalide ou candidature introuvable.');
  }
  setFlash(req, 'success', 'Étape mise à jour.');
  res.redirect(backTalent('recrutement'));
});

router.post('/candidats/:id/supprimer', (req, res) => {
  talent.deleteCandidate(Number(req.params.id));
  setFlash(req, 'success', 'Candidature supprimée.');
  res.redirect(backTalent('recrutement'));
});

// ---------- Filtrage ATS : critères, CV, classement ----------

router.post('/postes/:id/ats', (req, res) => {
  const openingId = Number(req.params.id);
  if (!talent.openingById(openingId)) return talentFail(req, res, 'recrutement', 'Poste introuvable.');

  const minExperience = parseAmount(req.body.min_experience || '0');
  const threshold = Number(req.body.ats_threshold);

  const result = ats.setOpeningAts(openingId, { minExperience, threshold });
  const messages = {
    'bad-experience': "Expérience minimale invalide (0 à 60 ans).",
    'bad-threshold': 'Seuil invalide (0 à 100).',
  };
  if (!result.ok) return talentFail(req, res, 'recrutement', messages[result.reason] || 'Réglage impossible.');

  // Les scores dépendent du seuil et de l'expérience : ils sont refaits.
  ats.rescoreOpening(openingId);
  setFlash(req, 'success', 'Réglages ATS mis à jour.');
  res.redirect(backTalent('recrutement'));
});

router.post('/postes/:id/criteres', (req, res) => {
  const openingId = Number(req.params.id);
  const label = (req.body.label || '').trim().slice(0, 120);
  const kind = (req.body.kind || '').trim();
  const weight = Number(req.body.weight);

  if (!label) return talentFail(req, res, 'recrutement', "L'intitulé du critère est obligatoire.");

  const result = ats.createCriterion({
    openingId, label, kind, weight,
    keywords: (req.body.keywords || '').trim().slice(0, 500),
  });

  const messages = {
    'bad-kind': 'Type de critère invalide.',
    'bad-weight': `Poids invalide (1 à ${ats.MAX_WEIGHT}).`,
    'no-opening': 'Poste introuvable.',
  };
  if (!result.ok) return talentFail(req, res, 'recrutement', messages[result.reason] || 'Critère refusé.');

  ats.rescoreOpening(openingId);
  setFlash(req, 'success', 'Critère ajouté. Les candidatures ont été réévaluées.');
  res.redirect(backTalent('recrutement'));
});

router.post('/criteres/:id/supprimer', (req, res) => {
  const openingId = ats.deleteCriterion(Number(req.params.id));
  if (openingId == null) return talentFail(req, res, 'recrutement', 'Critère introuvable.');

  ats.rescoreOpening(openingId);
  setFlash(req, 'success', 'Critère retiré. Les candidatures ont été réévaluées.');
  res.redirect(backTalent('recrutement'));
});

/**
 * Dépôt d'un CV : le fichier est stocké hors du dépôt, son texte extrait, et
 * la candidature réévaluée dans la foulée.
 */
function receiveCv(req, res, next) {
  cv.cvUpload(req, res, (err) => {
    if (!err) return next();
    const message = err.code === 'LIMIT_FILE_SIZE'
      ? 'CV trop volumineux : 5 Mo maximum.'
      : 'Format non pris en charge : PDF, DOCX, TXT ou Markdown.';
    talentFail(req, res, 'recrutement', message);
  });
}

// upload() enchaîne la réception du fichier puis le contrôle du jeton CSRF, que
// le corps multipart ne rend lisible qu'à ce moment-là.
router.post('/candidats/:id/cv', ...security.upload(receiveCv), async (req, res) => {
  const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(Number(req.params.id));
  if (!candidate) return talentFail(req, res, 'recrutement', 'Candidature introuvable.');
  if (!req.file) return talentFail(req, res, 'recrutement', 'Aucun fichier reçu.');

  let text = '';
  try {
    text = await cv.extractText(req.file.buffer, req.file.mimetype);
  } catch {
    // Un fichier illisible ne doit pas faire tomber la requête : on garde le
    // document, sans texte, et on le dit.
    text = '';
  }

  // Le CV précédent est remplacé, pas accumulé.
  cv.remove(candidate.cv_file);
  const fileName = cv.save(req.file);

  db.prepare(`
    UPDATE candidates SET cv_file = ?, cv_name = ?, cv_text = ?, cv_uploaded_at = ?
    WHERE id = ?
  `).run(fileName, req.file.originalname.slice(0, 200), text, new Date().toISOString(), candidate.id);

  ats.rescoreCandidate(candidate.id);

  setFlash(req, 'success', text
    ? 'CV déposé et analysé.'
    : "CV déposé, mais aucun texte n'a pu en être extrait : un PDF scanné demande une reconnaissance de caractères, que ce module ne fait pas.");
  res.redirect(backTalent('recrutement'));
});

/** Un CV est une donnée personnelle : il ne sort que par cette route authentifiée. */
router.get('/candidats/:id/cv', (req, res) => {
  const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(Number(req.params.id));
  if (!candidate || !candidate.cv_file) {
    return res.status(404).render('error', { message: 'CV introuvable.' });
  }
  res.download(cv.pathOf(candidate.cv_file), candidate.cv_name || 'cv');
});

router.post('/candidats/:id/cv/supprimer', (req, res) => {
  const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(Number(req.params.id));
  if (!candidate) return talentFail(req, res, 'recrutement', 'Candidature introuvable.');

  cv.remove(candidate.cv_file);
  db.prepare("UPDATE candidates SET cv_file = NULL, cv_name = '', cv_text = '', cv_uploaded_at = NULL WHERE id = ?")
    .run(candidate.id);
  ats.rescoreCandidate(candidate.id);

  setFlash(req, 'success', 'CV supprimé, fichier et texte compris.');
  res.redirect(backTalent('recrutement'));
});

router.post('/candidats/:id/experience', (req, res) => {
  const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(Number(req.params.id));
  if (!candidate) return talentFail(req, res, 'recrutement', 'Candidature introuvable.');

  const raw = (req.body.experience_years || '').trim();
  const years = raw === '' ? null : parseAmount(raw);
  if (years !== null && (!Number.isFinite(years) || years < 0 || years > 60)) {
    return talentFail(req, res, 'recrutement', 'Expérience invalide (0 à 60 ans).');
  }

  db.prepare('UPDATE candidates SET experience_years = ? WHERE id = ?').run(years, candidate.id);
  ats.rescoreCandidate(candidate.id);

  setFlash(req, 'success', 'Expérience enregistrée.');
  res.redirect(backTalent('recrutement'));
});

module.exports = router;
