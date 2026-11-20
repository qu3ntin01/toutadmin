const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const db = require('./db');
const settings = require('./settings');
const licenceCle = require('./licence-cle');

/*
 * « updates » est chargé à l'usage, et non en tête de fichier : il demande
 * « secret-store », qui demande celui-ci pour dériver sa clé du secret de
 * session. Le cercle ne se voit pas à l'exécution normale — le premier chargé
 * gagne — mais quand c'est « secret-store » qui part en premier, il reçoit un
 * « install » encore vide et tombe sur « sessionSecret is not a function ».
 *
 * Deux fonctions seulement en ont besoin, et aucune n'est appelée au
 * chargement : la demander au moment de s'en servir suffit, et évite de
 * réorganiser deux modules pour une étape d'assistant.
 */
function lesMisesAJour() {
  return require('./updates'); // eslint-disable-line global-require
}

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

/**
 * Contrôles affichés à l'étape « prérequis » de l'assistant.
 *
 * Chaque ligne porte des CLÉS de traduction, pas des phrases : ce module ne
 * connaît pas la langue de la personne qui installe. La route les résout, elle
 * qui a la requête sous la main. Ce qui n'est pas traduisible — un chemin, un
 * code d'erreur du système — voyage tel quel dans detailText.
 *
 * LA MÊME LISTE QUE L'ÉDITION PHP, dans le même ordre et sous les mêmes clés.
 * Les deux éditions posent les mêmes questions au serveur ; seules les réponses
 * diffèrent, puisque les moteurs diffèrent. Deux listes qui divergent laissent
 * croire qu'une édition exige moins que l'autre, et l'on choisit alors pour de
 * mauvaises raisons. Un test de chaque côté épingle l'ordre des clés.
 */
const CONTROLES = ['runtime', 'engine', 'data', 'hash', 'text', 'uploads', 'config',
  'crypto', 'network', 'proxy', 'production'];

/** Les moteurs de base que cette installation saurait mener, ici et maintenant. */
function moteursDisponibles() {
  const disponible = (module) => {
    try {
      require.resolve(module);
      return true;
    } catch {
      return false;
    }
  };
  return { sqlite: disponible('better-sqlite3'), mysql: disponible('mysql2') };
}

function runChecks() {
  const nodeMajor = Number(process.versions.node.split('.')[0]);
  const checks = [];

  checks.push({
    key: 'runtime',
    labelKey: 'install.checks.runtime',
    detailKey: 'install.checks.runtimeDetail',
    detailParams: { actuel: `Node ${process.versions.node}`, min: `Node ${MIN_NODE_MAJOR}` },
    ok: nodeMajor >= MIN_NODE_MAJOR,
    state: nodeMajor >= MIN_NODE_MAJOR ? 'ok' : 'fail',
    blocking: true,
  });

  // Un moteur, pas SQLite. Exiger SQLite quand on installe sur MySQL était un
  // contresens : le serveur peut très bien n'avoir que l'un des deux, et
  // l'installation se fait alors sur celui qu'il a.
  const moteurs = moteursDisponibles();
  const unMoteur = moteurs.sqlite || moteurs.mysql;
  checks.push({
    key: 'engine',
    labelKey: 'install.checks.engine',
    detailKey: moteurs.sqlite && moteurs.mysql
      ? 'install.checks.engineBoth'
      : moteurs.sqlite
        ? 'install.checks.engineSqlite'
        : moteurs.mysql
          ? 'install.checks.engineMysql'
          : 'install.checks.engineNone',
    ok: unMoteur,
    state: unMoteur ? 'ok' : 'fail',
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
    labelKey: 'install.checks.data',
    detailText: writeDetail,
    ok: writable,
    state: writable ? 'ok' : 'fail',
    blocking: true,
  });

  let hash = false;
  try {
    hash = require('bcryptjs').hashSync('sonde', 4).startsWith('$2');
  } catch {
    hash = false;
  }
  checks.push({
    key: 'hash',
    labelKey: 'install.checks.bcrypt',
    detailText: 'bcrypt',
    ok: hash,
    state: hash ? 'ok' : 'fail',
    blocking: true,
  });

  // L'équivalent de mbstring côté PHP : ici, le moteur le fournit d'origine.
  // La ligne reste, pour que les deux éditions répondent aux mêmes questions.
  checks.push({
    key: 'text',
    labelKey: 'install.checks.text',
    detailKey: 'install.checks.builtin',
    ok: true,
    state: 'ok',
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
    labelKey: 'install.checks.uploads',
    detailText: uploadDir,
    ok: uploadsOk,
    state: uploadsOk ? 'ok' : 'warn',
    blocking: false,
  });

  // Là où l'édition PHP écrit config.php, celle-ci lit l'environnement : même
  // question — où se pose la configuration —, réponse propre à l'édition.
  const fichierEnv = path.join(path.dirname(DATA_DIR), '.env');
  checks.push({
    key: 'config',
    labelKey: 'install.checks.config',
    detailKey: fs.existsSync(fichierEnv) ? 'install.checks.configFile' : 'install.checks.configEnv',
    detailParams: { path: fichierEnv },
    ok: true,
    state: 'ok',
    blocking: false,
  });

  checks.push({
    key: 'crypto',
    labelKey: 'install.checks.crypto',
    detailKey: 'install.checks.builtin',
    ok: typeof crypto.createCipheriv === 'function',
    state: typeof crypto.createCipheriv === 'function' ? 'ok' : 'warn',
    blocking: false,
  });

  checks.push({
    key: 'network',
    labelKey: 'install.checks.network',
    detailKey: 'install.checks.builtin',
    ok: typeof fetch === 'function',
    state: typeof fetch === 'function' ? 'ok' : 'warn',
    blocking: false,
  });

  const behindProxy = Boolean(process.env.TRUST_PROXY);
  checks.push({
    key: 'proxy',
    labelKey: 'install.checks.proxy',
    detailKey: behindProxy ? 'install.checks.proxyOn' : 'install.checks.proxyOff',
    detailParams: { value: process.env.TRUST_PROXY || '' },
    ok: true,
    state: behindProxy ? 'ok' : 'warn',
    blocking: false,
  });

  const secureCookies = process.env.NODE_ENV === 'production';
  checks.push({
    key: 'production',
    labelKey: 'install.checks.production',
    detailKey: secureCookies ? 'install.checks.productionOn' : 'install.checks.productionOff',
    ok: true,
    state: secureCookies ? 'ok' : 'warn',
    blocking: false,
  });

  return checks;
}

/**
 * Une ligne de contrôle, dite dans la langue de la requête. Le libellé sert
 * aussi bien à l'écran qu'au message qui énumère les prérequis manquants.
 */
function translateCheck(check, t) {
  return {
    ...check,
    label: t(check.labelKey, check.labelParams),
    detail: check.detailKey ? t(check.detailKey, check.detailParams) : check.detailText || '',
  };
}

/** Où vivent les données de cette instance : un fichier, ou un serveur. */
function databaseFile() {
  if (db.driver === 'mysql') {
    const hote = process.env.DB_HOST || '127.0.0.1';
    return `${process.env.DB_NAME || 'toutadmin'} @ ${hote}`;
  }
  return process.env.DB_PATH || path.join(DATA_DIR, 'app.sqlite');
}

/**
 * Les moteurs que cette édition sait mener.
 *
 * Les deux, depuis que l'accès à la base passe par un client synchrone : le
 * fichier qui ne demande rien, ou un serveur MySQL quand plusieurs machines
 * servent le même site.
 */
function engines() {
  return [
    { key: 'sqlite', name: 'SQLite', available: true },
    { key: 'mysql', name: 'MySQL / MariaDB', available: true },
  ];
}

/**
 * Essaie la connexion avant de s'y engager.
 *
 * Une adresse qui ne répond pas doit se dire à l'écran où on la saisit, et non
 * trois étapes plus loin sous la forme d'une page blanche.
 */
function tryMysql(champs, t) {
  const hote = String(champs.host || '').trim();
  const base = String(champs.name || '').trim();
  if (!hote || !base) return { ok: false, message: t('install.db.missing') };

  let client = null;
  try {
    const { ClientSynchrone } = require('./sync-mysql');
    client = new ClientSynchrone({
      user: champs.user || '',
      password: champs.password || '',
      database: base,
      charset: 'utf8mb4',
      connectTimeout: 5000,
      ...(hote.startsWith('/') ? { socketPath: hote } : { host: hote, port: Number(champs.port) || 3306 }),
    });
    const version = client.interroger('SELECT VERSION() AS v').lignes[0].v;
    return { ok: true, message: t('install.db.reached', { version }) };
  } catch (erreur) {
    return { ok: false, message: erreur.message };
  } finally {
    if (client) client.fermer();
  }
}

/** Une base ouverte sur les réglages que l'assistant vient de recueillir. */
function targetDatabase(draft) {
  if (draft.engine !== 'mysql') return db;
  return require('./db-mysql').creerBase({
    DB_HOST: draft.dbHost, DB_PORT: draft.dbPort, DB_NAME: draft.dbName,
    DB_USER: draft.dbUser, DB_PASSWORD: draft.dbPassword,
  });
}

/** Les réglages à écrire pour que l'instance reparte sur la bonne base. */
function instanceSettings(draft) {
  if (draft.engine !== 'mysql') return { DB_DRIVER: 'sqlite' };
  return {
    DB_DRIVER: 'mysql',
    DB_HOST: draft.dbHost,
    DB_PORT: String(draft.dbPort || 3306),
    DB_NAME: draft.dbName,
    DB_USER: draft.dbUser,
    DB_PASSWORD: draft.dbPassword,
  };
}

/** Le nombre de tables posées : ce que le palier « schéma » a de vérifiable. */
function tableCount() {
  return db.tables().length;
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
  CONTROLES,
  runChecks,
  translateCheck,
  databaseFile,
  engines,
  tableCount,
  tryMysql,
  targetDatabase,
  instanceSettings,
};

// ------------------------------------------------------- les mises à jour

/**
 * L'étape « Mises à jour » de l'assistant.
 *
 * Installer une version dépassée est une mauvaise façon de commencer : on pose
 * un CMS dont on sait déjà qu'il lui manque des correctifs, et la première
 * chose qu'on demandera au client sera de le mettre à jour. Autant le faire
 * avant qu'il n'y ait des données dedans.
 *
 * ── Pourquoi cette étape vient avant la licence ─────────────────────────
 *
 * Parce que c'est là qu'elle sert. Mais cela pose une difficulté réelle :
 * télécharger une archive demande une clé, et la clé se saisit à l'étape
 * d'après. On coupe donc en deux ce qui n'a pas à être fait d'un bloc :
 *
 *   - **savoir** s'il existe plus récent ne demande aucune clé. Le numéro de la
 *     dernière version est écrit sur la page de téléchargement du site ; le
 *     refuser à un installeur serait absurde ;
 *   - **télécharger** en demande une, et l'écran la réclame seulement à ce
 *     moment-là, une fois qu'on sait qu'elle va servir. Elle est ensuite
 *     reportée à l'étape « Licence », qui n'a donc pas à la redemander.
 *
 * ── Ce qui bloque, et ce qui ne bloque pas ──────────────────────────────
 *
 * Une version en retard bloque : c'est la demande, et elle est raisonnable.
 * Un éditeur injoignable **ne bloque pas**. Un client derrière un pare-feu
 * d'entreprise, sur un réseau sans sortie, ou pendant une panne de notre côté,
 * doit pouvoir installer son CMS. Transformer une panne de réseau en produit
 * inutilisable serait une faute bien plus grave que de laisser poser une
 * version d'un mois.
 */

/** Comparer deux numéros de version, composant par composant. */
function comparerVersions(a, b) {
  const morceaux = (v) => String(v || '0').split('.').map((n) => parseInt(n, 10) || 0);
  const gauche = morceaux(a);
  const droite = morceaux(b);
  for (let i = 0; i < Math.max(gauche.length, droite.length); i++) {
    const x = gauche[i] || 0;
    const y = droite[i] || 0;
    if (x !== y) return x > y ? 1 : -1;
  }
  return 0;
}

/**
 * Y a-t-il plus récent ? Sans clé — voir plus haut pourquoi.
 *
 * Ne jette jamais : un éditeur injoignable est un cas ordinaire, pas une
 * panne, et il se dit à l'écran.
 */
async function versionDisponible({ url, canal = 'stable' } = {}) {
  const updates = lesMisesAJour();
  const courante = updates.currentVersion();
  const cible = String(url || '').trim().replace(/\/+$/, '');
  if (!cible) return { ok: false, raison: 'sans-editeur', courante };

  let reponse;
  let brut = '';
  try {
    const adresse = new URL(`${cible}/api/v1/derniere`);
    adresse.searchParams.set('edition', updates.EDITION);
    adresse.searchParams.set('canal', canal);
    reponse = await fetch(adresse, { signal: AbortSignal.timeout(15000) });
    brut = await reponse.text();
  } catch (erreur) {
    return { ok: false, raison: 'injoignable', courante, detail: String(erreur.message || erreur) };
  }

  // Une réponse qui n'est pas du JSON n'est pas une version : c'est un
  // pare-feu applicatif, un proxy, une page de maintenance. On le dit comme
  // tel plutôt que de laisser croire à un problème de version.
  let corps = null;
  try {
    corps = JSON.parse(brut);
  } catch {
    corps = null;
  }
  if (!reponse.ok || corps === null || typeof corps !== 'object') {
    return {
      ok: false,
      raison: 'illisible',
      courante,
      statut: reponse.status,
      type: (reponse.headers.get('content-type') || '').split(';')[0],
    };
  }

  const derniere = String(corps.numero || '');
  if (!derniere) return { ok: true, aJour: true, courante, derniere: courante };
  return {
    ok: true,
    aJour: comparerVersions(derniere, courante) <= 0,
    courante,
    derniere,
    publieeLe: String(corps.publiee_le || ''),
  };
}

/**
 * Poser la dernière version, pendant l'installation.
 *
 * Ce n'est pas « updates.apply » et cela ne peut pas l'être : celui-là écrit
 * dans la base — l'historique des mises à jour, le journal, la sauvegarde
 * d'avant — et la base n'existe pas encore. Il n'y a d'ailleurs rien à
 * sauvegarder : aucune donnée n'a été posée, et les fichiers qu'on remplace
 * sont ceux qui viennent d'être déballés.
 *
 * Ce qui est gardé de l'autre chemin, en revanche, c'est tout ce qui compte :
 * la taille, l'empreinte, la signature de l'éditeur sur la phrase qui nomme
 * cette version précise, le contrôle du contenu de l'archive, et la remise en
 * place de ce qui a été mis de côté si la pose échoue à mi-chemin.
 *
 * La clé publique est celle du code — la même paire signe les licences et les
 * versions — et non un réglage : à l'installation il n'y a pas encore de
 * réglages, et une clé de vérification qu'on pourrait poser soi-même ne
 * vérifierait rien.
 */
async function poserLaMiseAJour({ url, cle, canal = 'stable', root = null } = {}) {
  const updates = lesMisesAJour();
  const racine = root || updates.ROOT;
  const cible = String(url || '').trim().replace(/\/+$/, '');
  const secret = String(cle || '').trim();
  if (!cible) return { ok: false, raison: 'sans-editeur' };
  if (!secret) return { ok: false, raison: 'sans-cle' };

  const pem = licenceCle.PEM;
  if (!String(pem || '').trim()) {
    // Une instance dont le code ne porte pas de clé publique ne peut rien
    // vérifier, donc ne doit rien poser. C'est le cas d'un dépôt de
    // développement, et il n'a pas à se mettre à jour tout seul.
    return { ok: false, raison: 'sans-cle-publique' };
  }

  // 1. L'annonce complète — empreinte, taille, signature, adresse de l'archive.
  //    Celle-là demande la clé, et c'est normal : elle mène au téléchargement.
  let annonce;
  try {
    const adresse = new URL(`${cible}/api/v1/version`);
    adresse.searchParams.set('edition', updates.EDITION);
    adresse.searchParams.set('canal', canal);
    adresse.searchParams.set('courante', updates.currentVersion());
    const reponse = await fetch(adresse, {
      headers: { 'X-Cle-Instance': secret },
      signal: AbortSignal.timeout(20000),
    });
    if (reponse.status === 401) return { ok: false, raison: 'cle-refusee' };
    if (!reponse.ok) return { ok: false, raison: `http-${reponse.status}` };
    const corps = await reponse.json();
    if (!corps.disponible) return { ok: false, raison: 'deja-a-jour' };
    annonce = corps.version;
  } catch (erreur) {
    return { ok: false, raison: 'injoignable', detail: String(erreur.message || erreur) };
  }

  // 2. Télécharger.
  let contenu;
  try {
    const reponse = await fetch(annonce.archive, {
      headers: { 'X-Cle-Instance': secret },
      signal: AbortSignal.timeout(180000),
    });
    if (!reponse.ok) return { ok: false, raison: 'telechargement', detail: String(reponse.status) };
    contenu = Buffer.from(await reponse.arrayBuffer());
  } catch (erreur) {
    return { ok: false, raison: 'telechargement', detail: String(erreur.message || erreur) };
  }

  // 3. Vérifier avant de croire.
  const verdict = updates.verifyArchive(contenu, annonce, pem);
  if (!verdict.ok) return { ok: false, raison: 'verification', detail: verdict.reason };

  let fichiers;
  try {
    fichiers = updates.readArchive(contenu);
  } catch (erreur) {
    return { ok: false, raison: 'archive', detail: String(erreur.message || erreur) };
  }
  const dedans = updates.inspectContents(fichiers, annonce.numero);
  if (!dedans.ok) return { ok: false, raison: 'contenu', detail: dedans.reason };

  // 4. Mettre de côté, poser, et remettre si la pose échoue à mi-chemin.
  const deCote = path.join(DATA_DIR, `installation-precedente-${updates.currentVersion()}`);
  let gardes = [];
  try {
    fs.rmSync(deCote, { recursive: true, force: true });
    gardes = updates.setAside(fichiers, deCote, racine);
    updates.writeFiles(fichiers, racine);
  } catch (erreur) {
    updates.restoreFiles(deCote, gardes, racine);
    return { ok: false, raison: 'ecriture', detail: String(erreur.message || erreur) };
  }

  return { ok: true, version: String(annonce.numero), depuis: updates.currentVersion(), redemarrer: true };
}

module.exports.comparerVersions = comparerVersions;
module.exports.versionDisponible = versionDisponible;
module.exports.poserLaMiseAJour = poserLaMiseAJour;
