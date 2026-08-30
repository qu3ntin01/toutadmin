require('dotenv').config();
const path = require('path');
const crypto = require('crypto');
const express = require('express');
const session = require('express-session');
const bcrypt = require('bcryptjs');

const db = require('./db');
const grades = require('./grades');
const { requireAdmin, requireEmployee } = require('./middleware/auth');

const app = express();
const PORT = process.env.PORT || 3000;

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, '..', 'views'));

app.use(express.urlencoded({ extended: true }));
app.use(express.static(path.join(__dirname, '..', 'public')));
app.use(session({
  secret: process.env.SESSION_SECRET || 'dev-secret-non-securise',
  resave: false,
  saveUninitialized: false,
  cookie: { httpOnly: true, maxAge: 1000 * 60 * 60 * 8 },
}));

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
  return crypto.randomBytes(6).toString('base64').replace(/[^a-zA-Z0-9]/g, '').slice(0, 10);
}

// ---------- Authentification ----------

app.get('/', (req, res) => {
  if (!req.session.user) return res.redirect('/connexion');
  return res.redirect(req.session.user.role === 'admin' ? '/admin' : '/mon-espace');
});

app.get('/connexion', (req, res) => {
  if (req.session.user) return res.redirect(req.session.user.role === 'admin' ? '/admin' : '/mon-espace');
  res.render('login');
});

app.post('/connexion', (req, res) => {
  const email = (req.body.email || '').toLowerCase().trim();
  const password = req.body.password || '';

  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(email);
  if (!user || !user.active || !bcrypt.compareSync(password, user.password_hash)) {
    setFlash(req, 'error', 'Identifiants incorrects.');
    return res.redirect('/connexion');
  }

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

app.post('/deconnexion', (req, res) => {
  req.session.destroy(() => res.redirect('/connexion'));
});

// ---------- Espace administrateur ----------

app.get('/admin', requireAdmin, (req, res) => {
  const employees = db.prepare("SELECT * FROM users WHERE role = 'employee' ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE").all();
  const tools = db.prepare('SELECT * FROM tools ORDER BY name COLLATE NOCASE').all();

  const assignmentRows = db.prepare(`
    SELECT a.id, a.employee_id, a.tool_id, a.assigned_at, a.note
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

  res.render('admin', {
    employees,
    tools,
    grades,
    toolsByEmployee,
    employeesByTool,
    toolsMap,
    employeesMap,
    stats: {
      employeeCount: employees.length,
      toolCount: tools.length,
      assignedCount: assignmentRows.length,
      availableCount: tools.filter((t) => !(employeesByTool[t.id] && employeesByTool[t.id].length)).length,
    },
  });
});

app.post('/admin/employes', requireAdmin, (req, res) => {
  const firstName = (req.body.first_name || '').trim();
  const lastName = (req.body.last_name || '').trim();
  const email = (req.body.email || '').toLowerCase().trim();
  const grade = (req.body.grade || '').trim();
  const department = (req.body.department || '').trim();

  if (!firstName || !lastName || !email || !grade) {
    setFlash(req, 'error', 'Merci de renseigner le prénom, le nom, l\'email et le grade.');
    return res.redirect('/admin#personnel');
  }

  const existing = db.prepare('SELECT id FROM users WHERE email = ?').get(email);
  if (existing) {
    setFlash(req, 'error', 'Un compte existe déjà avec cet email.');
    return res.redirect('/admin#personnel');
  }

  const password = generatePassword();
  const hash = bcrypt.hashSync(password, 10);

  db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, active)
    VALUES ('employee', ?, ?, ?, ?, ?, ?, 1)
  `).run(email, hash, firstName, lastName, grade, department);

  setFlash(req, 'success', `Membre ajouté. Identifiant : ${email} — Mot de passe temporaire : ${password}`);
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
  db.prepare('UPDATE users SET password_hash = ? WHERE id = ?').run(bcrypt.hashSync(password, 10), id);
  setFlash(req, 'success', `Nouveau mot de passe pour ${employee.email} : ${password}`);
  res.redirect('/admin#personnel');
});

app.post('/admin/employes/:id/supprimer', requireAdmin, (req, res) => {
  const id = Number(req.params.id);
  db.prepare("DELETE FROM users WHERE id = ? AND role = 'employee'").run(id);
  setFlash(req, 'success', 'Membre supprimé.');
  res.redirect('/admin#personnel');
});

app.post('/admin/outils', requireAdmin, (req, res) => {
  const name = (req.body.name || '').trim();
  const category = (req.body.category || '').trim();
  const reference = (req.body.reference || '').trim();
  const description = (req.body.description || '').trim();

  if (!name) {
    setFlash(req, 'error', "Merci de renseigner le nom de l'outil.");
    return res.redirect('/admin#outils');
  }

  db.prepare(`
    INSERT INTO tools (name, category, reference, description, status)
    VALUES (?, ?, ?, ?, 'disponible')
  `).run(name, category, reference, description);

  setFlash(req, 'success', `Outil « ${name} » ajouté au catalogue.`);
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
  const note = (req.body.note || '').trim();

  const employee = db.prepare("SELECT * FROM users WHERE id = ? AND role = 'employee'").get(employeeId);
  const tool = db.prepare('SELECT * FROM tools WHERE id = ?').get(toolId);

  if (!employee || !tool) {
    setFlash(req, 'error', 'Membre ou outil introuvable.');
    return res.redirect('/admin#affectations');
  }

  try {
    db.prepare('INSERT INTO assignments (employee_id, tool_id, note) VALUES (?, ?, ?)').run(employeeId, toolId, note);
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
  const tools = db.prepare(`
    SELECT t.*, a.assigned_at, a.note
    FROM assignments a
    JOIN tools t ON t.id = a.tool_id
    WHERE a.employee_id = ?
    ORDER BY a.assigned_at DESC
  `).all(employeeId);

  res.render('employee', { tools });
});

app.use((req, res) => {
  res.status(404).render('error', { message: 'Page introuvable.' });
});

app.listen(PORT, () => {
  console.log(`Private Member — serveur démarré sur http://localhost:${PORT}`);
});
