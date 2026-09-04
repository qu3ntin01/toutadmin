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
  contract_type TEXT NOT NULL DEFAULT '',
  contract_end_date TEXT,
  daily_rate REAL,
  is_hr INTEGER NOT NULL DEFAULT 0,
  is_finance INTEGER NOT NULL DEFAULT 0,
  leave_balance REAL NOT NULL DEFAULT 0,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
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
  scope TEXT NOT NULL CHECK(scope IN ('company','department','team')),
  scope_id INTEGER,
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

CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE COLLATE NOCASE,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS teams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(name, department_id)
);

-- Un service comme une équipe peuvent avoir plusieurs managers : l'encadrement
-- est une relation, pas une colonne sur le salarié.
CREATE TABLE IF NOT EXISTS org_managers (
  scope TEXT NOT NULL CHECK(scope IN ('department','team')),
  scope_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (scope, scope_id, user_id)
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
  visibility TEXT NOT NULL DEFAULT 'Privé',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_cse_candidacies_election ON cse_candidacies(election_id);
CREATE INDEX IF NOT EXISTS idx_cse_ballots_election ON cse_ballots(election_id);
CREATE INDEX IF NOT EXISTS idx_calendar_events_user ON calendar_events(user_id, start_date);

-- ---------- Tiers : clients et fournisseurs ----------
CREATE TABLE IF NOT EXISTS partners (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL DEFAULT 'Fournisseur' CHECK(kind IN ('Client','Fournisseur','Client et fournisseur')),
  name TEXT NOT NULL,
  registration TEXT NOT NULL DEFAULT '',
  contact_name TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Contrats commerciaux et fournisseurs ----------
CREATE TABLE IF NOT EXISTS partner_contracts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  reference TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL,
  start_date TEXT,
  end_date TEXT,
  notice_days INTEGER NOT NULL DEFAULT 0,
  amount REAL,
  billing_period TEXT NOT NULL DEFAULT 'Annuel',
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'Actif' CHECK(status IN ('Brouillon','Actif','Résilié','Échu')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Factures : recettes et dépenses ----------
CREATE TABLE IF NOT EXISTS invoices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  direction TEXT NOT NULL CHECK(direction IN ('Client','Fournisseur')),
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  issue_date TEXT NOT NULL,
  due_date TEXT,
  amount_ht REAL NOT NULL DEFAULT 0,
  vat_rate REAL NOT NULL DEFAULT 20,
  status TEXT NOT NULL DEFAULT 'Émise' CHECK(status IN ('Brouillon','Émise','Payée','Annulée')),
  paid_at TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Budgets par service ----------
CREATE TABLE IF NOT EXISTS budgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
  year INTEGER NOT NULL,
  amount REAL NOT NULL DEFAULT 0,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(department_id, year)
);

-- ---------- Notes de frais ----------
CREATE TABLE IF NOT EXISTS expense_claims (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  spent_on TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Autre',
  description TEXT NOT NULL DEFAULT '',
  amount REAL NOT NULL,
  status TEXT NOT NULL DEFAULT 'En attente' CHECK(status IN ('En attente','Approuvée','Refusée','Remboursée')),
  reviewed_by INTEGER REFERENCES users(id),
  review_note TEXT NOT NULL DEFAULT '',
  reviewed_at TEXT,
  reimbursed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Parc matériel ----------
CREATE TABLE IF NOT EXISTS assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  reference TEXT NOT NULL DEFAULT '',
  serial_number TEXT NOT NULL DEFAULT '',
  purchase_date TEXT,
  warranty_end TEXT,
  value REAL,
  status TEXT NOT NULL DEFAULT 'Disponible' CHECK(status IN ('Disponible','Affecté','En maintenance','Réformé')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS asset_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  asset_id INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  assigned_at TEXT NOT NULL DEFAULT (date('now')),
  returned_at TEXT,
  note TEXT NOT NULL DEFAULT ''
);

-- ---------- Salles et réservations ----------
CREATE TABLE IF NOT EXISTS rooms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE COLLATE NOCASE,
  location TEXT NOT NULL DEFAULT '',
  capacity INTEGER NOT NULL DEFAULT 0,
  equipment TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS room_bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  booking_date TEXT NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Documents d'entreprise ----------
CREATE TABLE IF NOT EXISTS company_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  url TEXT NOT NULL DEFAULT '',
  requires_ack INTEGER NOT NULL DEFAULT 0,
  published_at TEXT NOT NULL DEFAULT (date('now')),
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS document_acks (
  document_id INTEGER NOT NULL REFERENCES company_documents(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  acked_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (document_id, user_id)
);

-- ---------- Formation ----------
CREATE TABLE IF NOT EXISTS trainings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  provider TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  duration_hours REAL,
  cost REAL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS training_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  training_id INTEGER NOT NULL REFERENCES trainings(id) ON DELETE CASCADE,
  start_date TEXT NOT NULL,
  end_date TEXT,
  location TEXT NOT NULL DEFAULT '',
  seats INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'Planifiée' CHECK(status IN ('Planifiée','Confirmée','Terminée','Annulée')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS training_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL REFERENCES training_sessions(id) ON DELETE CASCADE,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'Demandée' CHECK(status IN ('Demandée','Inscrite','Refusée','Terminée','Annulée')),
  reviewed_by INTEGER REFERENCES users(id),
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(session_id, employee_id)
);

-- ---------- Entretiens annuels ----------
CREATE TABLE IF NOT EXISTS reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  reviewer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  period TEXT NOT NULL,
  scheduled_on TEXT,
  status TEXT NOT NULL DEFAULT 'Planifié' CHECK(status IN ('Planifié','Réalisé','Annulé')),
  strengths TEXT NOT NULL DEFAULT '',
  improvements TEXT NOT NULL DEFAULT '',
  objectives TEXT NOT NULL DEFAULT '',
  employee_comment TEXT NOT NULL DEFAULT '',
  rating INTEGER,
  completed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Recrutement ----------
CREATE TABLE IF NOT EXISTS job_openings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
  contract_type TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Ouvert' CHECK(status IN ('Ouvert','En cours','Pourvu','Annulé')),
  opened_on TEXT NOT NULL DEFAULT (date('now')),
  closed_on TEXT,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS candidates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opening_id INTEGER NOT NULL REFERENCES job_openings(id) ON DELETE CASCADE,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT '',
  stage TEXT NOT NULL DEFAULT 'Reçue' CHECK(stage IN ('Reçue','Présélection','Entretien','Offre','Recruté','Refusé')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_invoices_partner ON invoices(partner_id);
CREATE INDEX IF NOT EXISTS idx_expense_claims_employee ON expense_claims(employee_id);
CREATE INDEX IF NOT EXISTS idx_asset_assignments_asset ON asset_assignments(asset_id);
CREATE INDEX IF NOT EXISTS idx_room_bookings_date ON room_bookings(booking_date);
CREATE INDEX IF NOT EXISTS idx_training_registrations_employee ON training_registrations(employee_id);
CREATE INDEX IF NOT EXISTS idx_reviews_employee ON reviews(employee_id);
CREATE INDEX IF NOT EXISTS idx_candidates_opening ON candidates(opening_id);

CREATE INDEX IF NOT EXISTS idx_hr_requests_employee ON hr_requests(employee_id);
CREATE INDEX IF NOT EXISTS idx_payslips_employee ON payslips(employee_id);
CREATE INDEX IF NOT EXISTS idx_messages_recipient ON messages(recipient_id);
CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id);
CREATE INDEX IF NOT EXISTS idx_org_managers_user ON org_managers(user_id);
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
  "ALTER TABLE users ADD COLUMN is_finance INTEGER NOT NULL DEFAULT 0",
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
  'ALTER TABLE users ADD COLUMN department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL',
  'ALTER TABLE users ADD COLUMN team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL',
  "ALTER TABLE calendar_events ADD COLUMN visibility TEXT NOT NULL DEFAULT 'Privé'",
  "ALTER TABLE announcements ADD COLUMN scope_id INTEGER",
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
    INSERT INTO users (role, email, password_hash, first_name, last_name, grade, active)
    VALUES ('admin', ?, ?, 'Administrateur', 'Général', 'Direction', 1)
  `).run(adminEmail, bcrypt.hashSync(adminPassword, 12));
  console.log(`Compte administrateur créé : ${adminEmail}`);
}

db.exec(`
CREATE INDEX IF NOT EXISTS idx_users_team ON users(team_id);
CREATE INDEX IF NOT EXISTS idx_users_department ON users(department_id);
`);

// ---------- Reprise de l'ancien modèle d'organisation ----------
// Avant, un salarié portait un service en texte libre et un unique manager.
// Services et équipes sont devenus des entités, et l'encadrement une relation
// (plusieurs managers possibles). Cette reprise ne s'exécute qu'une fois.
function migrateOrganisation() {
  const done = db.prepare("SELECT value FROM settings WHERE key = 'org_model_migrated'").get();
  if (done && done.value === '1') return;

  const hasColumn = (table, column) =>
    db.prepare(`PRAGMA table_info(${table})`).all().some((c) => c.name === column);

  const run = db.transaction(() => {
    // 1. Chaque libellé de service distinct devient un service.
    if (hasColumn('users', 'department')) {
      const labels = db
        .prepare("SELECT DISTINCT department AS name FROM users WHERE department IS NOT NULL AND trim(department) != ''")
        .all();
      const insertDepartment = db.prepare('INSERT OR IGNORE INTO departments (name) VALUES (?)');
      const attach = db.prepare('UPDATE users SET department_id = (SELECT id FROM departments WHERE name = ?) WHERE department = ?');
      for (const { name } of labels) {
        insertDepartment.run(name.trim());
        attach.run(name.trim(), name);
      }
    }

    // 2. Chaque encadrant devient le manager d'une équipe portant ses collaborateurs.
    if (hasColumn('users', 'manager_id')) {
      const managers = db
        .prepare('SELECT DISTINCT manager_id AS id FROM users WHERE manager_id IS NOT NULL')
        .all();
      const managerRow = db.prepare('SELECT first_name, last_name, department_id FROM users WHERE id = ?');
      const insertTeam = db.prepare('INSERT INTO teams (name, department_id, description) VALUES (?, ?, ?)');
      const assign = db.prepare('UPDATE users SET team_id = ? WHERE manager_id = ?');
      const addManager = db.prepare("INSERT OR IGNORE INTO org_managers (scope, scope_id, user_id) VALUES ('team', ?, ?)");

      for (const { id } of managers) {
        const manager = managerRow.get(id);
        if (!manager) continue;
        const name = `Équipe ${manager.first_name} ${manager.last_name}`.trim();
        const existing = db.prepare('SELECT id FROM teams WHERE name = ?').get(name);
        const teamId = existing ? existing.id : insertTeam.run(name, manager.department_id || null, "Équipe reprise de l'ancien rattachement hiérarchique.").lastInsertRowid;
        assign.run(teamId, id);
        addManager.run(teamId, id);
      }

      // 3. Les actualités d'équipe suivent l'équipe de leur auteur.
      if (hasColumn('announcements', 'team_manager_id')) {
        db.prepare(`
          UPDATE announcements
          SET scope_id = (SELECT om.scope_id FROM org_managers om WHERE om.scope = 'team' AND om.user_id = announcements.team_manager_id)
          WHERE scope = 'team' AND scope_id IS NULL
        `).run();
      }
    }

    db.prepare("INSERT INTO settings (key, value) VALUES ('org_model_migrated', '1') ON CONFLICT(key) DO UPDATE SET value = '1'").run();
  });

  run();
}

// Une fois la reprise faite, les anciennes colonnes n'ont plus de lecteur.
// La suppression est tentée à chaque démarrage : elle échoue sans bruit quand
// c'est déjà fait, ou quand SQLite est trop ancien pour retirer une colonne.
function dropLegacyOrganisationColumns() {
  const done = db.prepare("SELECT value FROM settings WHERE key = 'org_model_migrated'").get();
  if (!done || done.value !== '1') return;

  for (const statement of [
    // Un index survivant empêcherait de retirer la colonne qu'il porte.
    'DROP INDEX IF EXISTS idx_users_manager',
    'ALTER TABLE users DROP COLUMN department',
    'ALTER TABLE users DROP COLUMN manager_id',
    'ALTER TABLE announcements DROP COLUMN team_manager_id',
  ]) {
    try {
      db.exec(statement);
    } catch {
      // Colonne déjà retirée, ou SQLite trop ancien : elle reste, simplement inutilisée.
    }
  }
}

migrateOrganisation();
dropLegacyOrganisationColumns();

function deactivateExpiredContracts() {
  const today = new Date().toISOString().slice(0, 10);
  const result = db
    .prepare("UPDATE users SET active = 0 WHERE role = 'employee' AND active = 1 AND contract_end_date IS NOT NULL AND contract_end_date != '' AND contract_end_date < ?")
    .run(today);
  return result.changes;
}

module.exports = db;
module.exports.deactivateExpiredContracts = deactivateExpiredContracts;
