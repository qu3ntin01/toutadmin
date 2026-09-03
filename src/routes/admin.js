const express = require('express');
const bcrypt = require('bcryptjs');

const db = require('../db');
const grades = require('../grades');
const contractTypes = require('../contract-types');
const hr = require('../hr');
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
  const teamSizes = {};
  for (const e of employees) {
    if (e.manager_id) teamSizes[e.manager_id] = (teamSizes[e.manager_id] || 0) + 1;
  }

  res.render('admin', {
    employees,
    tools,
    grades,
    contractTypes,
    toolsByEmployee,
    employeesByTool,
    toolsMap: Object.fromEntries(tools.map((t) => [t.id, t])),
    employeesMap,
    teamSizes,
    managers: employees.filter((e) => teamSizes[e.id]),
    hrMembers: employees.filter((e) => e.is_hr),
    hrEligibleEmployees: employees.filter((e) => !e.is_hr),
    companyNews: announcements.companyWide(),
    pendingRequestCount: db.prepare("SELECT COUNT(*) AS n FROM hr_requests WHERE status = 'En attente'").get().n,
    stats: {
      employeeCount: employees.length,
      toolCount: tools.length,
      assignedCount: assignmentRows.length,
      availableCount: tools.filter((t) => !(employeesByTool[t.id] && employeesByTool[t.id].length)).length,
    },
  });
});

// ---------- Organisation : rattachement hiérarchique et annuaire ----------

router.post('/employes/:id/manager', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#organisation');
  }

  const raw = (req.body.manager_id || '').trim();
  if (!raw) {
    db.prepare('UPDATE users SET manager_id = NULL WHERE id = ?').run(id);
    setFlash(req, 'success', `${employee.first_name} ${employee.last_name} n'est plus rattaché(e) à un manager.`);
    return res.redirect('/admin#organisation');
  }

  const managerId = Number(raw);
  if (managerId === id) {
    setFlash(req, 'error', 'Un membre ne peut pas être son propre manager.');
    return res.redirect('/admin#organisation');
  }

  const manager = findEmployee(managerId);
  if (!manager) {
    setFlash(req, 'error', 'Manager introuvable.');
    return res.redirect('/admin#organisation');
  }

  // Un rattachement circulaire priverait les deux personnes de leur espace équipe.
  if (manager.manager_id === id) {
    setFlash(req, 'error', 'Ce rattachement créerait une boucle hiérarchique.');
    return res.redirect('/admin#organisation');
  }

  db.prepare('UPDATE users SET manager_id = ? WHERE id = ?').run(managerId, id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} est rattaché(e) à ${manager.first_name} ${manager.last_name}.`);
  res.redirect('/admin#organisation');
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

  announcements.create({ authorId: req.session.user.id, scope: 'company', title, body });
  setFlash(req, 'success', "Actualité publiée pour toute l'entreprise.");
  res.redirect('/admin#actualites');
});

router.post('/actualites/:id/supprimer', (req, res) => {
  announcements.remove(Number(req.params.id));
  setFlash(req, 'success', 'Actualité supprimée.');
  res.redirect('/admin#actualites');
});

// ---------- Personnel ----------

router.post('/employes', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').toLowerCase().trim().slice(0, 254);
  const grade = (req.body.grade || '').trim();
  const department = (req.body.department || '').trim().slice(0, 100);
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
  if (db.prepare('SELECT id FROM users WHERE email = ?').get(email)) return fail('Un compte existe déjà avec cet email.');

  const password = generatePassword();
  const initialLeaveBalance = contractType === 'Freelance' ? 0 : hr.DEFAULT_ANNUAL_LEAVE;

  db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, contract_type, contract_end_date, daily_rate, leave_balance, active)
    VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
  `).run(
    email, bcrypt.hashSync(password, 12), firstName, lastName, grade, department,
    contractType, contractEndDate || null, dailyRate.value, initialLeaveBalance
  );

  setFlash(req, 'success', `Membre ajouté. Identifiant : ${email} — Mot de passe temporaire : ${password}`);
  res.redirect('/admin#personnel');
});

router.get('/employes/:id/modifier', (req, res) => {
  const employee = findEmployee(Number(req.params.id));
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }
  res.render('employee-edit', { employee, grades, contractTypes });
});

router.post('/employes/:id/modifier', (req, res) => {
  const id = Number(req.params.id);
  const employee = findEmployee(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }

  const grade = (req.body.grade || '').trim();
  const department = (req.body.department || '').trim().slice(0, 100);
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

  db.prepare('UPDATE users SET grade = ?, department = ?, contract_type = ?, contract_end_date = ?, daily_rate = ? WHERE id = ?')
    .run(grade, department, contractType, contractEndDate || null, dailyRate.value, id);

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
  db.prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?')
    .run(bcrypt.hashSync(password, 12), id);

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
