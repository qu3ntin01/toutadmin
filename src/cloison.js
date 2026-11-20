const CHEMIN = 'src/cloison.js';

/**
 * Le cloisonnement par société : une seule porte, et elle est fermée par défaut.
 *
 * Ce fichier ne lit rien et n'écrit rien. Il ne porte que deux choses : le
 * classement de chaque table du schéma, et le garde qui s'appuie dessus. Les
 * deux sont du texte, relisibles d'un bout à l'autre — c'est voulu : un
 * cloisonnement qu'on ne peut pas relire est un cloisonnement qu'on ne peut pas
 * croire.
 *
 * ── Pourquoi un garde, et non une réécriture automatique ──────────────────
 *
 * On pourrait intercepter chaque requête et y glisser « AND company_id = ? »
 * tout seul. C'est tentant, et c'est un piège : il faudrait analyser du SQL —
 * sous-requêtes, unions, jointures, agrégats — et une analyse qui se trompe
 * une fois sur mille produit une fuite silencieuse entre deux sociétés. Le
 * remède serait pire que le mal.
 *
 * Le garde ci-dessous ne réécrit rien. Il vérifie qu'une requête touchant une
 * table sous garde **parle** du périmètre, et refuse bruyamment sinon. Il ne
 * peut pas dire si le filtre est juste — c'est le travail des tests
 * d'étanchéité, un par table, qui posent deux sociétés et vérifient que l'une
 * ne voit pas les lignes de l'autre. Mais il rend l'oubli impossible, et c'est
 * l'oubli qui fuit, pas l'erreur : une erreur de filtre se voit à l'écran, un
 * filtre absent ne se voit jamais.
 *
 * ── Les trois familles ───────────────────────────────────────────────────
 *
 * CLOISONNEES   la table porte « company_id ». Toute requête qui la touche
 *               doit nommer le périmètre.
 * RATTACHEES    la table ne porte pas de « company_id » : ses lignes
 *               appartiennent à une ligne d'une autre table, et on ne les
 *               atteint que par elle. L'identifiant du parent fait office de
 *               droit d'accès — il a été obtenu dans le périmètre.
 * PARTAGEES     la table appartient à l'instance ou à une personne, pas à une
 *               société. Chacune porte la raison pour laquelle elle en est.
 *
 * Un test confronte ces trois listes au schéma : toute table ajoutée demain
 * doit être classée, sinon il échoue. C'est ce qui empêche le cloisonnement de
 * se périmer sans bruit.
 *
 * ── SOUS_GARDE : l'avancement, et pourquoi il est explicite ───────────────
 *
 * Cloisonner cent cinquante-deux tables d'un seul coup voudrait dire réécrire
 * des centaines de requêtes avant de pouvoir lancer un seul test. Le chantier
 * avance donc table par table : une table n'entre sous garde que lorsque ses
 * requêtes ont été reprises et que son test d'étanchéité passe. Ce qui n'est
 * pas encore sous garde se comporte comme avant — l'instance reste cohérente à
 * chaque étape, et l'avancement se compte.
 */

/** Les tables qui portent « company_id ». */
const CLOISONNEES = [
  'accounts',
  'announcements',
  'api_tokens',
  'app_services',
  'assets',
  'assignments',
  'bank_accounts',
  'budgets',
  'cash_forecasts',
  'checklist_templates',
  'checklists',
  'company_documents',
  'company_events',
  'corporate_mandates',
  'cse_benefits',
  'cse_elections',
  'cse_mandates',
  'cse_meetings',
  'custom_fields',
  'decisions',
  'departments',
  'enterprise_risks',
  'entries',
  'exchange_rates',
  'expense_claims',
  'fixed_assets',
  'general_meetings',
  'gift_records',
  'hr_requests',
  'incoming_documents',
  'interest_declarations',
  'internal_audits',
  'invoices',
  'items',
  'job_openings',
  'it_incidents',
  'journals',
  'kb_articles',
  'leave_adjustments',
  'mail_items',
  'meeting_actions',
  'medical_visits',
  'meetings',
  'nonconformities',
  'objectives',
  'one_on_ones',
  'opportunities',
  'org_managers',
  'partners',
  'payroll_rates',
  'payslips',
  'power_delegations',
  'ppe_items',
  'processing_records',
  'projects',
  'purchase_orders',
  'purchase_requests',
  'quality_actions',
  'quotes',
  'request_forms',
  'reviews',
  'risk_assessments',
  'rooms',
  'sepa_mandates',
  'products',
  'sales_orders',
  'delivery_notes',
  'shareholders',
  'shift_templates',
  'shifts',
  'signature_requests',
  'software_licences',
  'subscriptions',
  'support_groups',
  'surveys',
  'tickets',
  'time_entries',
  'teams',
  'tools',
  'trainings',
  'trash',
  'vat_returns',
  'vehicles',
  'visitors',
  'webhooks',
  'whistleblow_reports',
  'workplace_incidents',
];

/**
 * Les tables dont les lignes appartiennent à une ligne d'une autre table.
 *
 * On ne les atteint qu'en nommant le parent, dont l'identifiant a été obtenu
 * dans le périmètre : c'est lui qui fait droit d'accès. Leur étanchéité se
 * vérifie donc par celle de leur parent, et c'est ce que font les tests.
 */
const RATTACHEES = {
  asset_assignments: 'assets',
  audit_findings: 'internal_audits',
  bank_transactions: 'bank_accounts',
  candidates: 'job_openings',
  checklist_items: 'checklists',
  checklist_template_items: 'checklist_templates',
  crm_activities: 'partners',
  crm_contacts: 'partners',
  cse_ballots: 'cse_elections',
  cse_candidacies: 'cse_elections',
  cse_voters: 'cse_elections',
  custom_values: 'custom_fields',
  document_acks: 'company_documents',
  dunning_notices: 'invoices',
  delivery_note_lines: 'delivery_notes',
  sales_invoice_lines: 'invoices',
  stripe_sessions: 'invoices',
  sales_order_lines: 'sales_orders',
  entry_lines: 'entries',
  event_registrations: 'company_events',
  key_results: 'objectives',
  meeting_attendees: 'meetings',
  meeting_resolutions: 'general_meetings',
  opening_criteria: 'job_openings',
  partner_contacts: 'partners',
  partner_contracts: 'partners',
  partner_documents: 'partners',
  partner_reviews: 'partners',
  payslip_lines: 'payslips',
  ppe_assignments: 'ppe_items',
  project_members: 'projects',
  project_milestones: 'projects',
  project_tasks: 'projects',
  project_time: 'projects',
  purchase_order_lines: 'purchase_orders',
  purchase_receipts: 'purchase_order_lines',
  releases: 'app_services',
  request_steps: 'request_forms',
  room_bookings: 'rooms',
  share_movements: 'shareholders',
  signature_signers: 'signature_requests',
  software_accesses: 'software_licences',
  stock_movements: 'items',
  support_group_categories: 'support_groups',
  support_group_members: 'support_groups',
  survey_answers: 'survey_questions',
  survey_participations: 'surveys',
  survey_questions: 'surveys',
  ticket_messages: 'tickets',
  training_registrations: 'training_sessions',
  training_sessions: 'trainings',
  vehicle_events: 'vehicles',
  webhook_deliveries: 'webhooks',
  whistleblow_access_log: 'whistleblow_reports',
  whistleblow_messages: 'whistleblow_reports',
  workflow_decisions: 'workflow_requests',
  workflow_requests: 'request_forms',
};

/**
 * Les tables qui n'appartiennent pas à une société, et pourquoi.
 *
 * La raison est écrite à côté de chacune : c'est la seule façon de relire cette
 * liste dans six mois sans avoir à redevenir celui qui l'a écrite.
 */
const PARTAGEES = {
  audit_log: "le journal scellé de l'instance ; la société concernée est dans le détail de chaque entrée",
  calendar_events: "l'agenda personnel de chacun, qui ne change pas quand on change de société",
  external_messages: "la boîte professionnelle d'une personne",
  log_targets: "les cibles d'export du centre de journaux",
  logs: "le centre de journaux de l'instance",
  messages: "la messagerie interne : elle relie des personnes, pas des sociétés",
  notifications: "la file personnelle de chacun",
  sessions: "la session appartient à la personne, pas à la société qu'elle regarde",
  settings: "les réglages de l'instance : langue, palette, modules",
  skills: "le référentiel de compétences, commun au groupe : une compétence est la même partout",
  totp_recovery_codes: "les codes de secours d'une personne",
  webauthn_credentials: "les clés de sécurité d'une personne",
  saml_requests: "les demandes de connexion unique en attente : elles précèdent toute session, donc toute société",
  updates: "les mises à jour de l'instance",
  user_skills: "les compétences d'une personne",
  users: "une personne est une personne : un seul compte pour tout le groupe, et l'appartenance dit à quelles sociétés il donne accès",
  vault_access_grants: "les accès au coffre-fort d'une personne",
  vault_documents: "le coffre-fort d'une personne, qui lui survit même à son départ",
};

/**
 * Les tables dont les requêtes sont déjà reprises, et que le garde surveille.
 *
 * Elle s'allonge lot par lot. Une table n'y entre que lorsque ses requêtes
 * nomment le périmètre et que son test d'étanchéité passe — jamais avant, parce
 * qu'une table sous garde dont les requêtes ne sont pas reprises ne fuit pas :
 * elle tombe, et tout l'écran avec.
 */
const SOUS_GARDE = [
  'announcements',
  'departments',
  'teams',
  'org_managers',
  'partners',
  'invoices',
  'accounts',
  'journals',
  'entries',
  'projects',
  'tickets',
  'support_groups',
  'hr_requests',
  'payslips',
  'payroll_rates',
  'leave_adjustments',
  'time_entries',
  'medical_visits',
  'trainings',
  'job_openings',
  'reviews',
  'one_on_ones',
  'checklists',
  'checklist_templates',
  'expense_claims',
  'items',
  'assets',
  'tools',
  'assignments',
  'purchase_orders',
  'purchase_requests',
  'subscriptions',
  'quotes',
  'opportunities',
  'sepa_mandates',
  'products',
  'sales_orders',
  'delivery_notes',
  'budgets',
  'bank_accounts',
  'cash_forecasts',
  'fixed_assets',
  'vat_returns',
  'exchange_rates',
  // Lot 7 : le comité social, la vie sociale et le dispositif d'alerte.
  'cse_mandates',
  'cse_elections',
  'cse_meetings',
  'cse_benefits',
  'meetings',
  'whistleblow_reports',
  'shareholders',
  'corporate_mandates',
  'general_meetings',
  'interest_declarations',
  'gift_records',
  'power_delegations',
  'decisions',
  'meeting_actions',
  // Lot 8 : la qualité, la sécurité au travail et le registre des traitements.
  'nonconformities',
  'quality_actions',
  'internal_audits',
  'risk_assessments',
  'enterprise_risks',
  'workplace_incidents',
  'ppe_items',
  'processing_records',
  // Lot 9 : l'informatique — parc logiciel, services applicatifs, connaissances,
  // webhooks et parapheur.
  'app_services',
  'it_incidents',
  'software_licences',
  'kb_articles',
  'webhooks',
  'signature_requests',
  // Lot 10 : les moyens généraux — salles, flotte, accueil, documents et
  // événements d'entreprise, pièces reçues.
  'rooms',
  'vehicles',
  'visitors',
  'mail_items',
  'company_documents',
  'company_events',
  'incoming_documents',
  // Lot 11 : le reste — objectifs, enquêtes, plannings, formulaires de demande,
  // corbeille et champs personnalisés.
  'objectives',
  'surveys',
  'shifts',
  'shift_templates',
  'request_forms',
  'trash',
  'custom_fields',
  'api_tokens',
];

/** Les deux tables du cloisonnement lui-même : elles ne se cloisonnent pas. */
const SOCLE = ['companies', 'company_members'];

/*
 * Ce qu'une requête de schéma ou de service a le droit de faire sans périmètre.
 *
 * Créer une table, ajouter une colonne, compter les lignes d'une migration :
 * ces requêtes-là ne servent personne en particulier, et exiger d'elles un
 * périmètre n'aurait pas de sens. La liste est fermée, et le mot doit être le
 * premier de la requête — « DELETE FROM rooms WHERE … » ne passe pas parce
 * qu'il contient « from ».
 */
const HORS_PERIMETRE = /^\s*(?:create|alter|drop|pragma|begin|commit|rollback|vacuum|analyze|attach|detach|reindex|explain|show|set|describe)\b/i;

/**
 * La table que la requête mène, et pourquoi c'est la seule qui compte.
 *
 * Une première version regardait toutes les tables nommées, jointures comprises.
 * C'était trop large, et le trop large est aussi nuisible que le trop étroit : il
 * refusait cent cinquante requêtes qui ne fuyaient rien.
 *
 * Une jointure ne fait que décorer : « LEFT JOIN departments d ON d.id =
 * u.department_id » sert à écrire le nom du service à côté de la personne, et
 * c'est la table de tête — ici « users » — qui décide des lignes rendues. Même
 * chose d'une sous-requête dans la liste des colonnes : elle cherche un libellé
 * pour une ligne déjà choisie. Exiger un périmètre de ces requêtes-là aurait fait
 * ajouter un filtre sans effet à quinze modules, et aurait surtout appris au
 * lecteur que le garde se trompe — après quoi on le contourne.
 *
 * Ce qui fuit, c'est la table de tête : celle du FROM de premier niveau, du
 * UPDATE, du INSERT INTO, du DELETE FROM. C'est elle que le garde surveille, et
 * l'étanchéité des jointures se vérifie par les tests, table par table.
 *
 * Les deux parts d'une union mènent chacune la leur : les deux sont rendues.
 */
function tablesDe(sql) {
  const sans = String(sql)
    .replace(/--[^\n]*/g, ' ')
    .replace(/\/\*[\s\S]*?\*\//g, ' ');

  const trouvees = new Set();
  let profondeur = 0;
  let attend = null;
  let mot = '';

  const motFini = () => {
    const bas = mot.toLowerCase();
    mot = '';
    if (attend) {
      // Le mot qui suit le mot-clé : la table de tête, si c'en est un nom.
      if (/^[a-z_][a-z0-9_]*$/.test(bas) && bas !== 'select') trouvees.add(bas);
      attend = null;
      return;
    }
    if (profondeur > 0) return;
    if (bas === 'from' || bas === 'update' || bas === 'into') attend = bas;
    // Une union repart à zéro : la part suivante mène sa propre table.
    if (bas === 'union') attend = null;
  };

  for (const caractere of sans) {
    if (caractere === '(') { motFini(); profondeur += 1; continue; }
    if (caractere === ')') { motFini(); profondeur -= 1; continue; }
    if (/[\s,;`"']/.test(caractere)) { motFini(); continue; }
    mot += caractere;
  }
  motFini();

  return [...trouvees];
}

/*
 * Le cache : la même requête littérale revient des milliers de fois, et le
 * verdict ne dépend que de son texte. Sans lui, le garde se paierait à chaque
 * appel ; avec lui, il se paie une fois par requête distincte du produit.
 */
const verdicts = new Map();

/**
 * Le garde. Lève si une requête touche une table sous garde sans nommer le
 * périmètre ; ne rend rien quand tout va bien.
 *
 * Le message nomme la table et la requête : un refus qu'on ne sait pas situer
 * fait perdre plus de temps qu'il n'en fait gagner.
 */
function verifier(sql) {
  // Avant le cache : un refus déjà mémorisé ne doit pas échapper à une
  // suspension nommée, sinon l'ordre des appels déciderait du verdict.
  if (suspendu > 0) return;

  const texte = String(sql);
  if (verdicts.has(texte)) {
    const garde = verdicts.get(texte);
    if (garde) throw new Error(garde);
    return;
  }

  let faute = null;
  if (!HORS_PERIMETRE.test(texte)) {
    const concernees = tablesDe(texte).filter((t) => SOUS_GARDE.includes(t));
    if (concernees.length > 0 && !/\bcompany_id\b/i.test(texte)) {
      faute = `Cloisonnement : la requête touche ${concernees.join(', ')} sans nommer le périmètre `
        + `(« company_id »). Ajoutez le filtre, ou retirez la table de SOUS_GARDE dans ${CHEMIN}.\n`
        + `  ${texte.trim().replace(/\s+/g, ' ').slice(0, 200)}`;
    }
  }
  verdicts.set(texte, faute);
  if (faute) throw new Error(faute);
}

/*
 * La seule porte de sortie, et elle est nommée.
 *
 * Les migrations de schéma ont un problème d'œuf et de poule : celles qui
 * datent d'avant le cloisonnement touchent des tables qui n'avaient pas encore
 * de périmètre, et il n'y en a aucun à leur donner. Plutôt que de les laisser
 * tricher — un commentaire contenant « company_id » suffirait à passer le
 * garde —, l'exception est explicite, visible à la lecture, et comptée par un
 * test : hors de db.js, personne ne doit s'en servir.
 */
let suspendu = 0;

function sansPerimetre(travail) {
  suspendu += 1;
  try {
    return travail();
  } finally {
    suspendu -= 1;
  }
}

/** Pour les tests : le classement est-il complet, et sans recouvrement ? */
function classement() {
  return { CLOISONNEES, RATTACHEES, PARTAGEES, SOCLE, SOUS_GARDE };
}

module.exports = {
  CLOISONNEES,
  RATTACHEES,
  PARTAGEES,
  SOCLE,
  SOUS_GARDE,
  tablesDe,
  verifier,
  sansPerimetre,
  classement,
};
