const db = require('./db');
const { CLOISONNEES, RATTACHEES } = require('./cloison');
const societes = require('./societes');

/**
 * Une ligne rattachée est-elle dans le périmètre courant ?
 *
 * Le cloisonnement tient les tables qui portent leur « company_id ». Les tables
 * rattachées n'en portent pas : leurs lignes appartiennent à une ligne d'une
 * autre table, et la règle était qu'on ne les atteint qu'en nommant ce parent,
 * dont l'identifiant a été obtenu dans le périmètre.
 *
 * Une vingtaine de routes ne la suivaient pas : supprimer le contact d'un tiers,
 * révoquer un accès logiciel, rapprocher un mouvement bancaire, émarger une
 * inscription — toutes prenaient l'identifiant de la ligne rattachée elle-même,
 * sans remonter au parent. Un compte qui tient la gestion d'une filiale pouvait
 * donc agir sur les lignes d'une autre entité du groupe en devinant un numéro.
 *
 * Cette fonction remonte la chaîne jusqu'à une table cloisonnée et y vérifie le
 * périmètre. Les fonctions qui agissent sur une ligne rattachée par son seul
 * identifiant l'appellent avant d'écrire, et un test confronte ce plan au
 * schéma : une table rattachée ajoutée demain sans sa colonne parent le fait
 * échouer.
 */

/** La colonne qui désigne le parent, table par table. */
const PARENT = {
  asset_assignments: 'asset_id',
  audit_findings: 'audit_id',
  bank_transactions: 'account_id',
  candidates: 'opening_id',
  checklist_items: 'checklist_id',
  checklist_template_items: 'template_id',
  crm_activities: 'partner_id',
  crm_contacts: 'partner_id',
  cse_ballots: 'election_id',
  cse_candidacies: 'election_id',
  cse_voters: 'election_id',
  custom_values: 'field_id',
  document_acks: 'document_id',
  dunning_notices: 'invoice_id',
  delivery_note_lines: 'note_id',
  sales_invoice_lines: 'invoice_id',
  stripe_sessions: 'invoice_id',
  sales_order_lines: 'order_id',
  entry_lines: 'entry_id',
  event_registrations: 'event_id',
  key_results: 'objective_id',
  meeting_attendees: 'meeting_id',
  meeting_resolutions: 'meeting_id',
  opening_criteria: 'opening_id',
  partner_contacts: 'partner_id',
  partner_contracts: 'partner_id',
  partner_documents: 'partner_id',
  partner_reviews: 'partner_id',
  payslip_lines: 'payslip_id',
  ppe_assignments: 'ppe_id',
  project_members: 'project_id',
  project_milestones: 'project_id',
  project_tasks: 'project_id',
  project_time: 'project_id',
  purchase_order_lines: 'order_id',
  purchase_receipts: 'line_id',
  releases: 'service_id',
  request_steps: 'form_id',
  room_bookings: 'room_id',
  // Deux parents possibles, tous deux du registre des associés : le détenteur suffit.
  share_movements: 'shareholder_id',
  signature_signers: 'request_id',
  software_accesses: 'licence_id',
  stock_movements: 'item_id',
  support_group_categories: 'group_id',
  support_group_members: 'group_id',
  survey_answers: 'question_id',
  survey_participations: 'survey_id',
  survey_questions: 'survey_id',
  ticket_messages: 'ticket_id',
  training_registrations: 'session_id',
  training_sessions: 'training_id',
  vehicle_events: 'vehicle_id',
  webhook_deliveries: 'webhook_id',
  whistleblow_access_log: 'report_id',
  whistleblow_messages: 'report_id',
  workflow_decisions: 'request_id',
  workflow_requests: 'form_id',
};

const NOM = /^[a-z_]+$/;

/**
 * Vrai si la ligne « id » de « table » appartient, par ses parents, à la
 * société courante. Faux si elle n'existe pas, ou si elle est d'une autre
 * société — sans dire laquelle des deux raisons l'emporte, comme partout.
 */
function visible(table, id) {
  let courante = table;
  let ligne = Number(id) || 0;
  // Cinq étages suffisent : la plus longue chaîne du schéma en compte trois
  // (réception → ligne de commande → commande).
  for (let etage = 0; etage < 5 && ligne > 0; etage += 1) {
    if (!NOM.test(courante)) return false;
    if (CLOISONNEES.includes(courante)) {
      return Boolean(db.prepare(`SELECT 1 AS ok FROM ${courante} WHERE id = ? AND company_id = ?`)
        .get(ligne, societes.courant()));
    }
    const parent = RATTACHEES[courante];
    const colonne = PARENT[courante];
    if (!parent || !colonne) return false;
    const row = db.prepare(`SELECT ${colonne} AS p FROM ${courante} WHERE id = ?`).get(ligne);
    if (!row || row.p == null) return false;
    courante = parent;
    ligne = Number(row.p);
  }
  return false;
}

module.exports = { PARENT, visible };
