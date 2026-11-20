const os = require('os');
const fs = require('fs');
const path = require('path');

const db = require('./db');
const logs = require('./logs');

/**
 * Recopie du journal vers un collecteur : syslog, Wazuh.
 *
 * Un journal qui ne vit que dans la base qu'il surveille ne prouve rien le jour
 * où quelqu'un a la main sur cette base. Le recopier ailleurs, en continu, est
 * la seule façon d'avoir une trace que l'instance elle-même ne peut pas
 * réécrire. Quatre règles :
 *
 *   — **le filigrane, pas la marque.** Chaque cible garde le numéro de la
 *     dernière ligne reçue, plutôt qu'une case cochée sur chaque ligne. Une
 *     panne du collecteur ne perd rien : au rétablissement, l'export reprend
 *     où il s'était arrêté. Deux collecteurs n'avancent pas du même pas ;
 *   — **la trame est celle de la norme**, pas une invention maison : RFC 5424
 *     (celle qu'on veut) ou RFC 3164 (celle que demandent les vieux
 *     collecteurs). Les niveaux du journal sont déjà ceux de syslog, il n'y a
 *     donc rien à traduire ;
 *   — **Wazuh se branche de deux façons**, et les deux sont ici : le
 *     gestionnaire écoute le syslog (c'est alors une cible syslog ordinaire
 *     pointée sur lui), ou un agent local lit un fichier — auquel cas on écrit
 *     une ligne JSON par événement, ce que l'agent sait décoder tel quel ;
 *   — **une cible qui échoue s'éteint**, après une série d'échecs, plutôt que
 *     de faire croire que les journaux partent.
 *
 * Ce que ce module ne fait pas : garantir la remise. UDP ne le permet pas, et
 * TCP ne le permet qu'à moitié. Ce qu'il garantit, c'est qu'une ligne non
 * envoyée reste en base et repart au passage suivant.
 */

const TRANSPORTS = ['udp', 'tcp', 'tls', 'fichier'];
const FORMATS = ['rfc5424', 'rfc3164', 'json'];
const KINDS = ['syslog', 'wazuh'];

// Numéro d'entreprise privé réservé aux exemples par l'IANA (RFC 5612) : il
// nomme nos données structurées sans usurper celui d'un autre.
const ENTERPRISE_ID = 32473;

const BATCH = 200;
const TIMEOUT_MS = 5000;
// Après cette série d'échecs consécutifs, la cible s'éteint d'elle-même.
const FAILURE_LIMIT = 10;

const clampPort = (value) => {
  const port = Number(value);
  return Number.isInteger(port) && port >= 1 && port <= 65535 ? port : null;
};

/* ------------------------------------------------------------------ cibles */

function list() {
  return db.prepare('SELECT * FROM log_targets ORDER BY id').all();
}

function byId(id) {
  return db.prepare('SELECT * FROM log_targets WHERE id = ?').get(Number(id)) || null;
}

/**
 * Contrôle la définition d'une cible. Refuse plutôt que de corriger : une
 * adresse devinée est une adresse fausse qu'on croira bonne.
 */
function check(values = {}) {
  const kind = KINDS.includes(values.kind) ? values.kind : 'syslog';
  const transport = TRANSPORTS.includes(values.transport) ? values.transport : 'udp';
  const format = FORMATS.includes(values.format) ? values.format : 'rfc5424';
  const label = String(values.label || '').trim().slice(0, 120);

  if (!label) return { ok: false, message: 'Un nom est nécessaire pour reconnaître la cible.' };

  if (transport === 'fichier') {
    const file = String(values.path || '').trim();
    if (!file) return { ok: false, message: 'Le dépôt lu par l’agent demande un chemin de fichier.' };
    if (!path.isAbsolute(file)) return { ok: false, message: 'Le chemin du dépôt doit être absolu.' };
    if (format !== 'json') {
      return { ok: false, message: 'Un dépôt lu par un agent s’écrit en JSON, une ligne par événement.' };
    }
  } else {
    if (!String(values.host || '').trim()) return { ok: false, message: 'L’adresse du collecteur est nécessaire.' };
    if (clampPort(values.port) === null) return { ok: false, message: 'Port invalide (1 à 65535).' };
  }

  const facility = Number(values.facility);
  if (!Number.isInteger(facility) || facility < 0 || facility > 23) {
    return { ok: false, message: 'Facilité syslog invalide (0 à 23).' };
  }

  return {
    ok: true,
    values: {
      kind,
      label,
      transport,
      format,
      facility,
      host: String(values.host || '').trim().slice(0, 200),
      port: clampPort(values.port) || 514,
      app_name: (String(values.appName || 'toutadmin').trim() || 'toutadmin').replace(/\s+/g, '-').slice(0, 48),
      min_level: logs.levelOf(values.minLevel == null ? 6 : values.minLevel),
      // Aucun canal coché veut dire « tous » : une liste vide ne doit pas se
      // replier sur le canal par défaut, sinon la cible ne reçoit plus rien.
      channels: (Array.isArray(values.channels) ? values.channels : String(values.channels || '').split(','))
        .map((c) => String(c == null ? '' : c).trim().toLowerCase())
        .filter((c) => logs.CHANNELS.includes(c))
        .filter((c, i, all) => all.indexOf(c) === i)
        .join(','),
      path: String(values.path || '').trim().slice(0, 300),
      allow_self_signed: values.allowSelfSigned ? 1 : 0,
      enabled: values.enabled ? 1 : 0,
    },
  };
}

function create(values) {
  const verdict = check(values);
  if (!verdict.ok) return verdict;

  const v = verdict.values;
  // Une cible nouvelle part du présent : elle ne déverse pas six mois
  // d'historique chez le collecteur au premier passage.
  const from = db.prepare('SELECT COALESCE(MAX(id), 0) AS last FROM logs').get().last;

  const info = db.prepare(`
    INSERT INTO log_targets (kind, label, enabled, transport, host, port, format, facility,
                             app_name, min_level, channels, path, allow_self_signed, cursor)
    VALUES (@kind, @label, @enabled, @transport, @host, @port, @format, @facility,
            @app_name, @min_level, @channels, @path, @allow_self_signed, @cursor)
  `).run({ ...v, cursor: from });

  return { ok: true, id: info.lastInsertRowid };
}

function update(id, values) {
  const target = byId(id);
  if (!target) return { ok: false, message: 'Cible introuvable.' };

  const verdict = check(values);
  if (!verdict.ok) return verdict;

  const v = verdict.values;
  db.prepare(`
    UPDATE log_targets SET kind = @kind, label = @label, enabled = @enabled, transport = @transport,
      host = @host, port = @port, format = @format, facility = @facility, app_name = @app_name,
      min_level = @min_level, channels = @channels, path = @path, allow_self_signed = @allow_self_signed,
      failures = CASE WHEN @enabled = 1 THEN 0 ELSE failures END
    WHERE id = @id
  `).run({ ...v, id: Number(id) });

  return { ok: true };
}

function remove(id) {
  return db.prepare('DELETE FROM log_targets WHERE id = ?').run(Number(id)).changes;
}

function setEnabled(id, enabled) {
  db.prepare('UPDATE log_targets SET enabled = ?, failures = CASE WHEN ? = 1 THEN 0 ELSE failures END WHERE id = ?')
    .run(enabled ? 1 : 0, enabled ? 1 : 0, Number(id));
}

/**
 * Repart du présent : la cible oublie ce qu'elle n'a pas envoyé.
 *
 * Utile quand un collecteur est resté coupé une semaine et qu'on ne veut pas
 * lui déverser la semaine entière d'un coup.
 */
function skipBacklog(id) {
  const last = db.prepare('SELECT COALESCE(MAX(id), 0) AS last FROM logs').get().last;
  db.prepare('UPDATE log_targets SET cursor = ? WHERE id = ?').run(last, Number(id));
  return last;
}

/* ------------------------------------------------------------------ trames */

const pri = (facility, severity) => facility * 8 + logs.levelOf(severity);

/** L'horodatage de la RFC 5424 : date complète, millisecondes, décalage. */
function rfc5424Time(value) {
  const date = value instanceof Date ? value : new Date(`${String(value).replace(' ', 'T')}Z`);
  return Number.isNaN(date.getTime()) ? new Date().toISOString() : date.toISOString();
}

/** Celui de la RFC 3164 : trois lettres, un jour cadré, une heure. Rien de plus. */
function rfc3164Time(value) {
  const date = value instanceof Date ? value : new Date(`${String(value).replace(' ', 'T')}Z`);
  const when = Number.isNaN(date.getTime()) ? new Date() : date;
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const day = String(when.getUTCDate()).padStart(2, ' ');
  const time = when.toISOString().slice(11, 19);
  return `${months[when.getUTCMonth()]} ${day} ${time}`;
}

/** Échappement des données structurées : la norme n'en demande que trois. */
const sdEscape = (value) => String(value == null ? '' : value).replace(/([\]"\\])/g, '\\$1');

/** Ce que le collecteur reçoit, quel que soit le format : les mêmes faits. */
function payload(row) {
  let context = null;
  if (row.context) {
    try { context = JSON.parse(row.context); } catch { context = { texte: row.context }; }
  }
  return {
    timestamp: rfc5424Time(row.occurred_at),
    severity: logs.levelOf(row.level),
    level: logs.levelKeyword(row.level),
    channel: row.channel,
    event: row.event,
    message: row.message,
    actor: row.actor_label || '',
    actor_id: row.actor_id == null ? null : row.actor_id,
    ip: row.ip || '',
    request_id: row.request_id || '',
    id: row.id,
    context,
  };
}

/**
 * Une ligne, dans le format de la cible.
 *
 * Le JSON n'est pas un format syslog : c'est celui qu'un agent Wazuh lit dans
 * un fichier, et celui qu'on met dans le message d'une trame 5424 quand le
 * gestionnaire Wazuh écoute en syslog — son décodeur sait le lire, et on garde
 * l'en-tête normalisé par-dessus.
 */
function frame(target, row, { host = os.hostname() } = {}) {
  const data = payload(row);
  const app = target.app_name || 'toutadmin';
  const machine = String(host || '-').split(' ')[0].slice(0, 255) || '-';

  if (target.format === 'json') {
    return JSON.stringify({ ...data, app, host: machine });
  }

  if (target.format === 'rfc3164') {
    const text = `${data.channel}/${data.event || '-'} ${data.message}`.replace(/[\r\n]+/g, ' ').trim();
    return `<${pri(target.facility, row.level)}>${rfc3164Time(row.occurred_at)} ${machine} ${app}: ${text}`;
  }

  const sd = [
    `channel="${sdEscape(data.channel)}"`,
    `event="${sdEscape(data.event)}"`,
    `actor="${sdEscape(data.actor)}"`,
    `ip="${sdEscape(data.ip)}"`,
    `entry="${data.id}"`,
  ].join(' ');

  const message = target.kind === 'wazuh'
    // Le décodeur de Wazuh lit le JSON qui suit l'en-tête : on lui donne les
    // champs entiers plutôt qu'une phrase dans laquelle il faudrait piocher.
    ? JSON.stringify({ ...data, app, host: machine })
    : String(data.message || '').replace(/[\r\n]+/g, ' ');

  return `<${pri(target.facility, row.level)}>1 ${data.timestamp} ${machine} ${app} - `
    + `${data.event || '-'} [toutadmin@${ENTERPRISE_ID} ${sd}] ${message}`;
}

/* -------------------------------------------------------------- transports */

/** Envoie un lot. Ne lève pas : rend le verdict, comme le reste du produit. */
async function send(target, lines, deps = {}) {
  if (deps.send) return deps.send(target, lines);
  if (!lines.length) return { ok: true, sent: 0 };

  try {
    if (target.transport === 'fichier') {
      fs.mkdirSync(path.dirname(target.path), { recursive: true });
      fs.appendFileSync(target.path, `${lines.join('\n')}\n`, { encoding: 'utf8', mode: 0o640 });
      return { ok: true, sent: lines.length };
    }
    if (target.transport === 'udp') return await sendUdp(target, lines);
    return await sendStream(target, lines);
  } catch (err) {
    return { ok: false, sent: 0, error: err.message };
  }
}

function sendUdp(target, lines) {
  const dgram = require('dgram');
  return new Promise((resolve) => {
    const socket = dgram.createSocket('udp4');
    let done = false;
    const finish = (verdict) => {
      if (done) return;
      done = true;
      try { socket.close(); } catch { /* déjà fermée */ }
      resolve(verdict);
    };

    socket.on('error', (err) => finish({ ok: false, sent: 0, error: err.message }));

    let index = 0;
    const next = () => {
      if (index >= lines.length) return finish({ ok: true, sent: lines.length });
      // Un datagramme trop gros est jeté en route sans prévenir : on coupe à
      // une taille qu'aucun collecteur ne refuse.
      const payload = Buffer.from(lines[index].slice(0, 8000), 'utf8');
      index += 1;
      socket.send(payload, target.port, target.host, (err) => (err
        ? finish({ ok: false, sent: index - 1, error: err.message })
        : next()));
    };
    next();
  });
}

function sendStream(target, lines) {
  const net = require('net');
  const tls = require('tls');

  return new Promise((resolve) => {
    let done = false;
    const finish = (verdict) => {
      if (done) return;
      done = true;
      try { socket.end(); } catch { /* déjà fermée */ }
      resolve(verdict);
    };

    const options = { host: target.host, port: target.port };
    const socket = target.transport === 'tls'
      ? tls.connect({ ...options, rejectUnauthorized: !target.allow_self_signed }, () => write())
      : net.connect(options, () => write());

    socket.setTimeout(TIMEOUT_MS);
    socket.on('timeout', () => finish({ ok: false, sent: 0, error: 'délai dépassé' }));
    socket.on('error', (err) => finish({ ok: false, sent: 0, error: err.message }));

    // Trame par saut de ligne : c'est ce qu'attendent rsyslog, syslog-ng et le
    // gestionnaire Wazuh sur un flux TCP.
    const write = () => socket.write(`${lines.join('\n')}\n`, 'utf8', (err) => (err
      ? finish({ ok: false, sent: 0, error: err.message })
      : finish({ ok: true, sent: lines.length })));
  });
}

/* ----------------------------------------------------------------- passage */

/** Les lignes qu'une cible n'a pas encore reçues, dans l'ordre où elles sont nées. */
function backlog(target, { limit = BATCH } = {}) {
  const clauses = ['id > ?', 'level <= ?'];
  const params = [target.cursor, target.min_level];

  const channels = String(target.channels || '').split(',').filter(Boolean);
  if (channels.length) {
    clauses.push(`channel IN (${channels.map(() => '?').join(', ')})`);
    params.push(...channels);
  }

  return db.prepare(`SELECT * FROM logs WHERE ${clauses.join(' AND ')} ORDER BY id LIMIT ?`)
    .all(...params, limit);
}

function recordOutcome(target, result, { moved = null } = {}) {
  if (result.ok) {
    db.prepare(`
      UPDATE log_targets SET cursor = ?, sent = sent + ?, failures = 0, status = ?, last_at = datetime('now')
      WHERE id = ?
    `).run(moved == null ? target.cursor : moved, result.sent || 0,
      String(result.message || `${result.sent || 0} ligne(s) envoyée(s).`).slice(0, 300), target.id);
    return;
  }

  const failures = (byId(target.id) || target).failures + 1;
  db.prepare("UPDATE log_targets SET failures = ?, status = ?, last_at = datetime('now') WHERE id = ?")
    .run(failures, String(result.error || 'échec').slice(0, 300), target.id);

  if (failures >= FAILURE_LIMIT) {
    db.prepare('UPDATE log_targets SET enabled = 0 WHERE id = ?').run(target.id);
    logs.warning('integration', 'journal.export.eteint',
      `Export du journal éteint après ${FAILURE_LIMIT} échecs : ${target.label}.`,
      { context: { cible: target.label, erreur: String(result.error || '').slice(0, 200) } });
  }
}

/** Sert une cible, une fois. Rend le compte de ce qui est parti. */
async function flushTarget(target, { deps = {}, limit = BATCH } = {}) {
  const rows = backlog(target, { limit });
  if (!rows.length) {
    db.prepare("UPDATE log_targets SET status = ?, last_at = datetime('now') WHERE id = ?")
      .run('À jour.', target.id);
    return { ok: true, sent: 0, target: target.id };
  }

  const lines = rows.map((row) => frame(target, row));
  const result = await send(target, lines, deps);
  recordOutcome(target, result, { moved: result.ok ? rows[rows.length - 1].id : null });
  return { ...result, target: target.id, read: rows.length };
}

/**
 * Sert toutes les cibles allumées. Appelée par le balayage périodique.
 *
 * Une cible en panne n'emporte pas les autres, et son propre filigrane
 * n'avance pas : ce qu'elle n'a pas reçu repart au passage suivant.
 */
async function flush({ deps = {}, limit = BATCH } = {}) {
  let sent = 0;
  let failed = 0;
  const served = list().filter((t) => t.enabled === 1);

  for (const target of served) {
    const result = await flushTarget(target, { deps, limit });
    if (result.ok) sent += result.sent || 0;
    else failed += 1;
  }

  return { targets: served.length, sent, failed };
}

/**
 * Essai : envoie une ligne qui dit ce qu'elle est, sans toucher au filigrane.
 *
 * C'est le seul moyen de savoir qu'un collecteur répond avant d'attendre le
 * premier incident pour s'en apercevoir.
 */
async function test(id, { deps = {} } = {}) {
  const target = byId(id);
  if (!target) return { ok: false, message: 'Cible introuvable.' };

  const line = frame(target, {
    id: 0,
    occurred_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
    level: 5,
    channel: 'systeme',
    event: 'journal.export.essai',
    message: `Essai d'export depuis Toutadmin vers « ${target.label} ».`,
    context: JSON.stringify({ essai: true }),
    actor_label: 'système',
    actor_id: null,
    ip: '',
    request_id: '',
  });

  const result = await send(target, [line], deps);
  db.prepare("UPDATE log_targets SET status = ?, last_at = datetime('now') WHERE id = ?")
    .run(result.ok ? 'Essai reçu par le collecteur.' : `Essai refusé : ${result.error}`.slice(0, 300), target.id);

  return result.ok
    ? { ok: true, message: 'Ligne d’essai envoyée.', line }
    : { ok: false, message: `Envoi impossible : ${result.error}`, line };
}

/** Ce que l'écran affiche en tête : l'état de l'export, en une ligne par cible. */
function summary() {
  const rows = list();
  const last = db.prepare('SELECT COALESCE(MAX(id), 0) AS last FROM logs').get().last;
  return {
    targets: rows.length,
    enabled: rows.filter((t) => t.enabled === 1).length,
    pending: rows.filter((t) => t.enabled === 1)
      .reduce((max, t) => Math.max(max, last - t.cursor), 0),
    failing: rows.filter((t) => t.failures > 0).length,
  };
}

module.exports = {
  TRANSPORTS, FORMATS, KINDS, ENTERPRISE_ID, BATCH, FAILURE_LIMIT,
  list, byId, check, create, update, remove, setEnabled, skipBacklog,
  pri, rfc5424Time, rfc3164Time, payload, frame,
  send, backlog, flushTarget, flush, test, summary,
};
