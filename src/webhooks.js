const crypto = require('crypto');

const db = require('./db');
const secrets = require('./secret-store');

/**
 * Webhooks sortants.
 *
 * Prévenir un outil tiers au moment où quelque chose se passe, plutôt que de
 * le faire interroger l'API toutes les minutes. Quatre précautions :
 *
 *   — chaque envoi est **signé** (HMAC-SHA256 du corps, avec le secret du
 *     webhook) : le destinataire peut vérifier que l'appel vient bien d'ici et
 *     n'a pas été modifié en route ;
 *   — le secret est **chiffré en base**, comme les autres ;
 *   — une URL **interne** (boucle locale, réseau privé) est refusée par défaut :
 *     faire émettre des requêtes à un serveur vers son propre réseau est une
 *     porte dérobée classique. L'autoriser se fait sciemment, case cochée ;
 *   — un échec n'est pas silencieux : il est **journalisé, réessayé**, et le
 *     webhook se désactive tout seul après une série d'échecs, plutôt que de
 *     faire croire que l'information passe.
 */

const EVENTS = [
  { key: 'facture.creee', label: 'Facture créée' },
  { key: 'facture.payee', label: 'Facture payée' },
  { key: 'absence.approuvee', label: 'Absence approuvée' },
  { key: 'membre.arrive', label: 'Membre ajouté' },
  { key: 'membre.parti', label: 'Membre désactivé' },
  { key: 'document.signe', label: 'Document intégralement signé' },
  { key: 'ticket.ouvert', label: 'Ticket de support ouvert' },
  { key: 'sauvegarde.echec', label: "Échec d'externalisation de sauvegarde" },
];

const EVENT_KEYS = EVENTS.map((e) => e.key);
const MAX_ATTEMPTS = 5;
const TIMEOUT_MS = 8000;
// Après cette série d'échecs consécutifs, le webhook s'éteint de lui-même.
const FAILURE_LIMIT = 20;

/** Une adresse interne ne se devine pas au nom : on regarde ce qu'il désigne. */
function isPrivateHost(hostname) {
  const host = String(hostname || '').toLowerCase();
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.internal') || host.endsWith('.local')) return true;
  if (host === '::1' || host === '0.0.0.0') return true;

  const parts = host.split('.');
  if (parts.length === 4 && parts.every((p) => /^\d{1,3}$/.test(p))) {
    const [a, b] = parts.map(Number);
    if (a === 127 || a === 10 || a === 0) return true;
    if (a === 192 && b === 168) return true;
    if (a === 172 && b >= 16 && b <= 31) return true;
    if (a === 169 && b === 254) return true;
  }
  return false;
}

function checkUrl(raw, { allowPrivate = false } = {}) {
  let url;
  try {
    url = new URL(String(raw || ''));
  } catch {
    return { ok: false, message: 'URL invalide.' };
  }

  if (url.protocol !== 'https:' && url.protocol !== 'http:') return { ok: false, message: 'Seules les adresses http(s) sont acceptées.' };
  if (url.protocol === 'http:' && !allowPrivate) {
    return { ok: false, message: 'En clair (http), la charge utile et sa signature circulent lisibles : utilisez https.' };
  }
  if (isPrivateHost(url.hostname) && !allowPrivate) {
    return { ok: false, message: 'Cette adresse désigne le réseau interne. Cochez la case correspondante si c\'est voulu.' };
  }
  return { ok: true, url: url.toString() };
}

function list() {
  return db.prepare('SELECT * FROM webhooks ORDER BY active DESC, label COLLATE NOCASE').all().map((row) => ({
    ...row,
    eventList: row.events ? row.events.split(',') : [],
    // Le secret ne ressort jamais : on n'en montre que l'existence.
    secretSet: Boolean(secrets.decrypt(row.secret)),
  }));
}

function byId(id) {
  const row = db.prepare('SELECT * FROM webhooks WHERE id = ?').get(Number(id) || 0);
  return row ? { ...row, eventList: row.events ? row.events.split(',') : [] } : null;
}

function create({ label, url, events = [], allowPrivate = false, createdBy = null }) {
  const name = String(label || '').trim().slice(0, 120);
  if (!name) return { ok: false, message: 'Un intitulé est requis.' };

  const kept = [...new Set(events.filter((event) => EVENT_KEYS.includes(event)))];
  if (!kept.length) return { ok: false, message: 'Choisissez au moins un événement.' };

  const target = checkUrl(url, { allowPrivate });
  if (!target.ok) return target;

  const secret = crypto.randomBytes(32).toString('base64url');
  const id = db.prepare(`
    INSERT INTO webhooks (label, url, secret, events, allow_private, created_by)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(name, target.url, secrets.encrypt(secret), kept.join(','), allowPrivate ? 1 : 0, createdBy).lastInsertRowid;

  // Le secret n'est rendu qu'ici : le destinataire doit le recevoir maintenant.
  return { ok: true, id, secret };
}

function setActive(id, active) {
  return db.prepare('UPDATE webhooks SET active = ?, failures = CASE WHEN ? THEN 0 ELSE failures END WHERE id = ?')
    .run(active ? 1 : 0, active ? 1 : 0, id).changes > 0;
}

function remove(id) {
  db.prepare('DELETE FROM webhooks WHERE id = ?').run(id);
}

function signature(secret, body) {
  return `sha256=${crypto.createHmac('sha256', String(secret)).update(body).digest('hex')}`;
}

/** Met un événement en file pour tous les webhooks qui l'écoutent. */
function emit(event, payload = {}) {
  if (!EVENT_KEYS.includes(event)) return 0;

  const body = JSON.stringify({ event, at: new Date().toISOString(), data: payload });
  const targets = db.prepare("SELECT id FROM webhooks WHERE active = 1 AND (',' || events || ',') LIKE ?").all(`%,${event},%`);

  const queue = db.prepare(`
    INSERT INTO webhook_deliveries (webhook_id, event, payload, next_try_at)
    VALUES (?, ?, ?, datetime('now'))
  `);
  for (const target of targets) queue.run(target.id, event, body);
  return targets.length;
}

function pending({ limit = 50 } = {}) {
  return db.prepare(`
    SELECT d.*, w.url, w.secret, w.label, w.active FROM webhook_deliveries d
    JOIN webhooks w ON w.id = d.webhook_id
    WHERE d.status = 'En attente' AND (d.next_try_at IS NULL OR d.next_try_at <= datetime('now'))
    ORDER BY d.id LIMIT ?
  `).all(limit);
}

const backoffMinutes = (attempts) => Math.min(60, 2 ** attempts);

/** Envoie une livraison. Rend le verdict sans jamais lever d'exception. */
async function deliver(delivery, { fetchImpl = fetch } = {}) {
  const secret = secrets.decrypt(delivery.secret);
  if (!secret) {
    return { ok: false, status: 0, error: 'secret illisible (clé de session changée ?)' };
  }

  try {
    const response = await fetchImpl(delivery.url, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-salarie-member-event': delivery.event,
        'x-salarie-member-signature': signature(secret, delivery.payload),
        'user-agent': 'Salarie-Member-Webhook/1',
      },
      body: delivery.payload,
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    return response.ok
      ? { ok: true, status: response.status }
      : { ok: false, status: response.status, error: `réponse ${response.status}` };
  } catch (err) {
    return { ok: false, status: 0, error: err.message };
  }
}

function recordSuccess(delivery, status) {
  db.prepare(`
    UPDATE webhook_deliveries SET status = 'Livré', attempts = attempts + 1, delivered_at = datetime('now'), last_error = ''
    WHERE id = ?
  `).run(delivery.id);
  db.prepare("UPDATE webhooks SET last_status = ?, last_attempt_at = datetime('now'), failures = 0 WHERE id = ?")
    .run(`OK ${status}`, delivery.webhook_id);
}

function recordFailure(delivery, error) {
  const attempts = delivery.attempts + 1;
  const exhausted = attempts >= MAX_ATTEMPTS;

  db.prepare(`
    UPDATE webhook_deliveries
    SET status = ?, attempts = ?, last_error = ?, next_try_at = datetime('now', ?)
    WHERE id = ?
  `).run(exhausted ? 'Abandonné' : 'En attente', attempts, String(error).slice(0, 300),
    `+${backoffMinutes(attempts)} minutes`, delivery.id);

  const failures = db.prepare('SELECT failures FROM webhooks WHERE id = ?').get(delivery.webhook_id).failures + 1;
  db.prepare("UPDATE webhooks SET last_status = ?, last_attempt_at = datetime('now'), failures = ? WHERE id = ?")
    .run(String(error).slice(0, 200), failures, delivery.webhook_id);

  // Un webhook qui échoue depuis des jours ne doit pas continuer à faire croire
  // que l'information passe : il s'éteint, et l'administration le voit éteint.
  if (failures >= FAILURE_LIMIT) {
    db.prepare('UPDATE webhooks SET active = 0 WHERE id = ?').run(delivery.webhook_id);
  }
}

/** Vide la file d'attente. Appelée par le balayage périodique. */
async function flush({ limit = 50, deps = {} } = {}) {
  let delivered = 0;
  let failed = 0;

  for (const delivery of pending({ limit })) {
    const verdict = await deliver(delivery, deps);
    if (verdict.ok) {
      recordSuccess(delivery, verdict.status);
      delivered += 1;
    } else {
      recordFailure(delivery, verdict.error);
      failed += 1;
    }
  }
  return { delivered, failed };
}

function deliveries({ webhookId = null, limit = 100 } = {}) {
  const clause = webhookId ? 'WHERE d.webhook_id = ?' : '';
  const params = webhookId ? [webhookId, limit] : [limit];
  return db.prepare(`
    SELECT d.*, w.label FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
    ${clause} ORDER BY d.id DESC LIMIT ?
  `).all(...params);
}

/** Purge les livraisons anciennes : la file est un journal, pas une archive. */
function purge({ days = 30 } = {}) {
  return db.prepare(`
    DELETE FROM webhook_deliveries WHERE status != 'En attente' AND created_at < datetime('now', ?)
  `).run(`-${Number(days) || 30} days`).changes;
}

function summary() {
  return {
    active: db.prepare('SELECT COUNT(*) AS n FROM webhooks WHERE active = 1').get().n,
    waiting: db.prepare("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE status = 'En attente'").get().n,
    abandoned: db.prepare("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE status = 'Abandonné'").get().n,
  };
}

module.exports = {
  EVENTS, EVENT_KEYS, MAX_ATTEMPTS, FAILURE_LIMIT, TIMEOUT_MS,
  isPrivateHost, checkUrl, list, byId, create, setActive, remove,
  signature, emit, pending, deliver, flush, deliveries, purge, summary,
};
