const db = require('./db');
const secrets = require('./secret-store');
const audit = require('./audit');
const notifications = require('./notifications');
const horloge = require('./horloge');

/**
 * Relève de la messagerie externe, membre par membre.
 *
 * Chaque salarié a une adresse professionnelle chez l'hébergeur de courriel de
 * l'entreprise. Ce module en rapporte les messages récents dans son espace,
 * pour qu'il n'ait pas à ouvrir un second outil pour savoir ce qui l'attend.
 *
 * Quatre règles tiennent tout le reste :
 *
 *   — **la boîte reste la source.** La relève est en lecture seule : elle ne
 *     marque rien comme lu, ne déplace rien, n'efface rien. Le membre lit la
 *     même boîte depuis son propre logiciel, et une relève qui marque les
 *     messages lui volerait ses non-lus ;
 *   — **l'administration branche, elle ne lit pas.** Les identifiants sont
 *     posés par un administrateur — un membre ne se donne pas une adresse —
 *     mais les messages relevés ne sont lisibles que par leur destinataire.
 *     C'est la même séparation que pour les signalements internes ;
 *   — **le mot de passe est chiffré en base**, comme les autres secrets de
 *     l'instance. Une copie de la base ne donne pas les boîtes du personnel ;
 *   — **la relève est bornée et rejouable.** Un nombre de messages par passage,
 *     une fenêtre de jours, et une contrainte d'unicité (membre, uid) qui fait
 *     qu'un message déjà rapporté ne l'est pas deux fois.
 */

const DEFAULTS = { port: 993, folder: 'INBOX', sinceDays: 30, batch: 50 };
const MAX_BATCH = 200;
const MAX_BODY = 20000;

// Après cette série d'échecs consécutifs, la relève de ce membre s'éteint
// d'elle-même. Continuer à présenter un mot de passe refusé, c'est faire
// verrouiller le compte chez l'hébergeur — et le salarié n'y est pour rien.
const FAILURE_LIMIT = 5;

const COLUMNS = `id, first_name, last_name, mail_address, mail_imap_host, mail_imap_port,
                 mail_imap_secure, mail_password, mail_smtp_host, mail_smtp_port,
                 mail_sync_enabled, mail_synced_at, mail_sync_report, mail_sync_failures`;

/** Les réglages d'un membre, mot de passe en clair compris. Usage interne. */
function settingsFor(userId) {
  const row = db.prepare(`SELECT ${COLUMNS} FROM users WHERE id = ?`).get(Number(userId));
  if (!row) return null;

  return {
    userId: row.id,
    name: `${row.first_name} ${row.last_name}`,
    address: row.mail_address || '',
    host: row.mail_imap_host || '',
    port: Number(row.mail_imap_port) || DEFAULTS.port,
    secure: row.mail_imap_secure !== 0,
    password: secrets.decrypt(row.mail_password),
    smtpHost: row.mail_smtp_host || '',
    smtpPort: row.mail_smtp_port || null,
    enabled: row.mail_sync_enabled === 1,
    syncedAt: row.mail_synced_at,
    report: row.mail_sync_report || '',
    failures: row.mail_sync_failures || 0,
  };
}

/** Ce qu'on montre à l'écran : la présence du mot de passe, jamais sa valeur. */
function displaySettings(userId) {
  const row = db.prepare(`SELECT ${COLUMNS} FROM users WHERE id = ?`).get(Number(userId));
  if (!row) return null;

  const current = settingsFor(userId);
  return { ...current, password: secrets.mask(row.mail_password) };
}

/** Une adresse, un serveur, un mot de passe : sans les trois, rien à relever. */
function isReady(current) {
  return Boolean(current && current.address && current.host && current.password);
}

/**
 * Enregistre les identifiants. Réservé à l'administration.
 *
 * Le mot de passe laissé vide ne l'efface pas : c'est le cas courant quand on
 * ne vient corriger qu'un nom de serveur, et redemander le mot de passe à
 * chaque retouche finirait par le faire écrire sur un papier.
 */
function setCredentials(userId, values = {}) {
  const row = db.prepare('SELECT mail_password FROM users WHERE id = ?').get(Number(userId));
  if (!row) return { ok: false, message: 'Membre introuvable.' };

  const port = values.port === '' || values.port == null ? null : Number(values.port);
  if (port !== null && (!Number.isInteger(port) || port < 1 || port > 65535)) {
    return { ok: false, message: 'Port IMAP invalide (1 à 65535).' };
  }

  const host = String(values.host || '').trim().slice(0, 200);
  const wanted = values.enabled === true || values.enabled === '1' || values.enabled === 'on';

  const password = values.password == null || values.password === ''
    ? row.mail_password
    : secrets.encrypt(String(values.password).slice(0, 400));

  // Allumer la relève sans serveur ni mot de passe ne relèverait rien et
  // s'éteindrait au premier passage : autant le dire tout de suite.
  if (wanted && !(host && secrets.decrypt(password))) {
    return { ok: false, message: 'Pour relever cette boîte, il faut un serveur IMAP et un mot de passe.' };
  }

  db.prepare(`
    UPDATE users
    SET mail_imap_host = ?, mail_imap_port = ?, mail_imap_secure = ?, mail_password = ?,
        mail_sync_enabled = ?, mail_sync_failures = CASE WHEN ? = 1 THEN 0 ELSE mail_sync_failures END
    WHERE id = ?
  `).run(
    host,
    port,
    values.secure === false || values.secure === '0' ? 0 : 1,
    password,
    wanted ? 1 : 0,
    wanted ? 1 : 0,
    Number(userId),
  );

  return { ok: true };
}

/** Coupe la relève sans toucher aux identifiants. */
function disable(userId, reason = '') {
  db.prepare('UPDATE users SET mail_sync_enabled = 0, mail_sync_report = ? WHERE id = ?')
    .run(String(reason).slice(0, 300), Number(userId));
}

function recordOutcome(userId, result) {
  const ok = result.ok === true;
  db.prepare(`
    UPDATE users
    SET mail_sync_report = ?, mail_synced_at = datetime('now'),
        mail_sync_failures = CASE WHEN ? = 1 THEN 0 ELSE mail_sync_failures + 1 END
    WHERE id = ?
  `).run(String(result.message || '').slice(0, 300), ok ? 1 : 0, Number(userId));

  if (ok) return;

  // Le compte a refusé cinq fois de suite : on arrête d'insister. L'hébergeur
  // verrouillerait la boîte, et le membre ne saurait pas pourquoi.
  const failures = db.prepare('SELECT mail_sync_failures FROM users WHERE id = ?').get(Number(userId));
  if (failures && failures.mail_sync_failures >= FAILURE_LIMIT) {
    disable(userId, `Relève éteinte après ${FAILURE_LIMIT} échecs : ${result.message || ''}`.slice(0, 300));
    notifyAdmins(userId);
  }
}

/** Prévient l'administration, pas le membre : c'est un réglage, pas son travail. */
function notifyAdmins(userId) {
  const member = db.prepare('SELECT first_name, last_name FROM users WHERE id = ?').get(Number(userId));
  if (!member) return;

  const admins = db.prepare("SELECT id FROM users WHERE role = 'admin' AND active = 1").all();
  for (const admin of admins) {
    notifications.push({
      userId: admin.id,
      kind: 'messagerie',
      title: 'Relève de messagerie éteinte',
      body: `La boîte de ${member.first_name} ${member.last_name} a refusé la connexion ${FAILURE_LIMIT} fois de suite.`,
      link: `/admin/employes/${userId}/modifier`,
      dedupeKey: `mail-sync-off-${userId}`,
    });
  }
}

/** La connexion. Injectable : les tests fournissent leur propre client. */
async function connect(current, deps = {}) {
  if (deps.connect) return deps.connect(current);

  const { ImapFlow } = require('imapflow');
  const client = new ImapFlow({
    host: current.host,
    port: current.port,
    secure: current.secure,
    auth: { user: current.address, pass: current.password },
    // Le journal d'imapflow recopierait les en-têtes des courriels du membre.
    logger: false,
  });
  await client.connect();
  return client;
}

const since = (days) => new Date(Date.now() - days * 86400000);

const addressOf = (parsed) => {
  const first = parsed && parsed.from && parsed.from.value && parsed.from.value[0];
  return {
    name: first && first.name ? String(first.name).slice(0, 200) : '',
    address: first && first.address ? String(first.address).slice(0, 254) : (parsed && parsed.from ? String(parsed.from.text || '').slice(0, 254) : ''),
  };
};

/**
 * Relève la boîte d'un membre. Ne lève jamais : un serveur indisponible ne doit
 * pas emporter le balayage des autres membres avec lui.
 */
async function fetchFor(userId, { deps = {}, req = null, batch = DEFAULTS.batch, sinceDays = DEFAULTS.sinceDays } = {}) {
  const current = settingsFor(userId);
  if (!current) return { ok: false, message: 'Membre introuvable.' };
  if (!current.enabled) return { ok: false, message: 'Relève éteinte pour ce membre.' };
  if (!isReady(current)) return { ok: false, message: 'Boîte non configurée.' };

  const limit = Math.min(MAX_BATCH, Math.max(1, Number(batch) || DEFAULTS.batch));

  let client;
  try {
    client = await connect(current, deps);
  } catch (err) {
    const result = { ok: false, message: `Connexion refusée : ${err.message}` };
    recordOutcome(userId, result);
    return result;
  }

  const parser = deps.parseMail || (async (source) => require('mailparser').simpleParser(source));
  const insert = db.prepare(`
    INSERT OR IGNORE INTO external_messages
      (user_id, uid, from_name, from_address, subject, body, attachments, sent_at, seen_on_server)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `);

  let scanned = 0;
  let added = 0;

  try {
    const lock = await client.getMailboxLock(DEFAULTS.folder);
    try {
      const uids = await client.search({ since: since(sinceDays) }, { uid: true });
      // Les plus récents d'abord : si la fenêtre déborde le lot, mieux vaut
      // rapporter ce qui vient d'arriver que ce qui date de trois semaines.
      const wanted = (uids || []).slice(-limit).reverse();

      for (const uid of wanted) {
        scanned += 1;
        const downloaded = await client.download(String(uid), undefined, { uid: true });
        const parsed = await parser(downloaded.content);
        const from = addressOf(parsed);

        const done = insert.run(
          Number(userId),
          String(uid),
          from.name,
          from.address,
          String(parsed.subject || '').slice(0, 300),
          String(parsed.text || '').slice(0, MAX_BODY),
          (parsed.attachments || []).length,
          parsed.date ? new Date(parsed.date).toISOString() : null,
          parsed.seen ? 1 : 0,
        );
        if (done.changes) added += 1;
      }
    } finally {
      lock.release();
    }
  } catch (err) {
    const result = { ok: false, message: `Relève interrompue : ${err.message}`, scanned, added };
    recordOutcome(userId, result);
    try { await client.logout(); } catch { /* la connexion est déjà perdue */ }
    return result;
  }

  try { await client.logout(); } catch { /* rien à sauver */ }

  const result = {
    ok: true,
    scanned,
    added,
    message: `${added} message(s) nouveau(x) sur ${scanned} relevé(s).`,
  };
  recordOutcome(userId, result);
  if (added && req) {
    // Ce qu'on journalise, c'est le passage — jamais un sujet ni un expéditeur.
    audit.log(req, 'messagerie.releve', 'users', Number(userId), { messages: added });
  }
  return result;
}

/** Essai de connexion : le seul moyen de savoir que les identifiants passent. */
async function test(userId, { deps = {} } = {}) {
  const current = settingsFor(userId);
  if (!isReady(current)) return { ok: false, message: 'Boîte non configurée.' };

  let client;
  try {
    client = await connect(current, deps);
  } catch (err) {
    return { ok: false, message: `Connexion refusée : ${err.message}` };
  }

  try {
    const lock = await client.getMailboxLock(DEFAULTS.folder);
    let waiting = 0;
    try {
      const uids = await client.search({ since: since(DEFAULTS.sinceDays) }, { uid: true });
      waiting = (uids || []).length;
    } finally {
      lock.release();
    }
    await client.logout();
    return { ok: true, waiting, message: `Boîte ouverte : ${waiting} message(s) dans la fenêtre.` };
  } catch (err) {
    try { await client.logout(); } catch { /* connexion perdue */ }
    return { ok: false, message: `Boîte illisible : ${err.message}` };
  }
}

/** Les membres dont la boîte est à relever, dans l'ordre du plus ancien passage. */
function due() {
  return db.prepare(`
    SELECT id FROM users
    WHERE active = 1 AND mail_sync_enabled = 1
      AND mail_address != '' AND mail_imap_host != '' AND mail_password != ''
    ORDER BY COALESCE(mail_synced_at, '') ASC
  `).all().map((r) => r.id);
}

/**
 * Relève tout le monde. Appelée par le balayage périodique.
 *
 * Bornée par passage : sur une instance de deux cents personnes, tout relever
 * d'un coup tiendrait le balayage pendant des minutes. Les boîtes non servies
 * ce tour-ci passent en tête du suivant, puisque l'ordre est celui du dernier
 * passage.
 */
async function fetchAll({ deps = {}, members = 25 } = {}) {
  const ids = due().slice(0, Math.max(1, Number(members) || 25));
  let added = 0;
  let failed = 0;

  for (const id of ids) {
    const result = await fetchFor(id, { deps });
    if (result.ok) added += result.added || 0;
    else failed += 1;
  }

  return { boxes: ids.length, added, failed };
}

/** Ce que le membre voit dans son espace. Lui seul. */
function inbox(userId, { limit = 50 } = {}) {
  return db.prepare(`
    SELECT id, uid, from_name, from_address, subject, body, attachments, sent_at, fetched_at
    FROM external_messages
    WHERE user_id = ?
    ORDER BY COALESCE(sent_at, fetched_at) DESC
    LIMIT ?
  `).all(Number(userId), Math.min(200, Math.max(1, Number(limit) || 50)));
}

/** Un message relevé, et seulement si c'est bien le destinataire qui l'ouvre. */
function message(id, userId) {
  return db.prepare('SELECT * FROM external_messages WHERE id = ? AND user_id = ?')
    .get(Number(id), Number(userId)) || null;
}

/**
 * Efface la copie locale. La boîte, elle, n'est pas touchée : le message est
 * toujours chez l'hébergeur, et une relève ultérieure pourrait le rapporter.
 */
function forget(userId) {
  return db.prepare('DELETE FROM external_messages WHERE user_id = ?').run(Number(userId)).changes;
}

/** La copie locale ne sert qu'à lire : passé ce délai, elle n'a plus d'objet. */
function purge(days = 90) {
  return db.prepare(
    'DELETE FROM external_messages WHERE fetched_at < ?',
  ).run(horloge.instant(`-${Math.max(1, Number(days) || 90)} days`)).changes;
}

module.exports = {
  DEFAULTS, MAX_BATCH, FAILURE_LIMIT,
  settingsFor, displaySettings, isReady, setCredentials, disable, recordOutcome,
  connect, fetchFor, test, due, fetchAll, inbox, message, forget, purge,
};
