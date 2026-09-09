const db = require('./db');

/**
 * Espace développement : le référentiel des services applicatifs et le registre
 * des livraisons.
 *
 * Deux questions reviennent sans cesse dans une équipe technique, et aucun outil
 * de gestion classique n'y répond : « qu'est-ce qui tourne, et qui en répond ? »
 * et « qu'est-ce qui est parti en production, quand, et est-ce que ça a tenu ? ».
 * Le reste — le code, l'intégration continue — vit ailleurs et doit y rester ;
 * ce module tient la mémoire, pas la machinerie.
 */

const SERVICE_STATUSES = ['En construction', 'En service', 'Retiré'];
const CRITICALITIES = ['Vitale', 'Importante', 'Secondaire'];
const ENVIRONMENTS = ['Développement', 'Recette', 'Préproduction', 'Production'];
const RELEASE_STATUSES = ['Planifiée', 'Livrée', 'Échouée', 'Retirée'];

const SERVICE_COLUMNS = `
  s.*,
  u.first_name AS lead_first_name, u.last_name AS lead_last_name,
  p.name AS project_name,
  (SELECT COUNT(*) FROM releases r WHERE r.service_id = s.id) AS release_count,
  (SELECT r.version FROM releases r
    WHERE r.service_id = s.id AND r.environment = 'Production' AND r.status = 'Livrée'
    ORDER BY r.released_on DESC, r.id DESC LIMIT 1) AS live_version,
  (SELECT r.released_on FROM releases r
    WHERE r.service_id = s.id AND r.environment = 'Production' AND r.status = 'Livrée'
    ORDER BY r.released_on DESC, r.id DESC LIMIT 1) AS live_since,
  (SELECT COUNT(*) FROM it_incidents i WHERE i.service_id = s.id AND i.status NOT IN ('Résolu','Clos')) AS open_incidents
`;

// ---------------------------------------------------------------- services

function services({ includeRetired = false } = {}) {
  const where = includeRetired ? '' : "WHERE s.status != 'Retiré'";
  return db.prepare(`
    SELECT ${SERVICE_COLUMNS}
    FROM app_services s
    LEFT JOIN users u ON u.id = s.lead_id
    LEFT JOIN projects p ON p.id = s.project_id
    ${where}
    ORDER BY s.name COLLATE NOCASE
  `).all();
}

function serviceById(id) {
  return db.prepare(`
    SELECT ${SERVICE_COLUMNS}
    FROM app_services s
    LEFT JOIN users u ON u.id = s.lead_id
    LEFT JOIN projects p ON p.id = s.project_id
    WHERE s.id = ?
  `).get(Number(id) || 0) || null;
}

function createService(fields) {
  return db.prepare(`
    INSERT INTO app_services (name, code, description, repository, documentation, stack, criticality, lead_id, project_id, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.name, fields.code || '', fields.description || '', fields.repository || '',
    fields.documentation || '', fields.stack || '', fields.criticality || 'Importante',
    fields.leadId || null, fields.projectId || null, fields.status || 'En service'
  ).lastInsertRowid;
}

function updateService(id, fields) {
  db.prepare(`
    UPDATE app_services
    SET name = ?, code = ?, description = ?, repository = ?, documentation = ?, stack = ?,
        criticality = ?, lead_id = ?, project_id = ?, status = ?
    WHERE id = ?
  `).run(
    fields.name, fields.code || '', fields.description || '', fields.repository || '',
    fields.documentation || '', fields.stack || '', fields.criticality || 'Importante',
    fields.leadId || null, fields.projectId || null, fields.status, id
  );
}

function removeService(id) {
  db.prepare('DELETE FROM app_services WHERE id = ?').run(id);
}

// ---------------------------------------------------------------- livraisons

function releases({ serviceId = null, limit = 100 } = {}) {
  const clause = serviceId ? 'WHERE r.service_id = ?' : '';
  const params = serviceId ? [serviceId, limit] : [limit];
  return db.prepare(`
    SELECT r.*, s.name AS service_name, s.code AS service_code,
           u.first_name, u.last_name, i.title AS incident_title
    FROM releases r
    JOIN app_services s ON s.id = r.service_id
    LEFT JOIN users u ON u.id = r.author_id
    LEFT JOIN it_incidents i ON i.id = r.incident_id
    ${clause}
    ORDER BY COALESCE(r.released_on, r.planned_on) DESC, r.id DESC LIMIT ?
  `).all(...params);
}

function releaseById(id) {
  return db.prepare('SELECT * FROM releases WHERE id = ?').get(Number(id) || 0) || null;
}

function createRelease(fields) {
  return db.prepare(`
    INSERT INTO releases (service_id, version, environment, planned_on, released_on, status, changelog, author_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.serviceId, fields.version, fields.environment, fields.plannedOn || null,
    fields.releasedOn || null, fields.status || 'Planifiée', fields.changelog || '', fields.authorId || null
  ).lastInsertRowid;
}

function updateRelease(id, fields) {
  db.prepare(`
    UPDATE releases
    SET version = ?, environment = ?, planned_on = ?, released_on = ?, status = ?, changelog = ?, incident_id = ?
    WHERE id = ?
  `).run(
    fields.version, fields.environment, fields.plannedOn || null, fields.releasedOn || null,
    fields.status, fields.changelog || '', fields.incidentId || null, id
  );
}

function removeRelease(id) {
  db.prepare('DELETE FROM releases WHERE id = ?').run(id);
}

/**
 * Indicateurs de livraison, sur la fenêtre demandée.
 *
 * Le taux d'échec compte les livraisons échouées et celles qu'il a fallu
 * retirer : une livraison annulée en catastrophe a coûté autant qu'une panne,
 * et l'oublier reviendrait à ne mesurer que les bons jours. Le délai de
 * rétablissement, lui, vient des incidents — il n'y a qu'une seule source de
 * vérité pour cette durée-là, et elle est du côté du service informatique.
 */
function deliveryStats({ days = 90 } = {}) {
  const since = new Date();
  since.setUTCDate(since.getUTCDate() - days);
  const from = since.toISOString().slice(0, 10);

  const rows = db.prepare(`
    SELECT status FROM releases
    WHERE environment = 'Production' AND status != 'Planifiée' AND released_on IS NOT NULL AND released_on >= ?
  `).all(from);

  const failed = rows.filter((r) => r.status === 'Échouée' || r.status === 'Retirée').length;
  const delivered = rows.filter((r) => r.status === 'Livrée').length;
  const planned = db.prepare("SELECT COUNT(*) AS n FROM releases WHERE status = 'Planifiée'").get().n;

  return {
    days,
    delivered,
    failed,
    planned,
    attempts: rows.length,
    // Livraisons par semaine, arrondi au dixième : « 0,4 » se lit mieux que « 5 sur 90 jours ».
    perWeek: rows.length ? Math.round((delivered / (days / 7)) * 10) / 10 : 0,
    failureRate: rows.length ? Math.round((failed / rows.length) * 100) : 0,
  };
}

function summary() {
  const live = db.prepare("SELECT COUNT(*) AS n FROM app_services WHERE status = 'En service'").get().n;
  const vital = db.prepare("SELECT COUNT(*) AS n FROM app_services WHERE status = 'En service' AND criticality = 'Vitale'").get().n;
  const orphan = db.prepare("SELECT COUNT(*) AS n FROM app_services WHERE status != 'Retiré' AND lead_id IS NULL").get().n;
  return { live, vital, orphan, delivery: deliveryStats() };
}

module.exports = {
  SERVICE_STATUSES,
  CRITICALITIES,
  ENVIRONMENTS,
  RELEASE_STATUSES,
  services,
  serviceById,
  createService,
  updateService,
  removeService,
  releases,
  releaseById,
  createRelease,
  updateRelease,
  removeRelease,
  deliveryStats,
  summary,
};
