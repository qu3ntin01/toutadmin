const db = require('./db');

// Catégories d'un événement saisi par le membre. Les entrées venues des congés,
// du CSE ou du contrat sont dérivées : elles portent leur propre source.
const EVENT_CATEGORIES = ['Personnel', 'Réunion', 'Déplacement', 'Formation', 'Télétravail', 'Autre'];

function pad(n) {
  return String(n).padStart(2, '0');
}

function toISODate(date) {
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

/** Un mois valide au format AAAA-MM, sinon le mois courant. */
function normalizeMonth(value) {
  if (typeof value === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(value)) return value;
  const now = new Date();
  return `${now.getUTCFullYear()}-${pad(now.getUTCMonth() + 1)}`;
}

function monthBounds(month) {
  const [year, m] = month.split('-').map(Number);
  const first = new Date(Date.UTC(year, m - 1, 1));
  const last = new Date(Date.UTC(year, m, 0));
  return { first, last, firstISO: toISODate(first), lastISO: toISODate(last) };
}

function shiftMonth(month, delta) {
  const [year, m] = month.split('-').map(Number);
  const d = new Date(Date.UTC(year, m - 1 + delta, 1));
  return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}`;
}

/**
 * Grille du mois, semaines commençant le lundi : six lignes de sept jours,
 * débordant sur les mois voisins pour que la grille soit toujours pleine.
 */
function buildGrid(month) {
  const { first, last } = monthBounds(month);
  // getUTCDay() : 0 = dimanche. On ramène lundi à 0.
  const leading = (first.getUTCDay() + 6) % 7;
  const start = new Date(first);
  start.setUTCDate(start.getUTCDate() - leading);

  const weeks = [];
  const cursor = new Date(start);
  const todayISO = toISODate(new Date());

  while (weeks.length < 6) {
    const week = [];
    for (let i = 0; i < 7; i++) {
      const iso = toISODate(cursor);
      week.push({
        iso,
        day: cursor.getUTCDate(),
        inMonth: cursor >= first && cursor <= last,
        isToday: iso === todayISO,
        isWeekend: [0, 6].includes(cursor.getUTCDay()),
      });
      cursor.setUTCDate(cursor.getUTCDate() + 1);
    }
    weeks.push(week);
  }
  return weeks;
}

// ---------- Événements personnels ----------

function createEvent({ userId, title, description, location, startDate, endDate, startTime, endTime, allDay, category }) {
  return db.prepare(`
    INSERT INTO calendar_events (user_id, title, description, location, start_date, end_date, start_time, end_time, all_day, category)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(userId, title, description || '', location || '', startDate, endDate, startTime || '', endTime || '',
         allDay ? 1 : 0, category || 'Personnel').lastInsertRowid;
}

function deleteEvent(id, userId) {
  return db.prepare('DELETE FROM calendar_events WHERE id = ? AND user_id = ?').run(id, userId).changes > 0;
}

function personalEvents(userId, fromISO, toISO) {
  return db.prepare(`
    SELECT * FROM calendar_events
    WHERE user_id = ? AND start_date <= ? AND end_date >= ?
    ORDER BY start_date, start_time
  `).all(userId, toISO, fromISO);
}

// ---------- Entrées dérivées du reste du site ----------

/** Congés et absences approuvés : ils occupent l'agenda sans avoir à être ressaisis. */
function leaveEntries(userId, fromISO, toISO) {
  return db.prepare(`
    SELECT type, start_date, end_date FROM hr_requests
    WHERE employee_id = ? AND status = 'Approuvée' AND start_date <= ? AND end_date >= ?
    ORDER BY start_date
  `).all(userId, toISO, fromISO);
}

function meetingEntries(fromISO, toISO) {
  return db.prepare(`
    SELECT id, title, meeting_date, meeting_time, location FROM cse_meetings
    WHERE meeting_date BETWEEN ? AND ?
    ORDER BY meeting_date, meeting_time
  `).all(fromISO, toISO);
}

/**
 * Agenda d'un mois : chaque jour reçoit ses entrées, personnelles comme dérivées.
 * `attendsCse` ouvre les réunions du comité aux seuls salariés qu'elles concernent.
 */
function monthAgenda(user, month, { attendsCse = false } = {}) {
  const { firstISO, lastISO } = monthBounds(month);
  const byDay = {};

  const push = (iso, entry) => {
    if (iso < firstISO || iso > lastISO) return;
    (byDay[iso] = byDay[iso] || []).push(entry);
  };

  // Un événement de plusieurs jours est posé sur chacun d'eux.
  const spread = (startISO, endISO, make) => {
    const cur = new Date(`${startISO}T00:00:00Z`);
    const end = new Date(`${endISO}T00:00:00Z`);
    if (Number.isNaN(cur.getTime()) || Number.isNaN(end.getTime())) return;
    while (cur <= end) {
      push(toISODate(cur), make(toISODate(cur)));
      cur.setUTCDate(cur.getUTCDate() + 1);
    }
  };

  for (const event of personalEvents(user.id, firstISO, lastISO)) {
    spread(event.start_date, event.end_date, () => ({
      source: 'personnel',
      id: event.id,
      title: event.title,
      category: event.category,
      location: event.location,
      description: event.description,
      // La grille n'a la place que de l'heure de début ; la liste affiche la plage entière.
      time: event.all_day ? '' : [event.start_time, event.end_time].filter(Boolean).join(' – '),
      startTime: event.all_day ? '' : event.start_time,
      removable: true,
    }));
  }

  for (const leave of leaveEntries(user.id, firstISO, lastISO)) {
    spread(leave.start_date, leave.end_date, () => ({
      source: 'conge',
      title: leave.type,
      category: leave.type,
      time: '',
      startTime: '',
      removable: false,
    }));
  }

  if (attendsCse) {
    for (const meeting of meetingEntries(firstISO, lastISO)) {
      push(meeting.meeting_date, {
        source: 'cse',
        title: meeting.title,
        category: 'CSE',
        location: meeting.location,
        time: meeting.meeting_time,
        startTime: meeting.meeting_time,
        removable: false,
      });
    }
  }

  if (user.contract_end_date) {
    push(user.contract_end_date, { source: 'contrat', title: 'Fin de contrat', category: 'Contrat', time: '', startTime: '', removable: false });
  }

  return byDay;
}

/** Les prochaines échéances, tous types confondus, pour la colonne latérale. */
function upcoming(user, { attendsCse = false, days = 30, limit = 8 } = {}) {
  const today = new Date();
  const fromISO = toISODate(today);
  const horizon = new Date(today);
  horizon.setUTCDate(horizon.getUTCDate() + days);
  const toISO = toISODate(horizon);

  const entries = [];
  for (const event of personalEvents(user.id, fromISO, toISO)) {
    entries.push({
      date: event.start_date < fromISO ? fromISO : event.start_date,
      title: event.title,
      category: event.category,
      time: event.all_day ? '' : event.start_time,
      source: 'personnel',
    });
  }
  for (const leave of leaveEntries(user.id, fromISO, toISO)) {
    entries.push({
      date: leave.start_date < fromISO ? fromISO : leave.start_date,
      title: leave.type,
      category: leave.type,
      time: '',
      source: 'conge',
    });
  }
  if (attendsCse) {
    for (const meeting of meetingEntries(fromISO, toISO)) {
      entries.push({ date: meeting.meeting_date, title: meeting.title, category: 'CSE', time: meeting.meeting_time, source: 'cse' });
    }
  }

  return entries.sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time)).slice(0, limit);
}

module.exports = {
  EVENT_CATEGORIES,
  toISODate,
  normalizeMonth,
  monthBounds,
  shiftMonth,
  buildGrid,
  createEvent,
  deleteEvent,
  personalEvents,
  monthAgenda,
  upcoming,
};
