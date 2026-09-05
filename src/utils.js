const crypto = require('crypto');

function setFlash(req, type, message) {
  req.session.flash = { type, message };
}

const PASSWORD_ALPHABET = {
  lower: 'abcdefghijkmnopqrstuvwxyz',
  upper: 'ABCDEFGHJKLMNPQRSTUVWXYZ',
  digit: '23456789',
};

function pick(alphabet) {
  return alphabet[crypto.randomInt(alphabet.length)];
}

/**
 * Mot de passe temporaire : 16 caractères, au moins une minuscule, une majuscule
 * et un chiffre, pour satisfaire la politique dès sa création. Les caractères
 * qui se confondent à l'oral ou à l'écrit (l, I, 1, O, 0) en sont exclus : il est
 * dicté ou recopié une fois, puis remplacé à la première connexion.
 */
function generatePassword() {
  const all = PASSWORD_ALPHABET.lower + PASSWORD_ALPHABET.upper + PASSWORD_ALPHABET.digit;
  const chars = [pick(PASSWORD_ALPHABET.lower), pick(PASSWORD_ALPHABET.upper), pick(PASSWORD_ALPHABET.digit)];
  while (chars.length < 16) chars.push(pick(all));

  // Mélange de Fisher-Yates : sans lui, les trois premières positions trahiraient
  // la catégorie de chaque caractère.
  for (let i = chars.length - 1; i > 0; i--) {
    const j = crypto.randomInt(i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
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

/**
 * Une cible de redirection venue d'un formulaire. « / » ne suffit pas : « //site »
 * et « /\site » sont des URL absolues pour le navigateur et sortiraient du site.
 */
function safeRedirect(value, fallback = '/') {
  if (typeof value !== 'string') return fallback;
  const target = value.trim();
  if (!target.startsWith('/')) return fallback;
  if (target.startsWith('//') || target.startsWith('/\\')) return fallback;
  if (/[\x00-\x1f\x7f]/.test(target)) return fallback;
  return target;
}

const MIN_PASSWORD_LENGTH = 12;

// Les grands classiques, en clair dans toutes les listes de mots de passe.
const BANNED_PASSWORDS = new Set([
  'motdepasse', 'password', 'azertyuiop', 'qwertyuiop', '123456789012',
  'administrateur', 'changemoi123', 'entreprise123', 'bienvenue123',
]);

/**
 * Politique de mot de passe. Elle refuse ce qui se devine : trop court, trop
 * uniforme, trop proche du nom ou de l'adresse de la personne, ou déjà connu.
 * Rendue à part pour être appliquée partout de la même façon — profil, première
 * connexion, réinitialisation.
 */
function checkPassword(password, { email = '', firstName = '', lastName = '' } = {}) {
  const value = String(password || '');
  if (value.length < MIN_PASSWORD_LENGTH) {
    return { ok: false, message: `Le mot de passe doit faire au moins ${MIN_PASSWORD_LENGTH} caractères.` };
  }
  if (value.length > 200) return { ok: false, message: 'Mot de passe trop long (200 caractères maximum).' };

  const classes = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^a-zA-Z0-9]/].filter((re) => re.test(value)).length;
  if (classes < 3) {
    return { ok: false, message: 'Le mot de passe doit mêler au moins trois catégories : minuscules, majuscules, chiffres, symboles.' };
  }

  const lowered = value.toLowerCase();
  if (BANNED_PASSWORDS.has(lowered.replace(/[^a-z0-9]/g, ''))) {
    return { ok: false, message: 'Ce mot de passe est trop courant.' };
  }

  // Un seul caractère répété, ou une suite évidente, ne protège rien.
  if (/^(.)\1+$/.test(value)) return { ok: false, message: 'Un caractère répété ne fait pas un mot de passe.' };
  if (/0123456789|abcdefghij|azertyuiop|qwertyuiop/.test(lowered)) {
    return { ok: false, message: 'Le mot de passe suit une suite trop évidente.' };
  }

  const personal = [String(email).split('@')[0], firstName, lastName]
    .map((part) => String(part).toLowerCase().trim())
    .filter((part) => part.length >= 3);
  if (personal.some((part) => lowered.includes(part))) {
    return { ok: false, message: 'Le mot de passe ne doit pas contenir votre nom ni votre identifiant.' };
  }

  return { ok: true };
}

/**
 * Ajoute des mois à une date ISO, en butant sur la fin du mois.
 *
 * setUTCMonth déborde : le 29 février + 60 mois donne le 1er mars, parce que le
 * 29 février n'existe pas cette année-là. Une habilitation obtenue le 29 février
 * expire le 28, pas le lendemain.
 */
function addMonths(isoDate, months) {
  if (!isoDate) return null;
  const [year, month, day] = isoDate.split('-').map(Number);
  const target = new Date(Date.UTC(year, month - 1 + Number(months), 1));
  const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
  target.setUTCDate(Math.min(day, lastDay));
  return target.toISOString().slice(0, 10);
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
  safeRedirect,
  checkPassword,
  addMonths,
  MIN_PASSWORD_LENGTH,
};
