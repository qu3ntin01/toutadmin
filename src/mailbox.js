const settings = require('./settings');
const secrets = require('./secret-store');
const intake = require('./intake');
const audit = require('./audit');

/**
 * Capture d'une boîte aux lettres par IMAP.
 *
 * Les factures fournisseurs arrivent par courriel, à une adresse dédiée du
 * genre « factures@ ». Ce module relève cette boîte, prend les pièces jointes
 * qui ressemblent à des factures, les fait passer par la même réception que
 * les dépôts manuels, puis marque le message comme lu — ou le range dans un
 * dossier, au choix.
 *
 * Ce qu'il ne fait pas, volontairement :
 *
 *   — **il ne supprime jamais un message.** La boîte reste la source ; le CMS
 *     n'en est qu'un lecteur. Une capture qui efface est une capture qu'on ne
 *     peut pas rejouer ;
 *   — **il ne lit que ce qu'on lui désigne** : un dossier, et les messages non
 *     lus depuis un nombre de jours borné. Pas d'aspiration d'archive ;
 *   — **il ne crée aucune facture.** Les pièces atterrissent dans la corbeille
 *     du comptable, qui décide.
 */

const KEYS = {
  enabled: 'imap.enabled',
  host: 'imap.host',
  port: 'imap.port',
  secure: 'imap.secure',
  user: 'imap.user',
  password: 'imap.password',
  folder: 'imap.folder',
  action: 'imap.action',
  moveFolder: 'imap.move_folder',
  sinceDays: 'imap.since_days',
  batch: 'imap.batch',
  allowSelfSigned: 'imap.allow_self_signed',
  status: 'imap.status',
};

const ACTIONS = [
  { key: 'seen', label: 'Marquer le message comme lu' },
  { key: 'move', label: 'Déplacer le message dans un dossier' },
];

const DEFAULTS = { port: 993, folder: 'INBOX', action: 'seen', sinceDays: 30, batch: 25, moveFolder: 'Traitées' };
const MAX_BATCH = 100;

// Les pièces jointes qui méritent d'être lues : une signature d'image ou un
// calendrier .ics ne sont pas des factures.
const ATTACHMENT_TYPES = {
  'application/pdf': 'application/pdf',
  'application/x-pdf': 'application/pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
};

function config() {
  return {
    enabled: settings.get(KEYS.enabled) === '1',
    host: settings.get(KEYS.host) || '',
    port: Number(settings.get(KEYS.port)) || DEFAULTS.port,
    secure: settings.get(KEYS.secure) !== '0',
    user: settings.get(KEYS.user) || '',
    password: secrets.decrypt(settings.get(KEYS.password)),
    folder: settings.get(KEYS.folder) || DEFAULTS.folder,
    action: ACTIONS.some((a) => a.key === settings.get(KEYS.action)) ? settings.get(KEYS.action) : DEFAULTS.action,
    moveFolder: settings.get(KEYS.moveFolder) || DEFAULTS.moveFolder,
    sinceDays: Number(settings.get(KEYS.sinceDays)) || DEFAULTS.sinceDays,
    batch: Math.min(MAX_BATCH, Number(settings.get(KEYS.batch)) || DEFAULTS.batch),
    allowSelfSigned: settings.get(KEYS.allowSelfSigned) === '1',
  };
}

function displayConfig() {
  return { ...config(), password: secrets.mask(settings.get(KEYS.password)) };
}

function setConfig(values) {
  const host = String(values.host || '').trim().slice(0, 200);
  const user = String(values.user || '').trim().slice(0, 200);
  const port = Number(values.port) || DEFAULTS.port;
  const sinceDays = Number(values.sinceDays) || DEFAULTS.sinceDays;
  const batch = Number(values.batch) || DEFAULTS.batch;

  if (!host) return { ok: false, message: "L'hôte IMAP est requis." };
  if (!user) return { ok: false, message: "L'identifiant est requis." };
  if (!Number.isInteger(port) || port < 1 || port > 65535) return { ok: false, message: 'Port invalide.' };
  if (!Number.isInteger(sinceDays) || sinceDays < 1 || sinceDays > 365) {
    return { ok: false, message: 'La fenêtre de relève tient entre 1 et 365 jours.' };
  }
  if (!Number.isInteger(batch) || batch < 1 || batch > MAX_BATCH) {
    return { ok: false, message: `Le lot tient entre 1 et ${MAX_BATCH} messages.` };
  }
  if (!ACTIONS.some((a) => a.key === values.action)) return { ok: false, message: 'Action inconnue.' };

  settings.set(KEYS.host, host);
  settings.set(KEYS.port, String(port));
  settings.set(KEYS.secure, values.secure ? '1' : '0');
  settings.set(KEYS.user, user);
  settings.set(KEYS.folder, String(values.folder || DEFAULTS.folder).trim().slice(0, 120));
  settings.set(KEYS.action, values.action);
  settings.set(KEYS.moveFolder, String(values.moveFolder || DEFAULTS.moveFolder).trim().slice(0, 120));
  settings.set(KEYS.sinceDays, String(sinceDays));
  settings.set(KEYS.batch, String(batch));
  settings.set(KEYS.allowSelfSigned, values.allowSelfSigned ? '1' : '0');

  // Un mot de passe laissé vide conserve le précédent.
  const typed = String(values.password || '').trim();
  if (typed) settings.set(KEYS.password, secrets.encrypt(typed));

  const enabled = Boolean(values.enabled);
  if (enabled && !config().password) return { ok: false, message: 'Renseignez le mot de passe avant d\'activer la relève.' };
  settings.set(KEYS.enabled, enabled ? '1' : '0');

  return { ok: true };
}

function isReady() {
  const current = config();
  return Boolean(current.host && current.user && current.password);
}

function status() {
  const stored = settings.get(KEYS.status);
  if (!stored) return null;
  try {
    return JSON.parse(stored);
  } catch {
    return null;
  }
}

function recordStatus(result) {
  settings.set(KEYS.status, JSON.stringify({
    ok: Boolean(result.ok),
    message: String(result.message || '').slice(0, 300),
    at: new Date().toISOString(),
    received: result.received || 0,
    scanned: result.scanned || 0,
  }));
}

/** La connexion. Injectable : les tests fournissent leur propre client. */
async function connect(current, deps = {}) {
  if (deps.connect) return deps.connect(current);

  const { ImapFlow } = require('imapflow');
  const client = new ImapFlow({
    host: current.host,
    port: current.port,
    secure: current.secure,
    auth: { user: current.user, pass: current.password },
    // Le journal d'imapflow est bavard et contiendrait des en-têtes de courriel.
    logger: false,
    tls: { rejectUnauthorized: !current.allowSelfSigned },
  });
  await client.connect();
  return client;
}

const since = (days) => new Date(Date.now() - days * 86400000);

function attachmentsOf(parsed) {
  return (parsed.attachments || []).filter((attachment) => {
    const type = String(attachment.contentType || '').toLowerCase().split(';')[0].trim();
    const name = String(attachment.filename || '').toLowerCase();
    return Boolean(ATTACHMENT_TYPES[type]) || name.endsWith('.pdf') || name.endsWith('.docx');
  });
}

const mimeOf = (attachment) => {
  const type = String(attachment.contentType || '').toLowerCase().split(';')[0].trim();
  if (ATTACHMENT_TYPES[type]) return ATTACHMENT_TYPES[type];
  return String(attachment.filename || '').toLowerCase().endsWith('.docx')
    ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    : 'application/pdf';
};

/**
 * Relève la boîte une fois. Rend le compte de ce qui a été vu et de ce qui a
 * été retenu ; ne lève jamais, pour qu'un serveur mail indisponible n'emporte
 * pas le balayage périodique avec lui.
 */
async function fetchOnce({ deps = {}, req = null } = {}) {
  if (!isReady()) return { ok: false, message: 'Capture non configurée.' };

  const current = config();
  let client;
  try {
    client = await connect(current, deps);
  } catch (err) {
    const result = { ok: false, message: `Connexion refusée : ${err.message}` };
    recordStatus(result);
    return result;
  }

  const parser = deps.parseMail || (async (source) => require('mailparser').simpleParser(source));
  const received = [];
  const skipped = [];
  let scanned = 0;

  try {
    const lock = await client.getMailboxLock(current.folder);
    try {
      const uids = await client.search({ seen: false, since: since(current.sinceDays) }, { uid: true });
      const batch = (uids || []).slice(0, current.batch);

      for (const uid of batch) {
        scanned += 1;
        const downloaded = await client.download(String(uid), undefined, { uid: true });
        const parsed = await parser(downloaded.content);

        const attachments = attachmentsOf(parsed);
        if (!attachments.length) {
          skipped.push({ uid, reason: 'aucune pièce jointe exploitable' });
        }

        for (const attachment of attachments) {
          const outcome = await intake.receive({
            buffer: attachment.content,
            originalName: attachment.filename || 'facture.pdf',
            mimeType: mimeOf(attachment),
            source: 'Courriel',
            deps,
            mail: {
              uid: String(uid),
              from: parsed.from ? parsed.from.text : '',
              subject: parsed.subject || '',
              date: parsed.date ? new Date(parsed.date).toISOString() : null,
            },
          });
          if (outcome.ok) received.push({ uid, id: outcome.id, name: attachment.filename });
          else skipped.push({ uid, reason: outcome.message });
        }

        // Le message est marqué lu dans tous les cas : sans cela, un courriel
        // sans pièce jointe reviendrait à chaque relève.
        await client.messageFlagsAdd(String(uid), ['\\Seen'], { uid: true });
        if (current.action === 'move' && current.moveFolder) {
          try {
            await client.messageMove(String(uid), current.moveFolder, { uid: true });
          } catch (err) {
            skipped.push({ uid, reason: `déplacement impossible : ${err.message}` });
          }
        }
      }
    } finally {
      lock.release();
    }
  } catch (err) {
    const result = { ok: false, message: `Relève interrompue : ${err.message}`, received: received.length, scanned };
    recordStatus(result);
    try { await client.logout(); } catch { /* la connexion est déjà perdue */ }
    return result;
  }

  try { await client.logout(); } catch { /* rien à sauver */ }

  const result = {
    ok: true,
    scanned,
    received: received.length,
    documents: received,
    skipped,
    message: `${received.length} pièce(s) retenue(s) sur ${scanned} message(s) relevé(s).`,
  };
  recordStatus(result);
  if (received.length) {
    audit.log(req, 'pieces.capture', 'incoming_documents', null, { recues: received.length, messages: scanned });
  }
  return result;
}

/** Essai de connexion : le seul moyen de savoir que les identifiants passent. */
async function test({ deps = {} } = {}) {
  if (!isReady()) return { ok: false, message: 'Capture non configurée.' };

  const current = config();
  let client;
  try {
    client = await connect(current, deps);
  } catch (err) {
    const result = { ok: false, message: `Connexion refusée : ${err.message}` };
    recordStatus(result);
    return result;
  }

  try {
    const lock = await client.getMailboxLock(current.folder);
    let waiting = 0;
    try {
      const uids = await client.search({ seen: false, since: since(current.sinceDays) }, { uid: true });
      waiting = (uids || []).length;
    } finally {
      lock.release();
    }
    await client.logout();

    const result = { ok: true, message: `Boîte « ${current.folder} » ouverte : ${waiting} message(s) non lu(s) dans la fenêtre.`, waiting };
    recordStatus(result);
    return result;
  } catch (err) {
    try { await client.logout(); } catch { /* connexion perdue */ }
    const result = { ok: false, message: `Dossier illisible : ${err.message}` };
    recordStatus(result);
    return result;
  }
}

module.exports = {
  KEYS, ACTIONS, DEFAULTS, MAX_BATCH, ATTACHMENT_TYPES,
  config, displayConfig, setConfig, isReady, status, recordStatus,
  connect, attachmentsOf, fetchOnce, test,
};
