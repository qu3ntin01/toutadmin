const express = require('express');

const db = require('../db');
const org = require('../org');
const audit = require('../audit');
const projects = require('../projects');
const finance = require('../finance');
const { requireAuth } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(requireAuth);

const back = (anchor) => `/projets#${anchor}`;
const backTo = (id, anchor) => `/projets/${id}#${anchor}`;

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

/**
 * Qui pilote les projets : l'administration, la gestion, et les managers. Les
 * autres membres participent — ils voient leurs projets et y saisissent leur
 * temps, sans pouvoir en créer ni en supprimer.
 */
function canManage(req) {
  const user = req.currentUser;
  return Boolean(user && (user.role === 'admin' || user.is_finance || org.isManager(user.id)));
}

function requireManage(req, res, next) {
  if (canManage(req)) return next();
  res.status(403).render('error', { message: 'La conduite des projets est réservée à la gestion et aux managers.' });
}

/** Un projet n'est ouvert qu'à son équipe, sauf pour ceux qui les pilotent. */
function visibleTo(req, project) {
  if (canManage(req)) return true;
  const userId = req.currentUser.id;
  if (project.lead_id === userId) return true;
  return Boolean(db.prepare('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?').get(project.id, userId));
}

function readAmount(raw, { max = 1e9 } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || value < 0 || value > max) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function activeUsers() {
  return db.prepare("SELECT id, first_name, last_name, grade FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE").all();
}

// ---------- Vue d'ensemble ----------

router.get('/', (req, res) => {
  const manage = canManage(req);
  const all = manage ? projects.list({ includeArchived: req.query.archives === '1' }) : projects.forUser(req.currentUser.id);

  res.render('projets', {
    canManage: manage,
    showArchived: req.query.archives === '1',
    projectList: all.map((p) => ({ ...p, profit: projects.profitability(p) })),
    myTasks: projects.tasksOf(req.currentUser.id),
    myTime: projects.timeOf(req.currentUser.id).slice(0, 40),
    summary: projects.summary(),
    statuses: projects.STATUSES,
    people: activeUsers(),
    departments: org.departments(),
    teams: org.teams(),
    partners: manage ? finance.partners() : [],
  });
});

router.post('/', requireManage, (req, res) => {
  const name = (req.body.name || '').trim();
  if (!name || name.length > 160) return fail(req, res, back('projets'), 'Intitulé de projet invalide.');

  const start = readDate(req.body.start_date);
  const due = readDate(req.body.due_date);
  if (!start.ok || !due.ok) return fail(req, res, back('projets'), 'Date invalide.');
  if (start.value && due.value && due.value < start.value) {
    return fail(req, res, back('projets'), 'La date de fin précède la date de début.');
  }

  const budget = readAmount(req.body.budget_amount);
  const rate = readAmount(req.body.hourly_rate, { max: 10000 });
  if (!budget.ok || !rate.ok) return fail(req, res, back('projets'), 'Montant invalide.');
  if (!projects.STATUSES.includes(req.body.status || 'Cadrage')) return fail(req, res, back('projets'), 'Statut invalide.');

  const id = projects.create({
    code: (req.body.code || '').trim().slice(0, 20),
    name,
    partnerId: Number(req.body.partner_id) || null,
    departmentId: Number(req.body.department_id) || null,
    teamId: Number(req.body.team_id) || null,
    leadId: Number(req.body.lead_id) || null,
    status: req.body.status || 'Cadrage',
    startDate: start.value,
    dueDate: due.value,
    budgetAmount: budget.value,
    hourlyRate: rate.value,
    description: (req.body.description || '').trim().slice(0, 4000),
  });

  audit.log(req, 'projet.cree', 'projects', id, { nom: name });
  setFlash(req, 'success', 'Projet créé.');
  res.redirect(backTo(id, 'taches'));
});

// ---------- Fiche projet ----------

router.get('/:id', (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return res.status(404).render('error', { message: 'Projet introuvable.' });
  if (!visibleTo(req, project)) {
    return res.status(403).render('error', { message: "Ce projet n'est ouvert qu'à son équipe." });
  }

  res.render('projet', {
    project,
    canManage: canManage(req),
    board: projects.board(project.id),
    taskList: projects.tasks(project.id),
    milestoneList: projects.milestones(project.id),
    memberList: projects.members(project.id),
    entries: projects.timeEntries(project.id),
    byMember: projects.timeByMember(project.id),
    profit: projects.profitability(project),
    statuses: projects.STATUSES,
    taskStatuses: projects.TASK_STATUSES,
    priorities: projects.PRIORITIES,
    people: activeUsers(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.post('/:id/modifier', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');

  const name = (req.body.name || '').trim();
  const start = readDate(req.body.start_date);
  const due = readDate(req.body.due_date);
  const budget = readAmount(req.body.budget_amount);
  const rate = readAmount(req.body.hourly_rate, { max: 10000 });

  if (!name) return fail(req, res, backTo(project.id, 'reglages'), 'Intitulé de projet invalide.');
  if (!start.ok || !due.ok) return fail(req, res, backTo(project.id, 'reglages'), 'Date invalide.');
  if (start.value && due.value && due.value < start.value) {
    return fail(req, res, backTo(project.id, 'reglages'), 'La date de fin précède la date de début.');
  }
  if (!budget.ok || !rate.ok) return fail(req, res, backTo(project.id, 'reglages'), 'Montant invalide.');
  if (!projects.STATUSES.includes(req.body.status)) return fail(req, res, backTo(project.id, 'reglages'), 'Statut invalide.');

  projects.update(project.id, {
    code: (req.body.code || '').trim().slice(0, 20),
    name,
    partnerId: Number(req.body.partner_id) || null,
    departmentId: Number(req.body.department_id) || null,
    teamId: Number(req.body.team_id) || null,
    leadId: Number(req.body.lead_id) || null,
    status: req.body.status,
    startDate: start.value,
    dueDate: due.value,
    budgetAmount: budget.value,
    hourlyRate: rate.value,
    description: (req.body.description || '').trim().slice(0, 4000),
  });
  setFlash(req, 'success', 'Projet mis à jour.');
  res.redirect(backTo(project.id, 'reglages'));
});

router.post('/:id/archiver', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');
  projects.archive(project.id, !project.archived);
  audit.log(req, project.archived ? 'projet.desarchive' : 'projet.archive', 'projects', project.id);
  setFlash(req, 'success', project.archived ? 'Projet réactivé.' : 'Projet archivé.');
  res.redirect(back('projets'));
});

router.post('/:id/supprimer', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');
  projects.remove(project.id);
  audit.log(req, 'projet.supprime', 'projects', project.id, { nom: project.name });
  setFlash(req, 'success', 'Projet supprimé, avec ses tâches et son temps passé.');
  res.redirect(back('projets'));
});

// ---------- Équipe ----------

router.post('/:id/membres', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');

  const userId = Number(req.body.user_id);
  if (!db.prepare('SELECT 1 FROM users WHERE id = ? AND active = 1').get(userId)) {
    return fail(req, res, backTo(project.id, 'equipe'), 'Membre introuvable.');
  }
  projects.addMember(project.id, userId, (req.body.role || '').trim().slice(0, 60));
  setFlash(req, 'success', 'Membre ajouté au projet.');
  res.redirect(backTo(project.id, 'equipe'));
});

router.post('/:id/membres/:userId/retirer', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');
  projects.removeMember(project.id, Number(req.params.userId));
  setFlash(req, 'success', 'Membre retiré du projet.');
  res.redirect(backTo(project.id, 'equipe'));
});

// ---------- Jalons ----------

router.post('/:id/jalons', requireManage, (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project) return fail(req, res, back('projets'), 'Projet introuvable.');

  const title = (req.body.title || '').trim();
  const due = readDate(req.body.due_date);
  if (!title || !due.ok) return fail(req, res, backTo(project.id, 'jalons'), 'Jalon invalide.');

  projects.createMilestone(project.id, title.slice(0, 160), due.value);
  setFlash(req, 'success', 'Jalon ajouté.');
  res.redirect(backTo(project.id, 'jalons'));
});

router.post('/jalons/:id/basculer', requireManage, (req, res) => {
  const projectId = projects.toggleMilestone(Number(req.params.id));
  if (!projectId) return fail(req, res, back('projets'), 'Jalon introuvable.');
  res.redirect(backTo(projectId, 'jalons'));
});

router.post('/jalons/:id/supprimer', requireManage, (req, res) => {
  const projectId = projects.deleteMilestone(Number(req.params.id));
  if (!projectId) return fail(req, res, back('projets'), 'Jalon introuvable.');
  setFlash(req, 'success', 'Jalon retiré.');
  res.redirect(backTo(projectId, 'jalons'));
});

// ---------- Tâches ----------

router.post('/:id/taches', (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project || !visibleTo(req, project)) return fail(req, res, back('projets'), 'Projet introuvable.');

  const title = (req.body.title || '').trim();
  if (!title || title.length > 200) return fail(req, res, backTo(project.id, 'taches'), 'Intitulé de tâche invalide.');

  const due = readDate(req.body.due_date);
  if (!due.ok) return fail(req, res, backTo(project.id, 'taches'), 'Échéance invalide.');
  if (!projects.PRIORITIES.includes(req.body.priority || 'Normale')) {
    return fail(req, res, backTo(project.id, 'taches'), 'Priorité invalide.');
  }

  const estimate = (req.body.estimate_hours || '').trim();
  const estimateHours = estimate ? Number(estimate.replace(',', '.')) : null;
  if (estimate && (!Number.isFinite(estimateHours) || estimateHours < 0 || estimateHours > 10000)) {
    return fail(req, res, backTo(project.id, 'taches'), 'Estimation invalide.');
  }

  projects.createTask({
    projectId: project.id,
    milestoneId: Number(req.body.milestone_id) || null,
    title,
    description: (req.body.description || '').trim().slice(0, 2000),
    assigneeId: Number(req.body.assignee_id) || null,
    priority: req.body.priority || 'Normale',
    estimateHours,
    dueDate: due.value,
    createdBy: req.currentUser.id,
  });
  setFlash(req, 'success', 'Tâche ajoutée.');
  res.redirect(backTo(project.id, 'taches'));
});

router.post('/taches/:id/statut', (req, res) => {
  const task = projects.taskById(req.params.id);
  if (!task) return fail(req, res, back('projets'), 'Tâche introuvable.');
  const project = projects.byId(task.project_id);
  if (!visibleTo(req, project)) return fail(req, res, back('projets'), 'Tâche introuvable.');

  if (!projects.setTaskStatus(task.id, req.body.status)) {
    return fail(req, res, backTo(task.project_id, 'taches'), 'Statut invalide.');
  }
  res.redirect(backTo(task.project_id, 'taches'));
});

router.post('/taches/:id/affecter', (req, res) => {
  const task = projects.taskById(req.params.id);
  if (!task) return fail(req, res, back('projets'), 'Tâche introuvable.');
  const project = projects.byId(task.project_id);
  if (!visibleTo(req, project)) return fail(req, res, back('projets'), 'Tâche introuvable.');

  projects.assignTask(task.id, Number(req.body.assignee_id) || null);
  res.redirect(backTo(task.project_id, 'taches'));
});

router.post('/taches/:id/supprimer', requireManage, (req, res) => {
  const projectId = projects.deleteTask(Number(req.params.id));
  if (!projectId) return fail(req, res, back('projets'), 'Tâche introuvable.');
  setFlash(req, 'success', 'Tâche supprimée.');
  res.redirect(backTo(projectId, 'taches'));
});

// ---------- Temps passé ----------

router.post('/:id/temps', (req, res) => {
  const project = projects.byId(req.params.id);
  if (!project || !visibleTo(req, project)) return fail(req, res, back('projets'), 'Projet introuvable.');

  const spentOn = readDate(req.body.spent_on, { required: true });
  if (!spentOn.ok) return fail(req, res, backTo(project.id, 'temps'), 'Date invalide.');
  if (spentOn.value > new Date().toISOString().slice(0, 10)) {
    return fail(req, res, backTo(project.id, 'temps'), 'Une saisie de temps ne se fait pas à l\'avance.');
  }

  const hours = Number((req.body.hours || '').replace(',', '.'));
  const taskId = Number(req.body.task_id) || null;
  if (taskId) {
    const task = projects.taskById(taskId);
    if (!task || task.project_id !== project.id) {
      return fail(req, res, backTo(project.id, 'temps'), 'Tâche introuvable sur ce projet.');
    }
  }

  const verdict = projects.logTime({
    projectId: project.id,
    taskId,
    userId: req.currentUser.id,
    spentOn: spentOn.value,
    hours,
    note: (req.body.note || '').trim().slice(0, 300),
    billable: req.body.billable !== '0',
  });
  if (!verdict.ok) return fail(req, res, backTo(project.id, 'temps'), verdict.message);

  setFlash(req, 'success', 'Temps enregistré.');
  res.redirect(backTo(project.id, 'temps'));
});

router.post('/temps/:id/supprimer', (req, res) => {
  // Chacun efface ses propres saisies ; la gestion peut corriger celles de tous.
  const restrict = canManage(req) ? {} : { userId: req.currentUser.id };
  const projectId = projects.deleteTimeEntry(Number(req.params.id), restrict);
  if (!projectId) return fail(req, res, back('mon-temps'), 'Saisie introuvable, ou pas la vôtre.');
  setFlash(req, 'success', 'Saisie supprimée.');
  res.redirect(backTo(projectId, 'temps'));
});

module.exports = router;
