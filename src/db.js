const path = require('path');
const fs = require('fs');
const bcrypt = require('bcryptjs');

// DB_PATH permet d'isoler la base (tests, instances multiples) ; défaut : data/app.sqlite.
const dbPath = process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite');
const dataDir = path.dirname(dbPath);
if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });

/**
 * Le moteur : SQLite par défaut, MySQL sur demande.
 *
 *     DB_DRIVER=mysql DB_HOST=… DB_NAME=… DB_USER=… DB_PASSWORD=…
 *
 * Le reste de ce fichier, et les mille cent soixante et un appels du produit,
 * ne savent pas lequel des deux répond : db-mysql.js rend la forme de
 * better-sqlite3, et sql-mysql.js traduit le dialecte à un seul endroit. Le
 * schéma ci-dessous reste donc écrit en SQLite, qui est la base par défaut et
 * celle qu'on lit le plus souvent.
 */
const reglages = require('./reglages');
const surMysql = String(reglages.get('DB_DRIVER', 'sqlite')).toLowerCase() === 'mysql';

const db = surMysql
  ? require('./db-mysql').creerBase()
  : (() => {
      const Database = require('better-sqlite3');
      const base = new Database(dbPath);
      base.pragma('journal_mode = WAL');
      base.pragma('foreign_keys = ON');
      base.driver = 'sqlite';
      return base;
    })();

/**
 * La base SQLite qui a porté l'assistant, quand ce n'est pas elle qu'on garde.
 *
 * L'application a besoin d'une base avant de savoir laquelle elle aura : c'est
 * celle du démarrage, et l'assistant s'en sert jusqu'à ce qu'il en désigne une
 * autre. Sur une instance MySQL, la laisser derrière soi, c'est laisser
 * plusieurs mégaoctets dont personne ne saura dire à quoi ils servent.
 *
 * Effacée ici, au démarrage suivant — celui que l'installation sur MySQL
 * demande de toute façon — et seulement après s'être assuré qu'elle ne porte
 * aucun compte. Si elle en portait, ce serait une instance SQLite qu'on est en
 * train de faire migrer, et son fichier ne nous appartient pas.
 */
if (surMysql && fs.existsSync(dbPath)) {
  try {
    const Database = require('better-sqlite3');
    const amorce = new Database(dbPath, { readonly: true });
    const comptes = amorce
      .prepare("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table' AND name = 'users'").get().n === 1
      ? amorce.prepare('SELECT COUNT(*) AS n FROM users').get().n
      : -1;
    amorce.close();
    if (comptes === 0) {
      for (const trace of [dbPath, `${dbPath}-wal`, `${dbPath}-shm`]) fs.rmSync(trace, { force: true });
    }
  } catch {
    // Illisible, donc pas sûrement vide : on la laisse où elle est.
  }
}

/**
 * Les tables de l'instance.
 *
 * SQLite les tient dans sqlite_master, MySQL dans information_schema. Les
 * appelants — l'export intégral, l'assistant — n'ont pas à savoir lequel des
 * deux répond, d'où cette méthode plutôt que la requête recopiée trois fois.
 */
// Sur MySQL, la façade la porte déjà : toute base ouverte sait dire ses tables,
// y compris celle que l'assistant vient de poser et qui n'est pas encore la
// nôtre.
if (!surMysql) {
  db.tables = () => db.prepare(
    "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
  ).all().map((l) => l.name);
}

/**
 * Le schéma, posé.
 *
 * Sur SQLite il part tel quel. Sur MySQL il passe par le traducteur, qui en
 * connaît les quelques écarts — auto-incrément, longueur des index de texte,
 * index partiels — et tolère qu'une table déjà là ne soit pas une erreur.
 */
// Les scripts sont retenus au passage : l'assistant d'installation doit
// pouvoir poser exactement le même schéma sur la base que l'on vient de lui
// désigner, et non une copie de ce fichier qui dériverait.
const scripts = [];
const poser = (sql) => {
  scripts.push(sql);
  return surMysql ? db.poserLeSchema(sql) : db.exec(sql);
};
db.scripts = scripts;

poser(`
-- Sessions : persistées en base plutôt qu'en mémoire, pour survivre à un
-- redémarrage, tenir plusieurs processus, et permettre de révoquer d'un coup
-- toutes les sessions d'un compte.
CREATE TABLE IF NOT EXISTS sessions (
  sid TEXT PRIMARY KEY,
  user_id INTEGER,
  data TEXT NOT NULL,
  expires_at INTEGER NOT NULL,
  -- D'où et depuis quand cette session est ouverte. Sans ces colonnes, une
  -- liste de sessions n'est qu'une liste d'identifiants illisibles : personne
  -- ne peut y reconnaître la sienne, donc personne ne peut y reconnaître celle
  -- d'un autre. C'est ainsi qu'on s'aperçoit qu'un compte est ouvert ailleurs.
  created_at INTEGER,
  last_seen_at INTEGER,
  ip TEXT,
  user_agent TEXT
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
  ip TEXT NOT NULL DEFAULT '',
  -- Scellement : chaque entrée porte l'empreinte de la précédente, si bien
  -- qu'une ligne modifiée ou retirée du milieu rompt la chaîne et se voit.
  prev_hash TEXT NOT NULL DEFAULT '',
  hash TEXT NOT NULL DEFAULT ''
);

-- Codes de secours de la double authentification : hachés, à usage unique.
CREATE TABLE IF NOT EXISTS totp_recovery_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash TEXT NOT NULL,
  used_at TEXT
);

-- Les demandes SAML émises, en attente de leur réponse : une réponse ne vaut
-- que pour une demande partie d'ici, il y a peu, et une seule fois.
CREATE TABLE IF NOT EXISTS saml_requests (
  request_id TEXT PRIMARY KEY,
  created_at INTEGER NOT NULL
);

-- Les clés de sécurité et clés d'accès (WebAuthn) d'une personne : la clé
-- publique, son algorithme, et le compteur qui trahit une clé copiée.
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  credential_id TEXT NOT NULL UNIQUE,
  public_key TEXT NOT NULL,
  algorithm INTEGER NOT NULL,
  sign_count INTEGER NOT NULL DEFAULT 0,
  name TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_used_at TEXT
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
  is_it INTEGER NOT NULL DEFAULT 0,
  is_referent INTEGER NOT NULL DEFAULT 0,
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
  mail_imap_secure INTEGER NOT NULL DEFAULT 1,
  mail_password TEXT NOT NULL DEFAULT '',
  mail_sync_enabled INTEGER NOT NULL DEFAULT 0,
  mail_synced_at TEXT,
  mail_sync_report TEXT NOT NULL DEFAULT '',
  mail_sync_failures INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  must_change_password INTEGER NOT NULL DEFAULT 0,
  totp_secret TEXT,
  totp_enabled INTEGER NOT NULL DEFAULT 0,
  -- Le dernier pas de trente secondes déjà servi à ce compte. Un code qui ne
  -- lui est pas strictement supérieur a déjà servi, et se refuse (RFC 6238 §5.2).
  totp_last_step INTEGER NOT NULL DEFAULT 0,
  password_changed_at TEXT,
  last_login_at TEXT,
  failed_attempts INTEGER NOT NULL DEFAULT 0,
  locked_until TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS tools (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  assigned_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  username TEXT NOT NULL DEFAULT '',
  UNIQUE(employee_id, tool_id)
);

CREATE TABLE IF NOT EXISTS time_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  amount REAL NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id),
  -- Rattachement au bon de commande : c'est lui qui rend possible le
  -- rapprochement entre commandé, reçu et facturé.
  purchase_order_id INTEGER REFERENCES purchase_orders(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS payslips (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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

-- ---------- Messagerie externe : ce que la relève a rapporté ----------
--
-- La boîte du membre reste la source : on n'y écrit jamais, on n'y efface
-- jamais, et on ne marque aucun message comme lu — il la lit aussi depuis son
-- propre logiciel. Ce qui est ici n'est qu'une copie de lecture.
--
-- L'unicité (membre, uid) est ce qui rend la relève rejouable : repasser sur
-- les mêmes messages n'en écrit pas un de plus.
CREATE TABLE IF NOT EXISTS external_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  uid TEXT NOT NULL,
  from_name TEXT NOT NULL DEFAULT '',
  from_address TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  attachments INTEGER NOT NULL DEFAULT 0,
  sent_at TEXT,
  seen_on_server INTEGER NOT NULL DEFAULT 0,
  fetched_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (user_id, uid)
);

CREATE INDEX IF NOT EXISTS idx_external_messages_user
  ON external_messages(user_id, sent_at DESC);

-- Centre de journaux.
--
-- Le journal d'audit répond à « qui a fait quoi ». Celui-ci répond à « que
-- s'est-il passé » : les connexions refusées, les plafonds atteints, les
-- relèves, les envois, les erreurs, les balayages. Deux journaux plutôt qu'un
-- parce qu'ils n'ont ni la même durée de vie, ni le même volume, ni le même
-- lecteur — et parce qu'un journal d'audit scellé ne supporte pas d'être noyé
-- sous des lignes de débogage.
--
-- Les niveaux sont ceux de la norme syslog (RFC 5424), de 0 à 7 : c'est ce qui
-- permet de recopier une ligne vers un collecteur sans la traduire.
CREATE TABLE IF NOT EXISTS logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  occurred_at TEXT NOT NULL DEFAULT (datetime('now')),
  level INTEGER NOT NULL DEFAULT 6,
  channel TEXT NOT NULL DEFAULT 'systeme',
  event TEXT NOT NULL DEFAULT '',
  message TEXT NOT NULL DEFAULT '',
  context TEXT NOT NULL DEFAULT '',
  actor_id INTEGER,
  actor_label TEXT NOT NULL DEFAULT '',
  ip TEXT NOT NULL DEFAULT '',
  request_id TEXT NOT NULL DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_logs_recent ON logs(id DESC);
CREATE INDEX IF NOT EXISTS idx_logs_level ON logs(level, id DESC);
CREATE INDEX IF NOT EXISTS idx_logs_channel ON logs(channel, id DESC);

-- Les collecteurs vers lesquels le journal est recopié : un serveur syslog, un
-- gestionnaire Wazuh, un dépôt lu par un agent local.
--
-- Chaque cible garde son propre **filigrane** : le numéro de la dernière ligne
-- qu'elle a reçue. Une panne du collecteur ne perd rien — au rétablissement,
-- l'export reprend là où il s'était arrêté —, et deux collecteurs n'ont pas à
-- avancer du même pas. C'est aussi ce filigrane qui protège la purge : une
-- ligne qu'une cible active n'a pas encore vue n'est jamais supprimée.
CREATE TABLE IF NOT EXISTS updates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version TEXT NOT NULL,
  edition TEXT NOT NULL DEFAULT 'node',
  channel TEXT NOT NULL DEFAULT 'stable',
  mode TEXT NOT NULL DEFAULT 'manuelle',
  status TEXT NOT NULL DEFAULT 'en-cours',
  detail TEXT NOT NULL DEFAULT '',
  backup_file TEXT NOT NULL DEFAULT '',
  from_version TEXT NOT NULL DEFAULT '',
  by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  started_at TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT
);

CREATE TABLE IF NOT EXISTS log_targets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL DEFAULT 'syslog',
  label TEXT NOT NULL DEFAULT '',
  enabled INTEGER NOT NULL DEFAULT 0,
  transport TEXT NOT NULL DEFAULT 'udp',
  host TEXT NOT NULL DEFAULT '',
  port INTEGER NOT NULL DEFAULT 514,
  format TEXT NOT NULL DEFAULT 'rfc5424',
  facility INTEGER NOT NULL DEFAULT 16,
  app_name TEXT NOT NULL DEFAULT 'toutadmin',
  min_level INTEGER NOT NULL DEFAULT 6,
  channels TEXT NOT NULL DEFAULT '',
  path TEXT NOT NULL DEFAULT '',
  allow_self_signed INTEGER NOT NULL DEFAULT 0,
  cursor INTEGER NOT NULL DEFAULT 0,
  sent INTEGER NOT NULL DEFAULT 0,
  failures INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT '',
  last_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre. L'unicité du nom est par société, et non globale : deux
  -- entités d'un groupe ont chacune le droit d'avoir un service « Production ».
  company_id INTEGER,
  name TEXT NOT NULL COLLATE NOCASE,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, name)
);

CREATE TABLE IF NOT EXISTS teams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  /* Le périmètre, et non le service dont elle dépend : une équipe peut n'être
     rattachée à aucun service — l'organigramme les montre exprès — et n'aurait
     alors aucune société si elle tenait la sienne de son seul service. */
  company_id INTEGER,
  name TEXT NOT NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, name, department_id)
);

-- Un service comme une équipe peuvent avoir plusieurs managers : l'encadrement
-- est une relation, pas une colonne sur le salarié.
CREATE TABLE IF NOT EXISTS org_managers (
  /* Le périmètre. « scope_id » désigne un service ou une équipe selon « scope » :
     aucune clé étrangère ne peut le dire, et donc aucune cascade ne peut porter
     la société. Elle est donc portée ici. */
  company_id INTEGER,
  scope TEXT NOT NULL CHECK(scope IN ('department','team')),
  scope_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (scope, scope_id, user_id)
);

CREATE TABLE IF NOT EXISTS cse_mandates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  mandate_role TEXT NOT NULL DEFAULT 'Titulaire',
  started_on TEXT NOT NULL DEFAULT (date('now')),
  ends_on TEXT,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- Un mandat par salarié et par société : un même salarié peut siéger au
  -- comité de deux sociétés de l'instance, mais pas deux fois à la même.
  UNIQUE(company_id, user_id)
);

CREATE TABLE IF NOT EXISTS cse_elections (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  -- Le périmètre. L'unicité du nom est par société : deux entités d'un groupe
  -- ont chacune le droit d'avoir une « Salle du conseil ».
  company_id INTEGER,
  name TEXT NOT NULL COLLATE NOCASE,
  location TEXT NOT NULL DEFAULT '',
  capacity INTEGER NOT NULL DEFAULT 0,
  equipment TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, name)
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  -- Le périmètre. Le plan comptable est propre à chaque entité : deux sociétés
  -- d'un groupe tiennent chacune son 401000, et une unicité sur le seul code
  -- ferait tenir à l'une les comptes de l'autre.
  company_id INTEGER,
  code TEXT NOT NULL,
  label TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('Actif','Passif','Charge','Produit')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, code)
);

CREATE TABLE IF NOT EXISTS journals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre : chaque entité tient ses propres journaux, et leurs codes se
  -- ressemblent d'une entité à l'autre — « VE », « AC », « BQ » partout.
  company_id INTEGER,
  code TEXT NOT NULL,
  label TEXT NOT NULL,
  UNIQUE(company_id, code)
);

CREATE TABLE IF NOT EXISTS entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  journal_id INTEGER NOT NULL REFERENCES journals(id) ON DELETE RESTRICT,
  entry_date TEXT NOT NULL,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- Le numéro d'écriture, posé à la validation et non à la saisie : il doit
  -- être continu et suivre l'ordre chronologique, ce qu'une écriture du
  -- brouillard supprimée rendrait impossible s'il était donné plus tôt.
  number INTEGER,
  validated_at TEXT,
  -- L'écriture qu'elle contrepasse : une écriture validée ne se supprime pas,
  -- elle s'annule par une écriture inverse qui laisse la trace des deux.
  reverses_id INTEGER
);

CREATE TABLE IF NOT EXISTS entry_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
  label TEXT NOT NULL DEFAULT '',
  debit REAL NOT NULL DEFAULT 0,
  credit REAL NOT NULL DEFAULT 0,
  reconciliation TEXT NOT NULL DEFAULT '',
  lettered_at TEXT
);

-- ---------- Paie ----------
CREATE TABLE IF NOT EXISTS payroll_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  "ALTER TABLE users ADD COLUMN is_it INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN is_referent INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE audit_log ADD COLUMN prev_hash TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE audit_log ADD COLUMN hash TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE invoices ADD COLUMN purchase_order_id INTEGER REFERENCES purchase_orders(id) ON DELETE SET NULL",
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
  // Relève de la messagerie externe, membre par membre. Le mot de passe est
  // chiffré comme les autres secrets de l'instance : une copie de la base ne
  // donne pas les boîtes aux lettres du personnel.
  'ALTER TABLE users ADD COLUMN mail_imap_secure INTEGER NOT NULL DEFAULT 1',
  "ALTER TABLE users ADD COLUMN mail_password TEXT NOT NULL DEFAULT ''",
  'ALTER TABLE users ADD COLUMN mail_sync_enabled INTEGER NOT NULL DEFAULT 0',
  'ALTER TABLE users ADD COLUMN mail_synced_at TEXT',
  "ALTER TABLE users ADD COLUMN mail_sync_report TEXT NOT NULL DEFAULT ''",
  'ALTER TABLE users ADD COLUMN mail_sync_failures INTEGER NOT NULL DEFAULT 0',
  'ALTER TABLE users ADD COLUMN department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL',
  'ALTER TABLE users ADD COLUMN team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL',
  "ALTER TABLE calendar_events ADD COLUMN visibility TEXT NOT NULL DEFAULT 'Privé'",
  "ALTER TABLE announcements ADD COLUMN scope_id INTEGER",
  "ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0",
  "ALTER TABLE users ADD COLUMN totp_secret TEXT",
  "ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0",
  'ALTER TABLE users ADD COLUMN totp_last_step INTEGER NOT NULL DEFAULT 0',
  'ALTER TABLE sessions ADD COLUMN created_at INTEGER',
  'ALTER TABLE sessions ADD COLUMN last_seen_at INTEGER',
  'ALTER TABLE sessions ADD COLUMN ip TEXT',
  'ALTER TABLE sessions ADD COLUMN user_agent TEXT',
  "ALTER TABLE users ADD COLUMN password_changed_at TEXT",
  "ALTER TABLE users ADD COLUMN last_login_at TEXT",
  // Multidevise : la facture porte sa devise et le taux du jour de son émission.
  // Figer le taux est le point important — un taux qui bouge ne doit pas
  // réécrire les comptes de l'an dernier.
  "ALTER TABLE invoices ADD COLUMN currency TEXT NOT NULL DEFAULT 'EUR'",
  "ALTER TABLE invoices ADD COLUMN exchange_rate REAL NOT NULL DEFAULT 1",
  "ALTER TABLE invoices ADD COLUMN subscription_id INTEGER",
]) {
  try {
    poser(migration);
  } catch (err) {
    // « Duplicate column name » d'un côté, « duplicate column name » de
    // l'autre : les deux moteurs se rejoignent sur le mot, et poserLeSchema
    // rattrape déjà les codes MySQL.
    if (!/duplicate column/i.test(err.message)) throw err;
  }
}

// Tables des espaces métier ajoutés après le socle. Déclarées ici, après les
// migrations de colonnes, pour que leurs clés étrangères pointent vers des tables
// et des colonnes qui existent déjà.
poser(`
-- ---------- Projets, tâches et temps passé ----------

CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
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
  /*
   * Le groupe qui a la charge du ticket.
   *
   * Posé à l'ouverture depuis la catégorie, et transférable à la main. Sans
   * cette colonne un transfert serait impossible : la file serait toujours
   * recalculée depuis la catégorie, et déplacer une demande obligerait à mentir
   * sur ce dont elle parle.
   *
   * Nul veut dire « aucun groupe désigné » : le ticket reste visible par les
   * rôles, comme avant l'existence des groupes. C'est ce qui fait qu'une base
   * déjà en service ne perd rien le jour de la mise à jour.
   */
  group_id INTEGER,
  due_at TEXT,
  first_reply_at TEXT,
  closed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

/*
 * Les groupes de support.
 *
 * Avant eux, qui traitait un ticket se déduisait du rôle : l'administration et
 * les RH voyaient tout, la gestion tout sauf les RH, un manager son périmètre.
 * C'était une politique écrite dans le code, donc la même pour toutes les
 * entreprises — et fausse pour la plupart : le remplacement d'un écran ne se
 * traite pas par la direction financière.
 *
 * Un groupe porte un nom, des gens, et les catégories qu'il prend en charge.
 * L'administration le compose ; les rôles continuent de donner ce qu'ils
 * donnaient, et les groupes s'y ajoutent. Rien ne se retire par ce moyen : on
 * n'enlève pas aux RH l'accès aux demandes RH en oubliant de les mettre dans un
 * groupe.
 */
CREATE TABLE IF NOT EXISTS support_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS support_group_members (
  group_id INTEGER NOT NULL REFERENCES support_groups(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, user_id)
);

CREATE TABLE IF NOT EXISTS support_group_categories (
  group_id INTEGER NOT NULL REFERENCES support_groups(id) ON DELETE CASCADE,
  category TEXT NOT NULL,
  PRIMARY KEY (group_id, category)
);

-- Les membres d'un groupe se cherchent par la personne : « quels groupes Claire
-- traite-t-elle ? » est posé à chaque ouverture d'écran.
--
-- Pas d'index sur « support_group_categories » : la table compte quelques
-- dizaines de lignes, et surtout le traducteur MySQL donne une longueur à toute
-- colonne de texte indexée — par son nom, pour tout le schéma. Indexer
-- « category » ici ferait basculer « tickets.category » en VARCHAR sur les
-- nouvelles bases MySQL et pas sur les anciennes.
CREATE INDEX IF NOT EXISTS idx_support_group_members_user ON support_group_members(user_id);

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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  company_id INTEGER,
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
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- L'empreinte du mouvement dans le relevé importé : réimporter un relevé, ou
  -- deux relevés qui se recouvrent, ne double rien. Vide pour une saisie à la main.
  import_ref TEXT
);

CREATE TABLE IF NOT EXISTS cash_forecasts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  expected_on TEXT NOT NULL,
  amount REAL NOT NULL,
  certainty TEXT NOT NULL DEFAULT 'Probable',
  note TEXT NOT NULL DEFAULT ''
);

-- ---------- Immobilisations ----------

CREATE TABLE IF NOT EXISTS fixed_assets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
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
  company_id INTEGER,
  -- L'immatriculation reste unique dans toute l'instance, et ce n'est pas un
  -- oubli : une plaque désigne un véhicule physique, que deux sociétés d'un
  -- groupe ne peuvent pas détenir en même temps.
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
  company_id INTEGER,
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

-- ---------- Gouvernance : réunions, décisions, risques ----------

-- Une réunion sans relevé de décisions n'a pas eu lieu : trois mois après,
-- personne ne sait plus ce qui a été arbitré ni par qui. La réunion porte
-- l'ordre du jour et le compte rendu ; la décision et l'action en sortent et
-- vivent leur vie propre, parce qu'on les cherche par sujet, pas par date.
CREATE TABLE IF NOT EXISTS meetings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  title TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Comité de direction',
  held_on TEXT NOT NULL,
  starts_at TEXT NOT NULL DEFAULT '',
  ends_at TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  agenda TEXT NOT NULL DEFAULT '',
  minutes TEXT NOT NULL DEFAULT '',
  chair_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'Planifiée' CHECK(status IN ('Planifiée','Tenue','Annulée')),
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS meeting_attendees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  attendance TEXT NOT NULL DEFAULT 'Attendu' CHECK(attendance IN ('Attendu','Présent','Excusé','Absent')),
  UNIQUE(meeting_id, user_id)
);

-- Le registre des décisions. Une décision peut naître hors réunion (arbitrage
-- pris seul, dans le couloir) : le rattachement à une réunion est facultatif,
-- sinon la moitié des décisions n'entrerait jamais au registre.
CREATE TABLE IF NOT EXISTS decisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  meeting_id INTEGER REFERENCES meetings(id) ON DELETE SET NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  rationale TEXT NOT NULL DEFAULT '',
  decided_on TEXT NOT NULL,
  decided_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  scope TEXT NOT NULL DEFAULT 'Entreprise',
  status TEXT NOT NULL DEFAULT 'En vigueur' CHECK(status IN ('En vigueur','En cours','Suspendue','Abandonnée')),
  review_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Ce qui a été décidé n'est fait que si quelqu'un le porte et qu'une date le
-- rappelle : les actions rejoignent les échéances de l'entreprise.
CREATE TABLE IF NOT EXISTS meeting_actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  meeting_id INTEGER REFERENCES meetings(id) ON DELETE CASCADE,
  decision_id INTEGER REFERENCES decisions(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  assignee_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  due_date TEXT,
  status TEXT NOT NULL DEFAULT 'À faire' CHECK(status IN ('À faire','En cours','Faite','Abandonnée')),
  done_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Registre des risques de l'entreprise — distinct du document unique, qui ne
-- traite que la santé des personnes. Ici : la dépendance à un client, la panne
-- du système d'information, le départ d'une compétence unique. La cotation
-- résiduelle dit ce qu'il reste une fois le traitement en place ; c'est elle
-- qu'on relit, pas la cotation brute.
CREATE TABLE IF NOT EXISTS enterprise_risks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT 'Opérationnel',
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  likelihood INTEGER NOT NULL DEFAULT 3,
  impact INTEGER NOT NULL DEFAULT 3,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  treatment TEXT NOT NULL DEFAULT 'Réduire',
  action_plan TEXT NOT NULL DEFAULT '',
  residual_likelihood INTEGER,
  residual_impact INTEGER,
  status TEXT NOT NULL DEFAULT 'Ouvert' CHECK(status IN ('Ouvert','Maîtrisé','Clos')),
  identified_on TEXT NOT NULL DEFAULT (date('now')),
  next_review TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Sondages et baromètre social ----------

CREATE TABLE IF NOT EXISTS surveys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  title TEXT NOT NULL,
  intro TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Baromètre social',
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Ouvert','Clos')),
  opens_on TEXT,
  closes_on TEXT,
  audience TEXT NOT NULL DEFAULT 'Tous',
  audience_id INTEGER,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS survey_questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  survey_id INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 0,
  label TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'echelle' CHECK(type IN ('echelle','oui_non','choix','texte')),
  choices TEXT NOT NULL DEFAULT '',
  required INTEGER NOT NULL DEFAULT 1
);

-- Aucune colonne user_id ici, et ce n'est pas un oubli : c'est ce qui rend
-- l'anonymat vrai plutôt que promis. Une base saisie ne peut pas rendre ce
-- qu'elle ne contient pas.
CREATE TABLE IF NOT EXISTS survey_answers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  question_id INTEGER NOT NULL REFERENCES survey_questions(id) ON DELETE CASCADE,
  value TEXT NOT NULL DEFAULT '',
  submitted_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- La participation, elle, est nominative — mais elle ne dit que « a répondu ».
-- Elle empêche de répondre deux fois sans jamais permettre de savoir quoi.
CREATE TABLE IF NOT EXISTS survey_participations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  survey_id INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(survey_id, user_id)
);

-- ---------- Planning, roulements et astreintes ----------

CREATE TABLE IF NOT EXISTS shifts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  starts_at TEXT NOT NULL,
  ends_at TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Poste',
  label TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
  -- Un planning non publié est un brouillon : personne d'autre que son auteur
  -- ne doit organiser sa semaine dessus.
  published INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS shift_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Poste',
  start_time TEXT NOT NULL DEFAULT '09:00',
  end_time TEXT NOT NULL DEFAULT '17:00',
  weekdays TEXT NOT NULL DEFAULT '1,2,3,4,5',
  location TEXT NOT NULL DEFAULT ''
);

-- ---------- Qualité : non-conformités, actions, audits ----------

CREATE TABLE IF NOT EXISTS nonconformities (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  source TEXT NOT NULL DEFAULT 'Interne',
  severity TEXT NOT NULL DEFAULT 'Mineure' CHECK(severity IN ('Mineure','Majeure','Critique')),
  detected_on TEXT NOT NULL,
  detected_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  subject TEXT NOT NULL DEFAULT '',
  immediate_action TEXT NOT NULL DEFAULT '',
  root_cause TEXT NOT NULL DEFAULT '',
  cost REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'Ouverte' CHECK(status IN ('Ouverte','En traitement','Clôturée')),
  closed_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS internal_audits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL DEFAULT '',
  scope TEXT NOT NULL,
  standard TEXT NOT NULL DEFAULT '',
  planned_on TEXT,
  done_on TEXT,
  auditor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  summary TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Planifié' CHECK(status IN ('Planifié','Réalisé','Clos'))
);

CREATE TABLE IF NOT EXISTS audit_findings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  audit_id INTEGER NOT NULL REFERENCES internal_audits(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Remarque' CHECK(kind IN ('Non-conformité','Remarque','Point fort')),
  clause TEXT NOT NULL DEFAULT '',
  statement TEXT NOT NULL
);

-- Une action corrective dont personne n'a vérifié l'effet n'est qu'une
-- intention : l'efficacité se constate après coup, à une date qu'on se fixe.
CREATE TABLE IF NOT EXISTS quality_actions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  nonconformity_id INTEGER REFERENCES nonconformities(id) ON DELETE CASCADE,
  audit_id INTEGER REFERENCES internal_audits(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Corrective' CHECK(kind IN ('Corrective','Préventive','Amélioration')),
  label TEXT NOT NULL,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  due_date TEXT,
  status TEXT NOT NULL DEFAULT 'À faire' CHECK(status IN ('À faire','En cours','Faite')),
  done_on TEXT,
  effectiveness TEXT NOT NULL DEFAULT 'Non vérifiée' CHECK(effectiveness IN ('Non vérifiée','Efficace','Inefficace')),
  verified_on TEXT,
  verified_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Accueil : visiteurs et courrier ----------

-- Le registre des visiteurs sert à l'évacuation autant qu'à la sécurité : en
-- cas d'alarme, il faut savoir qui est dans les murs. Il porte donc l'heure
-- d'arrivée et celle de départ, pas seulement la date.
CREATE TABLE IF NOT EXISTS visitors (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  visited_on TEXT NOT NULL,
  arrived_at TEXT NOT NULL DEFAULT '',
  departed_at TEXT NOT NULL DEFAULT '',
  first_name TEXT NOT NULL DEFAULT '',
  last_name TEXT NOT NULL,
  company TEXT NOT NULL DEFAULT '',
  purpose TEXT NOT NULL DEFAULT '',
  host_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  badge TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS mail_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  direction TEXT NOT NULL DEFAULT 'Entrant' CHECK(direction IN ('Entrant','Sortant')),
  logged_on TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Lettre',
  correspondent TEXT NOT NULL DEFAULT '',
  recipient_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  recipient_label TEXT NOT NULL DEFAULT '',
  tracking TEXT NOT NULL DEFAULT '',
  subject TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'À remettre' CHECK(status IN ('À remettre','Remis','Archivé')),
  handed_on TEXT,
  handed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Devises, abonnements et TVA ----------

-- Un taux par devise, exprimé dans la devise de référence de l'instance. Le
-- taux vit ici pour être mis à jour ; celui qui a servi à une facture est copié
-- dans la facture, où il ne bouge plus.
CREATE TABLE IF NOT EXISTS exchange_rates (
  -- Le périmètre : une filiale à l'étranger ne tient pas ses comptes dans la
  -- devise de la maison mère, et n'a donc pas les mêmes taux à figer.
  company_id INTEGER,
  code TEXT NOT NULL,
  rate REAL NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  PRIMARY KEY (company_id, code)
);

-- Facturation récurrente : l'abonnement est le moule, la facture est la pièce.
-- On garde la date de la prochaine émission plutôt que de recalculer depuis le
-- début : une facture sautée ou avancée à la main ne dérègle pas la suite.
CREATE TABLE IF NOT EXISTS subscriptions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  direction TEXT NOT NULL DEFAULT 'Client' CHECK(direction IN ('Client','Fournisseur')),
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  amount_ht REAL NOT NULL DEFAULT 0,
  vat_rate REAL NOT NULL DEFAULT 20,
  currency TEXT NOT NULL DEFAULT 'EUR',
  period TEXT NOT NULL DEFAULT 'Mensuelle',
  start_date TEXT NOT NULL,
  next_issue TEXT NOT NULL,
  end_date TEXT,
  payment_days INTEGER NOT NULL DEFAULT 30,
  active INTEGER NOT NULL DEFAULT 1,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Déclarations de TVA. Le CMS calcule et conserve ; il ne télétransmet pas.
CREATE TABLE IF NOT EXISTS vat_returns (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  regime TEXT NOT NULL DEFAULT 'Mensuel',
  period_label TEXT NOT NULL,
  period_start TEXT NOT NULL,
  period_end TEXT NOT NULL,
  collected REAL NOT NULL DEFAULT 0,
  deductible REAL NOT NULL DEFAULT 0,
  due REAL NOT NULL DEFAULT 0,
  credit REAL NOT NULL DEFAULT 0,
  breakdown TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Déclarée','Payée')),
  filed_on TEXT,
  paid_on TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(period_start, period_end)
);

-- ---------- Parapheur : signature électronique interne ----------

-- Le document signé est figé dès la création : son empreinte est calculée là,
-- et revérifiée à chaque signature comme à chaque téléchargement. Signer un
-- document qui peut changer ensuite ne signifierait rien.
CREATE TABLE IF NOT EXISTS signature_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  title TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Document',
  body TEXT NOT NULL DEFAULT '',
  file_name TEXT NOT NULL DEFAULT '',
  original_name TEXT NOT NULL DEFAULT '',
  mime_type TEXT NOT NULL DEFAULT '',
  byte_size INTEGER NOT NULL DEFAULT 0,
  sha256 TEXT NOT NULL,
  deadline TEXT,
  status TEXT NOT NULL DEFAULT 'En cours' CHECK(status IN ('En cours','Signé','Refusé','Annulé')),
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  completed_at TEXT,
  closing_reason TEXT NOT NULL DEFAULT ''
);

-- Une signature n'est pas une case cochée : elle porte le moment, l'adresse
-- d'où elle vient, et un sceau calculé par l'instance à partir de l'empreinte
-- du document. Le sceau se recalcule pour vérification ; il ne se recopie pas
-- d'une signature à l'autre.
CREATE TABLE IF NOT EXISTS signature_signers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES signature_requests(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 1,
  role_label TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En attente' CHECK(status IN ('En attente','Signé','Refusé')),
  signed_at TEXT,
  reason TEXT NOT NULL DEFAULT '',
  seal TEXT NOT NULL DEFAULT '',
  ip TEXT NOT NULL DEFAULT '',
  UNIQUE(request_id, user_id)
);

-- ---------- Interfaces : jetons d'API et webhooks ----------

-- Un jeton n'est jamais conservé en clair : seule son empreinte SHA-256 est
-- stockée, avec le préfixe visible qui permet de le reconnaître dans une liste.
-- Perdu, il se révoque et se recrée ; il ne se relit pas.
CREATE TABLE IF NOT EXISTS api_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre du jeton : la société dans laquelle il travaille. Un jeton est
  -- créé depuis l'écran d'une société, et c'est celle-là qu'il voit.
  company_id INTEGER,
  label TEXT NOT NULL,
  prefix TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  scopes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  expires_at TEXT,
  last_used_at TEXT,
  last_ip TEXT NOT NULL DEFAULT '',
  calls INTEGER NOT NULL DEFAULT 0,
  revoked_at TEXT
);

CREATE TABLE IF NOT EXISTS webhooks (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  url TEXT NOT NULL,
  secret TEXT NOT NULL,
  events TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  -- Viser une adresse interne depuis le serveur est une porte dérobée classique :
  -- il faut le vouloir explicitement.
  allow_private INTEGER NOT NULL DEFAULT 0,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  last_status TEXT NOT NULL DEFAULT '',
  last_attempt_at TEXT,
  failures INTEGER NOT NULL DEFAULT 0
);

-- Chaque envoi laisse une trace : un webhook qui échoue en silence fait croire
-- que l'information est passée.
CREATE TABLE IF NOT EXISTS webhook_deliveries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  webhook_id INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
  event TEXT NOT NULL,
  payload TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En attente' CHECK(status IN ('En attente','Livré','Échec','Abandonné')),
  attempts INTEGER NOT NULL DEFAULT 0,
  last_error TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  delivered_at TEXT,
  next_try_at TEXT
);

-- ---------- Demandes internes et circuits d'approbation ----------

-- Un type de demande décrit ce qu'on saisit ; les étapes décrivent qui
-- l'approuve. Les deux sont paramétrables : chaque entreprise a ses propres
-- circuits, et les figer dans le code obligerait à recompiler pour ajouter une
-- validation.
CREATE TABLE IF NOT EXISTS request_forms (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  icon TEXT NOT NULL DEFAULT 'inbox',
  fields TEXT NOT NULL DEFAULT '[]',
  amount_field TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Une étape désigne une fonction, pas une personne : le circuit survit aux
-- départs. Le seuil permet de n'appeler la direction qu'au-delà d'un montant.
CREATE TABLE IF NOT EXISTS request_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_id INTEGER NOT NULL REFERENCES request_forms(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 1,
  approver TEXT NOT NULL DEFAULT 'manager',
  approver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  label TEXT NOT NULL DEFAULT '',
  threshold REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS workflow_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_id INTEGER NOT NULL REFERENCES request_forms(id) ON DELETE CASCADE,
  requester_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  payload TEXT NOT NULL DEFAULT '{}',
  amount REAL NOT NULL DEFAULT 0,
  summary TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En cours' CHECK(status IN ('En cours','Approuvée','Refusée','Annulée')),
  current_step INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  closed_at TEXT
);

CREATE TABLE IF NOT EXISTS workflow_decisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES workflow_requests(id) ON DELETE CASCADE,
  step_id INTEGER REFERENCES request_steps(id) ON DELETE SET NULL,
  position INTEGER NOT NULL DEFAULT 1,
  approver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  decision TEXT NOT NULL CHECK(decision IN ('Approuvée','Refusée')),
  note TEXT NOT NULL DEFAULT '',
  decided_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------- Pièces reçues : dépôt et capture de messagerie ----------

-- Une facture reçue passe par ici avant d'entrer en comptabilité : le fichier
-- est conservé tel quel, son analyse à côté, et le comptable tranche. Rien
-- n'écrit dans les factures sans un clic.
CREATE TABLE IF NOT EXISTS incoming_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  source TEXT NOT NULL DEFAULT 'Dépôt' CHECK(source IN ('Dépôt','Courriel')),
  file_name TEXT NOT NULL,
  original_name TEXT NOT NULL DEFAULT '',
  mime_type TEXT NOT NULL DEFAULT '',
  byte_size INTEGER NOT NULL DEFAULT 0,
  -- L'empreinte sert de garde-fou contre le doublon : la même pièce reçue
  -- deux fois (déposée puis reçue par courriel) ne fait qu'une ligne.
  sha256 TEXT NOT NULL,
  received_at TEXT NOT NULL DEFAULT (datetime('now')),
  mail_uid TEXT NOT NULL DEFAULT '',
  mail_from TEXT NOT NULL DEFAULT '',
  mail_subject TEXT NOT NULL DEFAULT '',
  mail_date TEXT,
  text_length INTEGER NOT NULL DEFAULT 0,
  analysis TEXT NOT NULL DEFAULT '{}',
  confidence INTEGER NOT NULL DEFAULT 0,
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'À traiter' CHECK(status IN ('À traiter','Facturée','Écartée')),
  note TEXT NOT NULL DEFAULT '',
  handled_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  handled_at TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL
);

-- ---------------------------------------------------------------- Service informatique
-- Le parc logiciel : ce que l'entreprise paie, qui y a accès, et ce qui casse.
-- Un logiciel n'est pas un équipement (assets) : il n'a pas de numéro de série,
-- il a des sièges, un renouvellement, et une liste de personnes qui y entrent.
CREATE TABLE IF NOT EXISTS software_licences (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  publisher TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Abonnement' CHECK(kind IN ('Abonnement','Licence perpétuelle','Logiciel libre','Développement interne')),
  -- 0 vaut « sans limite » : un logiciel libre n'a pas de sièges à compter.
  seats INTEGER NOT NULL DEFAULT 0,
  unit_cost REAL,
  billing_period TEXT NOT NULL DEFAULT 'Annuel',
  renewal_date TEXT,
  owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  criticality TEXT NOT NULL DEFAULT 'Importante' CHECK(criticality IN ('Vitale','Importante','Secondaire')),
  -- Marque un traitement de données personnelles : le registre RGPD s'y adosse.
  personal_data INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'Actif' CHECK(status IN ('Actif','En test','Retiré')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Qui a accès à quoi. Un accès retiré n'est pas effacé : la date de révocation
-- est précisément ce qu'une revue d'accès a besoin de lire.
CREATE TABLE IF NOT EXISTS software_accesses (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  licence_id INTEGER NOT NULL REFERENCES software_licences(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  level TEXT NOT NULL DEFAULT 'Utilisateur' CHECK(level IN ('Utilisateur','Gestionnaire','Administrateur')),
  granted_on TEXT NOT NULL DEFAULT (date('now')),
  granted_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  revoked_on TEXT,
  reviewed_on TEXT,
  note TEXT NOT NULL DEFAULT ''
);

-- Incidents du système d'information : l'horodatage sert au délai de
-- rétablissement, qui n'a de sens que mesuré, pas raconté.
CREATE TABLE IF NOT EXISTS it_incidents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL DEFAULT '',
  title TEXT NOT NULL,
  service_id INTEGER REFERENCES app_services(id) ON DELETE SET NULL,
  severity TEXT NOT NULL DEFAULT 'Majeur' CHECK(severity IN ('Critique','Majeur','Mineur')),
  started_at TEXT NOT NULL,
  detected_at TEXT,
  resolved_at TEXT,
  impact TEXT NOT NULL DEFAULT '',
  cause TEXT NOT NULL DEFAULT '',
  remediation TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Ouvert' CHECK(status IN ('Ouvert','En cours','Résolu','Clos')),
  declared_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Développement
-- Le référentiel applicatif : ce que l'entreprise fait tourner et qui en répond.
CREATE TABLE IF NOT EXISTS app_services (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  code TEXT NOT NULL DEFAULT '',
  description TEXT NOT NULL DEFAULT '',
  repository TEXT NOT NULL DEFAULT '',
  documentation TEXT NOT NULL DEFAULT '',
  stack TEXT NOT NULL DEFAULT '',
  criticality TEXT NOT NULL DEFAULT 'Importante' CHECK(criticality IN ('Vitale','Importante','Secondaire')),
  lead_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'En service' CHECK(status IN ('En construction','En service','Retiré')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Les livraisons. Une livraison retirée reste inscrite : c'est elle qui fait le
-- taux d'échec, et une ligne effacée embellirait l'indicateur sans rien réparer.
CREATE TABLE IF NOT EXISTS releases (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  service_id INTEGER NOT NULL REFERENCES app_services(id) ON DELETE CASCADE,
  version TEXT NOT NULL,
  environment TEXT NOT NULL DEFAULT 'Production' CHECK(environment IN ('Développement','Recette','Préproduction','Production')),
  planned_on TEXT,
  released_on TEXT,
  status TEXT NOT NULL DEFAULT 'Planifiée' CHECK(status IN ('Planifiée','Livrée','Échouée','Retirée')),
  changelog TEXT NOT NULL DEFAULT '',
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  incident_id INTEGER REFERENCES it_incidents(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Événements
CREATE TABLE IF NOT EXISTS company_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  title TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Séminaire' CHECK(kind IN ('Séminaire','Formation','Réunion générale','Atelier','Salon','Convivialité')),
  description TEXT NOT NULL DEFAULT '',
  location TEXT NOT NULL DEFAULT '',
  starts_at TEXT NOT NULL,
  ends_at TEXT,
  -- Portée : reprise du modèle des actualités, pour que le périmètre d'un
  -- événement se lise comme celui d'une annonce.
  scope TEXT NOT NULL DEFAULT 'company' CHECK(scope IN ('company','department','team')),
  scope_id INTEGER,
  -- 0 vaut « sans limite » : pas de liste d'attente pour une réunion générale.
  capacity INTEGER NOT NULL DEFAULT 0,
  registration_closes_on TEXT,
  budget REAL,
  cost REAL,
  organizer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Ouvert','Complet','Clos','Annulé')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS event_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL REFERENCES company_events(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'Inscrit' CHECK(status IN ('Inscrit','Liste d''attente','Annulée','Présent','Absent')),
  registered_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  UNIQUE(event_id, user_id)
);

-- ---------------------------------------------------------------- Points individuels
-- Le point d'un manager avec un collaborateur. Deux comptes rendus : celui que
-- les deux lisent, et les notes du manager, qui ne sortent jamais de son écran.
CREATE TABLE IF NOT EXISTS one_on_ones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  manager_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  scheduled_on TEXT NOT NULL,
  held_on TEXT,
  topics TEXT NOT NULL DEFAULT '',
  shared_note TEXT NOT NULL DEFAULT '',
  private_note TEXT NOT NULL DEFAULT '',
  mood INTEGER,
  next_on TEXT,
  status TEXT NOT NULL DEFAULT 'Planifié' CHECK(status IN ('Planifié','Tenu','Annulé')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Partenaires
CREATE TABLE IF NOT EXISTS partner_contacts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  is_primary INTEGER NOT NULL DEFAULT 0,
  notes TEXT NOT NULL DEFAULT ''
);

-- Les pièces qu'un tiers doit fournir, et jusqu'à quand elles valent. Une
-- attestation de vigilance périmée engage la responsabilité du donneur d'ordre :
-- c'est une échéance, pas une pièce jointe.
CREATE TABLE IF NOT EXISTS partner_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Attestation de vigilance' CHECK(kind IN ('Attestation de vigilance','Assurance','Kbis','Coordonnées bancaires','Certification','Autre')),
  reference TEXT NOT NULL DEFAULT '',
  issued_on TEXT,
  expires_on TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS partner_reviews (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  reviewed_on TEXT NOT NULL,
  reviewer_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  quality INTEGER,
  lead_time INTEGER,
  price INTEGER,
  comment TEXT NOT NULL DEFAULT '',
  next_review TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_incoming_documents_status ON incoming_documents(status, received_at);
CREATE INDEX IF NOT EXISTS idx_workflow_requests_state ON workflow_requests(status, form_id);
CREATE INDEX IF NOT EXISTS idx_workflow_requests_requester ON workflow_requests(requester_id, status);
CREATE INDEX IF NOT EXISTS idx_request_steps_form ON request_steps(form_id, position);
CREATE INDEX IF NOT EXISTS idx_webhook_deliveries_state ON webhook_deliveries(status, next_try_at);
CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_signature_signers_user ON signature_signers(user_id, status);
CREATE INDEX IF NOT EXISTS idx_signature_signers_request ON signature_signers(request_id, position);
CREATE INDEX IF NOT EXISTS idx_subscriptions_next ON subscriptions(active, next_issue);
-- Une échéance d'abonnement ne peut pas être facturée deux fois, même si deux
-- balayages se croisent : l'index l'interdit à la source.
CREATE UNIQUE INDEX IF NOT EXISTS idx_invoices_subscription_period ON invoices(subscription_id, issue_date) WHERE subscription_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_meeting_actions_assignee ON meeting_actions(assignee_id, status);
CREATE INDEX IF NOT EXISTS idx_meeting_attendees_meeting ON meeting_attendees(meeting_id);
CREATE INDEX IF NOT EXISTS idx_decisions_meeting ON decisions(meeting_id);
CREATE INDEX IF NOT EXISTS idx_survey_answers_question ON survey_answers(question_id);
CREATE INDEX IF NOT EXISTS idx_shifts_user ON shifts(user_id, starts_at);
CREATE INDEX IF NOT EXISTS idx_shifts_window ON shifts(starts_at, ends_at);
CREATE INDEX IF NOT EXISTS idx_quality_actions_owner ON quality_actions(owner_id, status);
CREATE INDEX IF NOT EXISTS idx_visitors_day ON visitors(visited_on);
CREATE INDEX IF NOT EXISTS idx_mail_status ON mail_items(status, logged_on);
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
-- ---------------------------------------------------------------- Alerte interne
-- Dispositif de recueil des signalements (loi du 21 mars 2022).
--
-- Trois exigences dictent ce schéma. L'auteur peut rester anonyme : la colonne
-- author_id est alors laissée vide, et rien ailleurs ne permet de le retrouver.
-- Le contenu est chiffré : une copie de la base ne livre pas les signalements.
-- Le code de suivi n'est que haché : il permet à l'auteur anonyme de revenir
-- lire la réponse, sans que la base puisse le lui redonner s'il le perd.
CREATE TABLE IF NOT EXISTS whistleblow_reports (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL UNIQUE,
  follow_code_hash TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Autre' CHECK(category IN ('Corruption','Fraude','Harcèlement','Discrimination','Sécurité des personnes','Environnement','Données personnelles','Autre')),
  subject_enc TEXT NOT NULL,
  body_enc TEXT NOT NULL DEFAULT '',
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  anonymous INTEGER NOT NULL DEFAULT 1,
  submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- La loi impose un accusé sous 7 jours et un retour sur les suites sous 3 mois.
  acknowledged_at TEXT,
  closed_at TEXT,
  outcome_enc TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Reçue' CHECK(status IN ('Reçue','Recevable','Irrecevable','En instruction','Clôturée'))
);

-- Les échanges entre l'auteur et le référent, dans les deux sens, chiffrés eux aussi.
CREATE TABLE IF NOT EXISTS whistleblow_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES whistleblow_reports(id) ON DELETE CASCADE,
  author_kind TEXT NOT NULL CHECK(author_kind IN ('auteur','referent')),
  referent_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body_enc TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Qui a ouvert quel signalement. Le journal d'audit général ne peut pas porter
-- cette trace sans nommer l'alerte à tout administrateur : elle vit donc ici,
-- lisible des seuls référents.
CREATE TABLE IF NOT EXISTS whistleblow_access_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES whistleblow_reports(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  occurred_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Achats
-- Bon de commande fournisseur. Le contrôle qui compte n'est pas la commande
-- elle-même, mais le rapprochement à trois : ce qui a été commandé, ce qui a
-- été reçu, ce qui est facturé. Une facture qui dépasse la commande, ou qui
-- porte sur ce qui n'est jamais arrivé, se voit avant le règlement.
CREATE TABLE IF NOT EXISTS purchase_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL UNIQUE,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE RESTRICT,
  request_id INTEGER REFERENCES purchase_requests(id) ON DELETE SET NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  ordered_on TEXT NOT NULL,
  expected_on TEXT,
  notes TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Envoyée','Reçue partiellement','Reçue','Annulée')),
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS purchase_order_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  unit_price REAL NOT NULL DEFAULT 0,
  -- Cumul des réceptions : recalculé à chaque bon de réception, jamais saisi.
  received_quantity REAL NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS purchase_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  line_id INTEGER NOT NULL REFERENCES purchase_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL,
  received_on TEXT NOT NULL,
  received_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Recouvrement
-- Les relances envoyées sur une facture client. Le niveau ne se déduit pas du
-- retard mais de ce qui a déjà été envoyé : on ne met pas en demeure quelqu'un
-- à qui l'on n'a jamais écrit.
CREATE TABLE IF NOT EXISTS dunning_notices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  level INTEGER NOT NULL CHECK(level BETWEEN 1 AND 3),
  sent_on TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ---------------------------------------------------------------- Vie juridique
-- La couche sociétaire : qui détient la société, qui la dirige, et ce que les
-- assemblées ont décidé. Rien de tout cela ne vivait dans le produit, alors que
-- c'est ce qui répond à « qui peut engager l'entreprise ».
CREATE TABLE IF NOT EXISTS shareholders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Personne physique' CHECK(kind IN ('Personne physique','Personne morale')),
  -- Rattachement au compte quand l'associé est aussi salarié.
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  registration TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '',
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Registre des mouvements de titres. La détention n'est pas une colonne mais
-- une somme : elle se recalcule des mouvements, comme un solde bancaire, si
-- bien qu'aucune cession ne peut modifier un capital sans laisser sa ligne.
CREATE TABLE IF NOT EXISTS share_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  shareholder_id INTEGER NOT NULL REFERENCES shareholders(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK(kind IN ('Souscription','Cession','Acquisition','Réduction')),
  moved_on TEXT NOT NULL,
  -- Signé : une cession retire, une acquisition ajoute.
  shares REAL NOT NULL,
  unit_price REAL,
  counterparty_id INTEGER REFERENCES shareholders(id) ON DELETE SET NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS corporate_mandates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  holder_name TEXT NOT NULL,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  role TEXT NOT NULL CHECK(role IN ('Président','Directeur général','Directeur général délégué','Gérant','Membre du conseil','Commissaire aux comptes')),
  started_on TEXT NOT NULL,
  ends_on TEXT,
  appointed_by TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'En cours' CHECK(status IN ('En cours','Échu','Révoqué')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS general_meetings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  -- La référence se numérote par société : chaque entité d'un groupe tient ses
  -- propres assemblées, et les numérote depuis la première.
  reference TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Assemblée générale ordinaire' CHECK(kind IN ('Assemblée générale ordinaire','Assemblée générale extraordinaire','Assemblée générale mixte')),
  held_on TEXT NOT NULL,
  location TEXT NOT NULL DEFAULT '',
  -- Quorum exigé et parts effectivement présentes ou représentées, en titres.
  quorum_required REAL NOT NULL DEFAULT 0,
  shares_present REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'Convoquée' CHECK(status IN ('Convoquée','Tenue','Annulée')),
  minutes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, reference)
);

CREATE TABLE IF NOT EXISTS meeting_resolutions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  meeting_id INTEGER NOT NULL REFERENCES general_meetings(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 1,
  label TEXT NOT NULL,
  -- Majorité exigée, en pourcentage des voix exprimées.
  majority_required REAL NOT NULL DEFAULT 50,
  votes_for REAL NOT NULL DEFAULT 0,
  votes_against REAL NOT NULL DEFAULT 0,
  votes_abstain REAL NOT NULL DEFAULT 0,
  outcome TEXT NOT NULL DEFAULT 'En attente' CHECK(outcome IN ('En attente','Adoptée','Rejetée'))
);

-- ---------------------------------------------------------------- Conformité
-- Déclarations de conflits d'intérêts. Ce registre n'a de valeur que si chacun
-- déclare le sien : il est donc ouvert à tous en écriture, et fermé en lecture.
CREATE TABLE IF NOT EXISTS interest_declarations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Autre' CHECK(kind IN ('Intérêt financier','Mandat externe','Lien familial','Activité accessoire','Autre')),
  entity TEXT NOT NULL,
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  description TEXT NOT NULL DEFAULT '',
  declared_on TEXT NOT NULL,
  ends_on TEXT,
  status TEXT NOT NULL DEFAULT 'Déclaré' CHECK(status IN ('Déclaré','Examiné','Mesure prise','Clos')),
  measure TEXT NOT NULL DEFAULT '',
  reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS gift_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  direction TEXT NOT NULL DEFAULT 'Reçu' CHECK(direction IN ('Reçu','Offert')),
  kind TEXT NOT NULL DEFAULT 'Cadeau' CHECK(kind IN ('Cadeau','Invitation','Voyage','Autre')),
  partner_id INTEGER REFERENCES partners(id) ON DELETE SET NULL,
  third_party TEXT NOT NULL DEFAULT '',
  occurred_on TEXT NOT NULL,
  value REAL NOT NULL DEFAULT 0,
  description TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Déclaré' CHECK(status IN ('Déclaré','Accepté','Refusé','Restitué')),
  reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Délégations de pouvoir et de signature : qui peut engager l'entreprise, sur
-- quel objet, jusqu'à quel montant, et jusqu'à quand.
CREATE TABLE IF NOT EXISTS power_delegations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  holder_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  granted_by_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  scope TEXT NOT NULL,
  amount_limit REAL,
  starts_on TEXT NOT NULL,
  ends_on TEXT,
  status TEXT NOT NULL DEFAULT 'En vigueur' CHECK(status IN ('En vigueur','Suspendue','Échue','Révoquée')),
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_share_movements_holder ON share_movements(shareholder_id, moved_on);
CREATE INDEX IF NOT EXISTS idx_meeting_resolutions_meeting ON meeting_resolutions(meeting_id, position);
CREATE INDEX IF NOT EXISTS idx_interest_declarations_user ON interest_declarations(user_id, status);
CREATE INDEX IF NOT EXISTS idx_gift_records_user ON gift_records(user_id, occurred_on);
CREATE INDEX IF NOT EXISTS idx_power_delegations_holder ON power_delegations(holder_id, status);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_partner ON purchase_orders(partner_id, status);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_order ON purchase_order_lines(order_id);
CREATE INDEX IF NOT EXISTS idx_purchase_receipts_line ON purchase_receipts(line_id, received_on);
CREATE INDEX IF NOT EXISTS idx_dunning_invoice ON dunning_notices(invoice_id, level);
CREATE INDEX IF NOT EXISTS idx_whistleblow_status ON whistleblow_reports(status, submitted_at);
CREATE INDEX IF NOT EXISTS idx_whistleblow_messages_report ON whistleblow_messages(report_id, created_at);
CREATE INDEX IF NOT EXISTS idx_software_accesses_licence ON software_accesses(licence_id, revoked_on);
CREATE INDEX IF NOT EXISTS idx_software_accesses_user ON software_accesses(user_id, revoked_on);
-- Une même personne ne peut pas détenir deux accès ouverts au même logiciel ;
-- un accès révoqué, lui, ne bloque pas une nouvelle attribution.
CREATE UNIQUE INDEX IF NOT EXISTS idx_software_accesses_open ON software_accesses(licence_id, user_id) WHERE revoked_on IS NULL;
CREATE INDEX IF NOT EXISTS idx_it_incidents_state ON it_incidents(status, started_at);
CREATE INDEX IF NOT EXISTS idx_releases_service ON releases(service_id, released_on);
CREATE INDEX IF NOT EXISTS idx_releases_env ON releases(environment, status, released_on);
CREATE INDEX IF NOT EXISTS idx_company_events_start ON company_events(starts_at, status);
CREATE INDEX IF NOT EXISTS idx_event_registrations_event ON event_registrations(event_id, status);
CREATE INDEX IF NOT EXISTS idx_event_registrations_user ON event_registrations(user_id, status);
CREATE INDEX IF NOT EXISTS idx_one_on_ones_pair ON one_on_ones(manager_id, employee_id, scheduled_on);
CREATE INDEX IF NOT EXISTS idx_one_on_ones_employee ON one_on_ones(employee_id, scheduled_on);
CREATE INDEX IF NOT EXISTS idx_partner_contacts_partner ON partner_contacts(partner_id);
CREATE INDEX IF NOT EXISTS idx_partner_documents_partner ON partner_documents(partner_id, expires_on);
CREATE INDEX IF NOT EXISTS idx_partner_reviews_partner ON partner_reviews(partner_id, reviewed_on);
CREATE INDEX IF NOT EXISTS idx_vault_grants_user ON vault_access_grants(user_id);

-- ── Les champs personnalisés ────────────────────────────────────────────
--
-- Deux tables plutôt qu'une colonne par champ : une entreprise qui ajoute
-- trois champs à la fiche salarié ne doit pas faire muter le schéma de la
-- table « users ». Une migration de schéma au clic est le genre de chose qui
-- marche cent fois puis casse une base en service, un mardi, sans témoin.
--
-- La définition et la valeur sont donc séparées. La définition se lit à
-- chaque écran, la valeur se joint à l'enregistrement qu'elle décore.
CREATE TABLE IF NOT EXISTS custom_fields (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  -- L'objet décoré, pris dans une liste fermée : « employe », « ticket »…
  -- Une chaîne libre laisserait une faute de frappe créer un objet fantôme,
  -- dont les valeurs ne seraient jamais ni lues ni effacées.
  objet TEXT NOT NULL,
  -- L'identifiant stable du champ. Il ne change jamais, même si le libellé
  -- change : c'est lui que porteront les exports et, demain, les rapports.
  cle TEXT NOT NULL,
  libelle TEXT NOT NULL,
  type TEXT NOT NULL,
  -- Les choix d'une liste, un par ligne. Vide pour les autres types.
  choix TEXT NOT NULL DEFAULT '',
  obligatoire INTEGER NOT NULL DEFAULT 0,
  aide TEXT NOT NULL DEFAULT '',
  rang INTEGER NOT NULL DEFAULT 0,
  -- Éteindre plutôt qu'effacer : un champ retiré de l'écran garde ses valeurs,
  -- et les retrouve si on le rallume. L'effacement, lui, est explicite.
  actif INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_custom_fields_objet ON custom_fields(objet, actif, rang);

CREATE TABLE IF NOT EXISTS custom_values (
  field_id INTEGER NOT NULL REFERENCES custom_fields(id) ON DELETE CASCADE,
  -- L'identifiant de l'enregistrement décoré, dans sa propre table. Il n'y a
  -- pas de clé étrangère : la table visée dépend de « objet », et aucune
  -- contrainte ne sait dire « selon la valeur d'une autre colonne ». Le
  -- ménage se fait donc à la suppression de l'enregistrement, par le module.
  record_id INTEGER NOT NULL,
  valeur TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (field_id, record_id)
);
CREATE INDEX IF NOT EXISTS idx_custom_values_record ON custom_values(record_id);

/*
 * La corbeille : ce qu'une suppression emporte, mis de côté avant de partir.
 *
 * « charge » tient le JSON de la ligne supprimée, de ses enfants en cascade,
 * des liens que la suppression a mis à nul, et de ses champs personnalisés. Ce
 * n'est pas une copie de confort : c'est tout ce qu'il faut pour remettre
 * l'enregistrement là où il était. L'écran n'affiche jamais cette charge, mais
 * « libelle », posé au moment de jeter — relire la charge pour l'afficher
 * rendrait visible, dans une liste d'administration, ce que la fiche elle-même
 * réservait à certains.
 */
CREATE TABLE IF NOT EXISTS trash (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  objet TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  libelle TEXT NOT NULL DEFAULT '',
  charge TEXT NOT NULL,
  supprime_par INTEGER REFERENCES users(id) ON DELETE SET NULL,
  supprime_le TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_trash_objet ON trash(objet, supprime_le);
CREATE INDEX IF NOT EXISTS idx_trash_date ON trash(supprime_le);

/*
 * Les sociétés : le périmètre de tout ce qui est métier.
 *
 * Une instance en porte au moins une — celle créée à l'installation, ou posée
 * par la migration ci-dessous pour une instance qui tournait déjà. Le reste du
 * produit n'a pas à savoir s'il y en a une ou douze : il demande le périmètre
 * courant et filtre avec.
 *
 * L'appartenance vit dans sa propre table, et non dans une colonne de « users » :
 * une personne peut relever de plusieurs entités d'un groupe — la direction en
 * relève souvent de toutes — et une colonne unique aurait obligé à dupliquer le
 * compte, donc le mot de passe, donc les sessions.
 */
CREATE TABLE IF NOT EXISTS companies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  legal_form TEXT NOT NULL DEFAULT '',
  registration TEXT NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- La date jusqu'à laquelle la comptabilité de cette société est validée :
  -- aucune écriture ne se saisit plus à cette date ou avant.
  locked_until TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_companies_name ON companies(name);

-- Les mandats de prélèvement SEPA : l'autorisation qu'un client signe pour
-- être prélevé. La référence unique du mandat (RUM) est unique par société —
-- deux entités d'un groupe peuvent chacune avoir son « RUM-0001 ».
CREATE TABLE IF NOT EXISTS sepa_mandates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  rum TEXT NOT NULL,
  signed_on TEXT NOT NULL,
  iban TEXT NOT NULL,
  bic TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Actif' CHECK(status IN ('Actif','Révoqué')),
  -- Le premier prélèvement d'un mandat part en « FRST », les suivants en « RCUR ».
  first_used_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, rum)
);

-- ---------- Ventes : catalogue, commandes, livraisons ----------
-- Le catalogue de ce que l'entreprise vend. Un bien peut être lié à un article
-- du stock : sa livraison en fait sortir la quantité.
CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Service' CHECK(kind IN ('Service','Bien')),
  unit TEXT NOT NULL DEFAULT 'unité',
  price_ht REAL NOT NULL DEFAULT 0,
  vat_rate REAL NOT NULL DEFAULT 20,
  item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, reference)
);

-- La commande client. Son statut ne dit que la décision (brouillon, confirmée,
-- annulée) : ce qui est livré et facturé se calcule sur les lignes, jamais ne
-- se recopie — une facture annulée doit rendre sa quantité d'elle-même.
CREATE TABLE IF NOT EXISTS sales_orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  reference TEXT NOT NULL,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  quote_id INTEGER REFERENCES quotes(id) ON DELETE SET NULL,
  order_date TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'Brouillon' CHECK(status IN ('Brouillon','Confirmée','Annulée')),
  notes TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, reference)
);

CREATE TABLE IF NOT EXISTS sales_order_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
  product_id INTEGER REFERENCES products(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  quantity REAL NOT NULL,
  unit TEXT NOT NULL DEFAULT 'unité',
  unit_price_ht REAL NOT NULL,
  vat_rate REAL NOT NULL DEFAULT 20,
  position INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS delivery_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  order_id INTEGER NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
  reference TEXT NOT NULL,
  delivered_on TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, reference)
);

CREATE TABLE IF NOT EXISTS delivery_note_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  note_id INTEGER NOT NULL REFERENCES delivery_notes(id) ON DELETE CASCADE,
  order_line_id INTEGER NOT NULL REFERENCES sales_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL
);

-- Ce qu'une facture reprend d'une commande, ligne à ligne.
CREATE TABLE IF NOT EXISTS sales_invoice_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  order_line_id INTEGER NOT NULL REFERENCES sales_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL
);

-- Les pages de paiement Stripe demandées pour une facture client : seule une
-- session créée ici fait foi quand Stripe annonce un paiement.
CREATE TABLE IF NOT EXISTS stripe_sessions (
  session_id TEXT PRIMARY KEY,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  amount_cents INTEGER NOT NULL,
  currency TEXT NOT NULL,
  url TEXT NOT NULL,
  -- open, paid ou orphan (payée, mais sa facture a disparu ou a été annulée) :
  -- un état interne, jamais affiché, que seul src/stripe.js écrit.
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL,
  paid_at TEXT
);

CREATE TABLE IF NOT EXISTS company_members (
  company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (company_id, user_id)
);
CREATE INDEX IF NOT EXISTS idx_company_members_user ON company_members(user_id);
`);

/*
 * Les colonnes ajoutées à des tables du second bloc de schéma.
 *
 * La liste plus haut s'exécute entre les deux blocs : y mettre un ALTER sur
 * « tickets » échouerait, la table n'existe pas encore à cet instant. Celle-ci
 * passe après, et sert aux bases déjà en service — les bases neuves ont la
 * colonne dans leur définition de table.
 */
for (const migration of [
  'ALTER TABLE tickets ADD COLUMN group_id INTEGER',
  // Le cloisonnement par société, table par table. Voir src/cloison.js.
  'ALTER TABLE announcements ADD COLUMN company_id INTEGER',
  'ALTER TABLE departments ADD COLUMN company_id INTEGER',
  'ALTER TABLE teams ADD COLUMN company_id INTEGER',
  'ALTER TABLE org_managers ADD COLUMN company_id INTEGER',
  'ALTER TABLE partners ADD COLUMN company_id INTEGER',
  'ALTER TABLE invoices ADD COLUMN company_id INTEGER',
  'ALTER TABLE accounts ADD COLUMN company_id INTEGER',
  'ALTER TABLE journals ADD COLUMN company_id INTEGER',
  'ALTER TABLE entries ADD COLUMN company_id INTEGER',
  'ALTER TABLE projects ADD COLUMN company_id INTEGER',
  'ALTER TABLE tickets ADD COLUMN company_id INTEGER',
  'ALTER TABLE support_groups ADD COLUMN company_id INTEGER',
  // Lot 5 : les ressources humaines.
  'ALTER TABLE hr_requests ADD COLUMN company_id INTEGER',
  'ALTER TABLE payslips ADD COLUMN company_id INTEGER',
  'ALTER TABLE payroll_rates ADD COLUMN company_id INTEGER',
  'ALTER TABLE leave_adjustments ADD COLUMN company_id INTEGER',
  'ALTER TABLE time_entries ADD COLUMN company_id INTEGER',
  'ALTER TABLE medical_visits ADD COLUMN company_id INTEGER',
  'ALTER TABLE trainings ADD COLUMN company_id INTEGER',
  'ALTER TABLE job_openings ADD COLUMN company_id INTEGER',
  'ALTER TABLE reviews ADD COLUMN company_id INTEGER',
  'ALTER TABLE one_on_ones ADD COLUMN company_id INTEGER',
  'ALTER TABLE checklists ADD COLUMN company_id INTEGER',
  'ALTER TABLE checklist_templates ADD COLUMN company_id INTEGER',
  'ALTER TABLE expense_claims ADD COLUMN company_id INTEGER',
  // Lot 6 : le stock, les achats et la trésorerie.
  'ALTER TABLE items ADD COLUMN company_id INTEGER',
  'ALTER TABLE assets ADD COLUMN company_id INTEGER',
  'ALTER TABLE tools ADD COLUMN company_id INTEGER',
  'ALTER TABLE assignments ADD COLUMN company_id INTEGER',
  'ALTER TABLE purchase_orders ADD COLUMN company_id INTEGER',
  'ALTER TABLE purchase_requests ADD COLUMN company_id INTEGER',
  'ALTER TABLE subscriptions ADD COLUMN company_id INTEGER',
  'ALTER TABLE quotes ADD COLUMN company_id INTEGER',
  'ALTER TABLE opportunities ADD COLUMN company_id INTEGER',
  'ALTER TABLE budgets ADD COLUMN company_id INTEGER',
  'ALTER TABLE bank_accounts ADD COLUMN company_id INTEGER',
  'ALTER TABLE cash_forecasts ADD COLUMN company_id INTEGER',
  'ALTER TABLE fixed_assets ADD COLUMN company_id INTEGER',
  'ALTER TABLE vat_returns ADD COLUMN company_id INTEGER',
  'ALTER TABLE exchange_rates ADD COLUMN company_id INTEGER',
  // Lot 7 : le comité social, la vie sociale et le dispositif d'alerte.
  'ALTER TABLE cse_mandates ADD COLUMN company_id INTEGER',
  'ALTER TABLE cse_elections ADD COLUMN company_id INTEGER',
  'ALTER TABLE cse_meetings ADD COLUMN company_id INTEGER',
  'ALTER TABLE cse_benefits ADD COLUMN company_id INTEGER',
  'ALTER TABLE meetings ADD COLUMN company_id INTEGER',
  'ALTER TABLE whistleblow_reports ADD COLUMN company_id INTEGER',
  'ALTER TABLE shareholders ADD COLUMN company_id INTEGER',
  'ALTER TABLE corporate_mandates ADD COLUMN company_id INTEGER',
  'ALTER TABLE general_meetings ADD COLUMN company_id INTEGER',
  'ALTER TABLE interest_declarations ADD COLUMN company_id INTEGER',
  'ALTER TABLE gift_records ADD COLUMN company_id INTEGER',
  'ALTER TABLE power_delegations ADD COLUMN company_id INTEGER',
  'ALTER TABLE decisions ADD COLUMN company_id INTEGER',
  'ALTER TABLE meeting_actions ADD COLUMN company_id INTEGER',
  // Lot 8 : la qualité, la sécurité au travail et le registre des traitements.
  'ALTER TABLE nonconformities ADD COLUMN company_id INTEGER',
  'ALTER TABLE quality_actions ADD COLUMN company_id INTEGER',
  'ALTER TABLE internal_audits ADD COLUMN company_id INTEGER',
  'ALTER TABLE risk_assessments ADD COLUMN company_id INTEGER',
  'ALTER TABLE enterprise_risks ADD COLUMN company_id INTEGER',
  'ALTER TABLE workplace_incidents ADD COLUMN company_id INTEGER',
  'ALTER TABLE ppe_items ADD COLUMN company_id INTEGER',
  'ALTER TABLE processing_records ADD COLUMN company_id INTEGER',
  // Lot 9 : l'informatique — parc logiciel, services applicatifs, connaissances,
  // webhooks et parapheur.
  'ALTER TABLE app_services ADD COLUMN company_id INTEGER',
  'ALTER TABLE it_incidents ADD COLUMN company_id INTEGER',
  'ALTER TABLE software_licences ADD COLUMN company_id INTEGER',
  'ALTER TABLE kb_articles ADD COLUMN company_id INTEGER',
  'ALTER TABLE webhooks ADD COLUMN company_id INTEGER',
  'ALTER TABLE signature_requests ADD COLUMN company_id INTEGER',
  // Lot 10 : les moyens généraux — salles, flotte, accueil, documents et
  // événements d'entreprise, pièces reçues.
  'ALTER TABLE rooms ADD COLUMN company_id INTEGER',
  'ALTER TABLE vehicles ADD COLUMN company_id INTEGER',
  'ALTER TABLE visitors ADD COLUMN company_id INTEGER',
  'ALTER TABLE mail_items ADD COLUMN company_id INTEGER',
  'ALTER TABLE company_documents ADD COLUMN company_id INTEGER',
  'ALTER TABLE company_events ADD COLUMN company_id INTEGER',
  'ALTER TABLE incoming_documents ADD COLUMN company_id INTEGER',
  // Lot 11 : le reste — objectifs, enquêtes, plannings, formulaires de demande,
  // corbeille et champs personnalisés.
  'ALTER TABLE objectives ADD COLUMN company_id INTEGER',
  'ALTER TABLE surveys ADD COLUMN company_id INTEGER',
  'ALTER TABLE shifts ADD COLUMN company_id INTEGER',
  'ALTER TABLE shift_templates ADD COLUMN company_id INTEGER',
  'ALTER TABLE request_forms ADD COLUMN company_id INTEGER',
  'ALTER TABLE trash ADD COLUMN company_id INTEGER',
  'ALTER TABLE custom_fields ADD COLUMN company_id INTEGER',
  // Le jeton d'API porte son propre périmètre.
  'ALTER TABLE api_tokens ADD COLUMN company_id INTEGER',
  // La validation des écritures et le verrouillage de la période (FEC).
  'ALTER TABLE entries ADD COLUMN number INTEGER',
  'ALTER TABLE entries ADD COLUMN validated_at TEXT',
  'ALTER TABLE entries ADD COLUMN reverses_id INTEGER',
  'ALTER TABLE companies ADD COLUMN locked_until TEXT',
  // L'import des relevés bancaires.
  'ALTER TABLE bank_transactions ADD COLUMN import_ref TEXT',
  // Les paiements SEPA : les coordonnées bancaires de la société et des tiers.
  "ALTER TABLE companies ADD COLUMN iban TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE companies ADD COLUMN bic TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE companies ADD COLUMN sepa_ics TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE partners ADD COLUMN iban TEXT NOT NULL DEFAULT ''",
  "ALTER TABLE partners ADD COLUMN bic TEXT NOT NULL DEFAULT ''",
  // Le lettrage : la date à laquelle une ligne a reçu son code (DateLet du FEC).
  'ALTER TABLE entry_lines ADD COLUMN lettered_at TEXT',
  // La raison d'une affaire perdue : une clé d'une liste fermée.
  "ALTER TABLE opportunities ADD COLUMN lost_reason TEXT NOT NULL DEFAULT ''",
  // Les indemnités kilométriques : la distance, la puissance fiscale, l'énergie.
  'ALTER TABLE expense_claims ADD COLUMN distance_km REAL',
  'ALTER TABLE expense_claims ADD COLUMN fiscal_hp INTEGER',
  'ALTER TABLE expense_claims ADD COLUMN electric INTEGER NOT NULL DEFAULT 0',
  // La commande d'où sort une facture.
  'ALTER TABLE invoices ADD COLUMN sales_order_id INTEGER',
  // L'identifiant opaque qu'une clé WebAuthn associe à la personne.
  'ALTER TABLE users ADD COLUMN webauthn_handle TEXT',
]) {
  try {
    poser(migration);
  } catch (err) {
    if (!/duplicate column/i.test(err.message)) throw err;
  }
}

/*
 * L'empreinte d'une pièce reçue est unique par société, non dans toute
 * l'instance : la même facture peut arriver chez deux entités d'un groupe, et
 * refuser la seconde laisserait la pièce nulle part. L'index global tombe donc,
 * et le nouveau se pose ici plutôt que dans le schéma — il nomme une colonne que
 * les migrations viennent d'ajouter, et une base d'avant le cloisonnement ne
 * l'aurait pas encore au moment où le schéma s'exécute.
 */
db.exec('DROP INDEX IF EXISTS idx_incoming_documents_sha');

/*
 * De même pour la clé technique d'un champ personnalisé : elle est unique par
 * société et par objet, non dans toute l'instance. Sans cela, la deuxième entité
 * à définir un champ « Référence interne » hériterait d'une clé suffixée parce
 * qu'une autre a pris le nom — ce qui se verrait dans ses exports.
 */
db.exec('DROP INDEX IF EXISTS idx_custom_fields_cle');
db.exec(`
  CREATE UNIQUE INDEX IF NOT EXISTS idx_custom_fields_societe_cle
    ON custom_fields(company_id, objet, cle)
`);
db.exec(`
  CREATE UNIQUE INDEX IF NOT EXISTS idx_incoming_documents_societe_sha
    ON incoming_documents(company_id, sha256)
`);

/*
 * La première société, pour une instance qui tournait déjà.
 *
 * Sans elle, une base existante aurait des lignes métier sans périmètre, que
 * plus aucune requête ne verrait : la mise à niveau se lirait comme une perte
 * de données. Elle reprend le nom de l'entreprise déjà réglé, rattache tout le
 * monde, et pose le périmètre sur les lignes qui n'en avaient pas.
 *
 * Elle ne s'exécute que si aucune société n'existe — une instance neuve en crée
 * une par l'assistant —, et ce qu'elle fait est idempotent : la relancer ne
 * crée pas de seconde société et ne déplace rien.
 */
/*
 * Les contraintes d'unicité qui doivent devenir des contraintes par société.
 *
 * Posées avant la migration des données : une table reconstruite perd puis
 * retrouve ses lignes, et mieux vaut qu'elle le fasse avant qu'on y écrive un
 * périmètre. Rejouée, la reconstruction ne fait rien — elle compare la
 * définition en base à celle du schéma.
 */
/* Les tables dont une unicité globale devient une unicité par société. Nommée,
   parce qu'un test la relit : chacune doit déclarer ce qui porte cette unicité,
   sinon la reconstruire ne fait rien. */
const A_RECONSTRUIRE = ['departments', 'teams', 'accounts', 'journals', 'exchange_rates',
  'cse_mandates', 'general_meetings', 'rooms'];
for (const table of A_RECONSTRUIRE) {
  reconstruirePourCloisonner(table);
}

let premiereSociete = db.prepare('SELECT id FROM companies ORDER BY id LIMIT 1').get();
if (!premiereSociete && db.prepare('SELECT COUNT(*) AS n FROM users').get().n > 0) {
  const nomReglé = db.prepare("SELECT value FROM settings WHERE key = 'company_name'").get();
  const nom = (nomReglé && String(nomReglé.value).trim()) || 'Mon entreprise';
  const creee = Number(db.prepare('INSERT INTO companies (name) VALUES (?)').run(nom).lastInsertRowid);
  db.prepare('INSERT INTO company_members (company_id, user_id) SELECT ?, id FROM users').run(creee);
  premiereSociete = { id: creee };
  console.log(`Cloisonnement : société « ${nom} » créée, et tout le monde y est rattaché.`);
}


/**
 * La première société, et le rattachement de tout le monde.
 *
 * Écrite ici et non dans src/societes.js, qui est son appelant naturel : ce
 * module-ci est requis par celui-là, et l'inverse ferait un cycle où « db » ne
 * serait pas encore prêt. societes.garantirPremiere() délègue donc ici.
 *
 * Idempotente : s'il existe déjà une société, elle ne fait que rattacher les
 * comptes qui n'ont aucun rattachement — ce qui couvre les comptes créés avant
 * que le cloisonnement existe.
 */
function garantirPremiereSociete(nom = '') {
  const premiere = db.prepare('SELECT * FROM companies ORDER BY id LIMIT 1').get();
  const voulu = String(nom).trim()
    || ((db.prepare("SELECT value FROM settings WHERE key = 'company_name'").get() || {}).value || '').trim()
    || 'Mon entreprise';
  const societe = premiere || db.prepare('SELECT * FROM companies WHERE id = ?')
    .get(Number(db.prepare('INSERT INTO companies (name) VALUES (?)').run(voulu).lastInsertRowid));

  db.prepare(`
    INSERT INTO company_members (company_id, user_id)
    SELECT ?, u.id FROM users u
    WHERE NOT EXISTS (SELECT 1 FROM company_members m WHERE m.user_id = u.id)
  `).run(societe.id);

  return societe;
}

/**
 * Changer une contrainte d'une table déjà en service.
 *
 * Le cloisonnement demande que les contraintes d'unicité globales deviennent des
 * contraintes par société : deux entités d'un groupe ont le droit d'avoir chacune
 * un service « Production », et une unicité sur le seul nom le leur interdit.
 *
 * Sur une base neuve, il suffit de corriger le schéma. Sur une base en service,
 * non : « CREATE TABLE IF NOT EXISTS » ne touche pas une table qui existe, et
 * SQLite ne sait pas retirer l'index implicite qu'une contrainte « UNIQUE » de
 * colonne a créé — il ne se supprime pas. La table doit donc être reconstruite.
 *
 * Ce que cette fonction ne fait pas : deviner la nouvelle définition. Elle la
 * reprend telle quelle dans le schéma de ce fichier, qui reste la seule source
 * de vérité — recopier la définition dans la migration, c'est accepter qu'un
 * jour les deux ne disent plus la même chose. Et elle ne reconstruit que si la
 * définition en base diffère de celle voulue : rejouée, elle ne fait rien.
 */
function definitionVoulue(table) {
  for (const script of scripts) {
    const motif = new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(([\\s\\S]*?)\\n\\);`);
    const trouve = script.match(motif);
    if (trouve) return `CREATE TABLE ${table} (${trouve[1]}\n)`;
  }
  return null;
}

/**
 * Les unicités déclarées par une définition de table.
 *
 * Deux formes, et il faut les deux : « name TEXT NOT NULL UNIQUE » pose une
 * unicité sur une colonne, « UNIQUE(company_id, name) » sur un tuple. La
 * bascule vers une unicité par société retire les premières et pose les
 * secondes.
 *
 * Cette lecture est à part pour une raison de preuve. Les deux instructions qui
 * s'en servent sont du MySQL, et le harnais de tests tourne sur SQLite : elles
 * ne s'exécutent pas ici. Ce qui décide — quelles colonnes, quels tuples — est
 * en revanche du texte vers du texte, et se vérifie sur le schéma réel. Reste à
 * MySQL le seul « ALTER TABLE », qui s'éprouve au passage de la suite sur
 * MySQL, que les deux éditions partagent.
 */
function unicitesDeDefinition(definition) {
  const texte = String(definition || '');
  const cle = texte.match(/^\s*PRIMARY KEY\s*\(([^)]+)\)/im);
  return {
    colonnes: [...texte.matchAll(/^\s*(\w+)[^,\n]*\bUNIQUE\b/gim)].map((m) => m[1]),
    tuples: [...texte.matchAll(/UNIQUE\s*\(([^)]+)\)/gi)].map((m) => m[1].trim()),
    // La clé primaire déclarée en contrainte de table, et non « id INTEGER
    // PRIMARY KEY » : c'est elle qui porte l'unicité des taux de change, où le
    // périmètre entre dans la clé plutôt que dans un index.
    cle: cle ? cle[1].trim() : null,
  };
}

/** La clé primaire en base dit-elle déjà ce que la définition veut ? */
function cleIdentique(colonnesEnBase, cleVoulue) {
  const rangee = (liste) => [...liste].map((c) => String(c).trim()).sort().join(',');
  return rangee(colonnesEnBase) === rangee(String(cleVoulue || '').split(','));
}

/* Déclarée, et non affectée à une constante : la migration qui s'en sert
   s'exécute plus haut dans le fichier, et seules les déclarations remontent. */
function sansBlanc(sql) {
  return String(sql || '')
    .replace(/--[^\n]*/g, ' ')
    .replace(/\s+/g, ' ')
    /*
     * Les guillemets tombent, et c'est ce qui rend la comparaison juste :
     * « ALTER TABLE … RENAME TO » laisse en base « CREATE TABLE "departments" »,
     * là où le schéma écrit la table sans guillemets. Sans cela, la définition
     * paraîtrait toujours différente et la table serait reconstruite à chaque
     * démarrage — une reconstruction par boot, sur une table en service.
     */
    .replace(/["`]/g, '')
    .replace(/CREATE TABLE (?:IF NOT EXISTS )?/i, 'CREATE TABLE ')
    .trim();
}

function reconstruirePourCloisonner(table) {
  const voulue = definitionVoulue(table);
  if (!voulue) throw new Error(`Définition introuvable dans le schéma : ${table}`);

  if (surMysql) {
    /*
     * MySQL sait retirer un index, lui. Le nom de l'index créé par une
     * contrainte « UNIQUE » de colonne est celui de la colonne ; les deux
     * instructions sont donc tentées et leurs échecs ignorés — « l'index n'existe
     * pas » et « la clé existe déjà » veulent tous deux dire que le travail est
     * déjà fait.
     *
     * Ce qui décide — quelles colonnes, quels tuples — est lu par
     * « unicitesDeDefinition », à part et sous test ; seules les deux
     * instructions restent à MySQL.
     */
    const { colonnes: colonnesUniques, tuples, cle } = unicitesDeDefinition(voulue);

    /*
     * La clé primaire d'abord, et sans filet : c'est elle qui cloisonne les taux
     * de change, où le périmètre entre dans la clé. Une base MySQL en service
     * garde sinon « PRIMARY KEY (code) » et refuse à la seconde société le taux
     * d'une devise que la première a déjà figé. Un échec doit donc faire échouer
     * la migration, là où une unicité manquante peut être ignorée : elle sera
     * reposée par un index nommé du schéma.
     */
    if (cle) {
      const enPlace = db.prepare(`PRAGMA table_info(${table})`).all()
        .filter((colonne) => colonne.pk).map((colonne) => colonne.name);
      if (!cleIdentique(enPlace, cle)) {
        db.prepare(`ALTER TABLE ${table} DROP PRIMARY KEY, ADD PRIMARY KEY (${cle})`).run();
      }
    }

    for (const colonne of colonnesUniques) {
      try {
        db.prepare(`ALTER TABLE ${table} DROP INDEX ${colonne}`).run();
      } catch { /* l'index n'était pas là : rien à retirer */ }
    }
    for (const tuple of tuples) {
      try {
        db.prepare(`ALTER TABLE ${table} ADD UNIQUE (${tuple})`).run();
      } catch { /* la contrainte y était déjà */ }
    }
    return false;
  }

  const enBase = db.prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?").get(table);
  if (!enBase) return false;
  if (sansBlanc(enBase.sql) === sansBlanc(voulue)) return false;

  const colonnes = db.prepare(`PRAGMA table_info(${table})`).all().map((c) => c.name);
  const neuves = [...voulue.matchAll(/^\s{2}(\w+)\s+(?:INTEGER|TEXT|REAL|BLOB|NUMERIC)/gim)].map((m) => m[1]);
  const communes = neuves.filter((c) => colonnes.includes(c));

  /*
   * Les clés étrangères sont suspendues le temps de la bascule : pendant les
   * quelques instructions où l'ancienne table disparaît, les lignes qui la citent
   * pointeraient dans le vide. Elles sont revérifiées juste après, et une
   * incohérence ferait échouer la migration plutôt que de passer inaperçue.
   */
  db.pragma('foreign_keys = OFF');
  try {
    db.exec('BEGIN');
    db.exec(voulue.replace(`CREATE TABLE ${table} (`, `CREATE TABLE ${table}__neuve (`));
    db.exec(`INSERT INTO ${table}__neuve (${communes.join(', ')}) SELECT ${communes.join(', ')} FROM ${table}`);
    db.exec(`DROP TABLE ${table}`);
    db.exec(`ALTER TABLE ${table}__neuve RENAME TO ${table}`);
    db.exec('COMMIT');
  } catch (err) {
    try { db.exec('ROLLBACK'); } catch { /* rien à annuler */ }
    db.pragma('foreign_keys = ON');
    throw err;
  }

  // Les index nommés du schéma repartent : « DROP TABLE » les a emportés.
  for (const script of scripts) {
    for (const m of script.matchAll(new RegExp(`CREATE (?:UNIQUE )?INDEX IF NOT EXISTS [^;]*? ON ${table}\\s*\\([^;]*?\\);`, 'gi'))) {
      db.exec(m[0]);
    }
  }

  const casse = db.prepare('PRAGMA foreign_key_check').all();
  db.pragma('foreign_keys = ON');
  if (casse.length > 0) {
    throw new Error(`Reconstruction de ${table} : ${casse.length} référence(s) cassée(s).`);
  }
  console.log(`Cloisonnement : table ${table} reconstruite pour l'unicité par société.`);
  return true;
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
  /*
   * Et son périmètre, sans quoi l'instance n'aurait aucune société et chaque
   * écran métier refuserait de répondre. Requis ici plutôt que dans la migration
   * plus haut : celle-ci s'exécute avant que ce compte existe.
   */
  garantirPremiereSociete();
}

poser(`
CREATE INDEX IF NOT EXISTS idx_users_team ON users(team_id);
CREATE INDEX IF NOT EXISTS idx_users_department ON users(department_id);
`);

// ---------- Reprise de l'ancien modèle d'organisation ----------
// Avant, un salarié portait un service en texte libre et un unique manager.
// Services et équipes sont devenus des entités, et l'encadrement une relation
// (plusieurs managers possibles). Cette reprise ne s'exécute qu'une fois.
//
// Elle s'exécute hors périmètre, et c'est l'un des rares cas légitimes : elle
// date d'avant le cloisonnement, et il n'y a aucune société à nommer à l'instant
// où elle tourne. Les lignes qu'elle pose reçoivent leur société à la toute fin
// de ce fichier, par la reprise de périmètre.
//
// La suspension n'est pas strictement nécessaire aujourd'hui — le garde n'est
// posé sur « db.prepare » qu'à la fin de ce fichier, donc après toutes les
// migrations de démarrage —, et c'est bien pour cela qu'elle est écrite : elle
// dit l'intention plutôt que de dépendre d'un ordre de lignes, et elle tient
// encore si cette reprise est un jour rejouée depuis le produit. Voir
// src/cloison.js, « sansPerimetre ».
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

  require('./cloison').sansPerimetre(run);
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

/*
 * Le périmètre posé sur les lignes qui n'en ont pas encore.
 *
 * En dernier, et à chaque démarrage. En dernier, parce que les migrations de
 * données qui précèdent — la reprise de l'ancien modèle d'organisation, par
 * exemple — posent des lignes hors périmètre, faute de société à nommer à
 * l'instant où elles tournent ; les rattacher avant qu'elles existent ne les
 * rattacherait pas. À chaque démarrage, parce que le cloisonnement avance par
 * lots et que chaque lot ajoute la colonne à de nouvelles tables : une instance
 * déjà passée au lot précédent verrait ses lignes recevoir une colonne vide que
 * plus aucune requête ne lit — la mise à jour se lirait comme une perte de
 * données.
 *
 * La requête nomme le périmètre : elle passe donc le garde, et c'est bien le
 * seul endroit du produit qui a le droit de l'écrire en bloc.
 */
if (premiereSociete) {
  for (const table of require('./cloison').SOUS_GARDE) {
    const reprises = db.prepare(`UPDATE ${table} SET company_id = ? WHERE company_id IS NULL`)
      .run(premiereSociete.id).changes;
    if (reprises > 0) {
      console.log(`Cloisonnement : ${reprises} ligne(s) de ${table} rattachée(s) à la première société.`);
    }
  }
}

function deactivateExpiredContracts() {
  const today = new Date().toISOString().slice(0, 10);
  const condition = "role = 'employee' AND active = 1 AND contract_end_date IS NOT NULL AND contract_end_date != '' AND contract_end_date < ?";
  // On relève qui part avant de fermer : après la mise à jour, la requête ne
  // retrouverait plus personne, et le départ ne serait annoncé à personne.
  const closing = db.prepare(`SELECT id, first_name, last_name, email FROM users WHERE ${condition}`).all(today);
  const result = db.prepare(`UPDATE users SET active = 0 WHERE ${condition}`).run(today);

  if (closing.length > 0) {
    // Chargé ici et non en tête de fichier : webhooks.js dépend de ce module.
    const webhooks = require('./webhooks');
    for (const person of closing) {
      webhooks.emit('membre.parti', {
        id: person.id,
        prenom: person.first_name,
        nom: person.last_name,
        email: person.email,
        motif: 'fin_de_contrat',
      });
    }
  }
  return result.changes;
}

/*
 * Le garde du cloisonnement, posé sur la seule porte qui existe.
 *
 * « db.prepare » est l'unique passage de toutes les requêtes du produit : mille
 * cent soixante et un appels dans cette édition. Envelopper cette méthode une
 * fois vaut mieux que d'espérer que chaque appelant pense au périmètre — et
 * c'est la seule façon de couvrir aussi ce qui sera écrit demain.
 *
 * Le garde ne réécrit rien : il refuse. La raison est dans src/cloison.js.
 */
const cloison = require('./cloison');
const prepareSansGarde = db.prepare.bind(db);
db.prepare = (sql) => {
  cloison.verifier(sql);
  return prepareSansGarde(sql);
};

module.exports = db;
module.exports.deactivateExpiredContracts = deactivateExpiredContracts;
module.exports.garantirPremiereSociete = garantirPremiereSociete;
module.exports.reconstruirePourCloisonner = reconstruirePourCloisonner;
module.exports.definitionVoulue = definitionVoulue;
module.exports.unicitesDeDefinition = unicitesDeDefinition;
module.exports.cleIdentique = cleIdentique;
module.exports.A_RECONSTRUIRE = A_RECONSTRUIRE;
