const fs = require('fs');
const os = require('os');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');

const db = require('./db');
const tar = require('./tar');
const settings = require('./settings');
const { UPLOAD_DIR } = require('./uploads');
const cv = require('./cv');
const vault = require('./vault');
const signing = require('./signing');
const intake = require('./intake');

/**
 * Sauvegarde et restauration de l'instance.
 *
 * Une sauvegarde de la seule base serait un piège : les photos, les CV et
 * surtout les bulletins du coffre-fort vivent hors de la base. Restaurer une
 * base sans eux rendrait une instance qui prétend détenir des documents
 * disparus. L'archive embarque donc la base *et* les dossiers de fichiers.
 *
 * La base est copiée par l'API de sauvegarde en ligne de SQLite, cohérente sur
 * une base en cours d'écriture — copier le fichier à la main ne le serait pas,
 * le journal WAL vivant à côté.
 */

const BACKUP_DIR = process.env.BACKUP_DIR
  || path.join(path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite')), 'sauvegardes');

const FORMAT_VERSION = 1;
const PREFIX = 'sauvegarde-';
const SUFFIX = '.tar.gz';

// Une archive téléversée pour restauration : au-delà, l'opérateur dépose le
// fichier directement dans le dossier des sauvegardes (voir le README).
const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

const DEFAULTS = { intervalMinutes: 60, keep: 24 };

/** Les dossiers de fichiers embarqués, sous le nom qu'ils portent dans l'archive. */
function fileRoots() {
  return [
    { root: 'uploads', dir: UPLOAD_DIR },
    { root: 'cv', dir: cv.CV_DIR },
    { root: 'coffre', dir: vault.VAULT_DIR },
    // Les documents mis à la signature font preuve : une sauvegarde qui les
    // oublierait rendrait une instance dont les attestations pointent vers rien.
    { root: 'parapheur', dir: signing.SIGN_DIR },
    // Les pièces reçues justifient des écritures : une sauvegarde sans elles
    // rendrait une comptabilité sans justificatifs.
    { root: 'pieces', dir: intake.DOCS_DIR },
  ];
}

const ARCHIVE_ROOTS = ['db', 'uploads', 'cv', 'coffre', 'parapheur', 'pieces', 'meta'];

function ensureDir() {
  if (!fs.existsSync(BACKUP_DIR)) fs.mkdirSync(BACKUP_DIR, { recursive: true, mode: 0o700 });
}

function config() {
  const interval = Number(settings.get('backup_interval_minutes')) || DEFAULTS.intervalMinutes;
  const keep = Number(settings.get('backup_keep')) || DEFAULTS.keep;
  return {
    enabled: settings.get('backup_enabled') !== '0',
    intervalMinutes: Math.min(1440, Math.max(15, interval)),
    keep: Math.min(500, Math.max(2, keep)),
  };
}

function setConfig({ enabled, intervalMinutes, keep }) {
  settings.setMany({
    backup_enabled: enabled ? '1' : '0',
    backup_interval_minutes: String(Math.min(1440, Math.max(15, Number(intervalMinutes) || DEFAULTS.intervalMinutes))),
    backup_keep: String(Math.min(500, Math.max(2, Number(keep) || DEFAULTS.keep))),
  });
}

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

/** Tous les fichiers d'un dossier, à plat : nos dossiers n'ont pas de sous-niveaux. */
function filesIn(dir) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir, { withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => entry.name);
}

/**
 * Écrit une sauvegarde complète et rend sa description.
 * @param {string} reason  ce qui l'a déclenchée : 'automatique', 'manuelle', 'avant restauration'
 */
async function create({ reason = 'manuelle', label = '' } = {}) {
  ensureDir();

  // Copie cohérente de la base, dans un fichier temporaire hors du dossier servi.
  const staging = path.join(os.tmpdir(), `pm-backup-${crypto.randomBytes(8).toString('hex')}.sqlite`);
  await db.backup(staging);

  try {
    const entries = [];
    const manifest = {
      format: FORMAT_VERSION,
      createdAt: new Date().toISOString(),
      reason,
      label: String(label).slice(0, 120),
      files: [],
    };

    const push = (name, source) => {
      const data = Buffer.isBuffer(source) ? source : fs.readFileSync(source);
      entries.push({ name, source: data });
      manifest.files.push({ name, size: data.length, sha256: sha256(data) });
    };

    push('db/app.sqlite', staging);
    for (const { root, dir } of fileRoots()) {
      for (const name of filesIn(dir)) push(`${root}/${name}`, path.join(dir, name));
    }

    // Le manifeste est ajouté en dernier : il décrit tout ce qui précède, et
    // c'est lui qui permet de vérifier chaque fichier à la restauration.
    entries.push({ name: 'meta/manifeste.json', source: Buffer.from(JSON.stringify(manifest, null, 2)) });

    const archive = zlib.gzipSync(tar.pack(entries), { level: 6 });
    // L'horodatage s'arrête à la seconde ; deux sauvegardes rapprochées — celle
    // de sécurité prise juste avant une restauration, par exemple — porteraient
    // le même nom et la première serait écrasée. Un suffixe aléatoire l'évite.
    const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    const fileName = `${PREFIX}${stamp}-${crypto.randomBytes(2).toString('hex')}${SUFFIX}`;
    fs.writeFileSync(path.join(BACKUP_DIR, fileName), archive, { mode: 0o600 });

    return { fileName, bytes: archive.length, files: manifest.files.length, reason, createdAt: manifest.createdAt };
  } finally {
    fs.rmSync(staging, { force: true });
  }
}

function isBackupName(name) {
  return name.startsWith(PREFIX) && name.endsWith(SUFFIX) && !name.includes('/') && !name.includes('..');
}

function list() {
  ensureDir();
  return fs.readdirSync(BACKUP_DIR)
    .filter(isBackupName)
    .map((fileName) => {
      const stat = fs.statSync(path.join(BACKUP_DIR, fileName));
      return { fileName, bytes: stat.size, createdAt: stat.mtime.toISOString() };
    })
    .sort((a, b) => b.createdAt.localeCompare(a.createdAt));
}

function pathOf(fileName) {
  if (!isBackupName(fileName)) return null;
  const target = path.join(BACKUP_DIR, path.basename(fileName));
  return fs.existsSync(target) ? target : null;
}

/** Supprime les sauvegardes au-delà du nombre conservé. Rend les noms retirés. */
function prune() {
  const { keep } = config();
  const removed = [];
  for (const entry of list().slice(keep)) {
    fs.rmSync(path.join(BACKUP_DIR, entry.fileName), { force: true });
    removed.push(entry.fileName);
  }
  return removed;
}

function remove(fileName) {
  const target = pathOf(fileName);
  if (!target) return false;
  fs.rmSync(target, { force: true });
  return true;
}

/**
 * Ouvre une archive et en vérifie l'intégrité, sans rien écrire.
 * Rend { ok, manifest, entries } ou { ok: false, message }.
 */
function inspect(buffer) {
  let plain;
  try {
    plain = zlib.gunzipSync(buffer);
  } catch {
    return { ok: false, message: "Ce fichier n'est pas une archive de sauvegarde lisible." };
  }

  let raw;
  try {
    raw = tar.unpack(plain);
  } catch (err) {
    return { ok: false, message: err.message };
  }

  const entries = new Map();
  for (const entry of raw) {
    const safe = tar.safeName(entry.name, ARCHIVE_ROOTS);
    if (!safe) return { ok: false, message: `Entrée refusée dans l'archive : ${entry.name}` };
    entries.set(entry.name, entry.data);
  }

  const manifestData = entries.get('meta/manifeste.json');
  if (!manifestData) return { ok: false, message: 'Archive sans manifeste : origine inconnue.' };

  let manifest;
  try {
    manifest = JSON.parse(manifestData.toString('utf8'));
  } catch {
    return { ok: false, message: 'Manifeste illisible.' };
  }
  if (manifest.format !== FORMAT_VERSION) {
    return { ok: false, message: `Format de sauvegarde ${manifest.format} non pris en charge par cette version.` };
  }
  if (!entries.has('db/app.sqlite')) return { ok: false, message: 'Archive sans base de données.' };

  // Chaque fichier est vérifié contre l'empreinte inscrite au manifeste : le
  // contrôle de tar ne couvre que les en-têtes, pas le contenu.
  for (const file of manifest.files) {
    const data = entries.get(file.name);
    if (!data) return { ok: false, message: `Fichier manquant dans l'archive : ${file.name}` };
    if (data.length !== file.size || sha256(data) !== file.sha256) {
      return { ok: false, message: `Fichier altéré dans l'archive : ${file.name}` };
    }
  }

  return { ok: true, manifest, entries };
}

function inspectFile(fileName) {
  const target = pathOf(fileName);
  if (!target) return { ok: false, message: 'Sauvegarde introuvable.' };
  return inspect(fs.readFileSync(target));
}

// ---------- Restauration ----------

/**
 * La table des sessions n'est pas restaurée : remplacer les sessions ouvertes
 * par celles d'hier déconnecterait l'administrateur au milieu de l'opération,
 * sans rien apporter.
 */
const NOT_RESTORED = new Set(['sessions']);

function tablesOf(connection, schema) {
  return connection.prepare(`SELECT name FROM ${schema}.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'`)
    .all().map((row) => row.name);
}

function columnsOf(connection, schema, table) {
  return connection.prepare(`PRAGMA ${schema}.table_info(${JSON.stringify(table)})`).all().map((row) => row.name);
}

/**
 * Restaure l'archive dans l'instance en cours.
 *
 * La base n'est pas remplacée sur le disque — la connexion ouverte ne le
 * supporterait pas — mais recopiée table par table depuis l'archive attachée,
 * dans une seule transaction. L'application reste debout, et un échec en cours
 * de route ne laisse pas une base à moitié écrite.
 */
function restore(buffer, { by = null } = {}) {
  const opened = inspect(buffer);
  if (!opened.ok) return opened;

  const staging = path.join(os.tmpdir(), `pm-restore-${crypto.randomBytes(8).toString('hex')}.sqlite`);
  fs.writeFileSync(staging, opened.entries.get('db/app.sqlite'), { mode: 0o600 });

  const report = { tables: 0, rows: 0, files: 0, skipped: [] };

  try {
    db.pragma('foreign_keys = OFF');
    db.exec(`ATTACH DATABASE ${JSON.stringify(staging)} AS restauration`);

    try {
      const sourceTables = new Set(tablesOf(db, 'restauration'));

      db.transaction(() => {
        for (const table of tablesOf(db, 'main')) {
          if (NOT_RESTORED.has(table)) continue;

          // Une table absente de l'archive n'existait pas alors : la vider est
          // la seule façon de rendre l'instance conforme à la sauvegarde.
          db.prepare(`DELETE FROM main.${JSON.stringify(table)}`).run();
          if (!sourceTables.has(table)) {
            report.skipped.push(table);
            continue;
          }

          // On ne recopie que les colonnes communes : une sauvegarde antérieure
          // à une migration n'a pas les colonnes ajoutées depuis.
          const target = columnsOf(db, 'main', table);
          const shared = columnsOf(db, 'restauration', table).filter((column) => target.includes(column));
          if (shared.length === 0) continue;

          const quoted = shared.map((column) => JSON.stringify(column)).join(', ');
          const info = db.prepare(`
            INSERT INTO main.${JSON.stringify(table)} (${quoted})
            SELECT ${quoted} FROM restauration.${JSON.stringify(table)}
          `).run();
          report.tables += 1;
          report.rows += info.changes;
        }
      })();
    } finally {
      db.exec('DETACH DATABASE restauration');
      db.pragma('foreign_keys = ON');
    }

    const integrity = db.pragma('integrity_check', { simple: true });
    if (integrity !== 'ok') return { ok: false, message: `Base incohérente après restauration : ${integrity}` };

    // Les dossiers de fichiers sont remis à l'état de l'archive : ce qui n'y
    // figure pas n'existait pas au moment de la sauvegarde.
    for (const { root, dir } of fileRoots()) {
      fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
      for (const name of filesIn(dir)) fs.rmSync(path.join(dir, name), { force: true });

      for (const [name, data] of opened.entries) {
        if (!name.startsWith(`${root}/`)) continue;
        fs.writeFileSync(path.join(dir, path.basename(name)), data, { mode: 0o600 });
        report.files += 1;
      }
    }

    return { ok: true, manifest: opened.manifest, report, by };
  } finally {
    fs.rmSync(staging, { force: true });
  }
}

// ---------- Balayage automatique ----------

// null tant qu'on ne s'est pas calé sur les archives déjà présentes ; 0 signifie
// « échéance immédiate ».
let lastRun = null;

/** Vrai si l'intervalle configuré est écoulé depuis la dernière sauvegarde. */
function isDue(now = Date.now()) {
  const { enabled, intervalMinutes } = config();
  if (!enabled) return false;
  if (lastRun === null) {
    // Au démarrage, on se cale sur la dernière archive présente : un
    // redémarrage ne doit pas relancer une sauvegarde à chaque fois.
    const latest = list()[0];
    lastRun = latest ? new Date(latest.createdAt).getTime() : 0;
  }
  return now - lastRun >= intervalMinutes * 60 * 1000;
}

async function runScheduled() {
  if (!isDue()) return null;
  const created = await create({ reason: 'automatique' });
  lastRun = Date.now();
  const removed = prune();
  return { ...created, removed };
}

/** Force la prochaine échéance : utilisé par les tests et après un changement de réglage. */
function resetSchedule() {
  lastRun = 0;
}

function summary() {
  const archives = list();
  const { enabled, intervalMinutes, keep } = config();
  return {
    count: archives.length,
    bytes: archives.reduce((sum, a) => sum + a.bytes, 0),
    latest: archives[0] || null,
    enabled,
    intervalMinutes,
    keep,
  };
}

module.exports = {
  BACKUP_DIR, FORMAT_VERSION, MAX_UPLOAD_BYTES, ARCHIVE_ROOTS, DEFAULTS, NOT_RESTORED,
  config, setConfig, create, list, pathOf, prune, remove, isBackupName,
  inspect, inspectFile, restore, isDue, runScheduled, resetSchedule, summary,
};
