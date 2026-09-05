const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const multer = require('multer');

const db = require('./db');
const fileType = require('./file-type');

/**
 * Coffre-fort numérique.
 *
 * Un bulletin de paie dématérialisé doit rester accessible au salarié bien
 * après son départ — cinquante ans, dans le cas général. Trois conséquences,
 * qui commandent tout ce module :
 *
 *   1. le document ne se modifie pas : il porte l'empreinte SHA-256 de son
 *      contenu, vérifiée à chaque téléchargement ;
 *   2. il ne se supprime pas à la légère : un retrait laisse la ligne, son
 *      auteur et son motif ;
 *   3. la personne partie doit pouvoir entrer, alors que son compte est fermé
 *      et qu'elle aura oublié son mot de passe (voir les codes d'accès).
 */

const VAULT_DIR = process.env.VAULT_DIR
  || path.join(path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite')), 'coffre');

const MAX_BYTES = 10 * 1024 * 1024;

const ACCEPTED = {
  'application/pdf': '.pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'image/png': '.png',
  'image/jpeg': '.jpg',
};

const CATEGORIES = [
  'Bulletin de paie',
  'Contrat de travail',
  'Avenant',
  'Certificat de travail',
  'Attestation employeur',
  'Solde de tout compte',
  'Autre document',
];

// Durée pendant laquelle un bulletin dématérialisé doit rester accessible.
const RETENTION_YEARS = 50;

// Un code d'accès reste valable ce nombre de jours, sauf choix contraire.
const DEFAULT_GRANT_DAYS = 90;
const MAX_GRANT_DAYS = 3650;

function ensureDir() {
  if (!fs.existsSync(VAULT_DIR)) fs.mkdirSync(VAULT_DIR, { recursive: true, mode: 0o700 });
}

// Réception en mémoire : rien n'est écrit avant que le jeton CSRF soit validé
// et que la signature du fichier corresponde au type annoncé.
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_BYTES, files: 1 },
  fileFilter(req, file, cb) {
    if (!ACCEPTED[file.mimetype]) return cb(new Error('unsupported-type'));
    cb(null, true);
  },
}).single('document');

function fingerprint(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

function retentionFrom(isoDate) {
  const date = new Date(`${isoDate}T00:00:00Z`);
  date.setUTCFullYear(date.getUTCFullYear() + RETENTION_YEARS);
  return date.toISOString().slice(0, 10);
}

/**
 * Dépose un document. Rend { ok, id } ou { ok: false, message } — jamais une
 * exception : un dépôt refusé est une erreur d'usage, pas une panne.
 */
function deposit({ userId, category, title, period, file, depositedBy, payslipId = null }) {
  if (!file) return { ok: false, message: 'Aucun fichier reçu.' };
  if (!CATEGORIES.includes(category)) return { ok: false, message: 'Catégorie invalide.' };
  if (!fileType.matches(file.buffer, file.mimetype)) {
    return { ok: false, message: "Ce fichier n'est pas du type annoncé : dépôt refusé." };
  }

  const hash = fingerprint(file.buffer);

  // Le même document déposé deux fois pour la même personne n'a pas à occuper
  // deux places : on rend l'existant plutôt que d'empiler.
  const existing = db.prepare('SELECT id FROM vault_documents WHERE user_id = ? AND sha256 = ? AND removed_at IS NULL').get(userId, hash);
  if (existing) return { ok: false, message: 'Ce document est déjà au coffre de cette personne.', duplicate: existing.id };

  ensureDir();
  const name = crypto.randomBytes(16).toString('hex') + (ACCEPTED[file.mimetype] || '.bin');
  fs.writeFileSync(path.join(VAULT_DIR, name), file.buffer, { mode: 0o600 });

  const today = new Date().toISOString().slice(0, 10);
  const id = db.prepare(`
    INSERT INTO vault_documents (user_id, category, title, period, payslip_id, file_name, original_name,
                                 mime_type, byte_size, sha256, deposited_by, retention_until)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(userId, category, title, period || '', payslipId, name,
    String(file.originalname || '').slice(0, 200), file.mimetype, file.buffer.length, hash,
    depositedBy || null, retentionFrom(today)).lastInsertRowid;

  return { ok: true, id, sha256: hash };
}

function byId(id) {
  return db.prepare('SELECT * FROM vault_documents WHERE id = ?').get(Number(id) || 0) || null;
}

function documentsFor(userId, { includeRemoved = false } = {}) {
  const clause = includeRemoved ? '' : 'AND removed_at IS NULL';
  return db.prepare(`
    SELECT d.*, u.first_name AS deposited_first_name, u.last_name AS deposited_last_name
    FROM vault_documents d LEFT JOIN users u ON u.id = d.deposited_by
    WHERE d.user_id = ? ${clause}
    ORDER BY d.period DESC, d.deposited_at DESC, d.id DESC
  `).all(userId);
}

function pathOf(document) {
  return path.join(VAULT_DIR, path.basename(document.file_name));
}

/**
 * Recalcule l'empreinte du fichier et la compare à celle enregistrée. Un
 * document dont le contenu a bougé n'est pas servi : c'est tout l'intérêt.
 */
function verify(document) {
  const target = pathOf(document);
  if (!fs.existsSync(target)) return { ok: false, reason: 'fichier absent' };

  const actual = fingerprint(fs.readFileSync(target));
  if (actual !== document.sha256) return { ok: false, reason: 'empreinte différente', actual };
  return { ok: true };
}

/** Contrôle d'intégrité de tout le coffre, pour la console RH. */
function audit() {
  const rows = db.prepare('SELECT * FROM vault_documents WHERE removed_at IS NULL').all();
  const broken = rows.filter((row) => !verify(row).ok);
  return { total: rows.length, broken };
}

/**
 * Retrait d'un document. La ligne reste, avec son auteur et son motif : c'est
 * la trace du retrait qui compte autant que le document.
 */
function remove(id, { removedBy, reason }) {
  const document = byId(id);
  if (!document || document.removed_at) return { ok: false, message: 'Document introuvable.' };
  if (!reason || reason.trim().length < 5) return { ok: false, message: 'Un retrait demande un motif explicite.' };

  const target = pathOf(document);
  if (fs.existsSync(target)) fs.rmSync(target, { force: true });

  db.prepare(`
    UPDATE vault_documents SET removed_at = datetime('now'), removed_by = ?, removal_reason = ? WHERE id = ?
  `).run(removedBy || null, reason.trim().slice(0, 300), id);
  return { ok: true };
}

// ---------- Codes d'accès après le départ ----------

function hashCode(code) {
  return crypto.createHash('sha256').update(String(code).toUpperCase().replace(/[^A-Z0-9]/g, '')).digest('hex');
}

/** Un code lisible à voix haute, sans caractères qui se confondent. */
function generateCode() {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const groups = [];
  for (let g = 0; g < 3; g++) {
    let chunk = '';
    for (let i = 0; i < 5; i++) chunk += alphabet[crypto.randomInt(alphabet.length)];
    groups.push(chunk);
  }
  return groups.join('-');
}

/** Émettre un code révoque le précédent : un seul vaut à la fois. */
function issueGrant(userId, { days = DEFAULT_GRANT_DAYS, createdBy } = {}) {
  const span = Math.min(MAX_GRANT_DAYS, Math.max(1, Number(days) || DEFAULT_GRANT_DAYS));
  const expires = new Date(Date.now() + span * 24 * 60 * 60 * 1000).toISOString();
  const code = generateCode();

  const write = db.transaction(() => {
    db.prepare("UPDATE vault_access_grants SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL").run(userId);
    db.prepare('INSERT INTO vault_access_grants (user_id, code_hash, created_by, expires_at) VALUES (?, ?, ?, ?)')
      .run(userId, hashCode(code), createdBy || null, expires);
  });
  write();
  return { code, expiresAt: expires };
}

function grantsFor(userId) {
  return db.prepare('SELECT * FROM vault_access_grants WHERE user_id = ? ORDER BY id DESC').all(userId);
}

function activeGrant(userId) {
  return db.prepare(`
    SELECT * FROM vault_access_grants
    WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ?
    ORDER BY id DESC LIMIT 1
  `).get(userId, new Date().toISOString()) || null;
}

function revokeGrants(userId) {
  return db.prepare("UPDATE vault_access_grants SET revoked_at = datetime('now') WHERE user_id = ? AND revoked_at IS NULL")
    .run(userId).changes;
}

/**
 * Échange un couple adresse + code contre l'identité correspondante.
 *
 * Les deux sont exigés ensemble et la réponse est la même dans tous les cas
 * d'échec : ni l'existence du compte, ni celle du code ne se déduisent d'ici.
 */
function redeem(email, code) {
  const user = db.prepare('SELECT * FROM users WHERE email = ?').get(String(email || '').toLowerCase().trim());
  if (!user) return null;

  const grant = activeGrant(user.id);
  if (!grant) return null;

  const submitted = Buffer.from(hashCode(code));
  const expected = Buffer.from(grant.code_hash);
  if (submitted.length !== expected.length || !crypto.timingSafeEqual(submitted, expected)) return null;

  db.prepare("UPDATE vault_access_grants SET last_used_at = datetime('now'), uses = uses + 1 WHERE id = ?").run(grant.id);
  return user;
}

/** Une personne a un coffre dès lors qu'un document y a été déposé pour elle. */
function hasDocuments(userId) {
  return db.prepare('SELECT 1 FROM vault_documents WHERE user_id = ? AND removed_at IS NULL').get(userId) !== undefined;
}

function summary() {
  const documents = db.prepare('SELECT COUNT(*) AS n FROM vault_documents WHERE removed_at IS NULL').get().n;
  const holders = db.prepare('SELECT COUNT(DISTINCT user_id) AS n FROM vault_documents WHERE removed_at IS NULL').get().n;
  const departed = db.prepare(`
    SELECT COUNT(DISTINCT d.user_id) AS n FROM vault_documents d JOIN users u ON u.id = d.user_id
    WHERE d.removed_at IS NULL AND u.active = 0
  `).get().n;
  const grants = db.prepare('SELECT COUNT(*) AS n FROM vault_access_grants WHERE revoked_at IS NULL AND expires_at > ?')
    .get(new Date().toISOString()).n;
  return { documents, holders, departed, grants };
}

module.exports = {
  VAULT_DIR, MAX_BYTES, ACCEPTED, CATEGORIES, RETENTION_YEARS, DEFAULT_GRANT_DAYS, MAX_GRANT_DAYS,
  upload, fingerprint, retentionFrom, deposit, byId, documentsFor, pathOf, verify, audit, remove,
  generateCode, issueGrant, grantsFor, activeGrant, revokeGrants, redeem, hasDocuments, summary,
};
