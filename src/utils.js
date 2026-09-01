const crypto = require('crypto');

function setFlash(req, type, message) {
  req.session.flash = { type, message };
}

function generatePassword() {
  return crypto.randomBytes(9).toString('base64').replace(/[^a-zA-Z0-9]/g, '').slice(0, 12);
}

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) && email.length <= 254;
}

function isValidDateString(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const d = new Date(`${value}T00:00:00Z`);
  return !Number.isNaN(d.getTime());
}

function isValidUrl(value) {
  try {
    const u = new URL(value);
    return u.protocol === 'http:' || u.protocol === 'https:';
  } catch {
    return false;
  }
}

// Le TJM arrive sous forme de texte de formulaire : on accepte la virgule décimale.
function parseDailyRate(value) {
  const trimmed = (value || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const n = Number(trimmed.replace(',', '.'));
  if (!Number.isFinite(n) || n < 0 || n > 100000) return { ok: false };
  return { ok: true, value: Math.round(n * 100) / 100 };
}

function parseAmount(value) {
  return Number((value || '').replace(',', '.'));
}

module.exports = {
  setFlash,
  generatePassword,
  isValidEmail,
  isValidDateString,
  isValidUrl,
  parseDailyRate,
  parseAmount,
};
