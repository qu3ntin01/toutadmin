const fs = require('fs');
const path = require('path');

// Les 15 langues les plus parlées, plus le français, langue de référence du produit.
const LOCALES = [
  { code: 'fr', label: 'Français', flag: '🇫🇷', dir: 'ltr' },
  { code: 'en', label: 'English', flag: '🇬🇧', dir: 'ltr' },
  { code: 'es', label: 'Español', flag: '🇪🇸', dir: 'ltr' },
  { code: 'de', label: 'Deutsch', flag: '🇩🇪', dir: 'ltr' },
  { code: 'it', label: 'Italiano', flag: '🇮🇹', dir: 'ltr' },
  { code: 'pt', label: 'Português', flag: '🇵🇹', dir: 'ltr' },
  { code: 'nl', label: 'Nederlands', flag: '🇳🇱', dir: 'ltr' },
  { code: 'pl', label: 'Polski', flag: '🇵🇱', dir: 'ltr' },
  { code: 'ru', label: 'Русский', flag: '🇷🇺', dir: 'ltr' },
  { code: 'tr', label: 'Türkçe', flag: '🇹🇷', dir: 'ltr' },
  { code: 'ar', label: 'العربية', flag: '🇸🇦', dir: 'rtl' },
  { code: 'hi', label: 'हिन्दी', flag: '🇮🇳', dir: 'ltr' },
  { code: 'zh', label: '中文', flag: '🇨🇳', dir: 'ltr' },
  { code: 'ja', label: '日本語', flag: '🇯🇵', dir: 'ltr' },
  { code: 'ko', label: '한국어', flag: '🇰🇷', dir: 'ltr' },
  { code: 'vi', label: 'Tiếng Việt', flag: '🇻🇳', dir: 'ltr' },
];

const DEFAULT_LOCALE = 'fr';
const CODES = LOCALES.map((l) => l.code);

const dictionaries = {};
for (const { code } of LOCALES) {
  const file = path.join(__dirname, 'locales', `${code}.js`);
  dictionaries[code] = fs.existsSync(file) ? require(file) : {};
}

function isSupported(code) {
  return CODES.includes(code);
}

function localeInfo(code) {
  return LOCALES.find((l) => l.code === code) || LOCALES[0];
}

/**
 * Traduit une clé. Toute clé absente retombe sur le français : une traduction
 * incomplète dégrade l'affichage, elle ne casse jamais la page.
 */
function translate(locale, key, params) {
  const dict = dictionaries[locale] || {};
  let value = dict[key];
  if (value === undefined) value = dictionaries[DEFAULT_LOCALE][key];
  if (value === undefined) return key;

  if (params) {
    for (const [name, replacement] of Object.entries(params)) {
      value = value.split(`{${name}}`).join(String(replacement));
    }
  }
  return value;
}

// Ordre de résolution : choix du compte, puis cookie (visiteur non connecté), puis navigateur.
function resolveLocale(req) {
  if (req.session && req.session.user && isSupported(req.session.user.locale)) {
    return req.session.user.locale;
  }
  if (req.cookies && isSupported(req.cookies.locale)) return req.cookies.locale;

  const header = req.headers['accept-language'];
  if (header) {
    for (const part of header.split(',')) {
      const code = part.split(';')[0].trim().slice(0, 2).toLowerCase();
      if (isSupported(code)) return code;
    }
  }
  return DEFAULT_LOCALE;
}

function middleware(req, res, next) {
  const locale = resolveLocale(req);
  const info = localeInfo(locale);

  res.locals.locale = locale;
  res.locals.localeDir = info.dir;
  res.locals.locales = LOCALES;
  res.locals.t = (key, params) => translate(locale, key, params);
  next();
}

module.exports = { LOCALES, DEFAULT_LOCALE, isSupported, localeInfo, translate, middleware };
