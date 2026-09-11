const { Readable } = require('stream');
const ftp = require('basic-ftp');

/**
 * Destination FTP.
 *
 * Le format d'archive est écrit à la main — une sauvegarde doit rester lisible
 * sans outil. Le transport, lui, n'a pas cette contrainte : ce n'est pas un
 * format à relire dans dix ans, mais un protocole à parler correctement, TLS
 * compris. Une bibliothèque éprouvée y vaut mieux qu'une réimplémentation.
 */

const MODES = [
  { key: 'ftps', label: 'FTPS explicite (recommandé)', secure: true },
  { key: 'ftps-implicite', label: 'FTPS implicite (port 990)', secure: 'implicit' },
  { key: 'ftp', label: 'FTP simple — sans chiffrement', secure: false },
];

const DEFAULT_TIMEOUT_MS = 30000;

function modeOf(key) {
  return MODES.find((m) => m.key === key) || MODES[0];
}

function normalizeDirectory(value) {
  const trimmed = String(value || '').trim().replace(/\\/g, '/');
  if (!trimmed) return '';
  // Un chemin distant reste relatif au dossier d'accueil sauf s'il commence par
  // une barre : on n'invente rien, on nettoie seulement les répétitions.
  return trimmed.replace(/\/{2,}/g, '/').replace(/\/$/, '');
}

async function connect(config, { timeout = DEFAULT_TIMEOUT_MS } = {}) {
  const mode = modeOf(config.mode);
  const client = new ftp.Client(timeout);
  client.ftp.verbose = false;

  await client.access({
    host: config.host,
    port: Number(config.port) || (mode.secure === 'implicit' ? 990 : 21),
    user: config.user,
    password: config.password,
    secure: mode.secure,
    secureOptions: {
      // Un certificat auto-signé est fréquent sur un NAS d'entreprise ; le
      // refuser d'office empêcherait la sauvegarde. Le choix est explicite et
      // affiché à l'écran, il n'est pas pris à la place de l'administrateur.
      rejectUnauthorized: config.allowSelfSigned ? false : true,
      servername: config.host,
    },
  });

  const directory = normalizeDirectory(config.directory);
  if (directory) await client.ensureDir(directory);
  return client;
}

/** Vérifie que la connexion s'établit et que le dossier est accessible en écriture. */
async function test(config) {
  let client = null;
  try {
    client = await connect(config);
    const probe = `.essai-toutadmin-${Date.now()}`;
    await client.uploadFrom(Readable.from(Buffer.from('essai')), probe);
    await client.remove(probe);
    return { ok: true, message: 'Connexion établie et écriture vérifiée.' };
  } catch (err) {
    return { ok: false, message: err.message };
  } finally {
    if (client) client.close();
  }
}

async function upload(config, fileName, buffer) {
  let client = null;
  try {
    client = await connect(config);
    await client.uploadFrom(Readable.from(buffer), fileName);
    return { ok: true, message: `${fileName} déposé (${buffer.length} octets).` };
  } catch (err) {
    return { ok: false, message: err.message };
  } finally {
    if (client) client.close();
  }
}

async function list(config, { prefix = 'sauvegarde-' } = {}) {
  let client = null;
  try {
    client = await connect(config);
    const entries = await client.list();
    return {
      ok: true,
      files: entries
        .filter((entry) => entry.isFile && entry.name.startsWith(prefix))
        .map((entry) => ({
          name: entry.name,
          bytes: entry.size,
          modifiedAt: entry.modifiedAt ? new Date(entry.modifiedAt).toISOString() : null,
        }))
        .sort((a, b) => b.name.localeCompare(a.name)),
    };
  } catch (err) {
    return { ok: false, message: err.message };
  } finally {
    if (client) client.close();
  }
}

async function remove(config, fileName) {
  let client = null;
  try {
    client = await connect(config);
    await client.remove(fileName);
    return { ok: true };
  } catch (err) {
    return { ok: false, message: err.message };
  } finally {
    if (client) client.close();
  }
}

module.exports = { MODES, DEFAULT_TIMEOUT_MS, modeOf, normalizeDirectory, test, upload, list, remove };
