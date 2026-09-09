const db = require('./db');

/**
 * Recherche globale.
 *
 * Une seule barre pour tout le CMS, mais jamais au prix du cloisonnement : la
 * recherche interroge chaque source avec les droits de la personne, et une
 * source qu'elle n'a pas le droit de voir n'est pas interrogée du tout. Rien
 * n'est filtré après coup — ce qui fuit ne se rattrape pas à l'affichage.
 */

const MAX_PER_SOURCE = 8;

function like(query) {
  return `%${String(query).trim()}%`;
}

function can(user, right) {
  if (!user) return false;
  if (user.role === 'admin') return true;
  if (right === 'hr') return Boolean(user.is_hr);
  if (right === 'finance') return Boolean(user.is_finance);
  if (right === 'it') return Boolean(user.is_it);
  return false;
}

/**
 * @param {string} query   ce que la personne a tapé
 * @param {object} user    la ligne utilisateur, avec ses droits
 * @param {object} deps    injecté pour les tests ; org sert au périmètre manager
 */
function search(query, user, { org = require('./org') } = {}) {
  const trimmed = String(query || '').trim();
  if (trimmed.length < 2) return [];

  const pattern = like(trimmed);
  const groups = [];
  const add = (source, rows) => {
    if (rows.length) groups.push({ source, rows: rows.slice(0, MAX_PER_SOURCE) });
  };

  // --- Annuaire : ouvert à tous, sauf les membres masqués par l'administration.
  add('Annuaire', db.prepare(`
    SELECT id, first_name || ' ' || last_name AS label, COALESCE(NULLIF(grade, ''), email) AS detail,
           '/annuaire' AS link
    FROM users WHERE active = 1 AND directory_hidden = 0
      AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR grade LIKE ?)
    ORDER BY last_name COLLATE NOCASE LIMIT ?
  `).all(pattern, pattern, pattern, pattern, MAX_PER_SOURCE));

  // --- Base de connaissances : la portée de chaque article est appliquée ici.
  const articles = db.prepare(`
    SELECT id, title AS label, category AS detail, visibility, scope_id
    FROM kb_articles WHERE published = 1 AND (title LIKE ? OR body LIKE ? OR category LIKE ?)
    ORDER BY title COLLATE NOCASE LIMIT 40
  `).all(pattern, pattern, pattern);
  add('Connaissances', articles
    .filter((a) => require('./support').canRead(a, user))
    .map((a) => ({ ...a, link: `/base-de-connaissances/${a.id}` })));

  // --- Projets : ceux dont la personne est membre, sauf droits de pilotage.
  const steersProjects = can(user, 'finance') || org.isManager(user.id);
  const projectRows = steersProjects
    ? db.prepare(`
        SELECT id, name AS label, status AS detail FROM projects
        WHERE archived = 0 AND (name LIKE ? OR code LIKE ? OR description LIKE ?)
        ORDER BY name COLLATE NOCASE LIMIT ?
      `).all(pattern, pattern, pattern, MAX_PER_SOURCE)
    : db.prepare(`
        SELECT DISTINCT p.id, p.name AS label, p.status AS detail FROM projects p
        LEFT JOIN project_members pm ON pm.project_id = p.id
        WHERE p.archived = 0 AND (pm.user_id = ? OR p.lead_id = ?)
          AND (p.name LIKE ? OR p.code LIKE ? OR p.description LIKE ?)
        ORDER BY p.name COLLATE NOCASE LIMIT ?
      `).all(user.id, user.id, pattern, pattern, pattern, MAX_PER_SOURCE);
  add('Projets', projectRows.map((p) => ({ ...p, link: `/projets/${p.id}` })));

  // --- Tickets : les siens, ou tous pour les équipes support.
  const isAgent = can(user, 'hr') || can(user, 'finance') || org.isManager(user.id);
  const ticketRows = isAgent
    ? db.prepare(`
        SELECT id, subject AS label, reference || ' · ' || status AS detail FROM tickets
        WHERE subject LIKE ? OR body LIKE ? OR reference LIKE ? ORDER BY id DESC LIMIT ?
      `).all(pattern, pattern, pattern, MAX_PER_SOURCE)
    : db.prepare(`
        SELECT id, subject AS label, reference || ' · ' || status AS detail FROM tickets
        WHERE requester_id = ? AND (subject LIKE ? OR body LIKE ? OR reference LIKE ?)
        ORDER BY id DESC LIMIT ?
      `).all(user.id, pattern, pattern, pattern, MAX_PER_SOURCE);
  add('Tickets', ticketRows.map((tk) => ({ ...tk, link: `/support/tickets/${tk.id}` })));

  // --- Gestion : tiers, contrats et factures, réservés à la gestion.
  if (can(user, 'finance')) {
    add('Tiers', db.prepare(`
      SELECT id, name AS label, kind AS detail, '/gestion#tiers' AS link FROM partners
      WHERE name LIKE ? OR email LIKE ? ORDER BY name COLLATE NOCASE LIMIT ?
    `).all(pattern, pattern, MAX_PER_SOURCE));

    add('Factures', db.prepare(`
      SELECT id, COALESCE(NULLIF(reference, ''), label) AS label,
             direction || ' · ' || status AS detail, '/gestion#factures' AS link
      FROM invoices WHERE reference LIKE ? OR label LIKE ? ORDER BY issue_date DESC LIMIT ?
    `).all(pattern, pattern, MAX_PER_SOURCE));

    add('Véhicules', db.prepare(`
      SELECT id, registration AS label, brand || ' ' || model AS detail FROM vehicles
      WHERE registration LIKE ? OR brand LIKE ? OR model LIKE ? ORDER BY registration LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE).map((v) => ({ ...v, link: `/flotte/${v.id}` })));
  }

  // --- Événements ouverts : chacun cherche ceux auxquels il peut s'inscrire.
  //     Un brouillon n'existe pas encore pour l'entreprise, il reste hors recherche.
  add('Événements', db.prepare(`
    SELECT id, title AS label, kind || ' · ' || substr(starts_at, 1, 10) AS detail FROM company_events
    WHERE status IN ('Ouvert','Complet','Clos') AND (title LIKE ? OR description LIKE ? OR location LIKE ?)
    ORDER BY starts_at DESC LIMIT ?
  `).all(pattern, pattern, pattern, MAX_PER_SOURCE).map((e) => ({ ...e, link: `/evenements/${e.id}` })));

  // --- Informatique : parc logiciel et référentiel applicatif.
  if (can(user, 'it')) {
    add('Logiciels', db.prepare(`
      SELECT id, name AS label, publisher || ' · ' || kind AS detail FROM software_licences
      WHERE name LIKE ? OR publisher LIKE ? OR notes LIKE ? ORDER BY name COLLATE NOCASE LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE).map((l) => ({ ...l, link: `/informatique/logiciels/${l.id}` })));

    add('Services applicatifs', db.prepare(`
      SELECT id, name AS label, COALESCE(NULLIF(stack, ''), criticality) AS detail FROM app_services
      WHERE name LIKE ? OR code LIKE ? OR description LIKE ? OR stack LIKE ?
      ORDER BY name COLLATE NOCASE LIMIT ?
    `).all(pattern, pattern, pattern, pattern, MAX_PER_SOURCE).map((a) => ({ ...a, link: `/developpement/services/${a.id}` })));
  }

  // --- Gouvernance : décisions et réunions, réservées à l'administration.
  //     Un relevé de décisions est plus confidentiel que la plupart des tables.
  if (user.role === 'admin') {
    add('Décisions', db.prepare(`
      SELECT id, title AS label, decided_on || ' · ' || status AS detail, '/direction#decisions' AS link
      FROM decisions WHERE title LIKE ? OR body LIKE ? OR rationale LIKE ?
      ORDER BY decided_on DESC LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE));

    add('Réunions', db.prepare(`
      SELECT id, title AS label, kind || ' · ' || held_on AS detail FROM meetings
      WHERE title LIKE ? OR agenda LIKE ? OR minutes LIKE ? ORDER BY held_on DESC LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE).map((m) => ({ ...m, link: `/direction/reunions/${m.id}` })));

    add('Risques', db.prepare(`
      SELECT id, title AS label, category || ' · ' || status AS detail, '/direction#risques' AS link
      FROM enterprise_risks WHERE title LIKE ? OR description LIKE ? OR reference LIKE ?
      ORDER BY id DESC LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE));
  }

  // --- Qualité : ouverte à l'encadrement, comme l'écran qui la porte.
  if (user.role === 'admin' || org.isManager(user.id)) {
    add('Qualité', db.prepare(`
      SELECT id, reference || ' — ' || title AS label, severity || ' · ' || status AS detail,
             '/qualite#non-conformites' AS link
      FROM nonconformities WHERE title LIKE ? OR description LIKE ? OR reference LIKE ? OR subject LIKE ?
      ORDER BY detected_on DESC LIMIT ?
    `).all(pattern, pattern, pattern, pattern, MAX_PER_SOURCE));
  }

  // --- RH : personnel et candidatures, réservés aux RH.
  if (can(user, 'hr')) {
    add('Candidatures', db.prepare(`
      SELECT c.id, c.first_name || ' ' || c.last_name AS label,
             o.title || ' · ' || c.stage AS detail, '/rh#recrutement' AS link
      FROM candidates c JOIN job_openings o ON o.id = c.opening_id
      WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.email LIKE ? OR c.cv_text LIKE ?
      ORDER BY c.id DESC LIMIT ?
    `).all(pattern, pattern, pattern, pattern, MAX_PER_SOURCE));

    add('Personnel', db.prepare(`
      SELECT id, first_name || ' ' || last_name AS label,
             contract_type || CASE WHEN active = 1 THEN '' ELSE ' · désactivé' END AS detail,
             '/admin#personnel' AS link
      FROM users WHERE role = 'employee' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)
      ORDER BY last_name COLLATE NOCASE LIMIT ?
    `).all(pattern, pattern, pattern, MAX_PER_SOURCE));
  }

  return groups;
}

module.exports = { search, MAX_PER_SOURCE };
