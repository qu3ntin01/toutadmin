const db = require('./db');

/**
 * Flotte de véhicules.
 *
 * Un véhicule d'entreprise porte trois échéances qui ne pardonnent pas : le
 * contrôle technique, l'assurance et l'entretien. Elles sont donc des colonnes
 * du véhicule, pas des événements à retrouver dans un historique.
 */

const KINDS = ['Voiture', 'Utilitaire', 'Camion', 'Deux-roues', 'Engin'];
const STATUSES = ['En service', 'En réparation', 'Immobilisé', 'Cédé'];
const EVENT_KINDS = ['Entretien', 'Réparation', 'Contrôle technique', 'Sinistre', 'Carburant', 'Assurance'];

function vehicles({ includeDisposed = false } = {}) {
  const where = includeDisposed ? '' : "WHERE v.status != 'Cédé'";
  return db.prepare(`
    SELECT v.*, u.first_name, u.last_name,
           (SELECT COALESCE(SUM(cost), 0) FROM vehicle_events WHERE vehicle_id = v.id) AS total_cost
    FROM vehicles v LEFT JOIN users u ON u.id = v.assigned_to
    ${where}
    ORDER BY v.registration COLLATE NOCASE
  `).all();
}

function byId(id) {
  return db.prepare('SELECT * FROM vehicles WHERE id = ?').get(Number(id) || 0) || null;
}

function create(fields) {
  return db.prepare(`
    INSERT INTO vehicles (registration, brand, model, kind, acquired_on, mileage, assigned_to, insurance_due, inspection_due, service_due, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.registration, fields.brand || '', fields.model || '', fields.kind,
    fields.acquiredOn || null, fields.mileage || 0, fields.assignedTo || null,
    fields.insuranceDue || null, fields.inspectionDue || null, fields.serviceDue || null,
    fields.status || 'En service'
  ).lastInsertRowid;
}

function update(id, fields) {
  db.prepare(`
    UPDATE vehicles SET brand = ?, model = ?, kind = ?, acquired_on = ?, mileage = ?, assigned_to = ?,
           insurance_due = ?, inspection_due = ?, service_due = ?, status = ? WHERE id = ?
  `).run(
    fields.brand || '', fields.model || '', fields.kind, fields.acquiredOn || null,
    fields.mileage || 0, fields.assignedTo || null, fields.insuranceDue || null,
    fields.inspectionDue || null, fields.serviceDue || null, fields.status, id
  );
}

function remove(id) {
  db.prepare('DELETE FROM vehicles WHERE id = ?').run(id);
}

function events(vehicleId) {
  return db.prepare('SELECT * FROM vehicle_events WHERE vehicle_id = ? ORDER BY occurred_on DESC, id DESC').all(vehicleId);
}

/**
 * Un événement peut faire avancer le compteur : le kilométrage du véhicule suit
 * le relevé le plus élevé, jamais un chiffre inférieur saisi par erreur.
 */
function addEvent({ vehicleId, kind, occurredOn, mileage, cost, note }) {
  const id = db.prepare(`
    INSERT INTO vehicle_events (vehicle_id, kind, occurred_on, mileage, cost, note)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(vehicleId, kind, occurredOn, mileage || null, cost == null ? null : cost, note || '').lastInsertRowid;

  if (mileage) {
    db.prepare('UPDATE vehicles SET mileage = MAX(mileage, ?) WHERE id = ?').run(mileage, vehicleId);
  }
  return id;
}

function deleteEvent(id) {
  const row = db.prepare('SELECT vehicle_id FROM vehicle_events WHERE id = ?').get(id);
  db.prepare('DELETE FROM vehicle_events WHERE id = ?').run(id);
  return row ? row.vehicle_id : null;
}

/** Les échéances : ce qui est dépassé d'abord, puis ce qui approche. */
function deadlines(withinDays = 60) {
  const window = `+${Number(withinDays) || 60} days`;
  const rows = db.prepare(`
    SELECT * FROM vehicles WHERE status != 'Cédé' AND (
      (insurance_due IS NOT NULL AND insurance_due <= date('now', ?)) OR
      (inspection_due IS NOT NULL AND inspection_due <= date('now', ?)) OR
      (service_due IS NOT NULL AND service_due <= date('now', ?))
    )
  `).all(window, window, window);

  const today = new Date().toISOString().slice(0, 10);
  const out = [];
  for (const vehicle of rows) {
    for (const [field, label] of [['insurance_due', 'Assurance'], ['inspection_due', 'Contrôle technique'], ['service_due', 'Entretien']]) {
      const due = vehicle[field];
      if (!due) continue;
      out.push({ vehicle, label, due, overdue: due < today });
    }
  }
  return out.filter((row) => row.due <= addDays(today, withinDays)).sort((a, b) => a.due.localeCompare(b.due));
}

function addDays(iso, days) {
  const date = new Date(`${iso}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function summary() {
  const active = db.prepare("SELECT COUNT(*) AS n FROM vehicles WHERE status != 'Cédé'").get().n;
  const unassigned = db.prepare("SELECT COUNT(*) AS n FROM vehicles WHERE status = 'En service' AND assigned_to IS NULL").get().n;
  const cost = db.prepare("SELECT COALESCE(SUM(cost), 0) AS total FROM vehicle_events WHERE occurred_on >= date('now', '-1 year')").get().total;
  return {
    active,
    unassigned,
    cost: Math.round(cost * 100) / 100,
    overdue: deadlines().filter((d) => d.overdue).length,
  };
}

module.exports = { KINDS, STATUSES, EVENT_KINDS, vehicles, byId, create, update, remove, events, addEvent, deleteEvent, deadlines, summary };
