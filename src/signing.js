const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const multer = require('multer');
const bcrypt = require('bcryptjs');

const db = require('./db');
const install = require('./install');
const fileType = require('./file-type');

/**
 * Parapheur : signature électronique interne.
 *
 * Ce que le module garantit, et qui suffit à un contrat de travail, un avenant
 * ou un accord interne :
 *
 *   — le document est **figé** dès la mise à la signature. Son empreinte
 *     SHA-256 est calculée là, revérifiée avant chaque signature et à chaque
 *     téléchargement. Signer un document qui peut changer ensuite ne
 *     signifierait rien ;
 *   — chaque signature porte **qui, quand, depuis quelle adresse**, et un
 *     sceau calculé par l'instance (HMAC de l'empreinte du document, du
 *     signataire et de l'horodatage, avec une clé qui vit hors de la base) :
 *     une ligne recopiée à la main dans la base ne passerait pas la
 *     vérification ;
 *   — le signataire **prouve sa présence** en ressaisissant son mot de passe,
 *     et consent explicitement. Une session ouverte sur un poste laissé sans
 *     surveillance ne suffit pas à engager quelqu'un ;
 *   — l'ordre des signataires est **respecté** : le parapheur circule, il ne
 *     s'éparpille pas.
 *
 * Ce que le module ne prétend pas être : une signature électronique
 * **qualifiée** au sens eIDAS. Celle-ci demande un prestataire de confiance
 * certifié et une identification en face-à-face. Ici, la valeur probante
 * repose sur le faisceau — empreinte, sceau, horodatage, journal d'audit —
 * comme pour une signature simple.
 */

const SIGN_DIR = process.env.SIGN_DIR
  || path.join(path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite')), 'parapheur');

const MAX_BYTES = 10 * 1024 * 1024;
const MAX_SIGNERS = 12;

const KINDS = ['Contrat de travail', 'Avenant', 'Accord interne', 'Règlement', 'Procès-verbal', 'Document'];
const STATUSES = ['En cours', 'Signé', 'Refusé', 'Annulé'];

const ACCEPTED = {
  'application/pdf': '.pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
};

function ensureDir() {
  if (!fs.existsSync(SIGN_DIR)) fs.mkdirSync(SIGN_DIR, { recursive: true, mode: 0o700 });
}

// Réception en mémoire : rien n'est écrit avant la validation du jeton CSRF et
// le contrôle de la signature du fichier.
const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_BYTES, files: 1 },
  fileFilter(req, file, cb) {
    if (!ACCEPTED[file.mimetype]) return cb(new Error('unsupported-type'));
    cb(null, true);
  },
}).single('document');

const fingerprint = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

/** La clé des sceaux : dérivée du secret de session, qui vit dans un fichier à part. */
function sealKey() {
  return crypto.hkdfSync('sha256', Buffer.from(install.sessionSecret()), Buffer.alloc(0), Buffer.from('parapheur'), 32);
}

function computeSeal({ documentHash, userId, signedAt }) {
  return crypto.createHmac('sha256', Buffer.from(sealKey()))
    .update(`${documentHash}|${userId}|${signedAt}`)
    .digest('hex');
}

// ---------- Lectures ----------

function byId(id) {
  return db.prepare('SELECT * FROM signature_requests WHERE id = ?').get(Number(id) || 0) || null;
}

function signersOf(requestId) {
  return db.prepare(`
    SELECT s.*, u.first_name, u.last_name, u.grade, u.email
    FROM signature_signers s JOIN users u ON u.id = s.user_id
    WHERE s.request_id = ? ORDER BY s.position, s.id
  `).all(requestId);
}

function decorate(request) {
  if (!request) return null;
  const signers = signersOf(request.id);
  const signed = signers.filter((s) => s.status === 'Signé').length;
  return {
    ...request,
    signers,
    signedCount: signed,
    total: signers.length,
    progress: signers.length ? Math.round((signed / signers.length) * 100) : 0,
    next: signers.find((s) => s.status === 'En attente') || null,
    overdue: Boolean(request.deadline && request.status === 'En cours' && request.deadline < new Date().toISOString().slice(0, 10)),
  };
}

function list({ status = null, limit = 200 } = {}) {
  const clause = status ? 'WHERE r.status = ?' : '';
  const params = status ? [status, limit] : [limit];
  return db.prepare(`
    SELECT r.*, u.first_name AS author_first, u.last_name AS author_last
    FROM signature_requests r LEFT JOIN users u ON u.id = r.created_by
    ${clause} ORDER BY r.created_at DESC LIMIT ?
  `).all(...params).map(decorate);
}

/** Les documents que cette personne doit signer maintenant : c'est à son tour. */
function pendingFor(userId) {
  return list({ status: 'En cours' }).filter((request) => request.next && request.next.user_id === Number(userId));
}

function pendingCountFor(userId) {
  return pendingFor(userId).length;
}

/** Tout ce qui concerne une personne : à signer, signé, refusé. */
function forUser(userId) {
  return list().filter((request) => request.signers.some((s) => s.user_id === Number(userId)));
}

function isParty(request, userId) {
  return request.created_by === Number(userId) || request.signers.some((s) => s.user_id === Number(userId));
}

// ---------- Création ----------

/**
 * Met un document à la signature. Le texte ou le fichier, l'un ou l'autre :
 * dans les deux cas l'empreinte porte sur ce qui sera relu, à l'octet près.
 */
function create({ title, kind, body = '', file = null, deadline = null, createdBy, signers = [] }) {
  if (!KINDS.includes(kind)) return { ok: false, message: 'Nature de document inconnue.' };
  if (!String(title || '').trim()) return { ok: false, message: 'Un intitulé est requis.' };
  if (!file && !String(body || '').trim()) return { ok: false, message: 'Déposez un fichier ou saisissez le texte à signer.' };
  if (!signers.length) return { ok: false, message: 'Désignez au moins un signataire.' };
  if (signers.length > MAX_SIGNERS) return { ok: false, message: `${MAX_SIGNERS} signataires au maximum.` };

  const unique = new Set(signers.map((s) => Number(s.userId)));
  if (unique.size !== signers.length) return { ok: false, message: 'Un signataire ne peut pas figurer deux fois.' };

  for (const signer of signers) {
    const user = db.prepare('SELECT id, active FROM users WHERE id = ?').get(Number(signer.userId));
    if (!user || !user.active) return { ok: false, message: 'Un signataire désigné est inconnu ou désactivé.' };
  }

  let stored = { fileName: '', originalName: '', mimeType: '', size: 0 };
  let hash;

  if (file) {
    if (!fileType.matches(file.buffer, file.mimetype)) {
      return { ok: false, message: "Ce fichier n'est pas du type annoncé : dépôt refusé." };
    }
    hash = fingerprint(file.buffer);
    ensureDir();
    const name = crypto.randomBytes(16).toString('hex') + (ACCEPTED[file.mimetype] || '.bin');
    fs.writeFileSync(path.join(SIGN_DIR, name), file.buffer, { mode: 0o600 });
    stored = {
      fileName: name,
      originalName: String(file.originalname || '').slice(0, 200),
      mimeType: file.mimetype,
      size: file.buffer.length,
    };
  } else {
    hash = fingerprint(Buffer.from(String(body), 'utf8'));
  }

  const insert = db.transaction(() => {
    const id = db.prepare(`
      INSERT INTO signature_requests (title, kind, body, file_name, original_name, mime_type, byte_size, sha256, deadline, created_by)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(String(title).trim().slice(0, 200), kind, file ? '' : String(body), stored.fileName, stored.originalName,
      stored.mimeType, stored.size, hash, deadline || null, createdBy || null).lastInsertRowid;

    const addSigner = db.prepare('INSERT INTO signature_signers (request_id, user_id, position, role_label) VALUES (?, ?, ?, ?)');
    signers.forEach((signer, index) => addSigner.run(id, Number(signer.userId), index + 1, String(signer.roleLabel || '').slice(0, 80)));
    return id;
  });

  return { ok: true, id: insert(), sha256: hash };
}

// ---------- Intégrité ----------

function pathOf(request) {
  return request.file_name ? path.join(SIGN_DIR, path.basename(request.file_name)) : null;
}

/** Recalcule l'empreinte de ce qui a été mis à la signature. */
function verifyDocument(request) {
  if (!request.file_name) {
    const actual = fingerprint(Buffer.from(String(request.body), 'utf8'));
    return actual === request.sha256
      ? { ok: true }
      : { ok: false, reason: 'le texte ne correspond plus à son empreinte', actual };
  }

  const target = pathOf(request);
  if (!fs.existsSync(target)) return { ok: false, reason: 'fichier absent' };

  const actual = fingerprint(fs.readFileSync(target));
  return actual === request.sha256 ? { ok: true } : { ok: false, reason: 'empreinte différente', actual };
}

/** Vérifie chaque sceau : une ligne écrite à la main en base ne passe pas. */
function verifySeals(request) {
  return signersOf(request.id)
    .filter((signer) => signer.status === 'Signé')
    .map((signer) => ({
      signer,
      valid: signer.seal === computeSeal({ documentHash: request.sha256, userId: signer.user_id, signedAt: signer.signed_at }),
    }));
}

function verify(requestOrId) {
  const request = typeof requestOrId === 'object' ? requestOrId : byId(requestOrId);
  if (!request) return { ok: false, reason: 'document introuvable' };

  const document = verifyDocument(request);
  const seals = verifySeals(request);
  const broken = seals.filter((s) => !s.valid);

  return {
    ok: document.ok && broken.length === 0,
    document,
    seals,
    broken: broken.map((s) => `${s.signer.first_name} ${s.signer.last_name}`),
  };
}

// ---------- Signature ----------

function canSign(request, userId) {
  if (!request || request.status !== 'En cours') return { ok: false, message: "Ce document n'est plus à la signature." };

  const signers = signersOf(request.id);
  const mine = signers.find((s) => s.user_id === Number(userId));
  if (!mine) return { ok: false, message: 'Vous ne figurez pas parmi les signataires.' };
  if (mine.status !== 'En attente') return { ok: false, message: `Vous avez déjà ${mine.status.toLowerCase()} ce document.` };

  // Le parapheur circule : chacun signe à son tour.
  const waiting = signers.find((s) => s.status === 'En attente');
  if (waiting && waiting.user_id !== Number(userId)) {
    return { ok: false, message: `C'est au tour de ${waiting.first_name} ${waiting.last_name} de signer.` };
  }
  return { ok: true, signer: mine };
}

/**
 * Signe. Le mot de passe est redemandé : une session ouverte sur un poste
 * laissé sans surveillance ne doit pas suffire à engager quelqu'un.
 */
function sign(requestId, userId, { password, consent, ip = '' }) {
  const request = byId(requestId);
  const allowed = canSign(request, userId);
  if (!allowed.ok) return allowed;

  if (!consent) return { ok: false, message: 'Cochez la case de consentement pour signer.' };

  const user = db.prepare('SELECT id, password_hash, must_change_password FROM users WHERE id = ?').get(Number(userId));
  if (!user) return { ok: false, message: 'Compte introuvable.' };
  if (user.must_change_password) return { ok: false, message: 'Choisissez d\'abord votre mot de passe définitif.' };
  if (!password || !bcrypt.compareSync(String(password), user.password_hash)) {
    return { ok: false, message: 'Mot de passe incorrect : la signature n\'a pas été apposée.' };
  }

  // On revérifie le document juste avant d'engager quelqu'un dessus.
  const integrity = verifyDocument(request);
  if (!integrity.ok) return { ok: false, message: `Document altéré depuis sa mise à la signature (${integrity.reason}) : signature refusée.` };

  const signedAt = new Date().toISOString();
  const seal = computeSeal({ documentHash: request.sha256, userId: Number(userId), signedAt });

  const apply = db.transaction(() => {
    db.prepare(`
      UPDATE signature_signers SET status = 'Signé', signed_at = ?, seal = ?, ip = ?
      WHERE request_id = ? AND user_id = ? AND status = 'En attente'
    `).run(signedAt, seal, String(ip).slice(0, 60), request.id, Number(userId));

    const remaining = db.prepare("SELECT COUNT(*) AS n FROM signature_signers WHERE request_id = ? AND status = 'En attente'").get(request.id).n;
    if (remaining === 0) {
      db.prepare("UPDATE signature_requests SET status = 'Signé', completed_at = ? WHERE id = ?").run(signedAt, request.id);
    }
    return remaining;
  });

  const remaining = apply();
  return { ok: true, seal, signedAt, remaining, completed: remaining === 0 };
}

/** Refuser interrompt le circuit : les suivants n'ont plus à se prononcer. */
function refuse(requestId, userId, { reason = '', ip = '' } = {}) {
  const request = byId(requestId);
  const allowed = canSign(request, userId);
  if (!allowed.ok) return allowed;
  if (!String(reason).trim()) return { ok: false, message: 'Un refus se motive.' };

  const apply = db.transaction(() => {
    db.prepare(`
      UPDATE signature_signers SET status = 'Refusé', signed_at = ?, reason = ?, ip = ?
      WHERE request_id = ? AND user_id = ?
    `).run(new Date().toISOString(), String(reason).trim().slice(0, 500), String(ip).slice(0, 60), request.id, Number(userId));

    db.prepare("UPDATE signature_requests SET status = 'Refusé', completed_at = ?, closing_reason = ? WHERE id = ?")
      .run(new Date().toISOString(), String(reason).trim().slice(0, 500), request.id);
  });
  apply();
  return { ok: true };
}

function cancel(requestId, reason = '') {
  const request = byId(requestId);
  if (!request) return { ok: false, message: 'Document introuvable.' };
  if (request.status !== 'En cours') return { ok: false, message: 'Ce document n\'est plus à la signature.' };

  db.prepare("UPDATE signature_requests SET status = 'Annulé', completed_at = ?, closing_reason = ? WHERE id = ?")
    .run(new Date().toISOString(), String(reason).slice(0, 500), request.id);
  return { ok: true };
}

/**
 * Un document signé ne se supprime pas : il est la preuve de l'engagement.
 * Seul un document annulé ou refusé, sans aucune signature apposée, s'efface.
 */
function remove(requestId) {
  const request = byId(requestId);
  if (!request) return { ok: false, message: 'Document introuvable.' };

  const signed = db.prepare("SELECT COUNT(*) AS n FROM signature_signers WHERE request_id = ? AND status = 'Signé'").get(request.id).n;
  if (signed > 0) return { ok: false, message: 'Un document déjà signé ne se supprime pas : il fait preuve.' };
  if (request.status === 'En cours') return { ok: false, message: 'Annulez le document avant de le supprimer.' };

  const target = pathOf(request);
  if (target && fs.existsSync(target)) fs.unlinkSync(target);
  db.prepare('DELETE FROM signature_requests WHERE id = ?').run(request.id);
  return { ok: true };
}

/** Les éléments de l'attestation de signature, tels qu'ils seront imprimés. */
function certificate(requestId) {
  const request = decorate(byId(requestId));
  if (!request) return null;
  return { request, verification: verify(request) };
}

function summary() {
  return {
    running: db.prepare("SELECT COUNT(*) AS n FROM signature_requests WHERE status = 'En cours'").get().n,
    signed: db.prepare("SELECT COUNT(*) AS n FROM signature_requests WHERE status = 'Signé'").get().n,
    refused: db.prepare("SELECT COUNT(*) AS n FROM signature_requests WHERE status = 'Refusé'").get().n,
    overdue: list({ status: 'En cours' }).filter((r) => r.overdue).length,
  };
}

module.exports = {
  SIGN_DIR, MAX_BYTES, MAX_SIGNERS, KINDS, STATUSES, ACCEPTED, upload,
  fingerprint, computeSeal, byId, signersOf, decorate, list, pendingFor, pendingCountFor, forUser, isParty,
  create, pathOf, verifyDocument, verifySeals, verify, canSign, sign, refuse, cancel, remove, certificate, summary,
};
