const db = require('./db');

/**
 * Planning d'équipe, roulements et astreintes.
 *
 * Le tableur partagé finit toujours par mentir : deux personnes sur le même
 * créneau, quelqu'un planifié pendant ses congés, une astreinte qui n'a jamais
 * été relue. Ce module tient la grille et refuse les contradictions au moment
 * où elles sont créées — plus tard, elles sont déjà devenues des absences.
 *
 * Un planning non publié reste un brouillon : personne d'autre que son auteur
 * ne doit organiser sa semaine dessus.
 */

const KINDS = ['Poste', 'Astreinte', 'Permanence', 'Télétravail', 'Formation'];
const WEEKDAYS = [
  { value: 1, label: 'Lundi', short: 'Lun' },
  { value: 2, label: 'Mardi', short: 'Mar' },
  { value: 3, label: 'Mercredi', short: 'Mer' },
  { value: 4, label: 'Jeudi', short: 'Jeu' },
  { value: 5, label: 'Vendredi', short: 'Ven' },
  { value: 6, label: 'Samedi', short: 'Sam' },
  { value: 7, label: 'Dimanche', short: 'Dim' },
];

const DAY_MS = 24 * 60 * 60 * 1000;

// ---------- Dates ----------

/** Le lundi de la semaine d'une date : toute la grille s'y accroche. */
function weekStart(iso) {
  const date = new Date(`${iso}T00:00:00Z`);
  const day = date.getUTCDay() || 7;
  date.setUTCDate(date.getUTCDate() - (day - 1));
  return date.toISOString().slice(0, 10);
}

function addDays(iso, days) {
  return new Date(new Date(`${iso}T00:00:00Z`).getTime() + days * DAY_MS).toISOString().slice(0, 10);
}

function weekDays(startIso) {
  return WEEKDAYS.map((d, index) => ({ ...d, date: addDays(startIso, index) }));
}

function hoursBetween(startsAt, endsAt) {
  const start = new Date(`${startsAt}:00Z`).getTime();
  const end = new Date(`${endsAt}:00Z`).getTime();
  if (!Number.isFinite(start) || !Number.isFinite(end) || end <= start) return 0;
  return Math.round(((end - start) / 3600000) * 100) / 100;
}

// ---------- Créneaux ----------

function between(from, to, { userId = null, teamId = null, publishedOnly = false } = {}) {
  const clauses = ['s.starts_at < ?', 's.ends_at > ?'];
  const params = [`${to}T23:59`, `${from}T00:00`];
  if (userId) { clauses.push('s.user_id = ?'); params.push(userId); }
  if (teamId) { clauses.push('s.team_id = ?'); params.push(teamId); }
  if (publishedOnly) clauses.push('s.published = 1');

  return db.prepare(`
    SELECT s.*, u.first_name, u.last_name, t.name AS team_name
    FROM shifts s
    JOIN users u ON u.id = s.user_id
    LEFT JOIN teams t ON t.id = s.team_id
    WHERE ${clauses.join(' AND ')}
    ORDER BY s.starts_at, u.last_name COLLATE NOCASE
  `).all(...params).map((row) => ({ ...row, hours: hoursBetween(row.starts_at, row.ends_at), day: row.starts_at.slice(0, 10) }));
}

function byId(id) {
  return db.prepare('SELECT * FROM shifts WHERE id = ?').get(Number(id) || 0) || null;
}

/**
 * Ce qui empêche un créneau d'exister : un autre créneau qui le chevauche, ou
 * une absence déjà accordée. Les deux sont rendus ensemble — c'est la même
 * question pour celui qui planifie.
 */
function conflicts({ userId, startsAt, endsAt, ignoreId = null }) {
  const overlapping = db.prepare(`
    SELECT s.*, u.first_name, u.last_name FROM shifts s JOIN users u ON u.id = s.user_id
    WHERE s.user_id = ? AND s.id != ? AND s.starts_at < ? AND s.ends_at > ?
  `).all(userId, ignoreId || 0, endsAt, startsAt);

  const day = startsAt.slice(0, 10);
  const lastDay = endsAt.slice(0, 10);
  const leave = db.prepare(`
    SELECT * FROM hr_requests
    WHERE employee_id = ? AND status = 'Approuvée' AND start_date <= ? AND end_date >= ?
  `).all(userId, lastDay, day);

  return { shifts: overlapping, leave, blocked: overlapping.length > 0 || leave.length > 0 };
}

function create({ userId, startsAt, endsAt, kind, label, location, teamId, published, createdBy }) {
  return db.prepare(`
    INSERT INTO shifts (user_id, starts_at, ends_at, kind, label, location, team_id, published, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(userId, startsAt, endsAt, kind, label || '', location || '', teamId || null, published ? 1 : 0, createdBy || null).lastInsertRowid;
}

function update(id, fields) {
  db.prepare(`
    UPDATE shifts SET user_id = ?, starts_at = ?, ends_at = ?, kind = ?, label = ?, location = ?, team_id = ? WHERE id = ?
  `).run(fields.userId, fields.startsAt, fields.endsAt, fields.kind, fields.label || '', fields.location || '', fields.teamId || null, id);
}

function remove(id) {
  db.prepare('DELETE FROM shifts WHERE id = ?').run(id);
}

/** Publier une semaine d'un coup : c'est ainsi qu'on annonce un planning. */
function publishWeek(startIso, { teamId = null } = {}) {
  const end = addDays(startIso, 7);
  const clause = teamId ? 'AND team_id = ?' : '';
  const params = teamId ? [`${end}T00:00`, `${startIso}T00:00`, teamId] : [`${end}T00:00`, `${startIso}T00:00`];
  return db.prepare(`UPDATE shifts SET published = 1 WHERE published = 0 AND starts_at < ? AND ends_at > ? ${clause}`).run(...params).changes;
}

// ---------- Roulements ----------

function templates() {
  return db.prepare('SELECT * FROM shift_templates ORDER BY name COLLATE NOCASE').all()
    .map((row) => ({ ...row, days: row.weekdays.split(',').map(Number).filter(Boolean) }));
}

function templateById(id) {
  const row = db.prepare('SELECT * FROM shift_templates WHERE id = ?').get(Number(id) || 0);
  return row ? { ...row, days: row.weekdays.split(',').map(Number).filter(Boolean) } : null;
}

function createTemplate({ name, kind, startTime, endTime, weekdays, location }) {
  return db.prepare(`
    INSERT INTO shift_templates (name, kind, start_time, end_time, weekdays, location) VALUES (?, ?, ?, ?, ?, ?)
  `).run(name, kind, startTime, endTime, (weekdays || []).join(','), location || '').lastInsertRowid;
}

function deleteTemplate(id) {
  db.prepare('DELETE FROM shift_templates WHERE id = ?').run(id);
}

/**
 * Applique un roulement sur une période. Les jours en conflit sont sautés et
 * rendus à l'appelant : poser un créneau par-dessus des congés accordés serait
 * une promesse qu'on ne peut pas tenir.
 */
function applyTemplate(templateId, { userId, from, to, createdBy = null, published = false }) {
  const template = templateById(templateId);
  if (!template) return { ok: false, message: 'Roulement inconnu.' };
  if (to < from) return { ok: false, message: 'La période finit avant de commencer.' };

  const created = [];
  const skipped = [];
  for (let day = from; day <= to; day = addDays(day, 1)) {
    const weekday = new Date(`${day}T00:00:00Z`).getUTCDay() || 7;
    if (!template.days.includes(weekday)) continue;

    const startsAt = `${day}T${template.start_time}`;
    // Un poste de nuit finit le lendemain : l'heure de fin plus petite le dit.
    const endsAt = template.end_time > template.start_time ? `${day}T${template.end_time}` : `${addDays(day, 1)}T${template.end_time}`;

    const clash = conflicts({ userId, startsAt, endsAt });
    if (clash.blocked) {
      skipped.push({ day, reason: clash.leave.length ? 'absence accordée' : 'créneau déjà posé' });
      continue;
    }
    create({ userId, startsAt, endsAt, kind: template.kind, label: template.name, location: template.location, published, createdBy });
    created.push(day);
  }
  return { ok: true, created, skipped };
}

// ---------- Lectures ----------

/** Qui est d'astreinte, et quand : la question qu'on pose à 3 h du matin. */
function onCall(from, to) {
  return between(from, to).filter((shift) => shift.kind === 'Astreinte');
}

function whoIsOnCall(at = new Date().toISOString().slice(0, 16)) {
  return db.prepare(`
    SELECT s.*, u.first_name, u.last_name, u.phone FROM shifts s JOIN users u ON u.id = s.user_id
    WHERE s.kind = 'Astreinte' AND s.starts_at <= ? AND s.ends_at > ? ORDER BY s.starts_at
  `).all(at, at);
}

/** Heures planifiées par personne sur la période : le déséquilibre saute aux yeux. */
function load(from, to, { teamId = null } = {}) {
  const rows = between(from, to, { teamId });
  const byUser = new Map();
  for (const shift of rows) {
    const entry = byUser.get(shift.user_id) || {
      userId: shift.user_id,
      name: `${shift.first_name} ${shift.last_name}`.trim(),
      hours: 0, shifts: 0, onCall: 0,
    };
    entry.hours = Math.round((entry.hours + shift.hours) * 100) / 100;
    entry.shifts += 1;
    if (shift.kind === 'Astreinte') entry.onCall += 1;
    byUser.set(shift.user_id, entry);
  }
  return [...byUser.values()].sort((a, b) => b.hours - a.hours);
}

/** La grille de la semaine : une ligne par personne, une colonne par jour. */
function grid(startIso, { teamId = null, userIds = null, publishedOnly = false } = {}) {
  const days = weekDays(startIso);
  const shifts = between(startIso, addDays(startIso, 6), { teamId, publishedOnly });

  const people = new Map();
  for (const shift of shifts) {
    if (userIds && !userIds.includes(shift.user_id)) continue;
    const entry = people.get(shift.user_id) || {
      userId: shift.user_id,
      name: `${shift.first_name} ${shift.last_name}`.trim(),
      days: Object.fromEntries(days.map((d) => [d.date, []])),
      hours: 0,
    };
    if (entry.days[shift.day]) entry.days[shift.day].push(shift);
    entry.hours = Math.round((entry.hours + shift.hours) * 100) / 100;
    people.set(shift.user_id, entry);
  }

  return { start: startIso, days, rows: [...people.values()].sort((a, b) => a.name.localeCompare(b.name)) };
}

module.exports = {
  KINDS, WEEKDAYS,
  weekStart, addDays, weekDays, hoursBetween,
  between, byId, conflicts, create, update, remove, publishWeek,
  templates, templateById, createTemplate, deleteTemplate, applyTemplate,
  onCall, whoIsOnCall, load, grid,
};
