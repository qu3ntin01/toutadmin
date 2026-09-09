const db = require('./db');
const notifications = require('./notifications');

/**
 * Événements d'entreprise : séminaires, formations, réunions générales.
 *
 * Toute la difficulté d'un événement tient dans la place. Une salle a une
 * capacité, les inscriptions arrivent dans le désordre, et quelqu'un se
 * désiste toujours la veille. Ce module tient la file : au-delà de la
 * capacité on n'est pas refusé, on est en liste d'attente, et un désistement
 * fait monter le premier qui attend — automatiquement, avec une notification,
 * parce qu'une place libérée que personne ne voit est une place perdue.
 */

const KINDS = ['Séminaire', 'Formation', 'Réunion générale', 'Atelier', 'Salon', 'Convivialité'];
const EVENT_STATUSES = ['Brouillon', 'Ouvert', 'Complet', 'Clos', 'Annulé'];
const REGISTRATION_STATUSES = ['Inscrit', "Liste d'attente", 'Annulée', 'Présent', 'Absent'];

// Les états qui occupent une place : le décompte de la capacité ne regarde
// qu'eux. Une annulation libère, une absence constatée non — la place a été
// réservée, et l'événement a eu lieu.
const HOLDING = ['Inscrit', 'Présent', 'Absent'];

const EVENT_COLUMNS = `
  e.*,
  u.first_name AS organizer_first_name, u.last_name AS organizer_last_name,
  CASE e.scope WHEN 'team' THEN (SELECT t.name FROM teams t WHERE t.id = e.scope_id)
               WHEN 'department' THEN (SELECT d.name FROM departments d WHERE d.id = e.scope_id)
               ELSE NULL END AS scope_name,
  (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN ('Inscrit','Présent','Absent')) AS taken,
  (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'Liste d''attente') AS waiting
`;

function all({ includeDrafts = true, limit = 200 } = {}) {
  const clause = includeDrafts ? '' : "WHERE e.status != 'Brouillon'";
  return db.prepare(`
    SELECT ${EVENT_COLUMNS}
    FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
    ${clause}
    ORDER BY e.starts_at DESC LIMIT ?
  `).all(limit);
}

function byId(id) {
  return db.prepare(`
    SELECT ${EVENT_COLUMNS}
    FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
    WHERE e.id = ?
  `).get(Number(id) || 0) || null;
}

/**
 * Ce qu'une personne a le droit de voir : les événements de l'entreprise, de
 * son service et de son équipe. Un brouillon n'existe pas encore, il reste
 * invisible — y compris de ceux qu'il concernera.
 */
function visibleTo(user, { limit = 100 } = {}) {
  return db.prepare(`
    SELECT ${EVENT_COLUMNS},
      (SELECT r.status FROM event_registrations r WHERE r.event_id = e.id AND r.user_id = ?) AS my_status
    FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
    WHERE e.status != 'Brouillon'
      AND (e.scope = 'company'
        OR (e.scope = 'department' AND e.scope_id = ?)
        OR (e.scope = 'team' AND e.scope_id = ?))
    ORDER BY e.starts_at DESC LIMIT ?
  `).all(user.id, user.department_id || -1, user.team_id || -1, limit);
}

function concerns(event, user) {
  if (event.scope === 'company') return true;
  if (event.scope === 'department') return event.scope_id === user.department_id;
  return event.scope_id === user.team_id;
}

function create(fields) {
  return db.prepare(`
    INSERT INTO company_events (title, kind, description, location, starts_at, ends_at, scope, scope_id,
                                capacity, registration_closes_on, budget, cost, organizer_id, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    fields.title, fields.kind, fields.description || '', fields.location || '',
    fields.startsAt, fields.endsAt || null, fields.scope,
    fields.scope === 'company' ? null : fields.scopeId,
    fields.capacity || 0, fields.registrationClosesOn || null,
    fields.budget == null ? null : fields.budget, fields.cost == null ? null : fields.cost,
    fields.organizerId || null, fields.status || 'Brouillon'
  ).lastInsertRowid;
}

function update(id, fields) {
  db.prepare(`
    UPDATE company_events
    SET title = ?, kind = ?, description = ?, location = ?, starts_at = ?, ends_at = ?,
        scope = ?, scope_id = ?, capacity = ?, registration_closes_on = ?, budget = ?, cost = ?,
        organizer_id = ?, status = ?
    WHERE id = ?
  `).run(
    fields.title, fields.kind, fields.description || '', fields.location || '',
    fields.startsAt, fields.endsAt || null, fields.scope,
    fields.scope === 'company' ? null : fields.scopeId,
    fields.capacity || 0, fields.registrationClosesOn || null,
    fields.budget == null ? null : fields.budget, fields.cost == null ? null : fields.cost,
    fields.organizerId || null, fields.status, id
  );
}

function remove(id) {
  db.prepare('DELETE FROM company_events WHERE id = ?').run(id);
}

// ---------------------------------------------------------------- inscriptions

function registrations(eventId) {
  return db.prepare(`
    SELECT r.*, u.first_name, u.last_name, u.email, u.grade
    FROM event_registrations r JOIN users u ON u.id = r.user_id
    WHERE r.event_id = ?
    ORDER BY CASE r.status WHEN 'Liste d''attente' THEN 1 WHEN 'Annulée' THEN 2 ELSE 0 END,
             r.registered_at, r.id
  `).all(eventId);
}

function registrationOf(eventId, userId) {
  return db.prepare('SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ?').get(eventId, userId) || null;
}

function seatsLeft(event) {
  if (!event.capacity) return null;
  return Math.max(0, event.capacity - event.taken);
}

/**
 * S'inscrire. Le résultat dit ce qui s'est passé — inscrit, ou mis en liste
 * d'attente — parce que les deux sont des succès et que la personne doit savoir
 * lequel.
 */
function register(eventId, userId) {
  const event = byId(eventId);
  if (!event) return { ok: false, reason: 'introuvable' };
  if (event.status !== 'Ouvert' && event.status !== 'Complet') return { ok: false, reason: 'ferme' };

  const today = new Date().toISOString().slice(0, 10);
  if (event.registration_closes_on && event.registration_closes_on < today) return { ok: false, reason: 'cloture' };

  const existing = registrationOf(eventId, userId);
  if (existing && existing.status !== 'Annulée') return { ok: false, reason: 'deja' };

  const full = event.capacity > 0 && event.taken >= event.capacity;
  const status = full ? "Liste d'attente" : 'Inscrit';

  if (existing) {
    db.prepare("UPDATE event_registrations SET status = ?, registered_at = datetime('now') WHERE id = ?").run(status, existing.id);
  } else {
    db.prepare('INSERT INTO event_registrations (event_id, user_id, status) VALUES (?, ?, ?)').run(eventId, userId, status);
  }

  syncFullness(eventId);
  return { ok: true, status };
}

/**
 * Se désister. La place libérée revient au premier de la liste d'attente, tout
 * de suite : sans cela elle reste vide alors que quelqu'un l'attend.
 */
function cancel(eventId, userId) {
  const registration = registrationOf(eventId, userId);
  if (!registration || registration.status === 'Annulée') return { ok: false, reason: 'introuvable' };

  db.prepare("UPDATE event_registrations SET status = 'Annulée' WHERE id = ?").run(registration.id);
  const promoted = registration.status === 'Inscrit' ? promoteFromWaitlist(eventId) : null;
  syncFullness(eventId);
  return { ok: true, promoted };
}

/** Fait monter le premier de la liste d'attente, s'il reste de la place. */
function promoteFromWaitlist(eventId) {
  const event = byId(eventId);
  if (!event || !event.capacity || event.taken >= event.capacity) return null;

  const next = db.prepare(`
    SELECT * FROM event_registrations
    WHERE event_id = ? AND status = 'Liste d''attente'
    ORDER BY registered_at, id LIMIT 1
  `).get(eventId);
  if (!next) return null;

  db.prepare("UPDATE event_registrations SET status = 'Inscrit' WHERE id = ?").run(next.id);
  notifications.push({
    userId: next.user_id,
    kind: 'evenement',
    title: `Place libérée — ${event.title}`,
    body: 'Vous étiez en liste d\'attente : une place s\'est libérée, votre inscription est confirmée.',
    link: `/evenements/${eventId}`,
    dedupeKey: `evenement:${eventId}:promotion:${next.user_id}`,
  });
  return next.user_id;
}

/**
 * Recale le statut « Complet » sur la réalité des places. L'organisateur ne
 * doit pas avoir à y penser : c'est la capacité qui décide, pas lui.
 */
function syncFullness(eventId) {
  const event = byId(eventId);
  if (!event || !event.capacity) return;
  if (event.status === 'Ouvert' && event.taken >= event.capacity) {
    db.prepare("UPDATE company_events SET status = 'Complet' WHERE id = ?").run(eventId);
  } else if (event.status === 'Complet' && event.taken < event.capacity) {
    db.prepare("UPDATE company_events SET status = 'Ouvert' WHERE id = ?").run(eventId);
  }
}

/** L'émargement : présent ou absent, une fois l'événement passé. */
function markAttendance(registrationId, status) {
  if (status !== 'Présent' && status !== 'Absent') return false;
  return db.prepare('UPDATE event_registrations SET status = ? WHERE id = ?').run(status, registrationId).changes > 0;
}

/** Prévient les inscrits d'une annulation : personne ne doit se déplacer pour rien. */
function notifyCancellation(event) {
  let sent = 0;
  for (const row of registrations(event.id)) {
    if (row.status === 'Annulée') continue;
    const done = notifications.push({
      userId: row.user_id,
      kind: 'evenement',
      title: `Annulé — ${event.title}`,
      body: `L'événement du ${String(event.starts_at).slice(0, 10)} est annulé.`,
      link: '/evenements',
      dedupeKey: `evenement:${event.id}:annulation:${row.user_id}`,
    });
    if (done) sent += 1;
  }
  return sent;
}

function summary() {
  const now = new Date().toISOString().slice(0, 16).replace('T', ' ');
  const upcoming = db.prepare("SELECT COUNT(*) AS n FROM company_events WHERE status IN ('Ouvert','Complet') AND starts_at >= ?").get(now).n;
  const drafts = db.prepare("SELECT COUNT(*) AS n FROM company_events WHERE status = 'Brouillon'").get().n;
  const registered = db.prepare("SELECT COUNT(*) AS n FROM event_registrations WHERE status IN ('Inscrit','Présent')").get().n;
  const waiting = db.prepare("SELECT COUNT(*) AS n FROM event_registrations WHERE status = 'Liste d''attente'").get().n;
  const spend = db.prepare("SELECT COALESCE(SUM(cost), 0) AS total FROM company_events WHERE starts_at >= date('now', '-1 year')").get().total;
  return { upcoming, drafts, registered, waiting, spend: Math.round(spend * 100) / 100 };
}

module.exports = {
  KINDS,
  EVENT_STATUSES,
  REGISTRATION_STATUSES,
  HOLDING,
  all,
  byId,
  visibleTo,
  concerns,
  create,
  update,
  remove,
  registrations,
  registrationOf,
  seatsLeft,
  register,
  cancel,
  promoteFromWaitlist,
  syncFullness,
  markAttendance,
  notifyCancellation,
  summary,
};
