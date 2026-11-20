const db = require('./db');
const horloge = require('./horloge');

/**
 * Centre de journaux.
 *
 * Le journal d'audit répond à « qui a fait quoi » : il est scellé, il se garde
 * des années, et il ne contient que des actes. Celui-ci répond à une autre
 * question — « que s'est-il passé » — et c'est pour cela qu'il est séparé :
 *
 *   — il porte des **niveaux**, ceux de la norme syslog (RFC 5424, de 0 à 7).
 *     Un journal sans niveaux oblige à tout lire ou à ne rien lire ;
 *   — il porte des **canaux** : l'authentification, la sécurité, les données,
 *     les intégrations, le système. On ne cherche pas une erreur de webhook au
 *     milieu des connexions ;
 *   — il se **purge** tout seul, par niveau : une ligne de débogage ne vaut pas
 *     la peine d'être gardée aussi longtemps qu'une alerte de sécurité ;
 *   — il se **recopie ailleurs** (voir `log-forward.js`), parce qu'un journal
 *     qui ne vit que dans la base qu'il surveille ne prouve rien le jour où
 *     quelqu'un a la main sur cette base.
 *
 * Ce qu'il ne fait pas : remplacer le journal d'audit. Les actes continuent d'y
 * aller, scellés. Les actions d'administration sont recopiées ici en plus, pour
 * qu'une recherche unique retrouve tout — mais la preuve reste là-bas.
 */

/** Les huit sévérités de la RFC 5424, dans l'ordre de la norme. */
const LEVELS = [
  { value: 0, key: 'urgence', keyword: 'emerg' },
  { value: 1, key: 'alerte', keyword: 'alert' },
  { value: 2, key: 'critique', keyword: 'crit' },
  { value: 3, key: 'erreur', keyword: 'err' },
  { value: 4, key: 'avertissement', keyword: 'warning' },
  { value: 5, key: 'remarquable', keyword: 'notice' },
  { value: 6, key: 'information', keyword: 'info' },
  { value: 7, key: 'debogage', keyword: 'debug' },
];

const LEVEL_BY_KEY = Object.fromEntries(LEVELS.map((l) => [l.key, l]));
const DEFAULT_LEVEL = 6;

/**
 * Les canaux. Un canal dit de quoi parle la ligne, pas d'où elle vient : c'est
 * ce qu'on cherche quand on ouvre un journal.
 */
const CHANNELS = [
  'authentification',   // connexions, verrouillages, double authentification
  'securite',           // plafonds, jetons refusés, accès interdits
  'administration',     // ce que fait l'administration, en écho du journal d'audit
  'donnees',            // imports, exports, effacements, sauvegardes
  'integration',        // webhooks, messagerie, API, plateformes
  'planification',      // balayage périodique et ce qu'il déclenche
  'systeme',            // démarrage, migrations, erreurs non rattrapées
];

const MAX_MESSAGE = 500;
const MAX_CONTEXT = 4000;
const ENTRIES_PER_PAGE = 60;

/**
 * Rétention par niveau, en jours. Une ligne de débogage n'a pas à vivre aussi
 * longtemps qu'une alerte : la garder coûte de la place et noie la recherche.
 */
const RETENTION = { critical: 365, warning: 180, info: 90, debug: 7 };

/** Ce qui ne doit jamais entrer dans un journal, même par mégarde. */
const SECRET_KEYS = /^(password|mot_de_passe|secret|token|jeton|authorization|cookie|totp|otp|api_key|cle)$/i;

const levelOf = (level) => {
  if (typeof level === 'number') return Math.min(7, Math.max(0, Math.trunc(level)));
  const found = LEVEL_BY_KEY[String(level || '').toLowerCase()];
  return found ? found.value : DEFAULT_LEVEL;
};

const levelKey = (value) => (LEVELS[levelOf(value)] || LEVELS[DEFAULT_LEVEL]).key;
const levelKeyword = (value) => (LEVELS[levelOf(value)] || LEVELS[DEFAULT_LEVEL]).keyword;

const channelOf = (channel) => {
  const name = String(channel || '').toLowerCase();
  return CHANNELS.includes(name) ? name : 'systeme';
};

/**
 * Nettoie le contexte avant écriture.
 *
 * Un journal se lit, s'exporte et part chez un collecteur : ce qui y entre en
 * sort. Une clé qui ressemble à un secret est donc remplacée par une mention,
 * et non écrite — même si l'appelant s'est trompé.
 */
function scrub(context, depth = 0) {
  if (context == null || depth > 4) return context == null ? null : '…';
  if (Array.isArray(context)) return context.slice(0, 50).map((v) => scrub(v, depth + 1));
  if (typeof context !== 'object') return context;

  const out = {};
  for (const [key, value] of Object.entries(context).slice(0, 50)) {
    out[key] = SECRET_KEYS.test(key) ? '[secret retiré]' : scrub(value, depth + 1);
  }
  return out;
}

const labelOf = (user) => {
  if (!user) return 'système';
  const name = [user.firstName || user.first_name, user.lastName || user.last_name].filter(Boolean).join(' ');
  return (name ? `${name} <${user.email}>` : user.email || `#${user.id}`).slice(0, 200);
};

const insert = db.prepare(`
  INSERT INTO logs (occurred_at, level, channel, event, message, context, actor_id, actor_label, ip, request_id)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
`);

/**
 * Écrit une ligne. Ne lève jamais : un journal qui fait tomber ce qu'il observe
 * est pire que pas de journal du tout.
 */
/*
 * « actorLabel » existe pour un seul cas : l'emprunt d'identité, où l'auteur
 * responsable n'est pas celui qui est en session. Le journal d'audit sait déjà
 * nommer les deux ; sans ce paramètre, le centre de journaux attribuerait l'acte
 * à la personne empruntée, et les deux journaux ne diraient pas la même chose.
 */
function record({ level = DEFAULT_LEVEL, channel = 'systeme', event = '', message = '', context = null, req = null, actor = null, actorId = null, actorLabel = '', ip = '' } = {}) {
  try {
    const user = actor || (req && req.session ? req.session.user : null);
    const cleaned = scrub(context);
    const row = insert.run(
      new Date().toISOString().slice(0, 19).replace('T', ' '),
      levelOf(level),
      channelOf(channel),
      String(event || '').slice(0, 120),
      String(message || '').slice(0, MAX_MESSAGE),
      cleaned == null ? '' : JSON.stringify(cleaned).slice(0, MAX_CONTEXT),
      actorId !== null ? actorId : (user ? user.id : null),
      actorLabel || labelOf(user),
      String(ip || (req && req.ip) || '').slice(0, 64),
      String((req && req.id) || '').slice(0, 40),
    );
    return row.lastInsertRowid;
  } catch (err) {
    console.error('Journal indisponible :', err.message);
    return null;
  }
}

// Les raccourcis qu'on écrit vraiment dans le code appelant.
const at = (level) => (channel, event, message, extra = {}) =>
  record({ level, channel, event, message, ...extra });

const emergency = at(0);
const alert = at(1);
const critical = at(2);
const error = at(3);
const warning = at(4);
const notice = at(5);
const info = at(6);
const debug = at(7);

/* ------------------------------------------------------------- consultation */

function filtersOf({ level = '', maxLevel = '', channel = '', event = '', search = '', actorId = null, from = '', to = '' } = {}) {
  const clauses = [];
  const params = [];

  if (level !== '' && level != null) { clauses.push('level = ?'); params.push(levelOf(level)); }
  // « à partir de » : au sens de la norme, plus grave veut dire plus petit.
  if (maxLevel !== '' && maxLevel != null) { clauses.push('level <= ?'); params.push(levelOf(maxLevel)); }
  if (channel) { clauses.push('channel = ?'); params.push(channelOf(channel)); }
  if (event) { clauses.push('event LIKE ?'); params.push(`${event}%`); }
  if (actorId) { clauses.push('actor_id = ?'); params.push(Number(actorId)); }
  if (from) { clauses.push('occurred_at >= ?'); params.push(from); }
  if (to) { clauses.push('occurred_at <= ?'); params.push(`${to} 23:59:59`); }
  if (search) {
    clauses.push('(message LIKE ? OR context LIKE ? OR event LIKE ? OR actor_label LIKE ? OR ip LIKE ?)');
    const like = `%${search}%`;
    params.push(like, like, like, like, like);
  }

  return { where: clauses.length ? `WHERE ${clauses.join(' AND ')}` : '', params };
}

function list(options = {}) {
  const { where, params } = filtersOf(options);
  const total = db.prepare(`SELECT COUNT(*) AS n FROM logs ${where}`).get(...params).n;
  const pages = Math.max(1, Math.ceil(total / ENTRIES_PER_PAGE));
  const page = Math.min(Math.max(1, Number(options.page) || 1), pages);

  const rows = db.prepare(`SELECT * FROM logs ${where} ORDER BY id DESC LIMIT ? OFFSET ?`)
    .all(...params, ENTRIES_PER_PAGE, (page - 1) * ENTRIES_PER_PAGE);

  return { rows: rows.map(decorate), total, page, pages };
}

function decorate(row) {
  let context = null;
  if (row.context) {
    try { context = JSON.parse(row.context); } catch { context = row.context; }
  }
  return { ...row, levelKey: levelKey(row.level), levelKeyword: levelKeyword(row.level), parsedContext: context };
}

function byId(id) {
  const row = db.prepare('SELECT * FROM logs WHERE id = ?').get(Number(id));
  return row ? decorate(row) : null;
}

/** De quoi dessiner l'état du journal sans parcourir des milliers de lignes. */
function stats({ days = 7 } = {}) {
  const since = horloge.instant(`-${Math.max(1, Number(days) || 7)} days`);
  const byLevel = db.prepare(`
    SELECT level, COUNT(*) AS n FROM logs WHERE occurred_at >= ? GROUP BY level ORDER BY level
  `).all(since).map((r) => ({ ...r, key: levelKey(r.level) }));

  const byChannel = db.prepare(`
    SELECT channel, COUNT(*) AS n FROM logs WHERE occurred_at >= ? GROUP BY channel ORDER BY n DESC
  `).all(since);

  const byDay = db.prepare(`
    SELECT substr(occurred_at, 1, 10) AS day, COUNT(*) AS n,
           SUM(CASE WHEN level <= 3 THEN 1 ELSE 0 END) AS graves
    FROM logs WHERE occurred_at >= ? GROUP BY day ORDER BY day
  `).all(since);

  return {
    total: db.prepare('SELECT COUNT(*) AS n FROM logs').get().n,
    window: db.prepare('SELECT COUNT(*) AS n FROM logs WHERE occurred_at >= ?').get(since).n,
    serious: db.prepare('SELECT COUNT(*) AS n FROM logs WHERE level <= 3 AND occurred_at >= ?').get(since).n,
    pending: db.prepare(
      'SELECT COUNT(*) AS n FROM logs WHERE id > (SELECT COALESCE(MIN(cursor), 0) FROM log_targets WHERE enabled = 1)',
    ).get().n,
    oldest: (db.prepare('SELECT occurred_at FROM logs ORDER BY id LIMIT 1').get() || {}).occurred_at || null,
    byLevel,
    byChannel,
    byDay,
    days: Math.max(1, Number(days) || 7),
  };
}

/** Les événements déjà rencontrés, pour alimenter le filtre sans les coder en dur. */
function knownEvents({ limit = 200 } = {}) {
  return db.prepare("SELECT DISTINCT event FROM logs WHERE event != '' ORDER BY event LIMIT ?")
    .all(limit).map((r) => r.event);
}

function toCsv(rows) {
  const escape = (value) => `"${String(value == null ? '' : value).replace(/"/g, '""')}"`;
  const head = ['Date', 'Niveau', 'Canal', 'Événement', 'Message', 'Contexte', 'Auteur', 'IP'];
  const lines = rows.map((r) => [
    r.occurred_at, levelKey(r.level), r.channel, r.event, r.message, r.context, r.actor_label, r.ip,
  ].map(escape).join(';'));
  return [head.map(escape).join(';'), ...lines].join('\n');
}

/**
 * La plus haute ligne qu'on ait le droit d'effacer.
 *
 * Une cible active qui n'a pas encore reçu une ligne la recevra au prochain
 * passage : la purge ne doit pas la lui prendre entre-temps. S'il n'y a aucune
 * cible active, rien ne retient la purge.
 */
function purgeCeiling() {
  const row = db.prepare('SELECT MIN(cursor) AS floor FROM log_targets WHERE enabled = 1').get();
  return row && row.floor != null ? row.floor : Number.MAX_SAFE_INTEGER;
}

/**
 * Purge par niveau. Rend le détail plutôt qu'un total : « 4 000 lignes
 * supprimées » ne dit pas si ce sont des traces de débogage ou des alertes.
 *
 * Une ligne qu'une cible active n'a pas encore reçue n'est jamais purgée : la
 * purge ne doit pas faire disparaître ce que l'export n'a pas encore vu.
 */
function purge(retention = RETENTION) {
  const removed = {};
  const ceiling = purgeCeiling();
  const bands = [
    ['debug', 'level = 7', retention.debug],
    ['info', 'level IN (5, 6)', retention.info],
    ['warning', 'level = 4', retention.warning],
    ['critical', 'level <= 3', retention.critical],
  ];

  for (const [name, clause, days] of bands) {
    const keep = Math.max(1, Number(days) || RETENTION[name]);
    removed[name] = db.prepare(
      `DELETE FROM logs WHERE ${clause} AND occurred_at < ? AND id <= ?`,
    ).run(horloge.instant(`-${keep} days`), ceiling).changes;
  }

  const total = Object.values(removed).reduce((sum, n) => sum + n, 0);
  if (total) {
    record({
      level: 5, channel: 'systeme', event: 'journal.purge',
      message: `${total} ligne(s) de journal purgée(s).`, context: removed,
    });
  }
  return { ...removed, total };
}

/** Vide le journal. Réservé à l'administration, et consigné comme tel. */
function clear({ req = null } = {}) {
  const removed = db.prepare('DELETE FROM logs').run().changes;
  record({
    level: 4, channel: 'administration', event: 'journal.vide', req,
    message: `Journal vidé : ${removed} ligne(s) supprimée(s).`,
  });
  return removed;
}

module.exports = {
  LEVELS, CHANNELS, RETENTION, ENTRIES_PER_PAGE, DEFAULT_LEVEL,
  levelOf, levelKey, levelKeyword, channelOf, scrub,
  record, emergency, alert, critical, error, warning, notice, info, debug,
  list, byId, stats, knownEvents, toCsv, purge, purgeCeiling, clear,
};
