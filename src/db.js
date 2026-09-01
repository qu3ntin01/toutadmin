const path = require('path');
const fs = require('fs');
const bcrypt = require('bcryptjs');
const Database = require('better-sqlite3');

const dataDir = path.join(__dirname, '..', 'data');
if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });

const db = new Database(path.join(dataDir, 'app.sqlite'));
db.pragma('journal_mode = WAL');
db.pragma('foreign_keys = ON');

db.exec(`
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  role TEXT NOT NULL CHECK(role IN ('admin','employee')),
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  first_name TEXT NOT NULL DEFAULT '',
  last_name TEXT NOT NULL DEFAULT '',
  grade TEXT NOT NULL DEFAULT '',
  department TEXT NOT NULL DEFAULT '',
  contract_type TEXT NOT NULL DEFAULT '',
  contract_end_date TEXT,
  daily_rate REAL,
  is_hr INTEGER NOT NULL DEFAULT 0,
  leave_balance REAL NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tools (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  reference TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  login_url TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'disponible',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  assigned_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  username TEXT NOT NULL DEFAULT '',
  UNIQUE(employee_id, tool_id)
);

CREATE TABLE IF NOT EXISTS time_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  clock_in TEXT NOT NULL,
  clock_out TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_time_entries_employee ON time_entries(employee_id);

CREATE TABLE IF NOT EXISTS hr_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  type TEXT NOT NULL,
  start_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  days REAL NOT NULL DEFAULT 0,
  reason TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En attente',
  reviewed_by INTEGER REFERENCES users(id),
  review_note TEXT NOT NULL DEFAULT '',
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS leave_adjustments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  amount REAL NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS payslips (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  period TEXT NOT NULL,
  gross_amount REAL NOT NULL,
  net_amount REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'À verser',
  paid_at TEXT,
  note TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_hr_requests_employee ON hr_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_payslips_employee ON payslips(employee_id);
`);

for (const migration of [
  "ALTER TABLE users ADD COLUMN failed_attempts INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN locked_until TEXT",
  "ALTER TABLE users ADD COLUMN contract_type TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE users ADD COLUMN contract_end_date TEXT",
  "ALTER TABLE tools ADD COLUMN login_url TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE assignments ADD COLUMN username TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE users ADD COLUMN daily_rate REAL",
  "ALTER TABLE users ADD COLUMN is_hr INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN leave_balance REAL NOT NULL DEFAULT 0",
]) {
  try {
    db.exec(migration);
  } catch (err) {
    if (!/duplicate column/i.test(err.message)) throw err;
  }
}

const adminEmail = (process.env.ADMIN_EMAIL || 'admin@entreprise.com').toLowerCase().trim();
const adminPassword = process.env.ADMIN_PASSWORD || 'change-moi-123';

const existingAdmin = db.prepare('SELECT id FROM users WHERE email = ?').get(adminEmail);
if (!existingAdmin) {
  if (adminPassword.length < 10) {
    console.warn('Attention : ADMIN_PASSWORD est court. Utilisez un mot de passe fort (12+ caractères) en production.');
  }
  const hash = bcrypt.hashSync(adminPassword, 12);
  db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, active)
    VALUES ('admin', ?, ?, 'Administrateur', 'Général', 'Direction', 'Administration', 1)
  `).run(adminEmail, hash);
  console.log(`Compte administrateur créé : ${adminEmail}`);
}

function deactivateExpiredContracts() {
  const today = new Date().toISOString().slice(0, 10);
  const result = db
    .prepare("UPDATE users SET active = 0 WHERE role = 'employee' AND active = 1 AND contract_end_date IS NOT NULL AND contract_end_date != '' AND contract_end_date < ?")
    .run(today);
  return result.changes;
}

module.exports = db;
module.exports.deactivateExpiredContracts = deactivateExpiredContracts;
