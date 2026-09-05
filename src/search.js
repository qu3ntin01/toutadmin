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
