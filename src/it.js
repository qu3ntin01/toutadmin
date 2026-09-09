const db = require('./db');

/**
 * Service informatique : le parc logiciel, les accès qu'il ouvre, et les
 * incidents du système d'information.
 *
 * Un logiciel n'est pas un équipement. Il n'a pas de numéro de série à coller
 * sur un capot : il a des sièges qu'on paie, une date de renouvellement qui
 * tombe, et une liste de personnes qui entrent dedans. Ces trois choses-là
 * échappent aux inventaires classiques, et ce sont exactement celles qui
 * coûtent — en argent quand un abonnement se reconduit pour rien, en risque
 * quand un compte reste ouvert après un départ.
 */

const LICENCE_KINDS = ['Abonnement', 'Licence perpétuelle', 'Logiciel libre', 'Développement interne'];
const CRITICALITIES = ['Vitale', 'Importante', 'Secondaire'];
const LICENCE_STATUSES = ['Actif', 'En test', 'Retiré'];
const ACCESS_LEVELS = ['Utilisateur', 'Gestionnaire', 'Administrateur'];
const SEVERITIES = ['Critique', 'Majeur', 'Mineur'];
const INCIDENT_STATUSES = ['Ouvert', 'En cours', 'Résolu', 'Clos'];

// Au-delà, un accès n'a plus été regardé depuis assez longtemps pour qu'on
// puisse dire qu'il est encore justifié.
const REVIEW_MONTHS = 12;

// ---------------------------------------------------------------- logiciels

const LICENCE_COLUMNS = `
  l.*,
  u.first_name AS owner_first_name, u.last_name AS owner_last_name,
  (SELECT COUNT(*) FROM software_accesses a WHERE a.licence_id = l.id AND a.revoked_on IS NULL) AS seats_used
`;

function licences({ includeRetired = false } = {}) {
  const where = includeRetired ? '' : "WHERE l.status != 'Retiré'";
  return db.prepare(`
    SELECT ${LICENCE_COLUMNS}
    FROM software_licences l LEFT JOIN users u ON u.id = l.owner_id
    ${where}
    ORDER BY l.name COLLATE NOCASE
  `).all();
}

function licenceById(id) {
  return db.prepare(`
    SELECT ${LICENCE_COLUMNS}
    FROM software_licences l LEFT JOIN users u ON u.id = l.owner_id
    WHERE l.id = ?
  `).get(Number(id) || 0) || null;
}

function createLicence(fields) {
  return db.prepare(`
    INSERT INTO software_licences (name, publisher, kind, seats, unit_cost, billing_period, renewal_date, owner_id, criticality, personal_data, status, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.name, fields.publisher || '', fields.kind, fields.seats || 0,
    fields.unitCost == null ? null : fields.unitCost, fields.billingPeriod || 'Annuel',
    fields.renewalDate || null, fields.ownerId || null, fields.criticality || 'Importante',
    fields.personalData ? 1 : 0, fields.status || 'Actif', fields.notes || ''
  ).lastInsertRowid;
}

function updateLicence(id, fields) {
  db.prepare(`
    UPDATE software_licences
    SET name = ?, publisher = ?, kind = ?, seats = ?, unit_cost = ?, billing_period = ?,
        renewal_date = ?, owner_id = ?, criticality = ?, personal_data = ?, status = ?, notes = ?
    WHERE id = ?
  `).run(
    fields.name, fields.publisher || '', fields.kind, fields.seats || 0,
    fields.unitCost == null ? null : fields.unitCost, fields.billingPeriod || 'Annuel',
    fields.renewalDate || null, fields.ownerId || null, fields.criticality || 'Importante',
    fields.personalData ? 1 : 0, fields.status, fields.notes || '', id
  );
}

function removeLicence(id) {
  db.prepare('DELETE FROM software_licences WHERE id = ?').run(id);
}

/** Le coût annualisé d'un abonnement, tous sièges confondus. */
function yearlyCost(licence) {
  if (licence.unit_cost == null) return null;
  const perYear = { Ponctuel: 0, Mensuel: 12, Trimestriel: 4, Annuel: 1 }[licence.billing_period];
  if (perYear === undefined) return null;
  const seats = licence.seats > 0 ? licence.seats : Math.max(licence.seats_used || 0, 1);
  return Math.round(licence.unit_cost * perYear * seats * 100) / 100;
}

// ---------------------------------------------------------------- accès

function accesses(licenceId, { includeRevoked = true } = {}) {
  const clause = includeRevoked ? '' : 'AND a.revoked_on IS NULL';
  return db.prepare(`
    SELECT a.*, u.first_name, u.last_name, u.email, u.active,
           g.first_name AS by_first_name, g.last_name AS by_last_name
    FROM software_accesses a
    JOIN users u ON u.id = a.user_id
    LEFT JOIN users g ON g.id = a.granted_by
    WHERE a.licence_id = ? ${clause}
    ORDER BY a.revoked_on IS NOT NULL, u.last_name COLLATE NOCASE
  `).all(licenceId);
}

/** Les logiciels ouverts à une personne : ce qu'un départ laisse derrière lui. */
function accessesFor(userId) {
  return db.prepare(`
    SELECT a.*, l.name, l.criticality
    FROM software_accesses a JOIN software_licences l ON l.id = a.licence_id
    WHERE a.user_id = ? AND a.revoked_on IS NULL
    ORDER BY l.name COLLATE NOCASE
  `).all(userId);
}

/**
 * Ouvre un accès. Le nombre de sièges est une contrainte, pas une indication :
 * dépasser ce qui est payé met l'entreprise en défaut de licence, et personne
 * ne s'en aperçoit avant l'audit de l'éditeur.
 */
function grantAccess({ licenceId, userId, level = 'Utilisateur', grantedBy = null, note = '' }) {
  const licence = licenceById(licenceId);
  if (!licence) return { ok: false, reason: 'introuvable' };
  if (db.prepare('SELECT 1 FROM software_accesses WHERE licence_id = ? AND user_id = ? AND revoked_on IS NULL').get(licenceId, userId)) {
    return { ok: false, reason: 'deja' };
  }
  if (licence.seats > 0 && licence.seats_used >= licence.seats) {
    return { ok: false, reason: 'complet', seats: licence.seats };
  }

  const id = db.prepare(`
    INSERT INTO software_accesses (licence_id, user_id, level, granted_by, note)
    VALUES (?, ?, ?, ?, ?)
  `).run(licenceId, userId, level, grantedBy, note.slice(0, 300)).lastInsertRowid;
  return { ok: true, id };
}

function revokeAccess(id) {
  return db.prepare("UPDATE software_accesses SET revoked_on = date('now') WHERE id = ? AND revoked_on IS NULL").run(id).changes > 0;
}

/** Marquer un accès revu, c'est dire « je l'ai regardé, il est encore justifié ». */
function markReviewed(id) {
  return db.prepare("UPDATE software_accesses SET reviewed_on = date('now') WHERE id = ? AND revoked_on IS NULL").run(id).changes > 0;
}

/**
 * La revue des accès. Elle ne juge pas à la place de l'exploitant : elle
 * remonte ce qui mérite un regard, avec le motif. Un compte fermé qui garde un
 * accès applicatif est le premier de la liste — c'est la faille la plus banale
 * et la plus exploitée : le départ a été traité côté RH, jamais côté SI.
 */
function accessReview() {
  const rows = db.prepare(`
    SELECT a.id, a.level, a.granted_on, a.reviewed_on, a.licence_id,
           l.name AS licence_name, l.criticality,
           u.id AS user_id, u.first_name, u.last_name, u.active, u.contract_end_date
    FROM software_accesses a
    JOIN software_licences l ON l.id = a.licence_id
    JOIN users u ON u.id = a.user_id
    WHERE a.revoked_on IS NULL
    ORDER BY l.name COLLATE NOCASE, u.last_name COLLATE NOCASE
  `).all();

  const stale = new Date();
  stale.setUTCMonth(stale.getUTCMonth() - REVIEW_MONTHS);
  const staleBefore = stale.toISOString().slice(0, 10);

  const flagged = [];
  for (const row of rows) {
    // Un compte désactivé qui conserve un accès : à révoquer, sans discussion.
    if (!row.active) flagged.push({ ...row, reason: 'inactif', severity: 'Critique' });
    else if (row.level === 'Administrateur') flagged.push({ ...row, reason: 'admin', severity: 'Majeur' });
    else if ((row.reviewed_on || row.granted_on) < staleBefore) flagged.push({ ...row, reason: 'ancien', severity: 'Mineur' });
  }
  return { total: rows.length, flagged };
}

// ---------------------------------------------------------------- incidents

function incidents({ includeClosed = true, limit = 200 } = {}) {
  const clause = includeClosed ? '' : "WHERE i.status NOT IN ('Résolu','Clos')";
  return db.prepare(`
    SELECT i.*, s.name AS service_name, u.first_name, u.last_name
    FROM it_incidents i
    LEFT JOIN app_services s ON s.id = i.service_id
    LEFT JOIN users u ON u.id = i.declared_by
    ${clause}
    ORDER BY i.started_at DESC, i.id DESC LIMIT ?
  `).all(limit);
}

function incidentById(id) {
  return db.prepare('SELECT * FROM it_incidents WHERE id = ?').get(Number(id) || 0) || null;
}

function createIncident(fields) {
  return db.prepare(`
    INSERT INTO it_incidents (reference, title, service_id, severity, started_at, detected_at, impact, declared_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.reference || '', fields.title, fields.serviceId || null, fields.severity,
    fields.startedAt, fields.detectedAt || null, fields.impact || '', fields.declaredBy || null
  ).lastInsertRowid;
}

function updateIncident(id, fields) {
  db.prepare(`
    UPDATE it_incidents
    SET title = ?, service_id = ?, severity = ?, started_at = ?, detected_at = ?, resolved_at = ?,
        impact = ?, cause = ?, remediation = ?, status = ?
    WHERE id = ?
  `).run(
    fields.title, fields.serviceId || null, fields.severity, fields.startedAt,
    fields.detectedAt || null, fields.resolvedAt || null, fields.impact || '',
    fields.cause || '', fields.remediation || '', fields.status, id
  );
}

function removeIncident(id) {
  db.prepare('DELETE FROM it_incidents WHERE id = ?').run(id);
}

/** Durée d'un incident en minutes, ou null tant qu'il n'est pas rétabli. */
function downtimeMinutes(incident) {
  if (!incident.resolved_at || !incident.started_at) return null;
  const start = Date.parse(`${incident.started_at.replace(' ', 'T')}Z`);
  const end = Date.parse(`${incident.resolved_at.replace(' ', 'T')}Z`);
  if (Number.isNaN(start) || Number.isNaN(end) || end < start) return null;
  return Math.round((end - start) / 60000);
}

/**
 * Le délai moyen de rétablissement, sur la fenêtre demandée. Calculé sur les
 * incidents effectivement rétablis : un incident encore ouvert n'a pas de durée,
 * et le compter à zéro flatterait l'indicateur au pire moment.
 */
function incidentStats({ days = 90 } = {}) {
  const since = new Date();
  since.setUTCDate(since.getUTCDate() - days);
  const rows = db.prepare('SELECT * FROM it_incidents WHERE started_at >= ?').all(since.toISOString().slice(0, 10));

  const durations = rows.map(downtimeMinutes).filter((d) => d != null);
  const open = db.prepare("SELECT COUNT(*) AS n FROM it_incidents WHERE status NOT IN ('Résolu','Clos')").get().n;
  return {
    days,
    total: rows.length,
    open,
    critical: rows.filter((r) => r.severity === 'Critique').length,
    resolved: durations.length,
    meanMinutes: durations.length ? Math.round(durations.reduce((a, b) => a + b, 0) / durations.length) : null,
  };
}

function summary() {
  const active = db.prepare("SELECT COUNT(*) AS n FROM software_licences WHERE status != 'Retiré'").get().n;
  const openAccesses = db.prepare('SELECT COUNT(*) AS n FROM software_accesses WHERE revoked_on IS NULL').get().n;
  const cost = licences().reduce((sum, l) => sum + (yearlyCost(l) || 0), 0);
  return {
    licences: active,
    accesses: openAccesses,
    flagged: accessReview().flagged.length,
    yearlyCost: Math.round(cost * 100) / 100,
    incidents: incidentStats(),
  };
}

module.exports = {
  LICENCE_KINDS,
  CRITICALITIES,
  LICENCE_STATUSES,
  ACCESS_LEVELS,
  SEVERITIES,
  INCIDENT_STATUSES,
  REVIEW_MONTHS,
  licences,
  licenceById,
  createLicence,
  updateLicence,
  removeLicence,
  yearlyCost,
  accesses,
  accessesFor,
  grantAccess,
  revokeAccess,
  markReviewed,
  accessReview,
  incidents,
  incidentById,
  createIncident,
  updateIncident,
  removeIncident,
  downtimeMinutes,
  incidentStats,
  summary,
};
