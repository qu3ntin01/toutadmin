-- Schéma de Toutadmin — édition PHP.
--
-- Repris tel quel de l'édition Node : mêmes tables, mêmes colonnes, mêmes
-- contraintes. Une base passe de l'une à l'autre sans conversion, ce qui est
-- la seule façon honnête de proposer deux éditions du même produit.

CREATE TABLE accounts (
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

CREATE TABLE announcements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  scope TEXT NOT NULL CHECK(scope IN ('company','department','team')),
  scope_id INTEGER,
  title TEXT NOT NULL,
  body TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- Le cloisonnement par société. Voir app/Core/Cloison.php.
  company_id INTEGER
);

CREATE TABLE api_tokens (
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

CREATE TABLE app_services (
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

CREATE TABLE asset_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  asset_id INTEGER NOT NULL REFERENCES assets(id) ON DELETE CASCADE,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  assigned_at TEXT NOT NULL DEFAULT (date('now')),
  returned_at TEXT,
  note TEXT NOT NULL DEFAULT ''
);

CREATE TABLE assets (
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

CREATE TABLE assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  tool_id INTEGER NOT NULL REFERENCES tools(id) ON DELETE CASCADE,
  assigned_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  username TEXT NOT NULL DEFAULT '',
  UNIQUE(employee_id, tool_id)
);

CREATE TABLE audit_findings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  audit_id INTEGER NOT NULL REFERENCES internal_audits(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Remarque' CHECK(kind IN ('Non-conformité','Remarque','Point fort')),
  clause TEXT NOT NULL DEFAULT '',
  statement TEXT NOT NULL
);

CREATE TABLE audit_log (
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

CREATE TABLE bank_accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  bank TEXT NOT NULL DEFAULT '',
  iban_last4 TEXT NOT NULL DEFAULT '',
  opening_balance REAL NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE bank_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL REFERENCES bank_accounts(id) ON DELETE CASCADE,
  value_date TEXT NOT NULL,
  label TEXT NOT NULL,
  amount REAL NOT NULL,
  category TEXT NOT NULL DEFAULT '',
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  claim_id INTEGER REFERENCES expense_claims(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- L'empreinte du mouvement dans le relevé importé : réimporter ne double rien.
  import_ref TEXT
);

CREATE TABLE budgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  department_id INTEGER NOT NULL REFERENCES departments(id) ON DELETE CASCADE,
  year INTEGER NOT NULL,
  amount REAL NOT NULL DEFAULT 0,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(department_id, year)
);

CREATE TABLE calendar_events (
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

CREATE TABLE candidates (
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

CREATE TABLE cash_forecasts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  expected_on TEXT NOT NULL,
  amount REAL NOT NULL,
  certainty TEXT NOT NULL DEFAULT 'Probable',
  note TEXT NOT NULL DEFAULT ''
);

CREATE TABLE checklist_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  checklist_id INTEGER NOT NULL REFERENCES checklists(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  owner_role TEXT NOT NULL DEFAULT 'RH',
  due_date TEXT,
  done_at TEXT,
  done_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE checklist_template_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  template_id INTEGER NOT NULL REFERENCES checklist_templates(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  owner_role TEXT NOT NULL DEFAULT 'RH',
  offset_days INTEGER NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE checklist_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Arrivée',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE checklists (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  template_id INTEGER REFERENCES checklist_templates(id) ON DELETE SET NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Arrivée',
  reference_date TEXT NOT NULL,
  completed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE company_documents (
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

CREATE TABLE company_events (
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

CREATE TABLE corporate_mandates (
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

CREATE TABLE crm_activities (
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

CREATE TABLE crm_contacts (
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

CREATE TABLE cse_ballots (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  election_id INTEGER NOT NULL REFERENCES cse_elections(id) ON DELETE CASCADE,
  candidacy_id INTEGER NOT NULL REFERENCES cse_candidacies(id) ON DELETE CASCADE,
  cast_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE cse_benefits (
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

CREATE TABLE cse_candidacies (
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

CREATE TABLE cse_elections (
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

CREATE TABLE cse_mandates (
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

CREATE TABLE cse_meetings (
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

CREATE TABLE cse_voters (
  election_id INTEGER NOT NULL REFERENCES cse_elections(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  voted_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (election_id, user_id)
);

CREATE TABLE decisions (
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

CREATE TABLE departments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre. L'unicité du nom est par société, et non globale : deux
  -- entités d'un groupe ont chacune le droit d'avoir un service « Production ».
  company_id INTEGER,
  name TEXT NOT NULL COLLATE NOCASE,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, name)
);

CREATE TABLE document_acks (
  document_id INTEGER NOT NULL REFERENCES company_documents(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  acked_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (document_id, user_id)
);

CREATE TABLE dunning_notices (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  level INTEGER NOT NULL CHECK(level BETWEEN 1 AND 3),
  sent_on TEXT NOT NULL,
  note TEXT NOT NULL DEFAULT '',
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE enterprise_risks (
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

CREATE TABLE entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  journal_id INTEGER NOT NULL REFERENCES journals(id) ON DELETE RESTRICT,
  entry_date TEXT NOT NULL,
  reference TEXT NOT NULL DEFAULT '',
  label TEXT NOT NULL,
  invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- Numéro posé à la validation, continu et chronologique ; voir Accounting.
  number INTEGER,
  validated_at TEXT,
  -- L'écriture contrepassée : une écriture validée ne se supprime pas.
  reverses_id INTEGER
);

CREATE TABLE entry_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  entry_id INTEGER NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
  account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE RESTRICT,
  label TEXT NOT NULL DEFAULT '',
  debit REAL NOT NULL DEFAULT 0,
  credit REAL NOT NULL DEFAULT 0,
  reconciliation TEXT NOT NULL DEFAULT '',
  lettered_at TEXT
);

CREATE TABLE event_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_id INTEGER NOT NULL REFERENCES company_events(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'Inscrit' CHECK(status IN ('Inscrit','Liste d''attente','Annulée','Présent','Absent')),
  registered_at TEXT NOT NULL DEFAULT (datetime('now')),
  note TEXT NOT NULL DEFAULT '',
  UNIQUE(event_id, user_id)
);

CREATE TABLE exchange_rates (
  -- Le périmètre : une filiale à l'étranger ne tient pas ses comptes dans la
  -- devise de la maison mère, et n'a donc pas les mêmes taux à figer.
  company_id INTEGER,
  code TEXT NOT NULL,
  rate REAL NOT NULL,
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  PRIMARY KEY (company_id, code)
);

CREATE TABLE expense_claims (
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

CREATE TABLE fixed_assets (
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

CREATE TABLE general_meetings (
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

CREATE TABLE gift_records (
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

CREATE TABLE hr_requests (
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

CREATE TABLE incoming_documents (
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

CREATE TABLE interest_declarations (
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

CREATE TABLE internal_audits (
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

CREATE TABLE invoices (
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
, purchase_order_id INTEGER REFERENCES purchase_orders(id) ON DELETE SET NULL, currency TEXT NOT NULL DEFAULT 'EUR', exchange_rate REAL NOT NULL DEFAULT 1, subscription_id INTEGER);

CREATE TABLE it_incidents (
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

CREATE TABLE items (
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

CREATE TABLE job_openings (
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

CREATE TABLE journals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre : chaque entité tient ses propres journaux, et leurs codes se
  -- ressemblent d'une entité à l'autre — « VE », « AC », « BQ » partout.
  company_id INTEGER,
  code TEXT NOT NULL,
  label TEXT NOT NULL,
  UNIQUE(company_id, code)
);

CREATE TABLE kb_articles (
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

CREATE TABLE key_results (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  objective_id INTEGER NOT NULL REFERENCES objectives(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  start_value REAL NOT NULL DEFAULT 0,
  target_value REAL NOT NULL DEFAULT 100,
  current_value REAL NOT NULL DEFAULT 0,
  unit TEXT NOT NULL DEFAULT ''
);

CREATE TABLE leave_adjustments (
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
CREATE TABLE logs (
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

-- Les collecteurs vers lesquels le journal est recopié : un serveur syslog, un
-- gestionnaire Wazuh, un dépôt lu par un agent local.
--
-- Chaque cible garde son propre « filigrane » : le numéro de la dernière ligne
-- qu'elle a reçue. Une panne du collecteur ne perd rien — au rétablissement,
-- l'export reprend là où il s'était arrêté —, et deux collecteurs n'ont pas à
-- avancer du même pas. C'est aussi ce filigrane qui protège la purge : une
-- ligne qu'une cible active n'a pas encore vue n'est jamais supprimée.
-- Mises à jour appliquées : ce qui a été posé, depuis quelle version, comment,
-- et avec quelle sauvegarde prise avant. Une mise à jour qui a échoué reste ici
-- aussi : c'est en la relisant qu'on comprend pourquoi.
CREATE TABLE updates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  version TEXT NOT NULL,
  edition TEXT NOT NULL DEFAULT 'php',
  channel TEXT NOT NULL DEFAULT 'stable',
  mode TEXT NOT NULL DEFAULT 'manual',
  status TEXT NOT NULL DEFAULT 'running',
  detail TEXT NOT NULL DEFAULT '',
  backup_file TEXT NOT NULL DEFAULT '',
  from_version TEXT NOT NULL DEFAULT '',
  by_user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  started_at TEXT NOT NULL DEFAULT (datetime('now')),
  finished_at TEXT
);

CREATE TABLE log_targets (
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

CREATE TABLE mail_items (
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

CREATE TABLE medical_visits (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Visite périodique',
  scheduled_on TEXT,
  done_on TEXT,
  verdict TEXT NOT NULL DEFAULT '',
  next_due TEXT
);

CREATE TABLE meeting_actions (
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

CREATE TABLE meeting_attendees (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  attendance TEXT NOT NULL DEFAULT 'Attendu' CHECK(attendance IN ('Attendu','Présent','Excusé','Absent')),
  UNIQUE(meeting_id, user_id)
);

CREATE TABLE meeting_resolutions (
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

CREATE TABLE meetings (
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

-- ---------- Messagerie externe : ce que la relève a rapporté ----------
--
-- La boîte du membre reste la source : on n'y écrit jamais, on n'y efface
-- jamais, et on ne marque aucun message comme lu — il la lit aussi depuis son
-- propre logiciel. Ce qui est ici n'est qu'une copie de lecture.
--
-- L'unicité (membre, uid) est ce qui rend la relève rejouable : repasser sur
-- les mêmes messages n'en écrit pas un de plus.
CREATE TABLE external_messages (
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

CREATE INDEX idx_external_messages_user
  ON external_messages(user_id, sent_at DESC);

CREATE TABLE messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  sender_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  recipient_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  subject TEXT NOT NULL DEFAULT '',
  body TEXT NOT NULL DEFAULT '',
  parent_id INTEGER REFERENCES messages(id) ON DELETE SET NULL,
  read_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE nonconformities (
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

CREATE TABLE notifications (
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

CREATE TABLE objectives (
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

CREATE TABLE one_on_ones (
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

CREATE TABLE opening_criteria (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  opening_id INTEGER NOT NULL REFERENCES job_openings(id) ON DELETE CASCADE,
  label TEXT NOT NULL,
  keywords TEXT NOT NULL DEFAULT '',
  kind TEXT NOT NULL DEFAULT 'Souhaité' CHECK(kind IN ('Requis','Souhaité')),
  weight INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE opportunities (
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

CREATE TABLE org_managers (
  -- Le périmètre : « scope_id » désigne un service ou une équipe selon
  -- « scope », et aucune clé étrangère ne peut le dire — donc aucune cascade ne
  -- peut porter la société à sa place.
  company_id INTEGER,
  scope TEXT NOT NULL CHECK(scope IN ('department','team')),
  scope_id INTEGER NOT NULL,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (scope, scope_id, user_id)
);

CREATE TABLE partner_contacts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT '',
  email TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '',
  is_primary INTEGER NOT NULL DEFAULT 0,
  notes TEXT NOT NULL DEFAULT ''
);

CREATE TABLE partner_contracts (
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

CREATE TABLE partner_documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Attestation de vigilance' CHECK(kind IN ('Attestation de vigilance','Assurance','Kbis','Coordonnées bancaires','Certification','Autre')),
  reference TEXT NOT NULL DEFAULT '',
  issued_on TEXT,
  expires_on TEXT,
  notes TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE partner_reviews (
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

CREATE TABLE partners (
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

CREATE TABLE payroll_rates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  label TEXT NOT NULL,
  base TEXT NOT NULL DEFAULT 'Brut' CHECK(base IN ('Brut','Plafond')),
  employee_rate REAL NOT NULL DEFAULT 0,
  employer_rate REAL NOT NULL DEFAULT 0,
  sort_order INTEGER NOT NULL DEFAULT 0,
  active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE payslip_lines (
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

CREATE TABLE payslips (
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

CREATE TABLE power_delegations (
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

CREATE TABLE ppe_assignments (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ppe_id INTEGER NOT NULL REFERENCES ppe_items(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  issued_on TEXT NOT NULL,
  expires_on TEXT,
  returned_on TEXT
);

CREATE TABLE ppe_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  category TEXT NOT NULL DEFAULT 'Protection',
  validity_months INTEGER
);

CREATE TABLE processing_records (
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

CREATE TABLE project_members (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  role TEXT NOT NULL DEFAULT '',
  UNIQUE(project_id, user_id)
);

CREATE TABLE project_milestones (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  due_date TEXT,
  reached_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE project_tasks (
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

CREATE TABLE project_time (
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

CREATE TABLE projects (
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

CREATE TABLE purchase_order_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_id INTEGER NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
  item_id INTEGER REFERENCES items(id) ON DELETE SET NULL,
  label TEXT NOT NULL,
  quantity REAL NOT NULL DEFAULT 1,
  unit_price REAL NOT NULL DEFAULT 0,
  -- Cumul des réceptions : recalculé à chaque bon de réception, jamais saisi.
  received_quantity REAL NOT NULL DEFAULT 0
);

CREATE TABLE purchase_orders (
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

CREATE TABLE purchase_receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  line_id INTEGER NOT NULL REFERENCES purchase_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL,
  received_on TEXT NOT NULL,
  received_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE purchase_requests (
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

CREATE TABLE quality_actions (
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

CREATE TABLE quotes (
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

CREATE TABLE releases (
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

CREATE TABLE request_forms (
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

CREATE TABLE request_steps (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  form_id INTEGER NOT NULL REFERENCES request_forms(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 1,
  approver TEXT NOT NULL DEFAULT 'manager',
  approver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  label TEXT NOT NULL DEFAULT '',
  threshold REAL NOT NULL DEFAULT 0
);

CREATE TABLE reviews (
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

CREATE TABLE risk_assessments (
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

CREATE TABLE room_bookings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  room_id INTEGER NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title TEXT NOT NULL,
  booking_date TEXT NOT NULL,
  start_time TEXT NOT NULL,
  end_time TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE rooms (
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

CREATE TABLE sessions (
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

CREATE TABLE settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE share_movements (
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

CREATE TABLE shareholders (
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

CREATE TABLE shift_templates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  kind TEXT NOT NULL DEFAULT 'Poste',
  start_time TEXT NOT NULL DEFAULT '09:00',
  end_time TEXT NOT NULL DEFAULT '17:00',
  weekdays TEXT NOT NULL DEFAULT '1,2,3,4,5',
  location TEXT NOT NULL DEFAULT ''
);

CREATE TABLE shifts (
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

CREATE TABLE signature_requests (
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

CREATE TABLE signature_signers (
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

CREATE TABLE skills (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  category TEXT NOT NULL DEFAULT 'Générale',
  validity_months INTEGER,
  mandatory INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE software_accesses (
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

CREATE TABLE software_licences (
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

CREATE TABLE stock_movements (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  item_id INTEGER NOT NULL REFERENCES items(id) ON DELETE CASCADE,
  kind TEXT NOT NULL CHECK(kind IN ('Entrée','Sortie','Inventaire')),
  quantity REAL NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  moved_on TEXT NOT NULL DEFAULT (date('now')),
  created_by INTEGER REFERENCES users(id),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE subscriptions (
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

CREATE TABLE survey_answers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  question_id INTEGER NOT NULL REFERENCES survey_questions(id) ON DELETE CASCADE,
  value TEXT NOT NULL DEFAULT '',
  submitted_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE survey_participations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  survey_id INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  submitted_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(survey_id, user_id)
);

CREATE TABLE survey_questions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  survey_id INTEGER NOT NULL REFERENCES surveys(id) ON DELETE CASCADE,
  position INTEGER NOT NULL DEFAULT 0,
  label TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'echelle' CHECK(type IN ('echelle','oui_non','choix','texte')),
  choices TEXT NOT NULL DEFAULT '',
  required INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE surveys (
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

CREATE TABLE teams (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  -- Le périmètre, et non le service dont elle dépend : une équipe peut n'être
  -- rattachée à aucun service — l'organigramme les montre exprès — et n'aurait
  -- alors aucune société si elle tenait la sienne de son seul service.
  company_id INTEGER,
  name TEXT NOT NULL,
  department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, name, department_id)
);

CREATE TABLE ticket_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id INTEGER NOT NULL REFERENCES tickets(id) ON DELETE CASCADE,
  author_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body TEXT NOT NULL,
  internal INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE tickets (
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
  -- Le groupe qui a la charge du ticket. Posé à l'ouverture depuis la catégorie,
  -- et transférable à la main. Sans cette colonne un transfert serait
  -- impossible : la file serait toujours recalculée depuis la catégorie, et
  -- déplacer une demande obligerait à mentir sur ce dont elle parle.
  --
  -- Nul veut dire « aucun groupe désigné » : le ticket reste visible par les
  -- rôles, comme avant l'existence des groupes. C'est ce qui fait qu'une base
  -- déjà en service ne perd rien le jour de la mise à jour.
  group_id INTEGER,
  due_at TEXT,
  first_reply_at TEXT,
  closed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Les groupes de support.
--
-- Avant eux, qui traitait un ticket se déduisait du rôle : l'administration et
-- les RH voyaient tout, la gestion tout sauf les RH, un manager son périmètre.
-- C'était une politique écrite dans le code, donc la même pour toutes les
-- entreprises — et fausse pour la plupart : le remplacement d'un écran ne se
-- traite pas par la direction financière.
--
-- Un groupe porte un nom, des gens, et les catégories qu'il prend en charge.
-- L'administration le compose ; les rôles continuent de donner ce qu'ils
-- donnaient, et les groupes s'y ajoutent. Rien ne se retire par ce moyen : on
-- n'enlève pas aux RH l'accès aux demandes RH en oubliant de les mettre dans un
-- groupe.
CREATE TABLE support_groups (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  name TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE support_group_members (
  group_id INTEGER NOT NULL REFERENCES support_groups(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  PRIMARY KEY (group_id, user_id)
);

CREATE TABLE support_group_categories (
  group_id INTEGER NOT NULL REFERENCES support_groups(id) ON DELETE CASCADE,
  category TEXT NOT NULL,
  PRIMARY KEY (group_id, category)
);

CREATE TABLE time_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  clock_in TEXT NOT NULL,
  clock_out TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE tools (
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

-- Les clés de sécurité et clés d'accès (WebAuthn) d'une personne.
CREATE TABLE webauthn_credentials (
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

-- Les demandes de connexion SAML parties d'ici : une réponse n'est reçue que
-- si elle répond à l'une d'elles, et chacune ne sert qu'une fois.
CREATE TABLE saml_requests (
  request_id TEXT PRIMARY KEY,
  created_at INTEGER NOT NULL
);

CREATE TABLE totp_recovery_codes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash TEXT NOT NULL,
  used_at TEXT
);

CREATE TABLE training_registrations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id INTEGER NOT NULL REFERENCES training_sessions(id) ON DELETE CASCADE,
  employee_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  status TEXT NOT NULL DEFAULT 'Demandée' CHECK(status IN ('Demandée','Inscrite','Refusée','Terminée','Annulée')),
  reviewed_by INTEGER REFERENCES users(id),
  reviewed_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(session_id, employee_id)
);

CREATE TABLE training_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  training_id INTEGER NOT NULL REFERENCES trainings(id) ON DELETE CASCADE,
  start_date TEXT NOT NULL,
  end_date TEXT,
  location TEXT NOT NULL DEFAULT '',
  seats INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'Planifiée' CHECK(status IN ('Planifiée','Confirmée','Terminée','Annulée')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE trainings (
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

CREATE TABLE user_skills (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  skill_id INTEGER NOT NULL REFERENCES skills(id) ON DELETE CASCADE,
  level INTEGER NOT NULL DEFAULT 1,
  obtained_on TEXT,
  expires_on TEXT,
  reference TEXT NOT NULL DEFAULT '',
  UNIQUE(user_id, skill_id)
);

CREATE TABLE users (
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

CREATE TABLE vat_returns (
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

CREATE TABLE vault_access_grants (
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

CREATE TABLE vault_documents (
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

CREATE TABLE vehicle_events (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
  kind TEXT NOT NULL DEFAULT 'Entretien',
  occurred_on TEXT NOT NULL,
  mileage INTEGER,
  cost REAL,
  note TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE vehicles (
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

CREATE TABLE visitors (
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

CREATE TABLE webhook_deliveries (
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

CREATE TABLE webhooks (
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

CREATE TABLE whistleblow_access_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES whistleblow_reports(id) ON DELETE CASCADE,
  user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  occurred_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE whistleblow_messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id INTEGER NOT NULL REFERENCES whistleblow_reports(id) ON DELETE CASCADE,
  author_kind TEXT NOT NULL CHECK(author_kind IN ('auteur','referent')),
  referent_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body_enc TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE whistleblow_reports (
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

CREATE TABLE workflow_decisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  request_id INTEGER NOT NULL REFERENCES workflow_requests(id) ON DELETE CASCADE,
  step_id INTEGER REFERENCES request_steps(id) ON DELETE SET NULL,
  position INTEGER NOT NULL DEFAULT 1,
  approver_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
  decision TEXT NOT NULL CHECK(decision IN ('Approuvée','Refusée')),
  note TEXT NOT NULL DEFAULT '',
  decided_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE workflow_requests (
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

CREATE TABLE workplace_incidents (
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

CREATE INDEX idx_api_tokens_hash ON api_tokens(token_hash);

CREATE INDEX idx_asset_assignments_asset ON asset_assignments(asset_id);

CREATE INDEX idx_audit_action ON audit_log(action);

CREATE INDEX idx_audit_actor ON audit_log(actor_id);

CREATE INDEX idx_audit_date ON audit_log(occurred_at DESC);

CREATE INDEX idx_bank_transactions_account ON bank_transactions(account_id, value_date);

CREATE INDEX idx_calendar_events_user ON calendar_events(user_id, start_date);

CREATE INDEX idx_candidates_opening ON candidates(opening_id);

CREATE INDEX idx_checklist_items_checklist ON checklist_items(checklist_id);

CREATE INDEX idx_company_events_start ON company_events(starts_at, status);

CREATE INDEX idx_cse_ballots_election ON cse_ballots(election_id);

CREATE INDEX idx_cse_candidacies_election ON cse_candidacies(election_id);

CREATE INDEX idx_decisions_meeting ON decisions(meeting_id);

CREATE INDEX idx_dunning_invoice ON dunning_notices(invoice_id, level);

CREATE INDEX idx_entry_lines_account ON entry_lines(account_id);

CREATE INDEX idx_entry_lines_entry ON entry_lines(entry_id);

CREATE INDEX idx_event_registrations_event ON event_registrations(event_id, status);

CREATE INDEX idx_event_registrations_user ON event_registrations(user_id, status);

CREATE INDEX idx_expense_claims_employee ON expense_claims(employee_id);

CREATE INDEX idx_gift_records_user ON gift_records(user_id, occurred_on);

CREATE INDEX idx_hr_requests_employee ON hr_requests(employee_id);


CREATE INDEX idx_incoming_documents_status ON incoming_documents(status, received_at);

CREATE INDEX idx_interest_declarations_user ON interest_declarations(user_id, status);

CREATE INDEX idx_invoices_partner ON invoices(partner_id);

CREATE UNIQUE INDEX idx_invoices_subscription_period ON invoices(subscription_id, issue_date) WHERE subscription_id IS NOT NULL;

CREATE INDEX idx_it_incidents_state ON it_incidents(status, started_at);

CREATE INDEX idx_logs_channel ON logs(channel, id DESC);

CREATE INDEX idx_logs_level ON logs(level, id DESC);

CREATE INDEX idx_logs_recent ON logs(id DESC);

CREATE INDEX idx_mail_status ON mail_items(status, logged_on);

CREATE INDEX idx_meeting_actions_assignee ON meeting_actions(assignee_id, status);

CREATE INDEX idx_meeting_attendees_meeting ON meeting_attendees(meeting_id);

CREATE INDEX idx_meeting_resolutions_meeting ON meeting_resolutions(meeting_id, position);

CREATE INDEX idx_messages_recipient ON messages(recipient_id);

CREATE INDEX idx_messages_sender ON messages(sender_id);

CREATE UNIQUE INDEX idx_notifications_dedupe ON notifications(user_id, dedupe_key) WHERE dedupe_key != '';

CREATE INDEX idx_notifications_user ON notifications(user_id, read_at);

CREATE INDEX idx_one_on_ones_employee ON one_on_ones(employee_id, scheduled_on);

CREATE INDEX idx_one_on_ones_pair ON one_on_ones(manager_id, employee_id, scheduled_on);

CREATE INDEX idx_opening_criteria_opening ON opening_criteria(opening_id);

CREATE INDEX idx_opportunities_partner ON opportunities(partner_id);

CREATE INDEX idx_org_managers_user ON org_managers(user_id);

CREATE INDEX idx_partner_contacts_partner ON partner_contacts(partner_id);

CREATE INDEX idx_partner_documents_partner ON partner_documents(partner_id, expires_on);

CREATE INDEX idx_partner_reviews_partner ON partner_reviews(partner_id, reviewed_on);

CREATE INDEX idx_payslip_lines_payslip ON payslip_lines(payslip_id);

CREATE INDEX idx_payslips_employee ON payslips(employee_id);

CREATE INDEX idx_power_delegations_holder ON power_delegations(holder_id, status);

CREATE INDEX idx_project_tasks_assignee ON project_tasks(assignee_id, status);

CREATE INDEX idx_project_tasks_project ON project_tasks(project_id, status);

CREATE INDEX idx_project_time_project ON project_time(project_id, spent_on);

CREATE INDEX idx_project_time_user ON project_time(user_id, spent_on);

CREATE INDEX idx_purchase_order_lines_order ON purchase_order_lines(order_id);

CREATE INDEX idx_purchase_orders_partner ON purchase_orders(partner_id, status);

CREATE INDEX idx_purchase_receipts_line ON purchase_receipts(line_id, received_on);

CREATE INDEX idx_quality_actions_owner ON quality_actions(owner_id, status);

CREATE INDEX idx_recovery_user ON totp_recovery_codes(user_id);

CREATE INDEX idx_releases_env ON releases(environment, status, released_on);

CREATE INDEX idx_releases_service ON releases(service_id, released_on);

CREATE INDEX idx_request_steps_form ON request_steps(form_id, position);

CREATE INDEX idx_reviews_employee ON reviews(employee_id);

CREATE INDEX idx_room_bookings_date ON room_bookings(booking_date);

CREATE INDEX idx_sessions_expiry ON sessions(expires_at);

CREATE INDEX idx_sessions_user ON sessions(user_id);

CREATE INDEX idx_share_movements_holder ON share_movements(shareholder_id, moved_on);

CREATE INDEX idx_shifts_user ON shifts(user_id, starts_at);

CREATE INDEX idx_shifts_window ON shifts(starts_at, ends_at);

CREATE INDEX idx_signature_signers_request ON signature_signers(request_id, position);

CREATE INDEX idx_signature_signers_user ON signature_signers(user_id, status);

CREATE INDEX idx_software_accesses_licence ON software_accesses(licence_id, revoked_on);

CREATE UNIQUE INDEX idx_software_accesses_open ON software_accesses(licence_id, user_id) WHERE revoked_on IS NULL;

CREATE INDEX idx_software_accesses_user ON software_accesses(user_id, revoked_on);

CREATE INDEX idx_stock_movements_item ON stock_movements(item_id);

CREATE INDEX idx_subscriptions_next ON subscriptions(active, next_issue);

CREATE INDEX idx_survey_answers_question ON survey_answers(question_id);

CREATE INDEX idx_ticket_messages_ticket ON ticket_messages(ticket_id);

CREATE INDEX idx_tickets_status ON tickets(status, priority);
-- Les membres d'un groupe se cherchent par la personne : « quels groupes Claire
-- traite-t-elle ? » est posé à chaque ouverture d'écran.
--
-- Pas d'index sur « support_group_categories » : la table compte quelques
-- dizaines de lignes, et surtout le traducteur MySQL donne une longueur à toute
-- colonne de texte indexée — par son nom, pour tout le schéma. Indexer
-- « category » ici ferait basculer « tickets.category » en VARCHAR sur les
-- nouvelles bases MySQL et pas sur les anciennes.
CREATE INDEX idx_support_group_members_user ON support_group_members(user_id);

CREATE INDEX idx_time_entries_employee ON time_entries(employee_id);

CREATE INDEX idx_training_registrations_employee ON training_registrations(employee_id);

CREATE INDEX idx_user_skills_user ON user_skills(user_id);

CREATE INDEX idx_users_department ON users(department_id);

CREATE INDEX idx_users_team ON users(team_id);

CREATE INDEX idx_vault_documents_user ON vault_documents(user_id, removed_at);

CREATE INDEX idx_vault_grants_user ON vault_access_grants(user_id);

CREATE INDEX idx_vehicle_events_vehicle ON vehicle_events(vehicle_id, occurred_on);

CREATE INDEX idx_visitors_day ON visitors(visited_on);

CREATE INDEX idx_webhook_deliveries_state ON webhook_deliveries(status, next_try_at);

CREATE INDEX idx_whistleblow_messages_report ON whistleblow_messages(report_id, created_at);

CREATE INDEX idx_whistleblow_status ON whistleblow_reports(status, submitted_at);

CREATE INDEX idx_workflow_requests_requester ON workflow_requests(requester_id, status);

CREATE INDEX idx_workflow_requests_state ON workflow_requests(status, form_id);

-- ── Les champs personnalisés ────────────────────────────────────────────
--
-- Deux tables plutôt qu'une colonne par champ : une entreprise qui ajoute
-- trois champs à la fiche salarié ne doit pas faire muter le schéma de la
-- table « users ». Une migration de schéma au clic est le genre de chose qui
-- marche cent fois puis casse une base en service, un mardi, sans témoin.
CREATE TABLE custom_fields (
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


CREATE INDEX idx_custom_fields_objet ON custom_fields(objet, actif, rang);

CREATE TABLE custom_values (
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

CREATE INDEX idx_custom_values_record ON custom_values(record_id);

-- La corbeille : ce qu'une suppression emporte, mis de côté avant de partir.
--
-- « charge » tient le JSON de la ligne supprimée, de ses enfants en cascade,
-- des liens que la suppression a mis à nul, et de ses champs personnalisés. Ce
-- n'est pas une copie de confort : c'est tout ce qu'il faut pour remettre
-- l'enregistrement là où il était. L'écran n'affiche jamais cette charge, mais
-- « libelle », posé au moment de jeter — relire la charge pour l'afficher
-- rendrait visible, dans une liste d'administration, ce que la fiche elle-même
-- réservait à certains.
CREATE TABLE trash (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  objet TEXT NOT NULL,
  record_id INTEGER NOT NULL,
  libelle TEXT NOT NULL DEFAULT '',
  charge TEXT NOT NULL,
  supprime_par INTEGER REFERENCES users(id) ON DELETE SET NULL,
  supprime_le TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX idx_trash_objet ON trash(objet, supprime_le);

CREATE INDEX idx_trash_date ON trash(supprime_le);

-- Les sociétés : le périmètre de tout ce qui est métier.
--
-- Une instance en porte au moins une — celle créée à l'installation, ou posée
-- par la migration pour une instance qui tournait déjà. Le reste du produit n'a
-- pas à savoir s'il y en a une ou douze : il demande le périmètre courant et
-- filtre avec.
--
-- L'appartenance vit dans sa propre table, et non dans une colonne de « users » :
-- une personne peut relever de plusieurs entités d'un groupe — la direction en
-- relève souvent de toutes — et une colonne unique aurait obligé à dupliquer le
-- compte, donc le mot de passe, donc les sessions.
CREATE TABLE companies (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  legal_form TEXT NOT NULL DEFAULT '',
  registration TEXT NOT NULL DEFAULT '',
  address TEXT NOT NULL DEFAULT '',
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  -- Jusqu'où la comptabilité de la société est validée et verrouillée.
  locked_until TEXT
);

CREATE UNIQUE INDEX idx_companies_name ON companies(name);

-- Les mandats de prélèvement SEPA ; la RUM est unique par société.
CREATE TABLE sepa_mandates (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  partner_id INTEGER NOT NULL REFERENCES partners(id) ON DELETE CASCADE,
  rum TEXT NOT NULL,
  signed_on TEXT NOT NULL,
  iban TEXT NOT NULL,
  bic TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'Actif' CHECK(status IN ('Actif','Révoqué')),
  first_used_on TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, rum)
);

-- Ventes : le catalogue, la commande client, ses livraisons, ce que ses
-- factures reprennent. Livré et facturé se calculent sur ces pièces.
CREATE TABLE products (
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

CREATE TABLE sales_orders (
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

CREATE TABLE sales_order_lines (
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

CREATE TABLE delivery_notes (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  company_id INTEGER,
  order_id INTEGER NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
  reference TEXT NOT NULL,
  delivered_on TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(company_id, reference)
);

CREATE TABLE delivery_note_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  note_id INTEGER NOT NULL REFERENCES delivery_notes(id) ON DELETE CASCADE,
  order_line_id INTEGER NOT NULL REFERENCES sales_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL
);

-- Les pages de paiement Stripe demandées pour une facture client : seule une
-- session créée ici fait foi quand Stripe annonce un paiement. L'état (open,
-- paid ou orphan) est interne, jamais affiché, et seul Stripe.php l'écrit.
CREATE TABLE stripe_sessions (
  session_id TEXT PRIMARY KEY,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  amount_cents INTEGER NOT NULL,
  currency TEXT NOT NULL,
  url TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'open',
  created_at TEXT NOT NULL,
  paid_at TEXT
);

CREATE TABLE sales_invoice_lines (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  invoice_id INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
  order_line_id INTEGER NOT NULL REFERENCES sales_order_lines(id) ON DELETE CASCADE,
  quantity REAL NOT NULL
);

CREATE TABLE company_members (
  company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  PRIMARY KEY (company_id, user_id)
);

CREATE INDEX idx_company_members_user ON company_members(user_id);
