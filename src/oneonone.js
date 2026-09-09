const db = require('./db');
const org = require('./org');

/**
 * Points individuels entre un manager et ses collaborateurs.
 *
 * Deux comptes rendus par point, et c'est tout l'intérêt : le résumé partagé,
 * que les deux relisent avant le suivant, et les notes du manager, qui ne
 * sortent jamais de son écran. Confondre les deux, c'est soit un manager qui
 * n'écrit rien de franc, soit un collaborateur qui découvre ce qu'on pense de
 * lui dans un export.
 *
 * Le périmètre est vérifié à l'écriture, pas seulement à l'affichage : un
 * changement d'équipe doit fermer l'accès sans attendre un rechargement.
 */

const STATUSES = ['Planifié', 'Tenu', 'Annulé'];

// Ce que le collaborateur voit de son côté : jamais private_note.
const SHARED_COLUMNS = 'id, manager_id, employee_id, scheduled_on, held_on, topics, shared_note, next_on, status';

function manages(managerId, employeeId) {
  return org.membersManagedBy(managerId).some((member) => member.id === Number(employeeId));
}

function byId(id) {
  return db.prepare('SELECT * FROM one_on_ones WHERE id = ?').get(Number(id) || 0) || null;
}

/** Un point n'est accessible qu'au manager qui l'a inscrit, et seulement tant qu'il encadre la personne. */
function ownedBy(id, managerId) {
  const point = byId(id);
  if (!point || point.manager_id !== managerId) return null;
  return manages(managerId, point.employee_id) ? point : null;
}

function forManager(managerId, { limit = 100 } = {}) {
  return db.prepare(`
    SELECT o.*, u.first_name, u.last_name, u.grade
    FROM one_on_ones o JOIN users u ON u.id = o.employee_id
    WHERE o.manager_id = ?
    ORDER BY o.status = 'Tenu', o.scheduled_on DESC, o.id DESC
    LIMIT ?
  `).all(managerId, limit);
}

/** Le fil d'un collaborateur, amputé des notes privées de son manager. */
function forEmployee(employeeId, { limit = 30 } = {}) {
  return db.prepare(`
    SELECT ${SHARED_COLUMNS.split(', ').map((c) => `o.${c}`).join(', ')},
           u.first_name AS manager_first_name, u.last_name AS manager_last_name
    FROM one_on_ones o LEFT JOIN users u ON u.id = o.manager_id
    WHERE o.employee_id = ? AND o.status != 'Annulé'
    ORDER BY o.scheduled_on DESC, o.id DESC LIMIT ?
  `).all(employeeId, limit);
}

/**
 * Le dernier point tenu et le prochain prévu, par collaborateur : c'est ce que
 * le manager regarde pour savoir qui il n'a pas vu depuis trop longtemps.
 */
function cadence(managerId) {
  return db.prepare(`
    SELECT u.id AS employee_id, u.first_name, u.last_name,
      (SELECT MAX(held_on) FROM one_on_ones o
        WHERE o.manager_id = ? AND o.employee_id = u.id AND o.status = 'Tenu') AS last_held,
      (SELECT MIN(scheduled_on) FROM one_on_ones o
        WHERE o.manager_id = ? AND o.employee_id = u.id AND o.status = 'Planifié') AS next_on
    FROM users u WHERE u.id IN (${org.membersManagedBy(managerId).map(() => '?').join(',') || 'NULL'})
    ORDER BY last_held IS NOT NULL, last_held, u.last_name COLLATE NOCASE
  `).all(managerId, managerId, ...org.membersManagedBy(managerId).map((m) => m.id));
}

function create({ managerId, employeeId, scheduledOn, topics = '' }) {
  if (!manages(managerId, employeeId)) return { ok: false, reason: 'perimetre' };
  return {
    ok: true,
    id: db.prepare(`
      INSERT INTO one_on_ones (manager_id, employee_id, scheduled_on, topics)
      VALUES (?, ?, ?, ?)
    `).run(managerId, Number(employeeId), scheduledOn, topics.slice(0, 2000)).lastInsertRowid,
  };
}

function update(id, managerId, fields) {
  const point = ownedBy(id, managerId);
  if (!point) return false;

  db.prepare(`
    UPDATE one_on_ones
    SET scheduled_on = ?, held_on = ?, topics = ?, shared_note = ?, private_note = ?, mood = ?, next_on = ?, status = ?
    WHERE id = ? AND manager_id = ?
  `).run(
    fields.scheduledOn, fields.heldOn || null, (fields.topics || '').slice(0, 2000),
    (fields.sharedNote || '').slice(0, 4000), (fields.privateNote || '').slice(0, 4000),
    fields.mood == null ? null : fields.mood, fields.nextOn || null, fields.status, id, managerId
  );
  return true;
}

function remove(id, managerId) {
  const point = ownedBy(id, managerId);
  if (!point) return false;
  db.prepare('DELETE FROM one_on_ones WHERE id = ? AND manager_id = ?').run(id, managerId);
  return true;
}

function summary(managerId) {
  const rows = forManager(managerId, { limit: 500 });
  const today = new Date().toISOString().slice(0, 10);
  return {
    planned: rows.filter((r) => r.status === 'Planifié').length,
    overdue: rows.filter((r) => r.status === 'Planifié' && r.scheduled_on < today).length,
    held: rows.filter((r) => r.status === 'Tenu').length,
    neverMet: cadence(managerId).filter((row) => !row.last_held).length,
  };
}

module.exports = { STATUSES, manages, byId, ownedBy, forManager, forEmployee, cadence, create, update, remove, summary };
