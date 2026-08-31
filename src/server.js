require('dotenv').config();
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const session = require('express-session');
const helmet = require('helmet');
const bcrypt = require('bcryptjs');

const db = require('./db');
const grades = require('./grades');
const contractTypes = require('./contract-types');
const timesheet = require('./timesheet');
const { requireAdmin, requireEmployee } = require('./middleware/auth');
const security = require('./security');

if (process.env.NODE_ENV === 'production') {
  if (!process.env.SESSION_SECRET || process.env.SESSION_SECRET === 'change-moi-en-production') {
    throw new Error('SESSION_SECRET doit être défini avec une valeur forte et unique en production.');
  }
  if (!process.env.ADMIN_PASSWORD || process.env.ADMIN_PASSWORD === 'change-moi-123') {
    throw new Error('ADMIN_PASSWORD doit être défini avec un mot de passe fort en production.');
  }
}

const app = express();
const PORT = process.env.PORT || 3000;
const isProd = process.env.NODE_ENV === 'production';

if (process.env.TRUST_PROXY) {
  app.set('trust proxy', Number(process.env.TRUST_PROXY) || 1);
}

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, '..', 'views'));
app.disable('x-powered-by');

app.use(security.nonceMiddleware);
app.use(
  helmet({
    contentSecurityPolicy: {
      directives: {
        defaultSrc: ["'self'"],
        baseUri: ["'self'"],
        objectSrc: ["'none'"],
        frameAncestors: ["'none'"],
        formAction: ["'self'"],
        scriptSrc: ["'self'", (req, res) => `'nonce-${res.locals.nonce}'`],
        styleSrc: ["'self'"],
        fontSrc: ["'self'"],
        imgSrc: ["'self'", 'data:'],
        connectSrc: ["'self'"],
        upgradeInsecureRequests: isProd ? [] : null,
      },
    },
    crossOriginEmbedderPolicy: false,
  })
);
app.use(security.globalLimiter);

app.use(express.urlencoded({ extended: true, limit: '20kb' }));
app.use(express.static(path.join(__dirname, '..', 'public'), { maxAge: isProd ? '1d' : 0 }));

app.use(
  session({
    name: 'pm.sid',
    secret: process.env.SESSION_SECRET || 'dev-secret-non-securise',
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: 'lax',
      secure: isProd,
      maxAge: 1000 * 60 * 60 * 8,
    },
  })
);

app.use(security.csrfMiddleware);

app.use((req, res, next) => {
  res.locals.currentUser = req.session.user || null;
  res.locals.flash = req.session.flash || null;
  delete req.session.flash;
  next();
});

function setFlash(req, type, message) {
  req.session.flash = { type, message };
}

function generatePassword() {
  return crypto.randomBytes(9).toString('base64').replace(/[^a-zA-Z0-9]/g, '').slice(0, 12);
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && email.length <= 254;
}

function isValidDateString(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime());
}

function isValidUrl(value) {
  try {
    const u = new URL(value);
    return u.protocol === 'http:' || u.protocol === 'https:';
  } catch {
    return false;
  }
}

function parseDailyRate(value) {
  const trimmed = (value || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const n = Number(trimmed.replace(',', '.'));
  if (!Number.isFinite(n) || n < 0 || n > 100000) return { ok: false };
  return { ok: true, value: Math.round(n * 100) / 100 };
}

// Désactive automatiquement les comptes dont le contrat est arrivé à échéance.
db.deactivateExpiredContracts();

// ---------- Authentification ----------

app.get('/', (req, res) => {
  if (!req.session.user) return res.redirect('/connexion');
  return res.redirect(req.session.user.role === 'admin' ? '/admin' : '/mon-espace');
});

app.get('/connexion', (req, res) => {
  if (req.session.user) return res.redirect(req.session.user.role === 'admin' ? '/admin' : '/mon-espace');
  res.render('login');
});

app.post('/connexion', security.loginLimiter, (req, res) => {
  const email = (req.body.email || '').toLowerCase().trim();
  const password = req.body.password || '';
  const genericError = () => {
    setFlash(req, 'error', 'Identifiants incorrects.');
    return res.redirect('/connexion');
  };

  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);

  if (!user) {
    bcrypt.compareSync(password, security.DUMMY_HASH); // constant-time: avoids leaking account existence
    return genericError();
  }

  if (security.isLocked(user)) {
    setFlash(req, 'error', `Compte temporairement verrouillé suite à plusieurs échecs. Réessayez dans ${security.LOCKOUT_MINUTES} minutes.`);
    return res.redirect('/connexion');
  }

  const today = new Date().toISOString().slice(0, 10);
  if (user.contract_end_date && user.contract_end_date < today) {
    if (user.active) db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(user.id);
    setFlash(req, 'error', 'Ce compte est arrivé au terme de son contrat et a été désactivé.');
    return res.redirect('/connexion');
  }

  if (!user.active || !bcrypt.compareSync(password, user.password_hash)) {
    if (user.active) security.registerFailedAttempt(user);
    return genericError();
  }

  security.resetFailedAttempts(user.id);

  req.session.regenerate((err) => {
    if (err) return genericError();
    req.session.user = {
      id: user.id,
      role: user.role,
      email: user.email,
      firstName: user.first_name,
      lastName: user.last_name,
      grade: user.grade,
    };
    res.redirect(user.role === 'admin' ? '/admin' : '/mon-espace');
  });
});

app.post('/deconnexion', (req, res) => {
  req.session.destroy(() => res.redirect('/connexion'));
});

// ---------- Espace administrateur ----------
// Toutes les routes /admin/* exigent le rôle 'admin' (middleware requireAdmin) :
// seul un compte administrateur peut créer, modifier ou supprimer des membres.

app.get('/admin', requireAdmin, (req, res) => {
  db.deactivateExpiredContracts();

  const employees = db.prepare("SELECT * FROM users WHERE role = 'employee' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE").all();
  const tools = db.prepare('SELECT * FROM tools ORDER BY name COLLATE NOCASE').all();

  const assignmentRows = db.prepare(`
    SELECT a.id, a.employee_id, a.tool_id, a.assigned_at, a.note, a.username
    FROM assignments a
  `).all();

  const toolsByEmployee = {};
  const employeesByTool = {};
  for (const row of assignmentRows) {
    (toolsByEmployee[row.employee_id] ||= []).push(row);
    (employeesByTool[row.tool_id] ||= []).push(row);
  }

  const toolsMap = Object.fromEntries(tools.map((t) => [t.id, t]));
  const employeesMap = Object.fromEntries(employees.map((e) => [e.id, e]));

  const freelancers = employees.filter((e) => e.contract_type === 'Freelance');
  const freelanceStats = Object.fromEntries(freelancers.map((e) => [e.id, timesheet.getStats(e.id, e.daily_rate)]));
  const openEntriesMap = Object.fromEntries(freelancers.map((e) => [e.id, Boolean(timesheet.getOpenEntry(e.id))]));

  res.render('admin', {
    employees,
    tools,
    grades,
    contractTypes,
    toolsByEmployee,
    employeesByTool,
    toolsMap,
    employeesMap,
    freelancers,
    freelanceStats,
    openEntriesMap,
    stats: {
      employeeCount: employees.length,
      toolCount: tools.length,
      assignedCount: assignmentRows.length,
      availableCount: tools.filter((t) => !(employeesByTool[t.id] && employeesByTool[t.id].length)).length,
    },
  });
});

app.post('/admin/employes', requireAdmin, (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').toLowerCase().trim().slice(0, 254);
  const grade = (req.body.grade || '').trim();
  const department = (req.body.department || '').trim().slice(0, 100);
  const contractType = (req.body.contract_type || '').trim();
  const contractEndDate = (req.body.contract_end_date || '').trim();
  const dailyRateResult = parseDailyRate(req.body.daily_rate);

  if (!firstName || !lastName || !email || !grade || !contractType) {
    setFlash(req, 'error', 'Merci de renseigner le prénom, le nom, l\'email, le grade et le type de contrat.');
    return res.redirect('/admin#personnel');
  }

  if (!isValidEmail(email)) {
    setFlash(req, 'error', 'Adresse email invalide.');
    return res.redirect('/admin#personnel');
  }

  if (!grades.includes(grade)) {
    setFlash(req, 'error', 'Grade invalide.');
    return res.redirect('/admin#personnel');
  }

  if (!contractTypes.includes(contractType)) {
    setFlash(req, 'error', 'Type de contrat invalide.');
    return res.redirect('/admin#personnel');
  }

  if (contractEndDate && !isValidDateString(contractEndDate)) {
    setFlash(req, 'error', 'Date de fin de contrat invalide.');
    return res.redirect('/admin#personnel');
  }

  if (!dailyRateResult.ok) {
    setFlash(req, 'error', 'TJM invalide.');
    return res.redirect('/admin#personnel');
  }

  const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  if (existing) {
    setFlash(req, 'error', 'Un compte existe déjà avec cet email.');
    return res.redirect('/admin#personnel');
  }

  const password = generatePassword();
  const hash = bcrypt.hashSync(password, 12);

  db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, contract_type, contract_end_date, daily_rate, active)
    VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
  `).run(email, hash, firstName, lastName, grade, department, contractType, contractEndDate || null, dailyRateResult.value);

  setFlash(req, 'success', `Membre ajouté. Identifiant : ${email} — Mot de passe temporaire : ${password}`);
  res.redirect('/admin#personnel');
});

app.get('/admin/employes/:id/modifier', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }
  res.render('employee-edit', { employee, grades, contractTypes });
});

app.post('/admin/employes/:id/modifier', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#personnel');
  }

  const grade = (req.body.grade || '').trim();
  const department = (req.body.department || '').trim().slice(0, 100);
  const contractType = (req.body.contract_type || '').trim();
  const contractEndDate = (req.body.contract_end_date || '').trim();
  const dailyRateResult = parseDailyRate(req.body.daily_rate);

  if (!grade || !contractTypes.includes(contractType) || !grades.includes(grade)) {
    setFlash(req, 'error', 'Grade ou type de contrat invalide.');
    return res.redirect(`/admin/employes/${id}/modifier`);
  }

  if (contractEndDate && !isValidDateString(contractEndDate)) {
    setFlash(req, 'error', 'Date de fin de contrat invalide.');
    return res.redirect(`/admin/employes/${id}/modifier`);
  }

  if (!dailyRateResult.ok) {
    setFlash(req, 'error', 'TJM invalide.');
    return res.redirect(`/admin/employes/${id}/modifier`);
  }

  db.prepare('UPDATE users SET grade = ?, department = ?, contract_type = ?, contract_end_date = ?, daily_rate = ? WHERE id = ?')
    .run(grade, department, contractType, contractEndDate || null, dailyRateResult.value, id);

  setFlash(req, 'success', `Profil de ${employee.first_name} ${employee.last_name} mis à jour.`);
  res.redirect('/admin#personnel');
});

app.post('/admin/employes/:id/statut', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) return res.redirect('/admin#personnel');

  db.prepare('UPDATE users SET active = ? WHERE id = ?').run(employee.active ? 0 : 1, id);
  setFlash(req, 'success', `${employee.first_name} ${employee.last_name} est désormais ${employee.active ? 'désactivé(e)' : 'actif(ve)'}.`);
  res.redirect('/admin#personnel');
});

app.post('/admin/employes/:id/reinitialiser', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) return res.redirect('/admin#personnel');

  const password = generatePassword();
  db.prepare('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?').run(bcrypt.hashSync(password, 12), id);
  setFlash(req, 'success', `Nouveau mot de passe pour ${employee.email} : ${password}`);
  res.redirect('/admin#personnel');
});

app.post('/admin/employes/:id/supprimer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  db.prepare("DELETE FROM users WHERE id = ? AND role = 'employee'").run(id);
  setFlash(req, 'success', 'Membre supprimé.');
  res.redirect('/admin#personnel');
});

app.get('/admin/employes/:id/temps', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) {
    setFlash(req, 'error', 'Membre introuvable.');
    return res.redirect('/admin#remuneration');
  }

  const entries = timesheet.getEntries(id, 200);
  const openEntry = timesheet.getOpenEntry(id);
  const statsData = timesheet.getStats(id, employee.daily_rate);

  res.render('employee-timesheet', { employee, entries, openEntry, statsData });
});

app.post('/admin/employes/:id/temps/cloturer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(id);
  if (!employee) return res.redirect('/admin#remuneration');

  const result = timesheet.clockOut(id);
  setFlash(req, result.ok ? 'success' : 'error', result.ok ? 'Pointage clôturé.' : 'Aucun pointage en cours pour ce membre.');
  res.redirect(`/admin/employes/${id}/temps`);
});

app.post('/admin/employes/:id/temps/:entryId/supprimer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const entryId = Number(req.params.entryId);
  db.prepare('DELETE FROM time_entries WHERE id = ? AND employee_id = ?').run(entryId, id);
  setFlash(req, 'success', 'Entrée supprimée.');
  res.redirect(`/admin/employes/${id}/temps`);
});

app.post('/admin/outils', requireAdmin, (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 150);
  const category = (req.body.category || '').trim().slice(0, 100);
  const reference = (req.body.reference || '').trim().slice(0, 100);
  const description = (req.body.description || '').trim().slice(0, 1000);
  const loginUrl = (req.body.login_url || '').trim().slice(0, 500);

  if (!name) {
    setFlash(req, 'error', "Merci de renseigner le nom de l'outil.");
    return res.redirect('/admin#outils');
  }

  if (loginUrl && !isValidUrl(loginUrl)) {
    setFlash(req, 'error', "URL de connexion invalide (http:// ou https:// requis).");
    return res.redirect('/admin#outils');
  }

  db.prepare(`
    INSERT INTO tools (name, category, reference, description, login_url, status)
    VALUES (?, ?, ?, ?, ?, 'disponible')
  `).run(name, category, reference, description, loginUrl);

  setFlash(req, 'success', `Outil « ${name} » ajouté au catalogue.`);
  res.redirect('/admin#outils');
});

app.get('/admin/outils/:id/modifier', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(id);
  if (!tool) {
    setFlash(req, 'error', 'Outil introuvable.');
    return res.redirect('/admin#outils');
  }
  res.render('tool-edit', { tool });
});

app.post('/admin/outils/:id/modifier', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(id);
  if (!tool) {
    setFlash(req, 'error', 'Outil introuvable.');
    return res.redirect('/admin#outils');
  }

  const name = (req.body.name || '').trim().slice(0, 150);
  const category = (req.body.category || '').trim().slice(0, 100);
  const reference = (req.body.reference || '').trim().slice(0, 100);
  const description = (req.body.description || '').trim().slice(0, 1000);
  const loginUrl = (req.body.login_url || '').trim().slice(0, 500);

  if (!name) {
    setFlash(req, 'error', "Merci de renseigner le nom de l'outil.");
    return res.redirect(`/admin/outils/${id}/modifier`);
  }

  if (loginUrl && !isValidUrl(loginUrl)) {
    setFlash(req, 'error', "URL de connexion invalide (http:// ou https:// requis).");
    return res.redirect(`/admin/outils/${id}/modifier`);
  }

  db.prepare('UPDATE tools SET name = ?, category = ?, reference = ?, description = ?, login_url = ? WHERE id = ?')
    .run(name, category, reference, description, loginUrl, id);

  setFlash(req, 'success', `Outil « ${name} » mis à jour.`);
  res.redirect('/admin#outils');
});

app.post('/admin/outils/:id/supprimer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  db.prepare('DELETE FROM tools WHERE id = ?').run(id);
  setFlash(req, 'success', 'Outil supprimé du catalogue.');
  res.redirect('/admin#outils');
});

app.post('/admin/affectations', requireAdmin, (req, res) => {
  const employeeId = Number(req.body.employee_id);
  const toolId = Number(req.body.tool_id);
  const note = (req.body.note || '').trim().slice(0, 300);
  const username = (req.body.username || '').trim().slice(0, 150);

  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(employeeId);
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(toolId);

  if (!employee || !tool) {
    setFlash(req, 'error', 'Membre ou outil introuvable.');
    return res.redirect('/admin#affectations');
  }

  try {
    db.prepare('INSERT INTO assignments (employee_id, tool_id, note, username) VALUES (?, ?, ?, ?)').run(employeeId, toolId, note, username);
    setFlash(req, 'success', `« ${tool.name} » affecté à ${employee.first_name} ${employee.last_name}.`);
  } catch (err) {
    setFlash(req, 'error', 'Cet outil est déjà affecté à ce membre.');
  }
  res.redirect('/admin#affectations');
});

app.post('/admin/affectations/:id/supprimer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  db.prepare('DELETE FROM assignments WHERE id = ?').run(id);
  setFlash(req, 'success', 'Affectation retirée.');
  res.redirect('/admin#affectations');
});

// ---------- Espace employé ----------

app.get('/mon-espace', requireEmployee, (req, res) => {
  const employeeId = req.session.user.id;
  const employee = db.prepare('SELECT * FROM users WHERE id = ?').get(employeeId);
  const tools = db.prepare(`
    SELECT t.*, a.assigned_at, a.note, a.username
    FROM assignments a
    JOIN tools t ON t.id = a.tool_id
    WHERE a.employee_id = ?
    ORDER BY a.assigned_at DESC
  `).all(employeeId);

  const isFreelance = employee.contract_type === 'Freelance';
  const openEntry = isFreelance ? timesheet.getOpenEntry(employeeId) : null;
  const entries = isFreelance ? timesheet.getEntries(employeeId, 30) : [];
  const statsData = isFreelance ? timesheet.getStats(employeeId, employee.daily_rate) : null;

  res.render('employee', { tools, employee, isFreelance, openEntry, entries, statsData });
});

app.post('/mon-espace/pointage/commencer', requireEmployee, (req, res) => {
  const employee = db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
  if (!employee || employee.contract_type !== 'Freelance') return res.redirect('/mon-espace');

  const result = timesheet.clockIn(employee.id);
  if (!result.ok) setFlash(req, 'error', 'Un pointage est déjà en cours.');
  res.redirect('/mon-espace');
});

app.post('/mon-espace/pointage/terminer', requireEmployee, (req, res) => {
  const employee = db.prepare('SELECT * FROM users WHERE id = ?').get(req.session.user.id);
  if (!employee || employee.contract_type !== 'Freelance') return res.redirect('/mon-espace');

  const result = timesheet.clockOut(employee.id);
  if (!result.ok) setFlash(req, 'error', 'Aucun pointage en cours.');
  res.redirect('/mon-espace');
});

app.use((req, res) => {
  res.status(404).render('error', { message: 'Page introuvable.' });
});

// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
  console.error(err);
  res.status(500).render('error', { message: 'Une erreur est survenue. Merci de réessayer.' });
});

app.listen(PORT, () => {
  console.log(`Private Member — serveur démarré sur http://localhost:${PORT}`);
});

setInterval(() => {
  db.deactivateExpiredContracts();
}, 60 * 60 * 1000);
