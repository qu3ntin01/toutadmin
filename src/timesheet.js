const db = require('./db');

function durationHours(entry, now = new Date()) {
  const start = new Date(entry.clock_in);
  const end = entry.clock_out ? new Date(entry.clock_out) : now;
  return Math.max(0, (end.getTime() - start.getTime()) / 3600000);
}

function getOpenEntry(employeeId) {
  return db.prepare('SELECT * FROM time_entries WHERE employee_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1').get(employeeId);
}

function getEntries(employeeId, limit = 60) {
  return db.prepare('SELECT * FROM time_entries WHERE employee_id = ? ORDER BY clock_in DESC LIMIT ?').all(employeeId, limit);
}

// Taux horaire dérivé du TJM (taux journalier moyen) sur une base de 8 heures.
function hourlyRate(dailyRate) {
  return dailyRate ? dailyRate / 8 : 0;
}

function getStats(employeeId, dailyRate) {
  const now = new Date();
  const monthStart = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), 1));
  const entries = db.prepare('SELECT * FROM time_entries WHERE employee_id = ?').all(employeeId);

  let totalHours = 0;
  let monthHours = 0;
  for (const entry of entries) {
    const hours = durationHours(entry, now);
    totalHours += hours;
    if (new Date(entry.clock_in) >= monthStart) monthHours += hours;
  }

  const rate = hourlyRate(dailyRate);
  return {
    entryCount: entries.length,
    totalHours,
    monthHours,
    hourlyRate: rate,
    totalEstimate: totalHours * rate,
    monthEstimate: monthHours * rate,
  };
}

function clockIn(employeeId) {
  const existing = getOpenEntry(employeeId);
  if (existing) return { ok: false, reason: 'already-open' };
  db.prepare('INSERT INTO time_entries (employee_id, clock_in) VALUES (?, ?)').run(employeeId, new Date().toISOString());
  return { ok: true };
}

function clockOut(employeeId) {
  const open = getOpenEntry(employeeId);
  if (!open) return { ok: false, reason: 'no-open-entry' };
  db.prepare('UPDATE time_entries SET clock_out = ? WHERE id = ?').run(new Date().toISOString(), open.id);
  return { ok: true };
}

module.exports = {
  durationHours,
  getOpenEntry,
  getEntries,
  hourlyRate,
  getStats,
  clockIn,
  clockOut,
};
