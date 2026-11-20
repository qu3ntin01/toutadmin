const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const db = require('./db');
const settings = require('./settings');
const secrets = require('./secret-store');
const backup = require('./backup');
const tar = require('./tar');
const logs = require('./logs');
const audit = require('./audit');

/**
 * Mises à jour de l'instance.
 *
 * Une mise à jour, c'est du code qui remplace du code sur un serveur en
 * production. Trois règles tiennent tout le reste :
 *
 *  1. On ne fait confiance qu'à la signature. L'empreinte dit que l'archive est
 *     entière, pas d'où elle vient ; seule la signature de l'éditeur le dit.
 *  2. On sauvegarde avant de toucher à quoi que ce soit, et on garde les
 *     fichiers remplacés à côté — un retour arrière ne doit pas dépendre du
 *     réseau.
 *  3. Une version qui ne démarre pas laisse la place à la précédente, toute
 *     seule. Personne ne surveille un serveur à trois heures du matin.
 *
 * Le mode appartient à l'instance : « avertir » ne touche à rien tout seul,
 * « auto » applique au balayage. Dans les deux cas, l'administration peut
 * appliquer d'un clic.
 */

const EDITION = 'node';
const ROOT = path.resolve(__dirname, '..');
// Les fichiers de travail vont où va le reste de l'état d'exécution : à côté de
// la base, donc hors du dépôt, et emportés par la même règle d'exclusion.
const DATA_DIR = path.dirname(process.env.DB_PATH || path.join(ROOT, 'data', 'app.sqlite'));
const DIR = path.join(DATA_DIR, 'mises-a-jour');
const MARKER = path.join(DIR, 'etat.json');

// Ce qu'une mise à jour remplace. Tout le reste — data/, .env, node_modules/,
// .git/ — appartient à l'instance et n'est jamais touché.
const CODE_ROOTS = ['src', 'views', 'public', 'tools', 'package.json', 'package-lock.json'];

/*
 * Quand le code ne s'écrit pas.
 *
 * Une mise à jour, ici, remplace des fichiers dans le dossier du produit. Cela
 * suppose que ce dossier appartienne à l'instance et lui survive. Dans une
 * image de conteneur, il n'appartient pas à l'instance : il appartient à
 * l'image, et la seule façon honnête d'en changer est de changer d'image. Des
 * fichiers réécrits à l'intérieur d'un conteneur disparaissent à son premier
 * remplacement — c'est-à-dire que la mise à jour aurait l'air de marcher, puis
 * se déferait toute seule, sans que rien ne le dise.
 *
 * Le même raisonnement vaut hors conteneur : un déploiement dont le code est
 * en lecture seule, ou posé par un gestionnaire de paquets, est dans le même
 * cas. La variable ne parle donc pas de conteneurs mais de ce qui compte
 * vraiment — le code ne s'écrit pas — et les images la posent.
 *
 * Ce qui reste actif : la vérification. Savoir qu'une version existe est
 * utile même quand on l'installe autrement ; c'est l'application, et le retour
 * en arrière, qui s'arrêtent.
 */
function codeImmuable() {
  const v = String(process.env.CODE_IMMUABLE || '').trim().toLowerCase();
  return v === '1' || v === 'true' || v === 'oui';
}

// Trois démarrages ratés suffisent à conclure : au quatrième, on revient en
// arrière. Deux laisseraient trop peu de place à un redémarrage lent.
const MAX_TRIES = 3;

function currentVersion() {
  try {
    // Relu à chaque appel : après une mise à jour, le fichier a changé.
    const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'package.json'), 'utf8'));
    return manifest.version || '0.0.0';
  } catch {
    return '0.0.0';
  }
}

function config() {
  const stored = settings.get('update_instance_key');
  return {
    url: settings.get('update_editor_url').replace(/\/+$/, ''),
    key: stored ? secrets.decrypt(stored) : '',
    publicKey: settings.get('update_public_key'),
    channel: settings.get('update_channel') === 'beta' ? 'beta' : 'stable',
    mode: settings.get('update_mode') === 'auto' ? 'auto' : 'avertir',
    name: settings.get('update_instance_name'),
    version: currentVersion(),
  };
}

function setConfig({ url, key, publicKey, channel, mode, name }) {
  const values = {};
  if (url !== undefined) values.update_editor_url = String(url).trim();
  // Une clé laissée vide ne l'efface pas : l'écran ne la réaffiche jamais, donc
  // un enregistrement du formulaire la perdrait à chaque passage.
  if (key) values.update_instance_key = secrets.encrypt(String(key).trim());
  if (publicKey !== undefined) values.update_public_key = String(publicKey).trim();
  if (channel !== undefined) values.update_channel = channel === 'beta' ? 'beta' : 'stable';
  if (mode !== undefined) values.update_mode = mode === 'auto' ? 'auto' : 'avertir';
  if (name !== undefined) values.update_instance_name = String(name).trim().slice(0, 120);
  settings.setMany(values);
  return config();
}

/** Sans adresse, sans clé ou sans clé publique, il n'y a rien à faire. */
function isConfigured() {
  const { url, key, publicKey } = config();
  return Boolean(url && key && publicKey);
}

/** Ce que l'éditeur a répondu la dernière fois, tel qu'on l'a rangé. */
function available() {
  const raw = settings.get('update_available');
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return null;
  }
}

/**
 * Demander s'il y a du nouveau. La réponse est rangée telle quelle : c'est au
 * moment d'appliquer qu'on vérifie, pas au moment de lire.
 */
async function check() {
  if (!isConfigured()) return { ok: false, reason: 'not-configured' };
  const settingsNow = config();

  const url = new URL(`${settingsNow.url}/api/v1/version`);
  url.searchParams.set('edition', EDITION);
  url.searchParams.set('canal', settingsNow.channel);
  url.searchParams.set('courante', settingsNow.version);
  if (settingsNow.name) url.searchParams.set('nom', settingsNow.name);

  try {
    const response = await fetch(url, {
      headers: { 'X-Cle-Instance': settingsNow.key },
      signal: AbortSignal.timeout(20000),
    });
    if (!response.ok) {
      const reason = response.status === 401 ? 'key-refused' : `http-${response.status}`;
      settings.setMany({ update_last_check: new Date().toISOString(), update_last_error: reason });
      logs.record({
        level: 4, channel: 'integration', event: 'update.check.failed',
        message: `Vérification des mises à jour refusée (${reason}).`,
      });
      return { ok: false, reason };
    }

    const body = await response.json();
    settings.setMany({
      update_last_check: new Date().toISOString(),
      update_last_error: '',
      update_available: body.disponible ? JSON.stringify(body.version) : '',
    });
    return { ok: true, available: Boolean(body.disponible), version: body.version || null };
  } catch (err) {
    settings.setMany({ update_last_check: new Date().toISOString(), update_last_error: 'unreachable' });
    logs.record({
      level: 4, channel: 'integration', event: 'update.check.failed',
      message: `Éditeur injoignable : ${err.message}`,
    });
    return { ok: false, reason: 'unreachable', detail: err.message };
  }
}

/**
 * Vérifier une archive avant de la croire : la taille, l'empreinte, puis la
 * signature de l'éditeur sur la phrase qui nomme cette version précise. Une
 * signature portée par l'empreinte seule laisserait resservir une archive
 * authentique sous un autre numéro.
 */
function verifyArchive(content, announced, publicKeyPem) {
  if (content.length !== Number(announced.taille)) return { ok: false, reason: 'taille' };

  const digest = crypto.createHash('sha256').update(content).digest('hex');
  if (digest !== announced.empreinte) return { ok: false, reason: 'empreinte' };

  const phrase = `toutadmin/${announced.edition}/${announced.numero}/${announced.empreinte}/${announced.taille}`;
  let signed = false;
  try {
    signed = crypto.verify(
      null,
      Buffer.from(phrase, 'utf8'),
      crypto.createPublicKey(publicKeyPem),
      Buffer.from(String(announced.signature), 'base64'),
    );
  } catch {
    // Une clé publique illisible ou une signature mal formée : c'est non.
    signed = false;
  }
  return signed ? { ok: true, digest } : { ok: false, reason: 'signature' };
}

/** L'archive doit contenir un produit, et celui qu'elle annonce. */
function inspectContents(files, number) {
  const manifest = files.find((f) => f.name === 'package.json');
  if (!manifest) return { ok: false, reason: 'package.json absent de l’archive' };
  if (!files.some((f) => f.name === path.join('src', 'server.js'))) {
    return { ok: false, reason: 'src/server.js absent de l’archive' };
  }
  let declared;
  try {
    declared = JSON.parse(manifest.content.toString('utf8'));
  } catch {
    return { ok: false, reason: 'package.json illisible' };
  }
  if (declared.version !== number) {
    return { ok: false, reason: `l’archive annonce ${declared.version}, la version dit ${number}` };
  }
  return { ok: true, manifest: declared };
}

/**
 * Lire l'archive. Les noms sont contrôlés même si la signature est bonne :
 * elle prouve l'origine, pas l'innocence, et un nom qui remonte d'un cran
 * écrirait hors de l'arborescence.
 */
function readArchive(content) {
  const entries = tar.unpack(zlib.gunzipSync(content));
  const files = [];
  for (const entry of entries) {
    const clean = entry.name.replace(/^\.\//, '');
    if (!CODE_ROOTS.includes(clean.split('/')[0])) continue;
    const safe = tar.safeName(clean, CODE_ROOTS);
    if (!safe) throw new Error(`Nom de fichier refusé dans l’archive : ${entry.name}`);
    files.push({ name: safe, content: Buffer.from(entry.data) });
  }
  if (!files.length) throw new Error('Archive vide : aucun fichier de code.');
  return files;
}

function writeMarker(state) {
  fs.mkdirSync(DIR, { recursive: true });
  fs.writeFileSync(MARKER, JSON.stringify(state, null, 2));
}

function readMarker() {
  if (!fs.existsSync(MARKER)) return null;
  try {
    return JSON.parse(fs.readFileSync(MARKER, 'utf8'));
  } catch {
    return null;
  }
}

function clearMarker() {
  if (fs.existsSync(MARKER)) fs.unlinkSync(MARKER);
}

/** Mettre de côté les fichiers qu'on s'apprête à remplacer. */
function setAside(files, dir, root = ROOT) {
  fs.mkdirSync(dir, { recursive: true });
  const kept = [];
  for (const file of files) {
    const source = path.join(root, file.name);
    if (!fs.existsSync(source)) continue;
    const target = path.join(dir, file.name);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
    kept.push(file.name);
  }
  return kept;
}

function writeFiles(files, root = ROOT) {
  for (const file of files) {
    const target = path.join(root, file.name);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.writeFileSync(target, file.content);
  }
}

/** Remettre ce qui avait été mis de côté. */
function restoreFiles(dir, names, root = ROOT) {
  for (const name of names) {
    const source = path.join(dir, name);
    if (!fs.existsSync(source)) continue;
    const target = path.join(root, name);
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
  }
}

function history(limit = 20) {
  return db.prepare(`
    SELECT u.*, users.first_name, users.last_name
    FROM updates u LEFT JOIN users ON users.id = u.by_user_id
    ORDER BY u.started_at DESC, u.id DESC LIMIT ?`).all(limit);
}

/**
 * Appliquer une mise à jour.
 *
 * L'ordre compte, et chaque étape peut refuser : télécharger, vérifier, lire,
 * regarder ce qu'il y a dedans, sauvegarder, mettre de côté, poser. Rien n'est
 * écrit dans l'arborescence avant que tout le reste soit passé — une archive
 * refusée n'a jamais touché le code.
 */
/**
 * Les étapes d'une pose, dans l'ordre où elles arrivent.
 *
 * Elles sont nommées ici parce que trois endroits en parlent : le code qui les
 * traverse, la fenêtre qui les affiche, et le journal. Une liste recopiée
 * aurait fini par décrire une quatrième chose.
 */
const ETAPES = ['telechargement', 'verification', 'lecture', 'sauvegarde', 'pose', 'redemarrage'];

async function apply({ by = null, mode = 'manual', root = ROOT } = {}) {
  // Avant tout le reste : rien n'est tenté si rien ne peut être écrit. Refuser
  // ici plutôt que d'échouer au milieu évite de laisser une instance à moitié
  // remplacée.
  if (codeImmuable()) return { ok: false, reason: 'code-immuable' };
  if (!isConfigured()) return { ok: false, reason: 'not-configured' };
  const announced = available();
  if (!announced) return { ok: false, reason: 'nothing-to-apply' };

  const settingsNow = config();
  const from = currentVersion();
  const inserted = db.prepare(`
    INSERT INTO updates (version, edition, channel, mode, status, from_version, by_user_id)
    VALUES (?, ?, ?, ?, 'running', ?, ?)`)
    .run(announced.numero, announced.edition, announced.canal || settingsNow.channel, mode, from, by);
  const id = inserted.lastInsertRowid;

  /**
   * Note l'étape en cours sur la ligne de la mise à jour.
   *
   * Elle y est écrite plutôt que gardée en mémoire pour une raison précise :
   * qui rafraîchit la page pendant une pose doit retrouver où elle en est, et
   * non un écran muet devant un processus qui travaille encore.
   */
  const etape = (nom) => {
    db.prepare("UPDATE updates SET detail = ? WHERE id = ?").run(`etape:${nom}`, id);
  };

  const fail = (reason, detail = '') => {
    db.prepare("UPDATE updates SET status = 'failed', detail = ?, finished_at = datetime('now') WHERE id = ?")
      .run(detail || reason, id);
    logs.record({
      level: 3, channel: 'systeme', event: 'update.failed',
      message: `Mise à jour ${announced.numero} refusée : ${detail || reason}`,
    });
    return { ok: false, reason, detail };
  };

  // 1. Télécharger.
  etape('telechargement');
  let content;
  try {
    const response = await fetch(announced.archive, {
      headers: { 'X-Cle-Instance': settingsNow.key },
      signal: AbortSignal.timeout(120000),
    });
    if (!response.ok) return fail('download', `l’éditeur a répondu ${response.status}`);
    content = Buffer.from(await response.arrayBuffer());
  } catch (err) {
    return fail('download', err.message);
  }

  // 2. Vérifier avant de croire.
  etape('verification');
  const verdict = verifyArchive(content, announced, settingsNow.publicKey);
  if (!verdict.ok) {
    logs.record({
      level: 1, channel: 'securite', event: 'update.rejected',
      message: `Archive de mise à jour refusée (${verdict.reason}) pour la version ${announced.numero}.`,
    });
    return fail('verification', verdict.reason);
  }

  // 3. Lire, et regarder ce qu'il y a dedans.
  etape('lecture');
  let files;
  try {
    files = readArchive(content);
  } catch (err) {
    return fail('archive', err.message);
  }
  const contents = inspectContents(files, announced.numero);
  if (!contents.ok) return fail('contents', contents.reason);

  // 4. Sauvegarder avant de toucher au code. Une mise à jour sans filet n'est
  //    pas une mise à jour, c'est un pari.
  etape('sauvegarde');
  let backupFile = '';
  try {
    const done = await backup.create({ reason: 'avant mise à jour', label: `vers ${announced.numero}` });
    backupFile = done.fileName;
  } catch (err) {
    return fail('backup', err.message);
  }

  // 5. Mettre de côté, puis poser.
  etape('pose');
  const aside = path.join(DIR, `precedent-${from}`);
  let kept = [];
  try {
    fs.rmSync(aside, { recursive: true, force: true });
    kept = setAside(files, aside, root);
    writeMarker({
      step: 'swapping', version: announced.numero, from, aside, kept, backupFile, id, tries: 0,
      at: new Date().toISOString(),
    });
    writeFiles(files, root);
  } catch (err) {
    // La pose a échoué à mi-chemin : on remet ce qu'on avait mis de côté.
    restoreFiles(aside, kept, root);
    clearMarker();
    return fail('write', err.message);
  }

  writeMarker({
    step: 'awaiting-restart', version: announced.numero, from, aside, kept, backupFile, id, tries: 0,
    at: new Date().toISOString(),
  });

  db.prepare("UPDATE updates SET backup_file = ?, detail = 'posée, en attente de redémarrage' WHERE id = ?")
    .run(backupFile, id);
  settings.set('update_available', '');

  logs.record({
    level: 5, channel: 'systeme', event: 'update.applied',
    message: `Version ${announced.numero} posée (depuis ${from}). Redémarrage attendu.`,
  });
  audit.logSystem('update.apply', 'instance', null, { de: from, vers: announced.numero, mode });

  return { ok: true, version: announced.numero, from, backupFile, restartNeeded: true };
}

/**
 * Au démarrage : vient-on d'appliquer une mise à jour ?
 *
 * Si oui, ce démarrage est le test. On compte les essais ; au-delà de trois, la
 * version posée ne démarre pas et la précédente revient. La base, elle, n'est
 * pas remise en arrière : elle a pu recevoir des écritures depuis, et la
 * sauvegarde prise avant est nommée dans l'historique pour qui veut la
 * restaurer en connaissance de cause.
 */
function onBoot({ root = ROOT } = {}) {
  const state = readMarker();
  if (!state) return { state: 'none' };

  if (state.step === 'swapping') {
    // Le processus est mort pendant la pose : l'arborescence est peut-être à
    // moitié neuve. La précédente est la seule version connue.
    restoreFiles(state.aside, state.kept || [], root);
    clearMarker();
    markFailed(state, 'interrompue pendant la pose');
    return { state: 'rolled-back', reason: 'pose interrompue', version: state.from };
  }

  if (state.step !== 'awaiting-restart') return { state: 'none' };

  const tries = Number(state.tries || 0) + 1;
  if (tries > MAX_TRIES) {
    restoreFiles(state.aside, state.kept || [], root);
    clearMarker();
    markFailed(state, `la version ${state.version} n’a pas démarré après ${MAX_TRIES} essais`);
    logs.record({
      level: 2, channel: 'systeme', event: 'update.rolledback',
      message: `Retour à la version ${state.from} : ${state.version} n’a pas démarré.`,
    });
    return { state: 'rolled-back', version: state.from, reason: 'démarrages ratés' };
  }

  writeMarker({ ...state, tries });
  return { state: 'trying', tries, version: state.version };
}

/**
 * Le démarrage a tenu : la mise à jour est acquise. Appelé une fois le serveur
 * en écoute depuis un moment — un processus qui meurt à la première requête ne
 * doit pas passer pour un succès.
 */
function confirmBoot() {
  const state = readMarker();
  if (!state || state.step !== 'awaiting-restart') return { state: 'none' };

  db.prepare("UPDATE updates SET status = 'success', detail = 'appliquée', finished_at = datetime('now') WHERE id = ?")
    .run(state.id);
  clearMarker();
  logs.record({
    level: 5, channel: 'systeme', event: 'update.confirmed',
    message: `Version ${state.version} en service.`,
  });
  return { state: 'confirmed', version: state.version };
}

function markFailed(state, detail) {
  if (!state.id) return;
  db.prepare("UPDATE updates SET status = 'failed', detail = ?, finished_at = datetime('now') WHERE id = ?")
    .run(detail, state.id);
}

/**
 * Revenir à la version précédente à la demande, tant que les fichiers mis de
 * côté sont là. Ce qui suit — redémarrer — appartient à l'hébergeur.
 */
function rollback({ by = null, root = ROOT } = {}) {
  if (codeImmuable()) return { ok: false, reason: 'code-immuable' };
  const last = db.prepare("SELECT * FROM updates WHERE status = 'success' ORDER BY id DESC LIMIT 1").get();
  if (!last) return { ok: false, reason: 'nothing-to-undo' };

  const aside = path.join(DIR, `precedent-${last.from_version}`);
  if (!fs.existsSync(aside)) return { ok: false, reason: 'files-gone' };

  const names = [];
  const walk = (dir, prefix = '') => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const relative = path.join(prefix, entry.name);
      if (entry.isDirectory()) walk(path.join(dir, entry.name), relative);
      else names.push(relative);
    }
  };
  walk(aside);
  restoreFiles(aside, names, root);

  db.prepare(`INSERT INTO updates (version, edition, channel, mode, status, detail, from_version, by_user_id, finished_at)
    VALUES (?, 'node', ?, 'rollback', 'rolledback', 'retour arrière demandé', ?, ?, datetime('now'))`)
    .run(last.from_version, last.channel, last.version, by);
  logs.record({
    level: 5, channel: 'systeme', event: 'update.rollback',
    message: `Retour à la version ${last.from_version} demandé.`,
  });
  audit.logSystem('update.rollback', 'instance', null, { de: last.version, vers: last.from_version });
  return { ok: true, version: last.from_version, restartNeeded: true };
}

/** Le balayage horaire : vérifier, et appliquer si l'instance l'a demandé. */
async function sweep() {
  if (!isConfigured()) return { checked: false };
  const seen = await check();
  if (!seen.ok || !seen.available) return { checked: true, available: false };

  // En code immuable, « auto » ne veut plus rien dire : on prévient, et c'est
  // tout. Le réglage reste tel que l'administrateur l'a laissé, pour qu'il
  // reprenne effet si l'instance change de mode de déploiement.
  if (codeImmuable() || config().mode !== 'auto') {
    logs.record({
      level: 5, channel: 'systeme', event: 'update.available',
      message: codeImmuable()
        ? `Version ${seen.version.numero} disponible. Le code de cette instance ne s’écrit pas : changez l’image.`
        : `Version ${seen.version.numero} disponible. Mode « prévenir seulement » : rien n’est appliqué.`,
    });
    return { checked: true, available: true, applied: false };
  }
  const result = await apply({ mode: 'auto' });
  return { checked: true, available: true, applied: result.ok, result };
}

/**
 * Où en est la pose en cours, s'il y en a une.
 *
 * Rend l'étape atteinte, ou null. C'est ce que la fenêtre interroge pendant
 * qu'elle attend, et ce qu'elle retrouve si on rafraîchit la page.
 */
function enCours() {
  const ligne = db.prepare("SELECT * FROM updates WHERE status = 'running' ORDER BY id DESC LIMIT 1").get();
  if (!ligne) return null;

  const nom = String(ligne.detail || '').startsWith('etape:') ? ligne.detail.slice(6) : '';
  return {
    id: ligne.id,
    version: ligne.version,
    depuis: ligne.from_version,
    etape: nom || ETAPES[0],
    rang: Math.max(0, ETAPES.indexOf(nom || ETAPES[0])),
    total: ETAPES.length,
    commencee_le: ligne.started_at,
  };
}

module.exports = {
  EDITION, ROOT, DIR, MARKER, CODE_ROOTS, MAX_TRIES, ETAPES, enCours,
  currentVersion, config, setConfig, isConfigured, available, check, codeImmuable,
  verifyArchive, inspectContents, readArchive,
  writeMarker, readMarker, clearMarker, setAside, writeFiles, restoreFiles,
  history, apply, onBoot, confirmBoot, rollback, sweep,
};
