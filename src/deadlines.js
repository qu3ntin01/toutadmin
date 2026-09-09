const db = require('./db');
const notifications = require('./notifications');

/**
 * Échéances de l'entreprise, rassemblées en un seul endroit.
 *
 * Chaque espace sait ce qui arrive à terme chez lui ; personne ne voit
 * l'ensemble. Ce module interroge toutes les sources, rend une liste homogène,
 * et en tire les notifications. Une seule règle : un objet, une date, un
 * destinataire — le reste est de la mise en forme.
 */

const HORIZON_DAYS = 45;

function today() {
  return new Date().toISOString().slice(0, 10);
}

function shift(days) {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

/** Les administrateurs et les RH : destinataires par défaut de ce qui n'a pas de porteur. */
function stewards() {
  return db.prepare("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_hr = 1)").all().map((r) => r.id);
}

/** Le service informatique : destinataire de ce qui touche au parc logiciel. */
function itStewards() {
  return db.prepare("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_it = 1)").all().map((r) => r.id);
}

/** L'administration seule : la vie sociale de la société ne se délègue pas. */
function admins() {
  return db.prepare("SELECT id FROM users WHERE active = 1 AND role = 'admin'").all().map((r) => r.id);
}

function financeStewards() {
  return db.prepare("SELECT id FROM users WHERE active = 1 AND (role = 'admin' OR is_finance = 1)").all().map((r) => r.id);
}

/**
 * Toutes les échéances à l'horizon, chacune sous la même forme :
 * { source, label, detail, due, overdue, link, audience }
 */
function collect({ withinDays = HORIZON_DAYS } = {}) {
  const limit = shift(withinDays);
  const now = today();
  const rows = [];

  const add = (source, label, detail, due, link, audience) => {
    if (!due) return;
    rows.push({ source, label, detail, due, overdue: due < now, link, audience });
  };

  // --- Contrats de travail arrivant à terme
  for (const user of db.prepare(`
    SELECT id, first_name, last_name, contract_type, contract_end_date FROM users
    WHERE active = 1 AND contract_end_date IS NOT NULL AND contract_end_date <= ?
  `).all(limit)) {
    add('Contrat de travail', `${user.first_name} ${user.last_name}`,
      `Fin de ${user.contract_type || 'contrat'}`, user.contract_end_date, '/admin#personnel', stewards());
  }

  // --- Contrats fournisseurs et clients, avec leur préavis
  for (const contract of db.prepare(`
    SELECT id, title, end_date, notice_days, owner_id FROM partner_contracts
    WHERE status = 'Actif' AND end_date IS NOT NULL
      AND date(end_date, '-' || COALESCE(notice_days, 0) || ' days') <= ?
  `).all(limit)) {
    const notice = contract.notice_days || 0;
    // C'est la date limite de dénonciation qui compte, pas la fin du contrat :
    // passé le préavis, la reconduction est acquise.
    const deadline = notice ? shiftFrom(contract.end_date, -notice) : contract.end_date;
    add('Contrat', contract.title, notice ? `Préavis de ${notice} jours` : 'Échéance', deadline, '/gestion#contrats',
      [...new Set([contract.owner_id, ...financeStewards()].filter(Boolean))]);
  }

  // --- Factures non réglées
  for (const invoice of db.prepare(`
    SELECT id, reference, label, direction, due_date FROM invoices
    WHERE status != 'Payée' AND due_date IS NOT NULL AND due_date <= ?
  `).all(limit)) {
    add('Facture', invoice.reference || invoice.label, invoice.direction, invoice.due_date, '/gestion#factures', financeStewards());
  }

  // --- Habilitations
  for (const held of db.prepare(`
    SELECT us.expires_on, s.name, u.id AS user_id, u.first_name, u.last_name
    FROM user_skills us JOIN skills s ON s.id = us.skill_id JOIN users u ON u.id = us.user_id
    WHERE u.active = 1 AND us.expires_on IS NOT NULL AND us.expires_on <= ?
  `).all(limit)) {
    add('Habilitation', `${held.first_name} ${held.last_name}`, held.name, held.expires_on, '/parcours#echeances',
      [...new Set([held.user_id, ...stewards()])]);
  }

  // --- Visites médicales
  for (const visit of db.prepare(`
    SELECT v.next_due, v.kind, u.id AS user_id, u.first_name, u.last_name
    FROM medical_visits v JOIN users u ON u.id = v.user_id
    WHERE u.active = 1 AND v.next_due IS NOT NULL AND v.next_due <= ?
  `).all(limit)) {
    // La personne est prévenue de sa propre visite : c'est elle qui s'y rend.
    add('Visite médicale', `${visit.first_name} ${visit.last_name}`, visit.kind, visit.next_due, '/sante-securite#echeances',
      [...new Set([visit.user_id, ...stewards()])]);
  }

  // --- Équipements de protection
  for (const ppe of db.prepare(`
    SELECT a.expires_on, p.name, u.id AS user_id, u.first_name, u.last_name
    FROM ppe_assignments a JOIN ppe_items p ON p.id = a.ppe_id JOIN users u ON u.id = a.user_id
    WHERE a.returned_on IS NULL AND a.expires_on IS NOT NULL AND a.expires_on <= ?
  `).all(limit)) {
    add('Protection', `${ppe.first_name} ${ppe.last_name}`, ppe.name, ppe.expires_on, '/sante-securite#echeances',
      [...new Set([ppe.user_id, ...stewards()])]);
  }

  // --- Véhicules : trois échéances par véhicule
  for (const vehicle of db.prepare("SELECT * FROM vehicles WHERE status != 'Cédé'").all()) {
    for (const [field, label] of [['insurance_due', 'Assurance'], ['inspection_due', 'Contrôle technique'], ['service_due', 'Entretien']]) {
      if (vehicle[field] && vehicle[field] <= limit) {
        add('Véhicule', vehicle.registration, label, vehicle[field], `/flotte/${vehicle.id}`,
          [...new Set([vehicle.assigned_to, ...financeStewards()].filter(Boolean))]);
      }
    }
  }

  // --- Revue du document unique
  for (const risk of db.prepare('SELECT * FROM risk_assessments WHERE next_review IS NOT NULL AND next_review <= ?').all(limit)) {
    add('Document unique', risk.hazard, risk.unit, risk.next_review, '/sante-securite#risques', stewards());
  }

  // --- Tâches et jalons de projet
  for (const task of db.prepare(`
    SELECT t.id, t.title, t.due_date, t.assignee_id, p.id AS project_id, p.name AS project_name
    FROM project_tasks t JOIN projects p ON p.id = t.project_id
    WHERE p.archived = 0 AND t.status != 'Terminée' AND t.due_date IS NOT NULL AND t.due_date <= ?
  `).all(limit)) {
    add('Tâche', task.title, task.project_name, task.due_date, `/projets/${task.project_id}#taches`,
      task.assignee_id ? [task.assignee_id] : []);
  }

  // --- Actions décidées en réunion
  for (const action of db.prepare(`
    SELECT a.id, a.label, a.due_date, a.assignee_id, m.title AS meeting_title
    FROM meeting_actions a LEFT JOIN meetings m ON m.id = a.meeting_id
    WHERE a.status NOT IN ('Faite','Abandonnée') AND a.due_date IS NOT NULL AND a.due_date <= ?
  `).all(limit)) {
    add('Action de direction', action.label, action.meeting_title || 'Hors réunion', action.due_date, '/direction#actions',
      action.assignee_id ? [action.assignee_id] : stewards());
  }

  // --- Décisions à réexaminer
  for (const decision of db.prepare(`
    SELECT id, title, scope, review_on FROM decisions
    WHERE status = 'En vigueur' AND review_on IS NOT NULL AND review_on <= ?
  `).all(limit)) {
    add('Décision', decision.title, `À réexaminer — ${decision.scope}`, decision.review_on, '/direction#decisions', stewards());
  }

  // --- Revue des risques de l'entreprise
  for (const risk of db.prepare(`
    SELECT id, title, category, next_review, owner_id FROM enterprise_risks
    WHERE status != 'Clos' AND next_review IS NOT NULL AND next_review <= ?
  `).all(limit)) {
    add('Risque', risk.title, `Revue — ${risk.category}`, risk.next_review, '/direction#risques',
      [...new Set([risk.owner_id, ...stewards()].filter(Boolean))]);
  }

  // --- Actions qualité
  for (const action of db.prepare(`
    SELECT a.id, a.label, a.due_date, a.owner_id, a.kind, n.reference
    FROM quality_actions a LEFT JOIN nonconformities n ON n.id = a.nonconformity_id
    WHERE a.status != 'Faite' AND a.due_date IS NOT NULL AND a.due_date <= ?
  `).all(limit)) {
    add('Qualité', action.label, `Action ${action.kind.toLowerCase()}${action.reference ? ` — ${action.reference}` : ''}`,
      action.due_date, '/qualite#actions', action.owner_id ? [action.owner_id] : stewards());
  }

  // --- Points de parcours d'arrivée ou de départ
  for (const item of db.prepare(`
    SELECT i.id, i.label, i.due_date, c.kind, u.first_name, u.last_name
    FROM checklist_items i JOIN checklists c ON c.id = i.checklist_id JOIN users u ON u.id = c.user_id
    WHERE i.done_at IS NULL AND i.due_date IS NOT NULL AND i.due_date <= ?
  `).all(limit)) {
    add('Parcours', item.label, `${item.kind} — ${item.first_name} ${item.last_name}`, item.due_date, '/parcours#parcours', stewards());
  }

  // --- Renouvellement des licences logicielles
  for (const licence of db.prepare(`
    SELECT id, name, billing_period, renewal_date, owner_id FROM software_licences
    WHERE status != 'Retiré' AND renewal_date IS NOT NULL AND renewal_date <= ?
  `).all(limit)) {
    add('Licence', licence.name, `Renouvellement — ${licence.billing_period}`, licence.renewal_date,
      '/informatique#logiciels', [...new Set([licence.owner_id, ...itStewards()].filter(Boolean))]);
  }

  // --- Pièces de conformité d'un tiers : une attestation périmée engage le
  //     donneur d'ordre, c'est donc une échéance et non une pièce jointe.
  for (const document of db.prepare(`
    SELECT d.kind, d.expires_on, p.name FROM partner_documents d
    JOIN partners p ON p.id = d.partner_id
    WHERE d.expires_on IS NOT NULL AND d.expires_on <= ?
  `).all(limit)) {
    add('Conformité tiers', document.name, document.kind, document.expires_on, '/gestion#tiers', financeStewards());
  }

  // --- Évaluations de tiers à refaire. Seule la dernière évaluation compte :
  //     sans cela, un tiers évalué dix fois ferait dix échéances.
  for (const review of db.prepare(`
    SELECT r.next_review, p.id, p.name FROM partner_reviews r
    JOIN partners p ON p.id = r.partner_id
    WHERE r.id = (SELECT r2.id FROM partner_reviews r2 WHERE r2.partner_id = p.id
                  ORDER BY r2.reviewed_on DESC, r2.id DESC LIMIT 1)
      AND r.next_review IS NOT NULL AND r.next_review <= ?
  `).all(limit)) {
    add('Évaluation tiers', review.name, 'Revue à refaire', review.next_review, `/partenaires/${review.id}#evaluations`, financeStewards());
  }

  // --- Clôture des inscriptions à un événement : c'est l'organisateur qui
  //     arrête la liste, et lui seul est prévenu.
  for (const event of db.prepare(`
    SELECT id, title, registration_closes_on, organizer_id FROM company_events
    WHERE status = 'Ouvert' AND registration_closes_on IS NOT NULL AND registration_closes_on <= ?
  `).all(limit)) {
    add('Événement', event.title, 'Clôture des inscriptions', event.registration_closes_on,
      `/evenements/${event.id}`, event.organizer_id ? [event.organizer_id] : stewards());
  }

  // --- Points individuels à tenir
  for (const point of db.prepare(`
    SELECT o.id, o.scheduled_on, o.manager_id, u.first_name, u.last_name
    FROM one_on_ones o JOIN users u ON u.id = o.employee_id
    WHERE o.status = 'Planifié' AND o.scheduled_on <= ?
  `).all(limit)) {
    add('Point individuel', `${point.first_name} ${point.last_name}`, 'À tenir', point.scheduled_on,
      '/mon-equipe#points', [point.manager_id]);
  }

  // --- Délais du dispositif d'alerte : accusé de réception sous 7 jours,
  //     retour sur les suites sous 3 mois. Adressés aux seuls référents, et
  //     désignant la référence du signalement, jamais son objet.
  const referents = db.prepare('SELECT id FROM users WHERE active = 1 AND is_referent = 1').all().map((r) => r.id);
  if (referents.length) {
    for (const report of db.prepare(`
      SELECT id, reference, submitted_at, acknowledged_at FROM whistleblow_reports
      WHERE status != 'Clôturée'
    `).all()) {
      const filed = String(report.submitted_at).slice(0, 10);
      if (!report.acknowledged_at) {
        const due = shiftFrom(filed, 7);
        if (due <= limit) add('Alerte', report.reference, 'Accusé de réception', due, `/alertes/signalements/${report.id}`, referents);
      }
      const outcome = shiftFrom(filed, 90);
      if (outcome <= limit) add('Alerte', report.reference, 'Retour sur les suites', outcome, `/alertes/signalements/${report.id}`, referents);
    }
  }

  // --- Mandats sociaux et délégations de pouvoir arrivant à terme. Un mandat
  //     échu qui continue d'être exercé engage la société sur des actes que
  //     personne n'avait le pouvoir de signer : c'est l'échéance qu'on oublie
  //     parce qu'elle ne se rappelle à personne.
  const boards = admins();
  if (boards.length) {
    for (const mandate of db.prepare(`
      SELECT id, holder_name, role, ends_on FROM corporate_mandates
      WHERE status = 'En cours' AND ends_on IS NOT NULL AND ends_on <= ?
    `).all(limit)) {
      add('Mandat social', mandate.holder_name, `Fin de mandat — ${mandate.role}`, mandate.ends_on, '/juridique#mandats', boards);
    }

    for (const delegation of db.prepare(`
      SELECT d.id, d.scope, d.ends_on, u.first_name, u.last_name
      FROM power_delegations d JOIN users u ON u.id = d.holder_id
      WHERE d.status = 'En vigueur' AND d.ends_on IS NOT NULL AND d.ends_on <= ?
    `).all(limit)) {
      add('Délégation de pouvoir', `${delegation.first_name} ${delegation.last_name}`,
        delegation.scope, delegation.ends_on, '/juridique#delegations', boards);
    }
  }

  return rows.sort((a, b) => a.due.localeCompare(b.due));
}

function shiftFrom(iso, days) {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

/**
 * Transforme les échéances en notifications. Rejouable à volonté : la clé de
 * déduplication empêche qu'une même échéance alerte deux fois.
 */
function notify({ withinDays = 15 } = {}) {
  let created = 0;
  for (const row of collect({ withinDays })) {
    const key = `${row.source}:${row.label}:${row.due}`;
    for (const userId of row.audience) {
      const done = notifications.push({
        userId,
        kind: 'echeance',
        title: `${row.source} — ${row.label}`,
        body: `${row.detail || ''}${row.overdue ? ' · échéance dépassée' : ''} (${row.due})`.trim(),
        link: row.link,
        dedupeKey: key,
      });
      if (done) created += 1;
    }
  }
  return created;
}

/** Le compte de ce qui presse, par source : c'est ce qu'affiche le tableau de bord. */
function summary({ withinDays = HORIZON_DAYS } = {}) {
  const rows = collect({ withinDays });
  const bySource = {};
  for (const row of rows) {
    bySource[row.source] = bySource[row.source] || { total: 0, overdue: 0 };
    bySource[row.source].total += 1;
    if (row.overdue) bySource[row.source].overdue += 1;
  }
  return { total: rows.length, overdue: rows.filter((r) => r.overdue).length, bySource, rows };
}

module.exports = { HORIZON_DAYS, collect, notify, summary };
