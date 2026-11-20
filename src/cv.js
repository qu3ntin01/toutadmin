const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');
const multer = require('multer');

const fileType = require('./file-type');

// Les CV sont des données personnelles : ils vivent hors du dépôt et ne sont
// jamais servis en statique, seulement par une route authentifiée.
const CV_DIR = process.env.CV_DIR || path.join(path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite')), 'cv');
const MAX_BYTES = 5 * 1024 * 1024;

const ACCEPTED = {
  'application/pdf': '.pdf',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': '.docx',
  'text/plain': '.txt',
  'text/markdown': '.md',
};

function ensureDir() {
  if (!fs.existsSync(CV_DIR)) fs.mkdirSync(CV_DIR, { recursive: true, mode: 0o700 });
}

// Stockage en mémoire : le jeton CSRF d'un envoi multipart n'est lisible
// qu'après décodage du corps. Rien n'est écrit tant qu'il n'est pas validé.
const cvUpload = multer({
  storage: multer.memoryStorage(),
  limits: { fileSize: MAX_BYTES, files: 1 },
  fileFilter(req, file, cb) {
    if (!ACCEPTED[file.mimetype]) return cb(new Error('unsupported-type'));
    cb(null, true);
  },
}).single('cv');

/**
 * Écrit le CV reçu et rend son nom de fichier, ou null si le contenu ne
 * correspond pas au type annoncé : le MIME d'un envoi vient du client.
 */
function save(file) {
  if (!fileType.matches(file.buffer, file.mimetype)) return null;
  ensureDir();
  // Nom aléatoire : le nom d'origine du fichier ne dicte jamais le chemin sur disque.
  const name = crypto.randomBytes(16).toString('hex') + (ACCEPTED[file.mimetype] || '.bin');
  fs.writeFileSync(path.join(CV_DIR, name), file.buffer, { mode: 0o600 });
  return name;
}

// ---------- Extraction du texte ----------

/** Un .docx est une archive ZIP : word/document.xml en porte le texte. */
function extractDocx(buffer) {
  const entries = [];
  // On parcourt les en-têtes locaux plutôt que le répertoire central : suffisant ici.
  for (let i = 0; i < buffer.length - 4; i++) {
    if (buffer.readUInt32LE(i) !== 0x04034b50) continue;

    const method = buffer.readUInt16LE(i + 8);
    const compressedSize = buffer.readUInt32LE(i + 18);
    const nameLength = buffer.readUInt16LE(i + 26);
    const extraLength = buffer.readUInt16LE(i + 28);
    const name = buffer.slice(i + 30, i + 30 + nameLength).toString('utf8');
    const start = i + 30 + nameLength + extraLength;

    if (name !== 'word/document.xml' || compressedSize === 0) continue;
    const data = buffer.slice(start, start + compressedSize);
    entries.push(method === 8 ? zlib.inflateRawSync(data) : data);
    break;
  }

  if (entries.length === 0) return '';
  const xml = entries[0].toString('utf8');

  return xml
    // Un saut de paragraphe ou de ligne devient un vrai retour à la ligne.
    .replace(/<\/w:p>/g, '\n')
    .replace(/<w:br[^>]*\/>/g, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}

async function extractPdf(buffer) {
  const { PDFParse } = require('pdf-parse');
  const parser = new PDFParse({ data: buffer });
  try {
    const result = await parser.getText();
    return result.text || '';
  } finally {
    await parser.destroy();
  }
}

/**
 * Rend le texte d'un CV. Un PDF scanné ne contient pas de texte : l'extraction
 * revient vide, et l'appelant doit le dire plutôt que de scorer sur du vide.
 */
async function extractText(buffer, mimetype) {
  let text = '';
  if (mimetype === 'application/pdf') text = await extractPdf(buffer);
  else if (mimetype.includes('wordprocessingml')) text = extractDocx(buffer);
  else text = buffer.toString('utf8');

  return text.replace(/\r\n/g, '\n').replace(/[ \t]+/g, ' ').replace(/\n{3,}/g, '\n\n').trim();
}

function remove(fileName) {
  if (!fileName) return;
  // Un nom de fichier venu de la base ne doit jamais sortir du dossier des CV.
  const safe = path.basename(fileName);
  const target = path.join(CV_DIR, safe);
  if (fs.existsSync(target)) fs.rmSync(target, { force: true });
}

function pathOf(fileName) {
  return path.join(CV_DIR, path.basename(fileName));
}

/** Le contenu correspond-il au type annoncé ? À contrôler avant toute écriture. */
function accepts(file) {
  return Boolean(file) && fileType.matches(file.buffer, file.mimetype);
}

module.exports = { CV_DIR, MAX_BYTES, ACCEPTED, cvUpload, save, accepts, extractText, extractDocx, remove, pathOf };
