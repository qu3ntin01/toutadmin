const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const db = require('./db');
const settings = require('./settings');

const DATA_DIR = path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite'));
const LOCK_FILE = path.join(DATA_DIR, 'install.lock');
const SECRET_FILE = path.join(DATA_DIR, 'session.key');
const MIN_NODE_MAJOR = 18;

/**
 * Secret de session : fourni par l'environnement en priorité, sinon généré une
 * fois pour toutes dans un fichier lisible du seul propriétaire. Une instance
 * fraîchement clonée démarre ainsi sans configuration préalable.
 */
function sessionSecret() {
  if (process.env.SESSION_SECRET && process.env.SESSION_SECRET !== 'change-moi-en-production') {
    return process.env.SESSION_SECRET;
  }

  try {
    if (fs.existsSync(SECRET_FILE)) return fs.readFileSync(SECRET_FILE, 'utf8').trim();
  } catch {
    /* illisible : on en génère un nouveau ci-dessous */
  }

  const secret = crypto.randomBytes(48).toString('base64url');
  fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.writeFileSync(SECRET_FILE, secret, { mode: 0o600 });
  return secret;
}

function hasAdmin() {
  return db.prepare("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'").get().n > 0;
}

/**
 * L'instance est installée dès qu'un verrou existe ou qu'un administrateur est
 * présent — ce second cas couvre les déploiements pilotés par variables
 * d'environnement, qui n'ont jamais vu l'assistant.
 */
function isInstalled() {
  return fs.existsSync(LOCK_FILE) || hasAdmin();
}

function markInstalled(version) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  const payload = { installedAt: new Date().toISOString(), version: version || '' };
  fs.writeFileSync(LOCK_FILE, JSON.stringify(payload, null, 2), { mode: 0o600 });
  settings.setMany({ installed_at: payload.installedAt, installed_version: payload.version });
}

/** Jeton facultatif : protège l'assistant d'une instance exposée avant sa configuration. */
function tokenRequired() {
  return Boolean(process.env.INSTALL_TOKEN);
}

function tokenMatches(candidate) {
  const expected = process.env.INSTALL_TOKEN || '';
  if (!expected) return true;

  const a = Buffer.from(String(candidate || ''));
  const b = Buffer.from(expected);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** Contrôles affichés à l'étape « prérequis » de l'assistant. */
function runChecks() {
  const nodeMajor = Number(process.versions.node.split('.')[0]);
  const checks = [];

  checks.push({
    key: 'node',
    label: `Node.js ${MIN_NODE_MAJOR} ou supérieur`,
    detail: `Version détectée : ${process.versions.node}`,
    ok: nodeMajor >= MIN_NODE_MAJOR,
    state: nodeMajor >= MIN_NODE_MAJOR ? 'ok' : 'fail',
    blocking: true,
  });

  let writable = false;
  let writeDetail = DATA_DIR;
  try {
    fs.mkdirSync(DATA_DIR, { recursive: true });
    const probe = path.join(DATA_DIR, `.probe-${crypto.randomBytes(4).toString('hex')}`);
    fs.writeFileSync(probe, 'ok');
    fs.rmSync(probe);
    writable = true;
  } catch (err) {
    writeDetail = `${DATA_DIR} — ${err.code || err.message}`;
  }
  checks.push({
    key: 'data',
    label: 'Dossier de données accessible en écriture',
    detail: writeDetail,
    ok: writable,
    state: writable ? 'ok' : 'fail',
    blocking: true,
  });

  let dbOk = false;
  let dbDetail = '';
  try {
    dbOk = db.prepare('SELECT 1 AS ok').get().ok === 1;
    dbDetail = process.env.DB_PATH || path.join(DATA_DIR, 'app.sqlite');
  } catch (err) {
    dbDetail = err.message;
  }
  checks.push({
    key: 'db',
    label: 'Base de données SQLite opérationnelle',
    detail: dbDetail,
    ok: dbOk,
    state: dbOk ? 'ok' : 'fail',
    blocking: true,
  });

  const uploadDir = process.env.UPLOAD_DIR || path.join(DATA_DIR, 'uploads');
  let uploadsOk = false;
  try {
    fs.mkdirSync(uploadDir, { recursive: true });
    uploadsOk = true;
  } catch {
    uploadsOk = false;
  }
  checks.push({
    key: 'uploads',
    label: 'Dossier des photos de profil',
    detail: uploadDir,
    ok: uploadsOk,
    state: uploadsOk ? 'ok' : 'warn',
    blocking: false,
  });

  const behindProxy = Boolean(process.env.TRUST_PROXY);
  checks.push({
    key: 'proxy',
    label: 'Reverse proxy déclaré (TRUST_PROXY)',
    detail: behindProxy
      ? `Activé : ${process.env.TRUST_PROXY}`
      : "À définir uniquement si l'application tourne derrière un proxy (Nginx, Traefik, hébergeur)",
    ok: true,
    state: behindProxy ? 'ok' : 'warn',
    blocking: false,
  });

  const secureCookies = process.env.NODE_ENV === 'production';
  checks.push({
    key: 'env',
    label: 'Mode production (cookies sécurisés, HTTPS attendu)',
    detail: secureCookies ? 'NODE_ENV=production' : 'NODE_ENV non défini : à activer avant la mise en service',
    ok: true,
    state: secureCookies ? 'ok' : 'warn',
    blocking: false,
  });

  return checks;
}

module.exports = {
  DATA_DIR,
  LOCK_FILE,
  SECRET_FILE,
  sessionSecret,
  isInstalled,
  hasAdmin,
  markInstalled,
  tokenRequired,
  tokenMatches,
  runChecks,
};
