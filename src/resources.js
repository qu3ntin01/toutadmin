const db = require('./db');

const ASSET_STATUSES = ['Disponible', 'Affecté', 'En maintenance', 'Réformé'];
const ASSET_CATEGORIES = ['Informatique', 'Téléphonie', 'Mobilier', 'Véhicule', 'Outillage', 'Autre'];

const TIME_PATTERN = /^([01]\d|2[0-3]):[0-5]\d$/;

// ---------- Parc matériel ----------

function assets() {
  return db.prepare(`
    SELECT a.*,
      u.id AS holder_id, u.first_name AS holder_first_name, u.last_name AS holder_last_name
    FROM assets a
    LEFT JOIN asset_assignments aa ON aa.asset_id = a.id AND aa.returned_at IS NULL
    LEFT JOIN users u ON u.id = aa.employee_id
    ORDER BY a.category COLLATE NOCASE, a.name COLLATE NOCASE
  `).all();
}

function assetById(id) {
  return db.prepare('SELECT * FROM assets WHERE id = ?').get(id) || null;
}

function createAsset(data) {
  return db.prepare(`
    INSERT INTO assets (name, category, reference, serial_number, purchase_date, warranty_end, value, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(data.name, data.category || '', data.reference || '', data.serialNumber || '',
         data.purchaseDate || null, data.warrantyEnd || null, data.value ?? null, data.notes || '').lastInsertRowid;
}

function setAssetStatus(id, status) {
  if (!ASSET_STATUSES.includes(status)) return { ok: false, reason: 'bad-status' };
  const asset = assetById(id);
  if (!asset) return { ok: false, reason: 'not-found' };

  // « Affecté » n'est pas un statut qu'on pose à la main : il découle d'une affectation.
  if (status === 'Affecté') return { ok: false, reason: 'assign-instead' };
  if (asset.status === 'Affecté') return { ok: false, reason: 'return-first' };

  db.prepare('UPDATE assets SET status = ? WHERE id = ?').run(status, id);
  return { ok: true };
}

function deleteAsset(id) {
  db.prepare('DELETE FROM assets WHERE id = ?').run(id);
}

function assignAsset({ assetId, employeeId, note }) {
  const asset = assetById(assetId);
  if (!asset) return { ok: false, reason: 'not-found' };
  if (asset.status === 'Réformé') return { ok: false, reason: 'retired' };

  const open = db.prepare('SELECT id FROM asset_assignments WHERE asset_id = ? AND returned_at IS NULL').get(assetId);
  if (open) return { ok: false, reason: 'already-assigned' };

  const employee = db.prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'").get(employeeId);
  if (!employee) return { ok: false, reason: 'no-employee' };

  const commit = db.transaction(() => {
    db.prepare('INSERT INTO asset_assignments (asset_id, employee_id, note) VALUES (?, ?, ?)').run(assetId, employeeId, note || '');
    db.prepare("UPDATE assets SET status = 'Affecté' WHERE id = ?").run(assetId);
  });
  commit();
  return { ok: true };
}

function returnAsset(assetId) {
  const open = db.prepare('SELECT id FROM asset_assignments WHERE asset_id = ? AND returned_at IS NULL').get(assetId);
  if (!open) return { ok: false, reason: 'not-assigned' };

  const commit = db.transaction(() => {
    db.prepare('UPDATE asset_assignments SET returned_at = date(\'now\') WHERE id = ?').run(open.id);
    db.prepare("UPDATE assets SET status = 'Disponible' WHERE id = ?").run(assetId);
  });
  commit();
  return { ok: true };
}

function assetsOf(employeeId) {
  return db.prepare(`
    SELECT a.*, aa.assigned_at, aa.note
    FROM asset_assignments aa JOIN assets a ON a.id = aa.asset_id
    WHERE aa.employee_id = ? AND aa.returned_at IS NULL
    ORDER BY a.name COLLATE NOCASE
  `).all(employeeId);
}

function assetHistory(assetId) {
  return db.prepare(`
    SELECT aa.*, u.first_name, u.last_name
    FROM asset_assignments aa JOIN users u ON u.id = aa.employee_id
    WHERE aa.asset_id = ? ORDER BY aa.assigned_at DESC
  `).all(assetId);
}

// ---------- Salles et réservations ----------

function rooms({ activeOnly = false } = {}) {
  const where = activeOnly ? 'WHERE active = 1' : '';
  return db.prepare(`SELECT * FROM rooms ${where} ORDER BY name COLLATE NOCASE`).all();
}

function roomById(id) {
  return db.prepare('SELECT * FROM rooms WHERE id = ?').get(id) || null;
}

function createRoom(data) {
  return db.prepare('INSERT INTO rooms (name, location, capacity, equipment) VALUES (?, ?, ?, ?)')
    .run(data.name, data.location || '', data.capacity || 0, data.equipment || '').lastInsertRowid;
}

function toggleRoom(id) {
  const room = roomById(id);
  if (!room) return false;
  db.prepare('UPDATE rooms SET active = ? WHERE id = ?').run(room.active ? 0 : 1, id);
  return true;
}

function deleteRoom(id) {
  db.prepare('DELETE FROM rooms WHERE id = ?').run(id);
}

function bookings({ from, to, roomId } = {}) {
  const clauses = [];
  const params = [];
  if (from) { clauses.push('b.booking_date >= ?'); params.push(from); }
  if (to) { clauses.push('b.booking_date <= ?'); params.push(to); }
  if (roomId) { clauses.push('b.room_id = ?'); params.push(roomId); }

  return db.prepare(`
    SELECT b.*, r.name AS room_name, r.location, u.first_name, u.last_name
    FROM room_bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN users u ON u.id = b.user_id
    ${clauses.length ? 'WHERE ' + clauses.join(' AND ') : ''}
    ORDER BY b.booking_date, b.start_time
  `).all(...params);
}

/** Deux réservations se chevauchent dès qu'elles partagent un instant de la même salle. */
function bookRoom({ roomId, userId, title, bookingDate, startTime, endTime }) {
  const room = roomById(roomId);
  if (!room) return { ok: false, reason: 'not-found' };
  if (!room.active) return { ok: false, reason: 'inactive' };
  if (!TIME_PATTERN.test(startTime) || !TIME_PATTERN.test(endTime)) return { ok: false, reason: 'bad-time' };
  if (endTime <= startTime) return { ok: false, reason: 'bad-range' };

  const clash = db.prepare(`
    SELECT b.id, u.first_name, u.last_name, b.start_time, b.end_time
    FROM room_bookings b JOIN users u ON u.id = b.user_id
    WHERE b.room_id = ? AND b.booking_date = ? AND b.start_time < ? AND b.end_time > ?
    LIMIT 1
  `).get(roomId, bookingDate, endTime, startTime);
  if (clash) return { ok: false, reason: 'clash', clash };

  db.prepare('INSERT INTO room_bookings (room_id, user_id, title, booking_date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)')
    .run(roomId, userId, title, bookingDate, startTime, endTime);
  return { ok: true };
}

/** Chacun annule sa réservation ; la gestion peut annuler n'importe laquelle. */
function cancelBooking(id, userId, { force = false } = {}) {
  const query = force
    ? db.prepare('DELETE FROM room_bookings WHERE id = ?').run(id)
    : db.prepare('DELETE FROM room_bookings WHERE id = ? AND user_id = ?').run(id, userId);
  return query.changes > 0;
}

module.exports = {
  ASSET_STATUSES,
  ASSET_CATEGORIES,
  assets,
  assetById,
  createAsset,
  setAssetStatus,
  deleteAsset,
  assignAsset,
  returnAsset,
  assetsOf,
  assetHistory,
  rooms,
  roomById,
  createRoom,
  toggleRoom,
  deleteRoom,
  bookings,
  bookRoom,
  cancelBooking,
};
