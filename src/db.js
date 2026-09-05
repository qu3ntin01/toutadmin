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
-- Sessions : persistées en base plutôt qu'en mémoire, pour survivre à un
-- redémarrage, tenir plusieurs processus, et permettre de révoquer d'un coup
-- toutes les sessions d'un compte.
CREATE TABLE IF NOT EXISTS sessions (
  sid TEXT PRIMARY KEY,
  user_id INTEGER,
  data TEXT NOT NULL,
  expires_at INTEGER NOT NULL
);

-- Journal d'audit : qui a fait quoi, quand, depuis où. Inscrit en base, jamais
-- modifiable depuis l'interface, et conservé même si l'objet visé est supprimé —
-- c'est précisément la trace d'une suppression qui compte.
CREATE TABLE IF NOT EXISTS audit_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  occurred_at TEXT NOT NULL DEFAULT (datetime('now')),
  actor_id INTEGER,
  actor_label TEXT NOT NULL DEFAULT '',
  action TEXT NOT NULL,
  entity TEXT NOT NULL DEFAULT '',
  entity_id INTEGER,
  detail TEXT NOT NULL DEFAULT '',
  ip TEXT NOT NULL DEFAULT ''
);

-- Codes de secours de la double authentification : hachés, à usage unique.
CREATE TABLE IF NOT EXISTS totp_recovery_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash TEXT NOT NULL,
  used_at TEXT
);

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
  gross_salary REAL,
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
  must_change_password INTEGER NOT NULL DEFAULT 0,
  totp_secret TEXT,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  password_changed_at TEXT,
  last_login_at TEXT,
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

CREATE INDEX IF NOT EXISTS idx_recovery_user ON totp_recovery_codes(user_id);
CREATE INDEX IF NOT EXISTS idx_audit_date ON audit_log(occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_actor ON audit_log(actor_id);
CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log(action);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_sessions_expiry ON sessions(expires_at);
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
  min_experience REAL NOT NULL DEFAULT 0,
  ats_threshold INTEGER NOT NULL DEFAULT 60,
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
  cv_file TEXT,
  cv_name TEXT NOT NULL DEFAULT '',
  cv_text TEXT NOT NULL DEFAULT '',
  cv_uploaded_at TEXT,
  experience_years REAL,
  ats_score INTEGER,
  ats_detail TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Comptabilité ----------
CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('Actif','Passif','Charge','Produit')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS journals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  journal_id INTEGER NOT NULL REFERENCES journals(id) ON DELETE RESTRICT,
  entry_date TEXT NOT NULL,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS entry_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
  label TEXT NOT NULL DEFAULT '',
  debit REAL NOT NULL DEFAULT 0,
  credit REAL NOT NULL DEFAULT 0,
  reconciliation TEXT NOT NULL DEFAULT ''
);

-- ---------- Paie ----------
CREATE TABLE IF NOT EXISTS payroll_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  base TEXT NOT NULL DEFAULT 'Brut' CHECK(base IN ('Brut','Plafond')),
  employee_rate REAL NOT NULL DEFAULT 0,
  employer_rate REAL NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS payslip_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  payslip_id INTEGER NOT NULL REFERENCES payslips(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  base_amount REAL NOT NULL DEFAULT 0,
  employee_rate REAL NOT NULL DEFAULT 0,
  employer_rate REAL NOT NULL DEFAULT 0,
  employee_amount REAL NOT NULL DEFAULT 0,
  employer_amount REAL NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- ---------- Stock et achats ----------
CREATE TABLE IF NOT EXISTS items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  unit TEXT NOT NULL DEFAULT 'unité',
  category TEXT NOT NULL DEFAULT '',
  stock_min REAL NOT NULL DEFAULT 0,
  unit_price REAL,
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK(kind IN ('Entrée','Sortie','Inventaire')),
  quantity REAL NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  moved_on TEXT NOT NULL DEFAULT (date('now')),
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS purchase_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  estimated_amount REAL NOT NULL DEFAULT 0,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  justification TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Manager' CHECK(status IN ('Manager','Gestion','Approuvée','Refusée','Annulée','Commandée')),
  manager_reviewed_by INTEGER REFERENCES users(id),
  manager_reviewed_at TEXT,
  finance_reviewed_by INTEGER REFERENCES users(id),
  finance_reviewed_at TEXT,
  review_note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- CRM ----------
CREATE TABLE IF NOT EXISTS crm_contacts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  first_name TEXT NOT NULL,
  last_name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS opportunities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  amount REAL NOT NULL DEFAULT 0,
  stage TEXT NOT NULL DEFAULT 'Qualification' CHECK(stage IN ('Qualification','Proposition','Négociation','Gagnée','Perdue')),
  probability INTEGER NOT NULL DEFAULT 50,
  expected_close TEXT,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  notes TEXT NOT NULL DEFAULT '',
  closed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS quotes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  opportunity_id INTEGER REFERENCES opportunities(id) ON DELETE SET NULL,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  issue_date TEXT NOT NULL DEFAULT (date('now')),
  valid_until TEXT,
  amount_ht REAL NOT NULL DEFAULT 0,
  vat_rate REAL NOT NULL DEFAULT 20,
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Envoyé','Accepté','Refusé','Expiré')),
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS crm_activities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER REFERENCES partners(id) ON DELETE CASCADE,
  opportunity_id INTEGER REFERENCES opportunities(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Relance',
  due_on TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  done_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Critères ATS d'un poste ----------
CREATE TABLE IF NOT EXISTS opening_criteria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opening_id INTEGER NOT NULL REFERENCES job_openings(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  keywords TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Souhaité' CHECK(kind IN ('Requis','Souhaité')),
  weight INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_opening_criteria_opening ON opening_criteria(opening_id);

CREATE INDEX IF NOT EXISTS idx_entry_lines_entry ON entry_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_entry_lines_account ON entry_lines(account_id);
CREATE INDEX IF NOT EXISTS idx_stock_movements_item ON stock_movements(item_id);
CREATE INDEX IF NOT EXISTS idx_payslip_lines_payslip ON payslip_lines(payslip_id);
CREATE INDEX IF NOT EXISTS idx_opportunities_partner ON opportunities(partner_id);

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
  "ALTER TABLE users ADD COLUMN gross_salary REAL",
  // CV et évaluation ATS d'une candidature.
  "ALTER TABLE candidates ADD COLUMN cv_file TEXT",
  "ALTER TABLE candidates ADD COLUMN cv_name TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE candidates ADD COLUMN cv_text TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE candidates ADD COLUMN cv_uploaded_at TEXT",
  "ALTER TABLE candidates ADD COLUMN experience_years REAL",
  "ALTER TABLE candidates ADD COLUMN ats_score INTEGER",
  "ALTER TABLE candidates ADD COLUMN ats_detail TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE job_openings ADD COLUMN min_experience REAL NOT NULL DEFAULT 0",
  "ALTER TABLE job_openings ADD COLUMN ats_threshold INTEGER NOT NULL DEFAULT 60",
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
  "ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN totp_secret TEXT",
  "ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN password_changed_at TEXT",
  "ALTER TABLE users ADD COLUMN last_login_at TEXT",
]) {
  try {
    db.exec(migration);
  } catch (err) {
    if (!/duplicate column/i.test(err.message)) throw err;
  }
}

// Tables des espaces métier ajoutés après le socle. Déclarées ici, après les
// migrations de colonnes, pour que leurs clés étrangères pointent vers des tables
// et des colonnes qui existent déjà.
db.exec(`
-- ---------- Projets, tâches et temps passé ----------

CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL DEFAULT '',
  name TEXT NOT NULL,
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
  lead_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'Cadrage',
  start_date TEXT,
  due_date TEXT,
  budget_amount REAL,
  hourly_rate REAL,
  description TEXT NOT NULL DEFAULT '',
  archived INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS project_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL DEFAULT '',
  UNIQUE(project_id, user_id)
);

CREATE TABLE IF NOT EXISTS project_milestones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  due_date TEXT,
  reached_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS project_tasks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  milestone_id INTEGER REFERENCES project_milestones(id) ON DELETE SET NULL,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'À faire',
  priority TEXT NOT NULL DEFAULT 'Normale',
  estimate_hours REAL,
  due_date TEXT,
  done_at TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS project_time (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  task_id INTEGER REFERENCES project_tasks(id) ON DELETE SET NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  spent_on TEXT NOT NULL,
  hours REAL NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  billable INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Tickets et support ----------

CREATE TABLE IF NOT EXISTS tickets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  reference TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'Autre',
  priority TEXT NOT NULL DEFAULT 'Normale',
  status TEXT NOT NULL DEFAULT 'Ouvert',
  origin TEXT NOT NULL DEFAULT 'Interne',
  requester_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  due_at TEXT,
  first_reply_at TEXT,
  closed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ticket_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body TEXT NOT NULL,
  internal INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Base de connaissances ----------

CREATE TABLE IF NOT EXISTS kb_articles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Général',
  body TEXT NOT NULL DEFAULT '',
  visibility TEXT NOT NULL DEFAULT 'Entreprise',
  scope_id INTEGER,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  published INTEGER NOT NULL DEFAULT 1,
  views INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Objectifs et résultats clés ----------

CREATE TABLE IF NOT EXISTS objectives (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  scope TEXT NOT NULL DEFAULT 'Entreprise',
  scope_id INTEGER,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  period TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En cours',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS key_results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  objective_id INTEGER NOT NULL REFERENCES objectives(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  start_value REAL NOT NULL DEFAULT 0,
  target_value REAL NOT NULL DEFAULT 100,
  current_value REAL NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT ''
);

-- ---------- Arrivée et départ ----------

CREATE TABLE IF NOT EXISTS checklist_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Arrivée',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS checklist_template_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER NOT NULL REFERENCES checklist_templates(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  owner_role TEXT NOT NULL DEFAULT 'RH',
  offset_days INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS checklists (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER REFERENCES checklist_templates(id) ON DELETE SET NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Arrivée',
  reference_date TEXT NOT NULL,
  completed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS checklist_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  checklist_id INTEGER NOT NULL REFERENCES checklists(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  owner_role TEXT NOT NULL DEFAULT 'RH',
  due_date TEXT,
  done_at TEXT,
  done_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);

-- ---------- Compétences et habilitations ----------

CREATE TABLE IF NOT EXISTS skills (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  category TEXT NOT NULL DEFAULT 'Générale',
  validity_months INTEGER,
  mandatory INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS user_skills (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  skill_id INTEGER NOT NULL REFERENCES skills(id) ON DELETE CASCADE,
  level INTEGER NOT NULL DEFAULT 1,
  obtained_on TEXT,
  expires_on TEXT,
  reference TEXT NOT NULL DEFAULT '',
  UNIQUE(user_id, skill_id)
);

-- ---------- Santé et sécurité au travail ----------

CREATE TABLE IF NOT EXISTS risk_assessments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  unit TEXT NOT NULL,
  hazard TEXT NOT NULL,
  exposure TEXT NOT NULL DEFAULT '',
  severity INTEGER NOT NULL DEFAULT 1,
  likelihood INTEGER NOT NULL DEFAULT 1,
  measures TEXT NOT NULL DEFAULT '',
  reviewed_on TEXT,
  next_review TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS workplace_incidents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  occurred_on TEXT NOT NULL,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  kind TEXT NOT NULL DEFAULT 'Accident du travail',
  location TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  days_off INTEGER NOT NULL DEFAULT 0,
  declared_on TEXT,
  follow_up TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS ppe_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Protection',
  validity_months INTEGER
);

CREATE TABLE IF NOT EXISTS ppe_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ppe_id INTEGER NOT NULL REFERENCES ppe_items(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  issued_on TEXT NOT NULL,
  expires_on TEXT,
  returned_on TEXT
);

CREATE TABLE IF NOT EXISTS medical_visits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Visite périodique',
  scheduled_on TEXT,
  done_on TEXT,
  verdict TEXT NOT NULL DEFAULT '',
  next_due TEXT
);

-- ---------- Trésorerie ----------

CREATE TABLE IF NOT EXISTS bank_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  bank TEXT NOT NULL DEFAULT '',
  iban_last4 TEXT NOT NULL DEFAULT '',
  opening_balance REAL NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS bank_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL REFERENCES bank_accounts(id) ON DELETE CASCADE,
  value_date TEXT NOT NULL,
  label TEXT NOT NULL,
  amount REAL NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  claim_id INTEGER REFERENCES expense_claims(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS cash_forecasts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  expected_on TEXT NOT NULL,
  amount REAL NOT NULL,
  certainty TEXT NOT NULL DEFAULT 'Probable',
  note TEXT NOT NULL DEFAULT ''
);

-- ---------- Immobilisations ----------

CREATE TABLE IF NOT EXISTS fixed_assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  label TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Matériel',
  acquired_on TEXT NOT NULL,
  amount REAL NOT NULL,
  duration_years REAL NOT NULL DEFAULT 3,
  method TEXT NOT NULL DEFAULT 'Linéaire',
  disposed_on TEXT,
  note TEXT NOT NULL DEFAULT ''
);

-- ---------- Flotte de véhicules ----------

CREATE TABLE IF NOT EXISTS vehicles (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  registration TEXT NOT NULL UNIQUE,
  brand TEXT NOT NULL DEFAULT '',
  model TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Voiture',
  acquired_on TEXT,
  mileage INTEGER NOT NULL DEFAULT 0,
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  insurance_due TEXT,
  inspection_due TEXT,
  service_due TEXT,
  status TEXT NOT NULL DEFAULT 'En service',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS vehicle_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Entretien',
  occurred_on TEXT NOT NULL,
  mileage INTEGER,
  cost REAL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Registre des traitements (RGPD) ----------

CREATE TABLE IF NOT EXISTS processing_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  purpose TEXT NOT NULL DEFAULT '',
  legal_basis TEXT NOT NULL DEFAULT '',
  data_categories TEXT NOT NULL DEFAULT '',
  recipients TEXT NOT NULL DEFAULT '',
  retention TEXT NOT NULL DEFAULT '',
  measures TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Coffre-fort numérique ----------

-- Un document déposé au coffre ne se modifie pas : il porte l'empreinte de son
-- contenu, une date de conservation, et son retrait éventuel laisse la ligne en
-- place avec son motif. C'est cette trace qui fait la valeur du coffre.
CREATE TABLE IF NOT EXISTS vault_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  category TEXT NOT NULL DEFAULT 'Bulletin de paie',
  title TEXT NOT NULL,
  period TEXT NOT NULL DEFAULT '',
  payslip_id INTEGER REFERENCES payslips(id) ON DELETE SET NULL,
  file_name TEXT NOT NULL,
  original_name TEXT NOT NULL DEFAULT '',
  mime_type TEXT NOT NULL DEFAULT '',
  byte_size INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL,
  deposited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  deposited_at TEXT NOT NULL DEFAULT (datetime('now')),
  retention_until TEXT NOT NULL DEFAULT '',
  removed_at TEXT,
  removed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  removal_reason TEXT NOT NULL DEFAULT ''
);

-- Accès au coffre après le départ : un code à usage limité, remis par les RH,
-- pour qui ne se souvient plus de son mot de passe des années après.
CREATE TABLE IF NOT EXISTS vault_access_grants (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT NOT NULL,
  revoked_at TEXT,
  last_used_at TEXT,
  uses INTEGER NOT NULL DEFAULT 0
);

-- ---------- Notifications ----------

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'echeance',
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  link TEXT NOT NULL DEFAULT '',
  dedupe_key TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  read_at TEXT
);

CREATE INDEX IF NOT EXISTS idx_project_tasks_project ON project_tasks(project_id, status);
CREATE INDEX IF NOT EXISTS idx_project_tasks_assignee ON project_tasks(assignee_id, status);
CREATE INDEX IF NOT EXISTS idx_project_time_project ON project_time(project_id, spent_on);
CREATE INDEX IF NOT EXISTS idx_project_time_user ON project_time(user_id, spent_on);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status, priority);
CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id);
CREATE INDEX IF NOT EXISTS idx_user_skills_user ON user_skills(user_id);
CREATE INDEX IF NOT EXISTS idx_checklist_items_checklist ON checklist_items(checklist_id);
CREATE INDEX IF NOT EXISTS idx_bank_transactions_account ON bank_transactions(account_id, value_date);
CREATE INDEX IF NOT EXISTS idx_vehicle_events_vehicle ON vehicle_events(vehicle_id, occurred_on);
CREATE UNIQUE INDEX IF NOT EXISTS idx_notifications_dedupe ON notifications(user_id, dedupe_key) WHERE dedupe_key != '';
CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id, read_at);
CREATE INDEX IF NOT EXISTS idx_vault_documents_user ON vault_documents(user_id, removed_at);
CREATE INDEX IF NOT EXISTS idx_vault_grants_user ON vault_access_grants(user_id);
`);

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
