const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const multer = require('multer');

const db = require('./db');
const cv = require('./cv');
const fileType = require('./file-type');
const scan = require('./invoice-scan');
const ai = require('./ai');

/**
 * Réception des pièces comptables.
 *
 * Une facture arrive de deux façons : quelqu'un la dépose, ou elle tombe dans
 * la boîte aux lettres de la comptabilité. Dans les deux cas elle suit le même
 * chemin : le fichier est conservé tel quel, son texte est lu, l'analyse est
 * rangée à côté, et **le comptable tranche**. Rien n'entre en comptabilité sans
 * un clic — une lecture automatique qui écrit directement dans les comptes est
 * une erreur qu'on découvre au bilan.
 *
 * L'empreinte du fichier fait office de garde-fou : la même facture déposée à
 * la main puis reçue par courriel ne fait qu'une seule ligne.
 */

const DOCS_DIR = process.env.DOCS_DIR
  || path.join(path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite')), 'pieces');

const MAX_BYTES = 15 * 1024 * 1024;

const ACCEPTED = {
  'application/pdf': '.pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'text/plain': '.txt',
};

const STATUSES = ['À traiter', 'Facturée', 'Écartée'];

function ensureDir() {
  if (!fs.existsSync(DOCS_DIR)) fs.mkdirSync(DOCS_DIR, { recursive: true, mode: 0o700 });
}

const upload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_BYTES, files: 1 },
  fileFilter(req, file, cb) {
    if (!ACCEPTED[file.mimetype]) return cb(new Error('unsupported-type'));
    cb(null, true);
  },
}).single('document');

const fingerprint = (buffer) => crypto.createHash('sha256').update(buffer).digest('hex');

// ---------- Fusion des deux lectures ----------

/**
 * Les règles et le modèle ne se valent pas champ par champ :
 *
 *   — un SIRET ou un IBAN trouvés par les règles ont passé leur **clé de
 *     contrôle**. Ils sont vrais, et rien ne les remplace ;
 *   — les montants se jugent en bloc, sur leur cohérence : le triplet où
 *     HT + TVA = TTC l'emporte, quel qu'en soit l'auteur ;
 *   — pour le reste — nom du fournisseur, numéro, dates — le modèle comble ce
 *     que les règles n'ont pas trouvé, sans écraser ce qu'elles ont trouvé.
 *
 * Chaque champ garde la trace de son origine : l'écran l'affiche, et le
 * comptable sait ce qu'il valide.
 */
function merge(rules, aiFields) {
  if (!aiFields) return { fields: rules.fields, sources: {}, coherent: rules.coherent, confidence: rules.confidence };

  const fields = { ...rules.fields };
  const sources = {};
  const take = (name, value) => {
    if (value === null || value === undefined || value === '') return;
    fields[name] = value;
    sources[name] = 'ia';
  };

  // Identifiants : les règles gagnent quand elles ont trouvé, parce qu'elles
  // ont vérifié. Sinon on accepte celui du modèle, après la même vérification.
  if (!fields.siret && aiFields.siret) {
    if (aiFields.siret.length === 14 && scan.luhnValid(aiFields.siret)) take('siret', aiFields.siret);
    else if (aiFields.siret.length === 9 && scan.luhnValid(aiFields.siret)) take('siren', aiFields.siret);
  }
  if (!fields.iban && aiFields.iban && scan.ibanValid(aiFields.iban)) take('iban', aiFields.iban);
  if (!fields.vatNumber && aiFields.vatNumber) take('vatNumber', aiFields.vatNumber);

  for (const name of ['reference', 'issueDate', 'dueDate', 'currency', 'supplierName']) {
    if (!fields[name] && aiFields[name]) take(name, aiFields[name]);
  }
  if (aiFields.label) take('label', aiFields.label);

  // Montants : on garde le triplet cohérent.
  const coherent = (ht, vat, ttc) => ht !== null && vat !== null && ttc !== null
    && Math.abs(Math.round((ht + vat) * 100) / 100 - ttc) <= 0.02;

  const rulesCoherent = coherent(fields.amountHt, fields.amountVat, fields.amountTtc);
  const aiCoherent = coherent(aiFields.amountHt, aiFields.amountVat, aiFields.amountTtc);

  if (!rulesCoherent && aiCoherent) {
    take('amountHt', aiFields.amountHt);
    take('amountVat', aiFields.amountVat);
    take('amountTtc', aiFields.amountTtc);
    if (aiFields.vatRate !== null) take('vatRate', aiFields.vatRate);
  } else if (!rulesCoherent) {
    for (const name of ['amountHt', 'amountVat', 'amountTtc', 'vatRate']) {
      if (fields[name] === null || fields[name] === undefined) take(name, aiFields[name]);
    }
  }

  // Le rapprochement se rejoue sur les identifiants fusionnés : un SIRET
  // apporté par le modèle peut désigner un tiers déjà connu.
  if (!fields.partnerId) {
    const match = scan.matchPartner(`${fields.supplierName || ''} ${fields.siret || ''} ${fields.vatNumber || ''}`, {
      sirets: fields.siret ? [fields.siret] : [],
      sirens: fields.siren ? [fields.siren] : [],
      vats: fields.vatNumber ? [fields.vatNumber] : [],
    });
    if (match) {
      fields.partnerId = match.partner.id;
      fields.partnerReason = match.reason;
      sources.partnerId = 'ia';
    }
  }

  const merged = coherent(fields.amountHt, fields.amountVat, fields.amountTtc);
  const bonus = Object.keys(sources).length ? 10 : 0;
  return {
    fields,
    sources,
    coherent: merged,
    confidence: Math.max(0, Math.min(100, rules.confidence + bonus + (merged && !rulesCoherent ? 15 : 0))),
  };
}

// L'extracteur PDF ajoute un repère de pagination (« -- 1 of 3 -- ») qui
// n'appartient pas au document : compté comme du texte, il ferait passer un
// scan sans aucun contenu pour une page lisible.
const PAGE_MARKER = /^\s*--\s*\d+\s+of\s+\d+\s*--\s*$/gm;

const usefulText = (raw) => String(raw || '').replace(PAGE_MARKER, '').trim();

/** Lit un document et rend son analyse, sans rien écrire. */
async function examine(buffer, mimeType, { fileName = '', deps = {} } = {}) {
  let text = '';
  try {
    text = usefulText(await cv.extractText(buffer, mimeType));
  } catch {
    text = '';
  }

  const rules = scan.analyse(text, { fileName });
  let analysis = { ...rules, sources: {} };
  let aiResult = null;

  if (ai.isReady() && text.trim()) {
    aiResult = await ai.analyse(text, deps);
    if (aiResult.ok) {
      const merged = merge(rules, aiResult.fields);
      analysis = { ...rules, ...merged, source: 'règles + modèle', model: aiResult.model };
    } else {
      analysis = { ...analysis, aiError: aiResult.message };
    }
  }

  return { analysis, text };
}

// ---------- Réception ----------

/**
 * Enregistre une pièce reçue. Rend { ok: false, duplicate } si le même fichier
 * est déjà là : deux exemplaires d'une facture ne sont pas deux factures.
 */
async function receive({ buffer, originalName = '', mimeType, source = 'Dépôt', mail = {}, createdBy = null, deps = {} }) {
  if (!buffer || !buffer.length) return { ok: false, message: 'Fichier vide.' };
  if (!ACCEPTED[mimeType]) return { ok: false, message: 'Seuls les PDF, DOCX et fichiers texte sont acceptés.' };
  if (mimeType !== 'text/plain' && !fileType.matches(buffer, mimeType)) {
    return { ok: false, message: "Ce fichier n'est pas du type annoncé : dépôt refusé." };
  }

  const hash = fingerprint(buffer);
  const existing = db.prepare('SELECT id FROM incoming_documents WHERE sha256 = ?').get(hash);
  if (existing) return { ok: false, message: 'Cette pièce est déjà arrivée.', duplicate: existing.id };

  const { analysis, text } = await examine(buffer, mimeType, { fileName: originalName, deps });

  ensureDir();
  const name = crypto.randomBytes(16).toString('hex') + (ACCEPTED[mimeType] || '.bin');
  fs.writeFileSync(path.join(DOCS_DIR, name), buffer, { mode: 0o600 });

  const id = db.prepare(`
    INSERT INTO incoming_documents (source, file_name, original_name, mime_type, byte_size, sha256,
                                    mail_uid, mail_from, mail_subject, mail_date,
                                    text_length, analysis, confidence, partner_id, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(source, name, String(originalName).slice(0, 200), mimeType, buffer.length, hash,
    String(mail.uid || '').slice(0, 40), String(mail.from || '').slice(0, 200),
    String(mail.subject || '').slice(0, 300), mail.date || null,
    text.length, JSON.stringify(analysis), analysis.confidence,
    analysis.fields.partnerId || null, createdBy).lastInsertRowid;

  return { ok: true, id, analysis };
}

/** Rejoue l'analyse sur une pièce déjà reçue — après avoir activé le modèle, par exemple. */
async function reanalyse(id, { deps = {} } = {}) {
  const document = byId(id);
  if (!document) return { ok: false, message: 'Pièce introuvable.' };

  const target = pathOf(document);
  if (!fs.existsSync(target)) return { ok: false, message: 'Fichier absent du serveur.' };

  const { analysis, text } = await examine(fs.readFileSync(target), document.mime_type, {
    fileName: document.original_name, deps,
  });

  db.prepare('UPDATE incoming_documents SET analysis = ?, confidence = ?, partner_id = ?, text_length = ? WHERE id = ?')
    .run(JSON.stringify(analysis), analysis.confidence, analysis.fields.partnerId || null, text.length, document.id);

  return { ok: true, analysis };
}

// ---------- Lectures ----------

function decorate(row) {
  if (!row) return null;
  let analysis = {};
  try {
    analysis = JSON.parse(row.analysis);
  } catch {
    analysis = {};
  }
  return {
    ...row,
    analysis,
    fields: analysis.fields || {},
    sources: analysis.sources || {},
    notes: analysis.notes || [],
  };
}

function byId(id) {
  return decorate(db.prepare(`
    SELECT d.*, p.name AS partner_name, i.reference AS invoice_reference
    FROM incoming_documents d
    LEFT JOIN partners p ON p.id = d.partner_id
    LEFT JOIN invoices i ON i.id = d.invoice_id
    WHERE d.id = ?
  `).get(Number(id) || 0));
}

function list({ status = null, limit = 200 } = {}) {
  const clause = status ? 'WHERE d.status = ?' : '';
  const params = status ? [status, limit] : [limit];
  return db.prepare(`
    SELECT d.*, p.name AS partner_name, i.reference AS invoice_reference
    FROM incoming_documents d
    LEFT JOIN partners p ON p.id = d.partner_id
    LEFT JOIN invoices i ON i.id = d.invoice_id
    ${clause}
    ORDER BY d.received_at DESC, d.id DESC LIMIT ?
  `).all(...params).map(decorate);
}

function pathOf(document) {
  return path.join(DOCS_DIR, path.basename(document.file_name));
}

/** L'empreinte est revérifiée avant de servir le fichier, comme au coffre-fort. */
function verify(document) {
  const target = pathOf(document);
  if (!fs.existsSync(target)) return { ok: false, reason: 'fichier absent' };

  const actual = fingerprint(fs.readFileSync(target));
  return actual === document.sha256 ? { ok: true } : { ok: false, reason: 'empreinte différente' };
}

function setStatus(id, status, { userId = null, note = '', invoiceId = null } = {}) {
  if (!STATUSES.includes(status)) return { ok: false, message: 'Statut inconnu.' };

  db.prepare(`
    UPDATE incoming_documents SET status = ?, note = ?, invoice_id = COALESCE(?, invoice_id),
           handled_by = ?, handled_at = datetime('now') WHERE id = ?
  `).run(status, String(note).slice(0, 500), invoiceId, userId, id);
  return { ok: true };
}

function attachPartner(id, partnerId) {
  db.prepare('UPDATE incoming_documents SET partner_id = ? WHERE id = ?').run(partnerId || null, id);
}

/** Une pièce déjà facturée ne se supprime pas : elle est la pièce justificative. */
function remove(id) {
  const document = byId(id);
  if (!document) return { ok: false, message: 'Pièce introuvable.' };
  if (document.status === 'Facturée') return { ok: false, message: 'Cette pièce justifie une facture : elle se conserve.' };

  const target = pathOf(document);
  if (fs.existsSync(target)) fs.unlinkSync(target);
  db.prepare('DELETE FROM incoming_documents WHERE id = ?').run(document.id);
  return { ok: true };
}

function summary() {
  const counts = db.prepare("SELECT status, COUNT(*) AS n FROM incoming_documents GROUP BY status").all();
  const byStatus = Object.fromEntries(counts.map((row) => [row.status, row.n]));
  return {
    waiting: byStatus['À traiter'] || 0,
    invoiced: byStatus.Facturée || 0,
    discarded: byStatus['Écartée'] || 0,
    unreadable: db.prepare("SELECT COUNT(*) AS n FROM incoming_documents WHERE status = 'À traiter' AND text_length = 0").get().n,
    lowConfidence: db.prepare("SELECT COUNT(*) AS n FROM incoming_documents WHERE status = 'À traiter' AND confidence < 40").get().n,
  };
}

module.exports = {
  DOCS_DIR, MAX_BYTES, ACCEPTED, STATUSES, upload, usefulText,
  fingerprint, merge, examine, receive, reanalyse,
  byId, list, pathOf, verify, setStatus, attachPartner, remove, summary,
};
