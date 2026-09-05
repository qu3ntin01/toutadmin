const db = require('./db');

/**
 * Conformité au règlement sur les données personnelles.
 *
 * Deux obligations distinctes : tenir le registre des traitements, et savoir
 * répondre à une personne qui demande ce qu'on détient sur elle, ou son
 * effacement. L'export rassemble ce que les vingt tables savent de quelqu'un ;
 * l'effacement distingue ce qui se supprime de ce qui doit être conservé —
 * un bulletin de paie ne s'efface pas sur demande.
 */

const LEGAL_BASES = [
  'Exécution du contrat',
  'Obligation légale',
  'Intérêt légitime',
  'Consentement',
  'Sauvegarde des intérêts vitaux',
  'Mission d\'intérêt public',
];

// ---------- Registre des traitements ----------

function records() {
  return db.prepare('SELECT * FROM processing_records ORDER BY name COLLATE NOCASE').all();
}

function recordById(id) {
  return db.prepare('SELECT * FROM processing_records WHERE id = ?').get(Number(id) || 0) || null;
}

function createRecord(fields) {
  return db.prepare(`
    INSERT INTO processing_records (name, purpose, legal_basis, data_categories, recipients, retention, measures)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(fields.name, fields.purpose || '', fields.legalBasis, fields.dataCategories || '',
    fields.recipients || '', fields.retention || '', fields.measures || '').lastInsertRowid;
}

function updateRecord(id, fields) {
  db.prepare(`
    UPDATE processing_records SET name = ?, purpose = ?, legal_basis = ?, data_categories = ?,
           recipients = ?, retention = ?, measures = ?, updated_at = datetime('now') WHERE id = ?
  `).run(fields.name, fields.purpose || '', fields.legalBasis, fields.dataCategories || '',
    fields.recipients || '', fields.retention || '', fields.measures || '', id);
}

function deleteRecord(id) {
  db.prepare('DELETE FROM processing_records WHERE id = ?').run(id);
}

/**
 * Le registre livré à l'installation : les traitements qu'un CMS de gestion du
 * personnel opère nécessairement. À compléter, pas à croire complet.
 */
const STARTER_RECORDS = [
  {
    name: 'Gestion administrative du personnel',
    purpose: "Tenue du dossier du salarié, contrat, rattachement, coordonnées professionnelles.",
    legalBasis: 'Exécution du contrat',
    dataCategories: 'Identité, coordonnées, contrat, grade, service et équipe.',
    recipients: 'Administration, ressources humaines, manager du périmètre.',
    retention: "Durée du contrat, puis cinq ans à compter du départ.",
    measures: 'Accès par rôle, journal d\'audit, chiffrement du transport, mots de passe hachés.',
  },
  {
    name: 'Gestion des congés et des absences',
    purpose: 'Instruction des demandes, décompte des soldes, planification des équipes.',
    legalBasis: 'Exécution du contrat',
    dataCategories: 'Dates, type de demande, motif facultatif, solde.',
    recipients: 'Ressources humaines et manager du périmètre. Le motif n\'est jamais partagé à l\'équipe.',
    retention: 'Cinq ans.',
    measures: "Agenda partagé sans motif ni type d'absence ; cloisonnement par périmètre.",
  },
  {
    name: 'Paie et rémunération',
    purpose: 'Établissement des bulletins, suivi des rémunérations et des pointages.',
    legalBasis: 'Obligation légale',
    dataCategories: 'Salaire, taux journalier, heures pointées, bulletins.',
    recipients: 'Ressources humaines, gestion, expert-comptable.',
    retention: 'Cinq ans pour les éléments de paie ; les bulletins doivent rester accessibles cinquante ans.',
    measures: 'Accès réservé aux rôles RH et gestion, tracé au journal d\'audit.',
  },
  {
    name: 'Recrutement',
    purpose: 'Instruction des candidatures, analyse des CV et suivi des étapes.',
    legalBasis: 'Intérêt légitime',
    dataCategories: 'Identité, coordonnées, CV et son texte extrait, expérience, score de filtrage.',
    recipients: 'Ressources humaines uniquement.',
    retention: "Deux ans après le dernier contact, sauf opposition de la personne.",
    measures: "CV stockés hors du dépôt en 0600, servis par une route authentifiée, jamais en statique. Le score aide à trier, il ne décide de rien.",
  },
  {
    name: 'Santé et sécurité au travail',
    purpose: 'Registre des accidents, suivi des visites médicales et des protections remises.',
    legalBasis: 'Obligation légale',
    dataCategories: "Circonstances d'accident, jours d'arrêt, avis d'aptitude, équipements remis.",
    recipients: 'Ressources humaines, membres du comité social et économique le cas échéant.',
    retention: 'Cinq ans pour le registre ; la durée réglementaire pour le suivi médical.',
    measures: "Seul l'avis d'aptitude est consigné : aucune donnée de santé n'est saisie dans l'outil.",
  },
  {
    name: 'Journalisation et sécurité',
    purpose: 'Traçabilité des actions, détection des accès anormaux, preuve en cas d\'incident.',
    legalBasis: 'Intérêt légitime',
    dataCategories: "Identifiant, action, objet visé, adresse IP, horodatage.",
    recipients: 'Administration.',
    retention: 'Un an par défaut, réglable dans la console de sécurité.',
    measures: 'Journal non modifiable depuis l\'interface, purge automatique au-delà de la durée retenue.',
  },
];

function seedRecords() {
  if (db.prepare('SELECT COUNT(*) AS n FROM processing_records').get().n > 0) return 0;
  let created = 0;
  for (const record of STARTER_RECORDS) {
    createRecord(record);
    created += 1;
  }
  return created;
}

// ---------- Droit d'accès : ce que l'instance détient sur une personne ----------

/**
 * Chaque entrée dit d'où vient la donnée, et si elle peut être effacée sur
 * demande. Ce qui relève d'une obligation légale de conservation ne le peut pas.
 */
const PERSONAL_SOURCES = [
  { key: 'compte', label: 'Compte', erasable: false,
    query: "SELECT id, email, first_name, last_name, grade, contract_type, contract_end_date, phone, bio, locale, created_at, last_login_at FROM users WHERE id = ?" },
  { key: 'demandes', label: 'Demandes de congés et absences', erasable: true,
    query: 'SELECT id, type, start_date, end_date, days, reason, status, created_at FROM hr_requests WHERE employee_id = ?' },
  { key: 'ajustements', label: 'Ajustements de solde', erasable: true,
    query: 'SELECT id, amount, reason, created_at FROM leave_adjustments WHERE employee_id = ?' },
  { key: 'bulletins', label: 'Bulletins de paie', erasable: false,
    query: 'SELECT id, period, gross_amount, net_amount, status, created_at FROM payslips WHERE employee_id = ?' },
  { key: 'pointages', label: 'Pointages', erasable: true,
    query: 'SELECT id, clock_in, clock_out, created_at FROM time_entries WHERE employee_id = ?' },
  { key: 'affectations', label: 'Outils affectés', erasable: true,
    query: 'SELECT id, tool_id, username, assigned_at FROM assignments WHERE employee_id = ?' },
  { key: 'equipements', label: 'Équipements affectés', erasable: true,
    query: 'SELECT id, asset_id, assigned_at, returned_at FROM asset_assignments WHERE employee_id = ?' },
  { key: 'messages', label: 'Messagerie interne', erasable: true,
    query: 'SELECT id, sender_id, recipient_id, subject, created_at FROM messages WHERE sender_id = ? OR recipient_id = ?' },
  { key: 'agenda', label: 'Agenda personnel', erasable: true,
    query: 'SELECT id, title, start_date, end_date, visibility FROM calendar_events WHERE user_id = ?' },
  { key: 'competences', label: 'Compétences et habilitations', erasable: false,
    query: 'SELECT us.id, s.name, us.level, us.obtained_on, us.expires_on FROM user_skills us JOIN skills s ON s.id = us.skill_id WHERE us.user_id = ?' },
  { key: 'visites', label: 'Visites médicales', erasable: false,
    query: 'SELECT id, kind, scheduled_on, done_on, verdict, next_due FROM medical_visits WHERE user_id = ?' },
  { key: 'accidents', label: 'Registre des accidents', erasable: false,
    query: 'SELECT id, occurred_on, kind, location, days_off FROM workplace_incidents WHERE user_id = ?' },
  { key: 'protections', label: 'Équipements de protection remis', erasable: true,
    query: 'SELECT id, ppe_id, issued_on, expires_on, returned_on FROM ppe_assignments WHERE user_id = ?' },
  { key: 'temps_projet', label: 'Temps passé sur les projets', erasable: true,
    query: 'SELECT id, project_id, spent_on, hours, note FROM project_time WHERE user_id = ?' },
  { key: 'taches', label: 'Tâches assignées', erasable: true,
    query: 'SELECT id, project_id, title, status, due_date FROM project_tasks WHERE assignee_id = ?' },
  { key: 'tickets', label: 'Tickets ouverts', erasable: true,
    query: 'SELECT id, reference, subject, status, created_at FROM tickets WHERE requester_id = ?' },
  { key: 'frais', label: 'Notes de frais', erasable: false,
    query: 'SELECT id, spent_on, category, description, amount, status FROM expense_claims WHERE employee_id = ?' },
  { key: 'parcours', label: "Parcours d'arrivée et de départ", erasable: true,
    query: 'SELECT id, kind, reference_date, completed_at FROM checklists WHERE user_id = ?' },
  { key: 'notifications', label: 'Notifications', erasable: true,
    query: 'SELECT id, title, body, created_at, read_at FROM notifications WHERE user_id = ?' },
  { key: 'journal', label: "Journal d'audit", erasable: false,
    query: 'SELECT id, occurred_at, action, entity, entity_id, ip FROM audit_log WHERE actor_id = ?' },
];

function collectFor(userId) {
  const out = [];
  for (const source of PERSONAL_SOURCES) {
    let rows;
    try {
      // Deux sources portent l'identifiant deux fois (émetteur ou destinataire).
      const placeholders = (source.query.match(/\?/g) || []).length;
      rows = db.prepare(source.query).all(...Array(placeholders).fill(userId));
    } catch (err) {
      // Une table absente (module jamais installé) n'est pas une erreur d'export.
      rows = [];
    }
    out.push({ ...source, rows });
  }
  return out;
}

function exportFor(userId) {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
  if (!user) return null;

  return {
    genere_le: new Date().toISOString(),
    personne: { id: user.id, email: user.email, nom: `${user.first_name} ${user.last_name}` },
    donnees: Object.fromEntries(collectFor(userId).map((s) => [s.label, s.rows])),
  };
}

/**
 * Efface ce qui peut l'être et rend le détail de ce qui a été gardé, avec le
 * motif. Le compte lui-même est anonymisé plutôt que supprimé : les écritures
 * qui le référencent doivent rester cohérentes.
 */
function eraseFor(userId) {
  const user = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
  if (!user) return null;

  const erased = [];
  const kept = [];

  const run = db.transaction(() => {
    for (const source of collectFor(userId)) {
      if (!source.rows.length) continue;
      if (!source.erasable) {
        kept.push({ label: source.label, count: source.rows.length });
        continue;
      }
      const table = source.query.match(/FROM\s+(\w+)/i)[1];
      const column = source.query.includes('employee_id') ? 'employee_id'
        : source.query.includes('assignee_id') ? 'assignee_id'
          : source.query.includes('requester_id') ? 'requester_id'
            : 'user_id';

      let removed;
      if (source.key === 'messages') {
        removed = db.prepare('DELETE FROM messages WHERE sender_id = ? OR recipient_id = ?').run(userId, userId).changes;
      } else {
        removed = db.prepare(`DELETE FROM ${table} WHERE ${column} = ?`).run(userId).changes;
      }
      erased.push({ label: source.label, count: removed });
    }

    // Le compte est anonymisé : identité effacée, identifiant conservé pour que
    // les traces obligatoires restent rattachables sans nommer personne.
    db.prepare(`
      UPDATE users SET first_name = 'Compte', last_name = 'anonymisé', email = ?, phone = '', bio = '',
             avatar_file = NULL, mail_address = '', totp_secret = NULL, totp_enabled = 0,
             active = 0, directory_hidden = 1
      WHERE id = ?
    `).run(`anonyme-${userId}@invalide.local`, userId);
  });
  run();

  return { erased, kept, email: user.email };
}

module.exports = {
  LEGAL_BASES, STARTER_RECORDS, PERSONAL_SOURCES,
  records, recordById, createRecord, updateRecord, deleteRecord, seedRecords,
  collectFor, exportFor, eraseFor,
};
