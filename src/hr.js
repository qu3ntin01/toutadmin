const db = require('./db');
const requestTypes = require('./request-types');

const DEFAULT_ANNUAL_LEAVE = 25;

function isEligibleForHrFeatures(employee) {
  return employee.role === 'employee' && employee.contract_type !== 'Freelance';
}

function countBusinessDays(startStr, endStr) {
  const start = new Date(`${startStr}T00:00:00Z`);
  const end = new Date(`${endStr}T00:00:00Z`);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) return null;

  let count = 0;
  const cur = new Date(start);
  while (cur.getTime() <= end.getTime()) {
    const day = cur.getUTCDay();
    if (day !== 0 && day !== 6) count++;
    cur.setUTCDate(cur.getUTCDate() + 1);
  }
  return count;
}

// ---------- Demandes ----------

function createRequest({ employeeId, type, startDate, endDate, days, reason }) {
  return db
    .prepare('INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, reason) VALUES (?, ?, ?, ?, ?, ?)')
    .run(employeeId, type, startDate, endDate, days, reason).lastInsertRowid;
}

function getRequestsForEmployee(employeeId, limit = 100) {
  return db.prepare('SELECT * FROM hr_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?').all(employeeId, limit);
}

function getRequestById(id) {
  return db.prepare('SELECT * FROM hr_requests WHERE id = ?').get(id);
}

function getAllRequests({ status } = {}) {
  const rows = status
    ? db.prepare(`
        SELECT r.*, u.first_name, u.last_name, u.email
        FROM hr_requests r JOIN users u ON u.id = r.employee_id
        WHERE r.status = ?
        ORDER BY r.created_at DESC
      `).all(status)
    : db.prepare(`
        SELECT r.*, u.first_name, u.last_name, u.email
        FROM hr_requests r JOIN users u ON u.id = r.employee_id
        ORDER BY r.created_at DESC
      `).all();
  return rows;
}

function approveRequest(id, reviewerId, note) {
  const request = getRequestById(id);
  if (!request || request.status !== 'En attente') return { ok: false, reason: 'not-pending' };

  if (requestTypes.affectsBalance(request.type)) {
    db.prepare('UPDATE users SET leave_balance = leave_balance - ? WHERE id = ?').run(request.days, request.employee_id);
  }

  db.prepare("UPDATE hr_requests SET status = 'Approuvée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?")
    .run(reviewerId, note || '', new Date().toISOString(), id);

  // Les outils de planification ont besoin de le savoir tout de suite : le
  // motif, lui, ne sort pas de l'entreprise.
  require('./webhooks').emit('absence.approuvee', {
    id, type: request.type, du: request.start_date, au: request.end_date, jours: request.days,
    personne: `${request.first_name} ${request.last_name}`,
  });

  return { ok: true };
}

function rejectRequest(id, reviewerId, note) {
  const request = getRequestById(id);
  if (!request || request.status !== 'En attente') return { ok: false, reason: 'not-pending' };

  db.prepare("UPDATE hr_requests SET status = 'Refusée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?")
    .run(reviewerId, note || '', new Date().toISOString(), id);

  return { ok: true };
}

// Annulation côté employé : uniquement tant que la demande est en attente.
function cancelOwnRequest(id, employeeId) {
  const request = getRequestById(id);
  if (!request || request.employee_id !== employeeId || request.status !== 'En attente') {
    return { ok: false, reason: 'not-cancellable' };
  }
  db.prepare("UPDATE hr_requests SET status = 'Annulée', reviewed_at = ? WHERE id = ?").run(new Date().toISOString(), id);
  return { ok: true };
}

// Annulation côté RH : possible même après approbation (rembourse le solde si nécessaire).
function revokeRequest(id, reviewerId, note) {
  const request = getRequestById(id);
  if (!request || !['En attente', 'Approuvée'].includes(request.status)) {
    return { ok: false, reason: 'not-revocable' };
  }

  if (request.status === 'Approuvée' && requestTypes.affectsBalance(request.type)) {
    db.prepare('UPDATE users SET leave_balance = leave_balance + ? WHERE id = ?').run(request.days, request.employee_id);
  }

  db.prepare("UPDATE hr_requests SET status = 'Annulée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?")
    .run(reviewerId, note || '', new Date().toISOString(), id);

  return { ok: true };
}

// ---------- Solde de congés ----------

function adjustBalance(employeeId, amount, reason, createdBy) {
  db.prepare('UPDATE users SET leave_balance = leave_balance + ? WHERE id = ?').run(amount, employeeId);
  db.prepare('INSERT INTO leave_adjustments (employee_id, amount, reason, created_by) VALUES (?, ?, ?, ?)').run(employeeId, amount, reason || '', createdBy);
}

function getAdjustments(employeeId, limit = 50) {
  return db.prepare('SELECT * FROM leave_adjustments WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?').all(employeeId, limit);
}

// ---------- Fiches de paie ----------

function createPayslip({ employeeId, period, grossAmount, netAmount, note, createdBy }) {
  return db
    .prepare('INSERT INTO payslips (employee_id, period, gross_amount, net_amount, note, created_by) VALUES (?, ?, ?, ?, ?, ?)')
    .run(employeeId, period, grossAmount, netAmount, note || '', createdBy).lastInsertRowid;
}

function getPayslipsForEmployee(employeeId, limit = 60) {
  return db.prepare('SELECT * FROM payslips WHERE employee_id = ? ORDER BY period DESC LIMIT ?').all(employeeId, limit);
}

function getAllPayslips(limit = 300) {
  return db.prepare(`
    SELECT p.*, u.first_name, u.last_name
    FROM payslips p JOIN users u ON u.id = p.employee_id
    ORDER BY p.period DESC, u.last_name COLLATE NOCASE
    LIMIT ?
  `).all(limit);
}

function markPayslipPaid(id) {
  db.prepare("UPDATE payslips SET status = 'Payée', paid_at = ? WHERE id = ?").run(new Date().toISOString(), id);
}

function deletePayslip(id) {
  db.prepare('DELETE FROM payslips WHERE id = ?').run(id);
}

module.exports = {
  DEFAULT_ANNUAL_LEAVE,
  isEligibleForHrFeatures,
  countBusinessDays,
  createRequest,
  getRequestsForEmployee,
  getRequestById,
  getAllRequests,
  approveRequest,
  rejectRequest,
  cancelOwnRequest,
  revokeRequest,
  adjustBalance,
  getAdjustments,
  createPayslip,
  getPayslipsForEmployee,
  getAllPayslips,
  markPayslipPaid,
  deletePayslip,
};
