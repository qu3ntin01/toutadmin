const path = require('path');
const fs = require('fs');
const bcrypt = require('bcryptjs');
const Database = require('better-sqlite3');

// DB_PATH permet d'isoler la base (tests, instances multiples) ; défaut : data/app.sqlite.
const dbPath = process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite');
const dataDir = path.dirname(dbPath);
if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });

const db = new Database(dbPath);
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
  manager_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  avatar_file TEXT,
  bio TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  directory_hidden INTEGER NOT NULL DEFAULT 0,
  locale TEXT NOT NULL DEFAULT 'fr',
  mail_address TEXT NOT NULL DEFAULT '',
  mail_imap_host TEXT NOT NULL DEFAULT '',
  mail_imap_port INTEGER,
  mail_smtp_host TEXT NOT NULL DEFAULT '',
  mail_smtp_port INTEGER,
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

CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  scope TEXT NOT NULL CHECK(scope IN ('company','team')),
  team_manager_id INTEGER REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  parent_id INTEGER REFERENCES messages(id) ON DELETE SET NULL,
  read_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS cse_mandates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE,
  mandate_role TEXT NOT NULL DEFAULT 'Titulaire',
  started_on TEXT NOT NULL DEFAULT (date('now')),
  ends_on TEXT,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS cse_elections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  seats INTEGER NOT NULL DEFAULT 1,
  status TEXT NOT NULL DEFAULT 'Candidatures' CHECK(status IN ('Candidatures','Vote','Clôturée')),
  candidacy_deadline TEXT,
  vote_start TEXT,
  vote_end TEXT,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  closed_at TEXT
);

CREATE TABLE IF NOT EXISTS cse_candidacies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  election_id INTEGER NOT NULL REFERENCES cse_elections(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  statement TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En attente' CHECK(status IN ('En attente','Validée','Refusée')),
  reviewed_by INTEGER REFERENCES users(id),
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(election_id, user_id)
);

-- Bulletin dépouillable mais anonyme : aucune colonne ne relie un bulletin à son électeur.
CREATE TABLE IF NOT EXISTS cse_ballots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  election_id INTEGER NOT NULL REFERENCES cse_elections(id) ON DELETE CASCADE,
  candidacy_id INTEGER NOT NULL REFERENCES cse_candidacies(id) ON DELETE CASCADE,
  cast_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Émargement : dit qui a voté, jamais pour qui.
CREATE TABLE IF NOT EXISTS cse_voters (
  election_id INTEGER NOT NULL REFERENCES cse_elections(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  voted_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (election_id, user_id)
);

CREATE TABLE IF NOT EXISTS cse_meetings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  meeting_date TEXT NOT NULL,
  meeting_time TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  agenda TEXT NOT NULL DEFAULT '',
  minutes TEXT NOT NULL DEFAULT '',
  minutes_published INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS cse_benefits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  partner TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  discount TEXT NOT NULL DEFAULT '',
  code TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '',
  valid_until TEXT,
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS calendar_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  start_date TEXT NOT NULL,
  end_date TEXT NOT NULL,
  start_time TEXT NOT NULL DEFAULT '',
  end_time TEXT NOT NULL DEFAULT '',
  all_day INTEGER NOT NULL DEFAULT 1,
  category TEXT NOT NULL DEFAULT 'Personnel',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_cse_candidacies_election ON cse_candidacies(election_id);
CREATE INDEX IF NOT EXISTS idx_cse_ballots_election ON cse_ballots(election_id);
CREATE INDEX IF NOT EXISTS idx_calendar_events_user ON calendar_events(user_id, start_date);

CREATE INDEX IF NOT EXISTS idx_hr_requests_employee ON hr_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_payslips_employee ON payslips(employee_id);
CREATE INDEX IF NOT EXISTS idx_messages_recipient ON messages(recipient_id);
CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_users_manager ON users(manager_id);
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
  'ALTER TABLE users ADD COLUMN manager_id INTEGER REFERENCES users(id) ON DELETE SET NULL',
  'ALTER TABLE users ADD COLUMN avatar_file TEXT',
  "ALTER TABLE users ADD COLUMN bio TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE users ADD COLUMN phone TEXT NOT NULL DEFAULT ''",
  'ALTER TABLE users ADD COLUMN directory_hidden INTEGER NOT NULL DEFAULT 0',
  "ALTER TABLE users ADD COLUMN locale TEXT NOT NULL DEFAULT 'fr'",
  "ALTER TABLE users ADD COLUMN mail_address TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE users ADD COLUMN mail_imap_host TEXT NOT NULL DEFAULT ''",
  'ALTER TABLE users ADD COLUMN mail_imap_port INTEGER',
  "ALTER TABLE users ADD COLUMN mail_smtp_host TEXT NOT NULL DEFAULT ''",
  'ALTER TABLE users ADD COLUMN mail_smtp_port INTEGER',
]) {
  try {
    db.exec(migration);
  } catch (err) {
    if (!/duplicate column/i.test(err.message)) throw err;
  }
}

// Installation sans interface (conteneur, CI, déploiement automatisé) : renseigner
// ADMIN_EMAIL et ADMIN_PASSWORD crée le compte au démarrage. Sans ces variables,
// aucun compte n'est créé et l'assistant d'installation prend le relais.
const adminEmail = (process.env.ADMIN_EMAIL || '').toLowerCase().trim();
const adminPassword = process.env.ADMIN_PASSWORD || '';

if (adminEmail && adminPassword && !db.prepare('SELECT id FROM users WHERE email = ?').get(adminEmail)) {
  if (adminPassword.length < 10) {
    console.warn('Attention : ADMIN_PASSWORD est court. Utilisez un mot de passe fort (12+ caractères) en production.');
  }
  db.prepare(`
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, department, active)
    VALUES ('admin', ?, ?, 'Administrateur', 'Général', 'Direction', 'Administration', 1)
  `).run(adminEmail, bcrypt.hashSync(adminPassword, 12));
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
