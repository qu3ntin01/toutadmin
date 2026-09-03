const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const multer = require('multer');

// Les photos de profil vivent hors du dépôt, à côté de la base.
const UPLOAD_DIR = process.env.UPLOAD_DIR || path.join(__dirname, '..', 'data', 'uploads');
const MAX_BYTES = 2 * 1024 * 1024;
const ALLOWED = new Map([
  ['image/jpeg', '.jpg'],
  ['image/png', '.png'],
  ['image/webp', '.webp'],
]);

fs.mkdirSync(UPLOAD_DIR, { recursive: true });

const storage = multer.diskStorage({
  destination: (req, file, cb) => cb(null, UPLOAD_DIR),
  // Nom aléatoire : le nom d'origine, fourni par le client, n'est jamais réutilisé.
  filename: (req, file, cb) => {
    const extension = ALLOWED.get(file.mimetype) || '';
    cb(null, `${crypto.randomBytes(16).toString('hex')}${extension}`);
  },
});

const avatarUpload = multer({
  storage,
  limits: { fileSize: MAX_BYTES, files: 1 },
  fileFilter: (req, file, cb) => cb(null, ALLOWED.has(file.mimetype)),
});

function removeAvatar(filename) {
  if (!filename) return;
  // Le nom est généré par nos soins ; on refuse malgré tout tout ce qui sort du dossier.
  if (!/^[a-f0-9]{32}\.(jpg|png|webp)$/.test(filename)) return;
  fs.rm(path.join(UPLOAD_DIR, filename), { force: true }, () => {});
}

module.exports = { UPLOAD_DIR, MAX_BYTES, avatarUpload, removeAvatar };
