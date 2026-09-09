const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const audit = require('../audit');
const sessionStore = require('../session-store');
const grades = require('../grades');
const contractTypes = require('../contract-types');
const hr = require('../hr');
const org = require('../org');
const settings = require('../settings');
const modules = require('../modules');
const themes = require('../themes');
const accounting = require('../accounting');
const payroll = require('../payroll');
const announcements = require('../announcements');
const { requireAdmin } = require('../middleware/auth');
const { setFlash, generatePassword, isValidEmail, isValidDateString, isValidUrl, parseDailyRate } = require('../utils');

const router = express.Router();

// Toutes les routes de ce routeur sont montées sous /admin et exigent le rôle 'admin' :
// seul un administrateur peut créer, modifier ou supprimer des membres, des outils et des accès RH.
router.use(requireAdmin);

function findEmployee(id) {
  return db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
}

// ---------- Tableau de bord ----------

router.get('/', (req, res) => {
  db.deactivateExpiredContracts();

  const employees = db
    .prepare("SELECT * FROM users WHERE role = 'employee' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE")
    .all();
  const tools = db.prepare('SELECT * FROM tools ORDER BY name COLLATE NOCASE').all();
  const assignmentRows = db
    .prepare('SELECT a.id, a.employee_id, a.tool_id, a.assigned_at, a.note, a.username FROM assignments a')
    .all();

  const toolsByEmployee = {};
  const employeesByTool = {};
  for (const row of assignmentRows) {
    (toolsByEmployee[row.employee_id] ||= []).push(row);
    (employeesByTool[row.tool_id] ||= []).push(row);
  }

  const employeesMap = Object.fromEntries(employees.map((e) => [e.id, e]));

  const departments = org.departments();
  const teams = org.teams();

  res.render('admin', {
    employees,
    tools,
    grades,
    contractTypes,
    toolsByEmployee,
    employeesByTool,
    toolsMap: Object.fromEntries(tools.map((t) => [t.id, t])),
    employeesMap,
    departments,
    teams,
    departmentsMap: Object.fromEntries(departments.map((d) => [d.id, d])),
    teamsMap: Object.fromEntries(teams.map((t) => [t.id, t])),
    departmentManagers: Object.fromEntries(departments.map((d) => [d.id, org.managersOf('department', d.id)])),
    teamManagers: Object.fromEntries(teams.map((t) => [t.id, org.managersOf('team', t.id)])),
    hrMembers: employees.filter((e) => e.is_hr),
    hrEligibleEmployees: employees.filter((e) => !e.is_hr),
    financeMembers: employees.filter((e) => e.is_finance),
    financeEligibleEmployees: employees.filter((e) => !e.is_finance),
    itMembers: employees.filter((e) => e.is_it),
    itEligibleEmployees: employees.filter((e) => !e.is_it),
    referentMembers: employees.filter((e) => e.is_referent),
    referentEligibleEmployees: employees.filter((e) => !e.is_referent),
    companyNews: announcements.all(),
    modules: modules.list(),
    palettes: themes.list(),
    annualLeaveDays: annualLeaveDays(),
    pendingRequestCount: db.prepare("SELECT COUNT(*) AS n FROM hr_requests WHERE status = 'En attente'").get().n,
    stats: {
      employeeCount: employees.length,
      toolCount: tools.length,
      assignedCount: assignmentRows.length,
      availableCount: tools.filter((t) => !(employeesByTool[t.id] && employeesByTool[t.id].length)).length,
    },
  });
});

// ---------- Organisation : services, équipes, encadrement et annuaire ----------

const backToOrg = '/admin#organisation';

function orgFail(req, res, message) {
  setFlash(req, 'error', message);
  return res.redirect(backToOrg);
}

router.post('/services', (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 120);
  if (!name) return orgFail(req, res, "Le nom du service est obligatoire.");
  if (org.departments().some((d) => d.name.toLowerCase() === name.toLowerCase())) {
    return orgFail(req, res, 'Un service porte déjà ce nom.');
  }

  org.createDepartment({ name, description: (req.body.description || '').trim().slice(0, 500) });
  setFlash(req, 'success', `Service « ${name} » créé.`);
  res.redirect(backToOrg);
});

router.post('/services/:id/modifier', (req, res) => {
  const id = Number(req.params.id);
  if (!org.departmentById(id)) return orgFail(req, res, 'Service introuvable.');

  const name = (req.body.name || '').trim().slice(0, 120);
  if (!name) return orgFail(req, res, "Le nom du service est obligatoire.");
  if (org.departments().some((d) => d.id !== id && d.name.toLowerCase() === name.toLowerCase())) {
    return orgFail(req, res, 'Un autre service porte déjà ce nom.');
  }

  org.updateDepartment(id, { name, description: (req.body.description || '').trim().slice(0, 500) });
  setFlash(req, 'success', 'Service mis à jour.');
  res.redirect(backToOrg);
});

router.post('/services/:id/supprimer', (req, res) => {
  const id = Number(req.params.id);
  if (!org.departmentById(id)) return orgFail(req, res, 'Service introuvable.');

  // Les membres et les équipes sont détachés, jamais supprimés avec le service.
  org.deleteDepartment(id);
  setFlash(req, 'success', 'Service supprimé. Ses membres et ses équipes en ont été détachés.');
  res.redirect(backToOrg);
});

router.post('/equipes', (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 120);
  const departmentId = Number(req.body.department_id) || null;

  if (!name) return orgFail(req, res, "Le nom de l'équipe est obligatoire.");
  if (departmentId && !org.departmentById(departmentId)) return orgFail(req, res, 'Service introuvable.');
  if (org.teams().some((t) => t.name.toLowerCase() === name.toLowerCase() && t.department_id === departmentId)) {
    return orgFail(req, res, 'Une équipe porte déjà ce nom dans ce service.');
  }

  org.createTeam({ name, departmentId, description: (req.body.description || '').trim().slice(0, 500) });
  setFlash(req, 'success', `Équipe « ${name} » créée.`);
  res.redirect(backToOrg);
});

router.post('/equipes/:id/modifier', (req, res) => {
  const id = Number(req.params.id);
  if (!org.teamById(id)) return orgFail(req, res, 'Équipe introuvable.');

  const name = (req.body.name || '').trim().slice(0, 120);
  const departmentId = Number(req.body.department_id) || null;
  if (!name) return orgFail(req, res, "Le nom de l'équipe est obligatoire.");
  if (departmentId && !org.departmentById(departmentId)) return orgFail(req, res, 'Service introuvable.');

  org.updateTeam(id, { name, departmentId, description: (req.body.description || '').trim().slice(0, 500) });
  setFlash(req, 'success', 'Équipe mise à jour.');
  res.redirect(backToOrg);
});

router.post('/equipes/:id/supprimer', (req, res) => {
  const id = Number(req.params.id);
  if (!org.teamById(id)) return orgFail(req, res, 'Équipe introuvable.');

  org.deleteTeam(id);
  setFlash(req, 'success', 'Équipe supprimée. Ses membres en ont été détachés.');
  res.redirect(backToOrg);
});

// Un service comme une équipe acceptent plusieurs managers.
router.post('/encadrement', (req, res) => {
  const scope = (req.body.scope || '').trim();
  const scopeId = Number(req.body.scope_id);
  const userId = Number(req.body.user_id);

  const result = org.addManager(scope, scopeId, userId);
  const messages = {
    'bad-scope': 'Périmètre invalide.',
    'not-found': 'Service ou équipe introuvable.',
    'no-user': 'Membre introuvable.',
  };
  if (!result.ok) return orgFail(req, res, messages[result.reason] || 'Encadrement impossible.');

  setFlash(req, 'success', `${result.user.first_name} ${result.user.last_name} encadre désormais ce périmètre.`);
  res.redirect(backToOrg);
});

router.post('/encadrement/retirer', (req, res) => {
  org.removeManager((req.body.scope || '').trim(), Number(req.body.scope_id), Number(req.body.user_id));
  setFlash(req, 'success', 'Encadrement retiré.');
  res.redirect(backToOrg);
});

router.post('/employes/:id/rattachement', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) return orgFail(req, res, 'Membre introuvable.');

  const result = org.assignMembership(id, {
    departmentId: Number(req.body.department_id) || null,
    teamId: Number(req.body.team_id) || null,
  });

  const messages = { 'no-team': 'Équipe introuvable.', 'no-department': 'Service introuvable.' };
  if (!result.ok) return orgFail(req, res, messages[result.reason] || 'Rattachement impossible.');

  setFlash(req, 'success', `Rattachement de ${employee.first_name} ${employee.last_name} mis à jour.`);
  res.redirect(backToOrg);
});

router.post('/employes/:id/annuaire', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) return res.redirect('/admin#organisation');

  db.prepare('UPDATE users SET directory_hidden = ? WHERE id = ?').run(employee.directory_hidden ? 0 : 1, id);
  setFlash(
    req,
    'success',
    `${employee.first_name} ${employee.last_name} ${employee.directory_hidden ? 'apparaît de nouveau' : "n'apparaît plus"} dans l'annuaire.`
  );
  res.redirect('/admin#organisation');
});

// ---------- Messagerie : paramètres serveur, réservés à l'administration ----------

router.post('/employes/:id/messagerie', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#organisation');
  }

  const address = (req.body.mail_address || '').trim().slice(0, 254);
  const imapHost = (req.body.mail_imap_host || '').trim().slice(0, 200);
  const smtpHost = (req.body.mail_smtp_host || '').trim().slice(0, 200);
  const imapPort = req.body.mail_imap_port ? Number(req.body.mail_imap_port) : null;
  const smtpPort = req.body.mail_smtp_port ? Number(req.body.mail_smtp_port) : null;

  const validPort = (p) => p === null || (Number.isInteger(p) && p > 0 && p <= 65535);

  if (address && !isValidEmail(address)) {
    setFlash(req, 'error', 'Adresse de messagerie invalide.');
    return res.redirect(`/admin/employes/${id}/modifier`);
  }
  if (!validPort(imapPort) || !validPort(smtpPort)) {
    setFlash(req, 'error', 'Port invalide (1 à 65535).');
    return res.redirect(`/admin/employes/${id}/modifier`);
  }

  db.prepare(`
    UPDATE users SET mail_address = ?, mail_imap_host = ?, mail_imap_port = ?, mail_smtp_host = ?, mail_smtp_port = ?
    WHERE id = ?
  `).run(address, imapHost, imapPort, smtpHost, smtpPort, id);

  setFlash(req, 'success', `Messagerie de ${employee.first_name} ${employee.last_name} mise à jour.`);
  res.redirect(`/admin/employes/${id}/modifier`);
});

// ---------- Actualités de l'entreprise ----------

router.post('/actualites', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 150);
  const body = (req.body.body || '').trim().slice(0, 2000);

  if (!title) {
    setFlash(req, 'error', "Le titre de l'actualité est obligatoire.");
    return res.redirect('/admin#actualites');
  }

  // « entreprise », ou un périmètre précis sous la forme « department:3 » / « team:7 ».
  const target = (req.body.target || 'company').trim();
  let scope = 'company';
  let scopeId = null;

  if (target !== 'company') {
    const [rawScope, rawId] = target.split(':');
    scopeId = Number(rawId);
    if (!announcements.SCOPES.includes(rawScope) || rawScope === 'company' || !scopeId) {
      setFlash(req, 'error', 'Destinataire invalide.');
      return res.redirect('/admin#actualites');
    }
    const exists = rawScope === 'team' ? org.teamById(scopeId) : org.departmentById(scopeId);
    if (!exists) {
      setFlash(req, 'error', 'Service ou équipe introuvable.');
      return res.redirect('/admin#actualites');
    }
    scope = rawScope;
  }

  announcements.create({ authorId: req.session.user.id, scope, scopeId, title, body });
  setFlash(req, 'success', scope === 'company' ? "Actualité publiée pour toute l'entreprise." : 'Actualité publiée pour ce périmètre.');
  res.redirect('/admin#actualites');
});

router.post('/actualites/:id/supprimer', (req, res) => {
  announcements.remove(Number(req.params.id));
  setFlash(req, 'success', 'Actualité supprimée.');
  res.redirect('/admin#actualites');
});

// ---------- Personnel ----------

// Quota annuel posé à l'installation, avec repli sur la valeur par défaut du module RH.
function annualLeaveDays() {
  const configured = Number(settings.get('annual_leave_days'));
  return Number.isFinite(configured) ? configured : hr.DEFAULT_ANNUAL_LEAVE;
}

router.post('/employes', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').toLowerCase().trim().slice(0, 254);
  const grade = (req.body.grade || '').trim();
  const departmentId = Number(req.body.department_id) || null;
  const teamId = Number(req.body.team_id) || null;
  const contractType = (req.body.contract_type || '').trim();
  const contractEndDate = (req.body.contract_end_date || '').trim();
  const dailyRate = parseDailyRate(req.body.daily_rate);

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/admin#personnel');
  };

  if (!firstName || !lastName || !email || !grade || !contractType) {
    return fail("Merci de renseigner le prénom, le nom, l'email, le grade et le type de contrat.");
  }
  if (!isValidEmail(email)) return fail('Adresse email invalide.');
  if (!grades.includes(grade)) return fail('Grade invalide.');
  if (!contractTypes.includes(contractType)) return fail('Type de contrat invalide.');
  if (contractEndDate && !isValidDateString(contractEndDate)) return fail('Date de fin de contrat invalide.');
  if (!dailyRate.ok) return fail('TJM invalide.');
  if (departmentId && !org.departmentById(departmentId)) return fail('Service introuvable.');
  if (teamId && !org.teamById(teamId)) return fail('Équipe introuvable.');
  if (db.prepare('SELECT id FROM users WHERE email = ?').get(email)) return fail('Un compte existe déjà avec cet email.');

  const password = generatePassword();
  const initialLeaveBalance = contractType === 'Freelance' ? 0 : annualLeaveDays();

  const created = db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, contract_type, contract_end_date, daily_rate, leave_balance, active, must_change_password)
    VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)
  `).run(
    email, bcrypt.hashSync(password, 12), firstName, lastName, grade,
    contractType, contractEndDate || null, dailyRate.value, initialLeaveBalance
  );

  // L'équipe porte son service : le rattachement reste cohérent quel que soit le champ rempli.
  org.assignMembership(Number(created.lastInsertRowid), { departmentId, teamId });

  setFlash(req, 'success', `Membre ajouté. Identifiant : ${email} — Mot de passe temporaire : ${password}`);
  res.redirect('/admin#personnel');
});

router.get('/employes/:id/modifier', (req, res) => {
  const employee = findEmployee(Number(req.params.id));
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }
  res.render('employee-edit', { employee, grades, contractTypes, departments: org.departments(), teams: org.teams() });
});

router.post('/employes/:id/modifier', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }

  const grade = (req.body.grade || '').trim();
  const departmentId = Number(req.body.department_id) || null;
  const teamId = Number(req.body.team_id) || null;
  const contractType = (req.body.contract_type || '').trim();
  const contractEndDate = (req.body.contract_end_date || '').trim();
  const dailyRate = parseDailyRate(req.body.daily_rate);

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(`/admin/employes/${id}/modifier`);
  };

  if (!grades.includes(grade) || !contractTypes.includes(contractType)) return fail('Grade ou type de contrat invalide.');
  if (contractEndDate && !isValidDateString(contractEndDate)) return fail('Date de fin de contrat invalide.');
  if (!dailyRate.ok) return fail('TJM invalide.');
  if (departmentId && !org.departmentById(departmentId)) return fail('Service introuvable.');
  if (teamId && !org.teamById(teamId)) return fail('Équipe introuvable.');

  db.prepare('UPDATE users SET grade = ?, contract_type = ?, contract_end_date = ?, daily_rate = ? WHERE id = ?')
    .run(grade, contractType, contractEndDate || null, dailyRate.value, id);
  org.assignMembership(id, { departmentId, teamId });

  setFlash(req, 'success', `Profil de ${employee.first_name} ${employee.last_name} mis à jour.`);
  res.redirect('/admin#personnel');
});

router.post('/employes/:id/statut', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) return res.redirect('/admin#personnel');

  db.prepare('UPDATE users SET active = ? WHERE id = ?').run(employee.active ? 0 : 1, id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} est désormais ${employee.active ? 'désactivé(e)' : 'actif(ve)'}.`);
  res.redirect('/admin#personnel');
});

router.post('/employes/:id/reinitialiser', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) return res.redirect('/admin#personnel');

  const password = generatePassword();
  db.prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL, must_change_password = 1 WHERE id = ?')
    .run(bcrypt.hashSync(password, 12), id);

  // Le mot de passe transmis par un tiers ne doit pas rester en vigueur : toutes
  // les sessions ouvertes tombent, et la personne devra en choisir un autre.
  sessionStore.store().revokeUser(id);
  audit.log(req, 'utilisateur.mot_de_passe_reinitialise', 'users', id, { email: employee.email });

  setFlash(req, 'success', `Nouveau mot de passe pour ${employee.email} : ${password}`);
  res.redirect('/admin#personnel');
});

router.post('/employes/:id/supprimer', (req, res) => {
  db.prepare("DELETE FROM users WHERE id = ? AND role = 'employee'").run(Number(req.params.id));
  setFlash(req, 'success', 'Membre supprimé.');
  res.redirect('/admin#personnel');
});

// ---------- Outils ----------

function readToolForm(body) {
  return {
    name: (body.name || '').trim().slice(0, 150),
    category: (body.category || '').trim().slice(0, 100),
    reference: (body.reference || '').trim().slice(0, 100),
    description: (body.description || '').trim().slice(0, 1000),
    loginUrl: (body.login_url || '').trim().slice(0, 500),
  };
}

router.post('/outils', (req, res) => {
  const tool = readToolForm(req.body);
  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/admin#outils');
  };

  if (!tool.name) return fail("Merci de renseigner le nom de l'outil.");
  if (tool.loginUrl && !isValidUrl(tool.loginUrl)) return fail('URL de connexion invalide (http:// ou https:// requis).');

  db.prepare(`
    INSERT INTO tools (name, category, reference, description, login_url, status)
    VALUES (?, ?, ?, ?, ?, 'disponible')
  `).run(tool.name, tool.category, tool.reference, tool.description, tool.loginUrl);

  setFlash(req, 'success', `Outil « ${tool.name} » ajouté au catalogue.`);
  res.redirect('/admin#outils');
});

router.get('/outils/:id/modifier', (req, res) => {
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(Number(req.params.id));
  if (!tool) {
    setFlash(req, 'error', 'Outil introuvable.');
    return res.redirect('/admin#outils');
  }
  res.render('tool-edit', { tool });
});

router.post('/outils/:id/modifier', (req, res) => {
  const id = Number(req.params.id);
  if (!db.prepare('SELECT id FROM tools WHERE id = ?').get(id)) {
    setFlash(req, 'error', 'Outil introuvable.');
    return res.redirect('/admin#outils');
  }

  const tool = readToolForm(req.body);
  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect(`/admin/outils/${id}/modifier`);
  };

  if (!tool.name) return fail("Merci de renseigner le nom de l'outil.");
  if (tool.loginUrl && !isValidUrl(tool.loginUrl)) return fail('URL de connexion invalide (http:// ou https:// requis).');

  db.prepare('UPDATE tools SET name = ?, category = ?, reference = ?, description = ?, login_url = ? WHERE id = ?')
    .run(tool.name, tool.category, tool.reference, tool.description, tool.loginUrl, id);

  setFlash(req, 'success', `Outil « ${tool.name} » mis à jour.`);
  res.redirect('/admin#outils');
});

router.post('/outils/:id/supprimer', (req, res) => {
  db.prepare('DELETE FROM tools WHERE id = ?').run(Number(req.params.id));
  setFlash(req, 'success', 'Outil supprimé du catalogue.');
  res.redirect('/admin#outils');
});

// ---------- Affectations ----------

router.post('/affectations', (req, res) => {
  const employeeId = Number(req.body.employee_id);
  const toolId = Number(req.body.tool_id);
  const note = (req.body.note || '').trim().slice(0, 300);
  const username = (req.body.username || '').trim().slice(0, 150);

  const employee = findEmployee(employeeId);
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(toolId);

  if (!employee || !tool) {
    setFlash(req, 'error', 'Membre ou outil introuvable.');
    return res.redirect('/admin#affectations');
  }

  try {
    db.prepare('INSERT INTO assignments (employee_id, tool_id, note, username) VALUES (?, ?, ?, ?)')
      .run(employeeId, toolId, note, username);
    setFlash(req, 'success', `« ${tool.name} » affecté à ${employee.first_name} ${employee.last_name}.`);
  } catch {
    setFlash(req, 'error', 'Cet outil est déjà affecté à ce membre.');
  }
  res.redirect('/admin#affectations');
});

router.post('/affectations/:id/supprimer', (req, res) => {
  db.prepare('DELETE FROM assignments WHERE id = ?').run(Number(req.params.id));
  setFlash(req, 'success', 'Affectation retirée.');
  res.redirect('/admin#affectations');
});

// ---------- Gestion des accès RH ----------

// Débloquer un module : son écran, ses routes et son entrée de navigation apparaissent.
/** Palette de l'instance : un choix d'identité, donc réservé à l'administration. */
router.post('/apparence', (req, res) => {
  const key = (req.body.palette || '').trim();
  if (!themes.set(key)) {
    setFlash(req, 'error', 'Palette inconnue.');
    return res.redirect('/admin#apparence');
  }

  audit.log(req, 'apparence.palette_changee', 'settings', null, { palette: key });
  setFlash(req, 'success', `Palette « ${themes.byKey(key).label} » appliquée à toute l'instance.`);
  res.redirect('/admin#apparence');
});

router.post('/modules/:key', (req, res) => {
  const key = req.params.key;
  const module = modules.byKey(key);
  if (!module) {
    setFlash(req, 'error', 'Module inconnu.');
    return res.redirect('/admin#modules');
  }

  const enable = req.body.enabled === 'on';
  modules.setEnabled(key, enable);

  // À la première activation, le module reçoit de quoi ne pas s'ouvrir sur du vide.
  if (enable && key === 'comptabilite') accounting.seedDefaults();
  if (enable && key === 'paie') payroll.seedDefaults();

  setFlash(req, 'success', enable
    ? `Module « ${module.label} » activé.`
    : `Module « ${module.label} » désactivé. Ses données sont conservées.`);
  res.redirect('/admin#modules');
});

router.post('/gestion/nommer', (req, res) => {
  const id = Number(req.body.employee_id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#rh');
  }

  db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} accède à la gestion administrative et financière.`);
  res.redirect('/admin#rh');
});

router.post('/gestion/:id/retirer', (req, res) => {
  db.prepare('UPDATE users SET is_finance = 0 WHERE id = ?').run(Number(req.params.id));
  setFlash(req, 'success', "Accès à la gestion retiré.");
  res.redirect('/admin#rh');
});

router.post('/alerte/nommer', (req, res) => {
  const id = Number(req.body.employee_id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#rh');
  }

  db.prepare('UPDATE users SET is_referent = 1 WHERE id = ?').run(id);
  // La désignation est tracée ; les signalements, eux, ne le sont pas.
  audit.log(req, 'admin.referent_nomme', 'users', id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} devient référent du dispositif d'alerte.`);
  res.redirect('/admin#rh');
});

router.post('/alerte/:id/retirer', (req, res) => {
  const id = Number(req.params.id);
  db.prepare('UPDATE users SET is_referent = 0 WHERE id = ?').run(id);
  audit.log(req, 'admin.referent_retire', 'users', id);
  setFlash(req, 'success', 'Rôle de référent retiré.');
  res.redirect('/admin#rh');
});

router.post('/informatique/nommer', (req, res) => {
  const id = Number(req.body.employee_id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#rh');
  }

  db.prepare('UPDATE users SET is_it = 1 WHERE id = ?').run(id);
  audit.log(req, 'admin.informatique_nomme', 'users', id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} rejoint le service informatique.`);
  res.redirect('/admin#rh');
});

router.post('/informatique/:id/retirer', (req, res) => {
  const id = Number(req.params.id);
  db.prepare('UPDATE users SET is_it = 0 WHERE id = ?').run(id);
  audit.log(req, 'admin.informatique_retire', 'users', id);
  setFlash(req, 'success', "Accès au service informatique retiré.");
  res.redirect('/admin#rh');
});

router.post('/rh/nommer', (req, res) => {
  const id = Number(req.body.employee_id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#rh');
  }

  db.prepare('UPDATE users SET is_hr = 1 WHERE id = ?').run(id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} a désormais accès à l'espace RH.`);
  res.redirect('/admin#rh');
});

router.post('/rh/:id/retirer', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#rh');
  }

  db.prepare('UPDATE users SET is_hr = 0 WHERE id = ?').run(id);
  setFlash(req, 'success', `Accès RH retiré pour ${employee.first_name} ${employee.last_name}.`);
  res.redirect('/admin#rh');
});

module.exports = router;
