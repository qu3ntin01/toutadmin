const db = require('./db');
const { addMonths } = require('./utils');
const horloge = require('./horloge');
const societes = require('./societes');

/** Le périmètre courant : une visite médicale est suivie par la société qui l'organise. */
const ici = () => societes.courant();

/**
 * Santé et sécurité au travail.
 *
 * Trois obligations distinctes, tenues ensemble parce qu'elles se répondent :
 * l'évaluation des risques (le document unique), le registre des accidents, et
 * le suivi de ce qui protège — équipements de protection et visites médicales.
 *
 * Le CMS tient le registre ; il ne remplace ni la déclaration à la caisse
 * d'assurance maladie, ni l'avis du médecin du travail.
 */

const INCIDENT_KINDS = ['Accident du travail', 'Accident de trajet', 'Presque-accident', 'Maladie professionnelle'];
const VISIT_KINDS = ['Visite d\'embauche', 'Visite périodique', 'Visite de reprise', 'Visite à la demande'];
const SEVERITIES = [1, 2, 3, 4];
const LIKELIHOODS = [1, 2, 3, 4];

// Au-delà de ce produit gravité × probabilité, le risque appelle une action.
const ACTION_THRESHOLD = 6;

// ---------- Évaluation des risques ----------

function riskScore(row) {
  return Number(row.severity || 0) * Number(row.likelihood || 0);
}

function risks() {
  return db.prepare(`
    SELECT * FROM risk_assessments WHERE company_id = ?
    ORDER BY unit COLLATE NOCASE, hazard COLLATE NOCASE
  `).all(ici())
    .map((row) => ({ ...row, score: riskScore(row), critical: riskScore(row) >= ACTION_THRESHOLD }))
    .sort((a, b) => b.score - a.score);
}

function createRisk({ unit, hazard, exposure, severity, likelihood, measures, reviewedOn, nextReview }) {
  return db.prepare(`
    INSERT INTO risk_assessments (company_id, unit, hazard, exposure, severity, likelihood, measures, reviewed_on, next_review)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), unit, hazard, exposure || '', severity, likelihood, measures || '', reviewedOn || null, nextReview || null).lastInsertRowid;
}

function updateRisk(id, fields) {
  db.prepare(`
    UPDATE risk_assessments SET unit = ?, hazard = ?, exposure = ?, severity = ?, likelihood = ?,
           measures = ?, reviewed_on = ?, next_review = ? WHERE id = ? AND company_id = ?
  `).run(fields.unit, fields.hazard, fields.exposure || '', fields.severity, fields.likelihood,
    fields.measures || '', fields.reviewedOn || null, fields.nextReview || null, id, ici());
}

function deleteRisk(id) {
  db.prepare('DELETE FROM risk_assessments WHERE id = ? AND company_id = ?').run(id, ici());
}

function riskById(id) {
  return db.prepare('SELECT * FROM risk_assessments WHERE id = ? AND company_id = ?')
    .get(Number(id) || 0, ici()) || null;
}

// ---------- Registre des accidents ----------

function incidents({ limit = 200 } = {}) {
  return db.prepare(`
    SELECT i.*, u.first_name, u.last_name FROM workplace_incidents i
    LEFT JOIN users u ON u.id = i.user_id
    WHERE i.company_id = ?
    ORDER BY i.occurred_on DESC, i.id DESC LIMIT ?
  `).all(ici(), limit);
}

function createIncident({ occurredOn, userId, kind, location, description, daysOff, declaredOn, followUp }) {
  return db.prepare(`
    INSERT INTO workplace_incidents (company_id, occurred_on, user_id, kind, location, description, days_off, declared_on, follow_up)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), occurredOn, userId || null, kind, location || '', description || '', daysOff || 0, declaredOn || null, followUp || '').lastInsertRowid;
}

function deleteIncident(id) {
  return db.prepare('DELETE FROM workplace_incidents WHERE id = ? AND company_id = ?')
    .run(id, ici()).changes > 0;
}

/**
 * Indicateurs réglementaires sur douze mois : taux de fréquence et de gravité.
 * Les heures travaillées sont estimées à partir de l'effectif — la formule
 * légale les demande, et personne ne les saisit à la main.
 */
function indicators({ hoursPerYear = 1607 } = {}) {
  const headcount = db.prepare("SELECT COUNT(*) AS n FROM users WHERE active = 1 AND role = 'employee'").get().n;
  const worked = headcount * hoursPerYear;

  const rows = db.prepare(`
    SELECT COUNT(*) AS n, COALESCE(SUM(days_off), 0) AS days
    FROM workplace_incidents
    WHERE company_id = ? AND kind IN ('Accident du travail', 'Accident de trajet')
      AND days_off > 0 AND occurred_on >= date('now', '-1 year')
  `).get(ici());

  return {
    headcount,
    worked,
    accidents: rows.n,
    daysOff: rows.days,
    // Fréquence : accidents avec arrêt par million d'heures travaillées.
    frequency: worked ? Math.round((rows.n * 1e6 / worked) * 100) / 100 : 0,
    // Gravité : journées perdues par millier d'heures travaillées.
    severity: worked ? Math.round((rows.days * 1e3 / worked) * 100) / 100 : 0,
  };
}

// ---------- Équipements de protection ----------

function ppeItems() {
  return db.prepare(`
    SELECT * FROM ppe_items WHERE company_id = ?
    ORDER BY category COLLATE NOCASE, name COLLATE NOCASE
  `).all(ici());
}

function createPpe({ name, category, validityMonths }) {
  return db.prepare('INSERT INTO ppe_items (company_id, name, category, validity_months) VALUES (?, ?, ?, ?)')
    .run(ici(), name, category, validityMonths || null).lastInsertRowid;
}

function deletePpe(id) {
  return db.prepare('DELETE FROM ppe_items WHERE id = ? AND company_id = ?').run(id, ici()).changes > 0;
}

function issuePpe({ ppeId, userId, issuedOn }) {
  const item = db.prepare('SELECT * FROM ppe_items WHERE id = ? AND company_id = ?').get(ppeId, ici());
  if (!item) return null;

  const expires = item.validity_months && issuedOn ? addMonths(issuedOn, item.validity_months) : null;
  return db.prepare('INSERT INTO ppe_assignments (ppe_id, user_id, issued_on, expires_on) VALUES (?, ?, ?, ?)')
    .run(ppeId, userId, issuedOn, expires).lastInsertRowid;
}

function returnPpe(id) {
  db.prepare(`
    UPDATE ppe_assignments SET returned_on = date('now')
    WHERE id = ? AND returned_on IS NULL
      AND ppe_id IN (SELECT id FROM ppe_items WHERE company_id = ?)
  `).run(id, ici());
}

function ppeAssignments({ activeOnly = true } = {}) {
  // Une dotation n'a pas de périmètre propre : elle le tient de sa protection.
  const clause = activeOnly ? 'AND a.returned_on IS NULL' : '';
  return db.prepare(`
    SELECT a.*, p.name, p.category, u.first_name, u.last_name
    FROM ppe_assignments a
    JOIN ppe_items p ON p.id = a.ppe_id
    JOIN users u ON u.id = a.user_id
    WHERE p.company_id = ? ${clause}
    ORDER BY a.expires_on IS NULL, a.expires_on
  `).all(ici());
}

// ---------- Visites médicales ----------

function visits({ userId = null } = {}) {
  const clause = userId ? 'AND v.user_id = ?' : '';
  const params = userId ? [ici(), userId] : [ici()];
  return db.prepare(`
    SELECT v.*, u.first_name, u.last_name FROM medical_visits v
    JOIN users u ON u.id = v.user_id
    WHERE v.company_id = ? ${clause}
    ORDER BY v.next_due IS NULL, v.next_due, v.scheduled_on DESC
  `).all(...params);
}

function createVisit({ userId, kind, scheduledOn, doneOn, verdict, nextDue }) {
  return db.prepare(`
    INSERT INTO medical_visits (company_id, user_id, kind, scheduled_on, done_on, verdict, next_due)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), userId, kind, scheduledOn || null, doneOn || null, verdict || '', nextDue || null).lastInsertRowid;
}

function deleteVisit(id) {
  return db.prepare('DELETE FROM medical_visits WHERE id = ? AND company_id = ?').run(id, ici()).changes > 0;
}

/** Ce qui arrive à échéance : visites dues, protections périmées. */
function upcoming(withinDays = 60) {
  const limite = horloge.dansJours(Number(withinDays) || 60);
  return {
    visits: db.prepare(`
      SELECT v.*, u.first_name, u.last_name FROM medical_visits v JOIN users u ON u.id = v.user_id
      WHERE v.company_id = ? AND v.next_due IS NOT NULL AND v.next_due <= ? AND u.active = 1
      ORDER BY v.next_due
    `).all(ici(), limite),
    ppe: db.prepare(`
      SELECT a.*, p.name, u.first_name, u.last_name FROM ppe_assignments a
      JOIN ppe_items p ON p.id = a.ppe_id JOIN users u ON u.id = a.user_id
      WHERE p.company_id = ? AND a.returned_on IS NULL
        AND a.expires_on IS NOT NULL AND a.expires_on <= ?
      ORDER BY a.expires_on
    `).all(ici(), limite),
    risks: db.prepare(`
      SELECT * FROM risk_assessments
      WHERE company_id = ? AND next_review IS NOT NULL AND next_review <= ?
      ORDER BY next_review
    `).all(ici(), limite),
  };
}

module.exports = {
  INCIDENT_KINDS, VISIT_KINDS, SEVERITIES, LIKELIHOODS, ACTION_THRESHOLD,
  riskScore, risks, createRisk, updateRisk, deleteRisk, riskById,
  incidents, createIncident, deleteIncident, indicators,
  ppeItems, createPpe, deletePpe, issuePpe, returnPpe, ppeAssignments,
  visits, createVisit, deleteVisit, upcoming,
};
