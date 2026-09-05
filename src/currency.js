const db = require('./db');
const settings = require('./settings');

/**
 * Multidevise.
 *
 * Une entreprise qui facture à l'étranger encaisse dans une monnaie et tient
 * ses comptes dans une autre. Deux règles suffisent à ne pas s'y perdre :
 *
 * 1. La devise de référence de l'instance est celle des totaux. Tout ce qui
 *    s'additionne y est ramené ; additionner des euros et des dollars ne veut
 *    rien dire.
 * 2. Le taux qui a servi à une facture est copié dans la facture. Le taux du
 *    jour sert à la convertir une fois, à son émission, et plus jamais après :
 *    sans cela, la mise à jour d'un taux réécrirait le chiffre d'affaires de
 *    l'an dernier.
 */

const CURRENCIES = [
  { code: 'EUR', label: 'Euro', symbol: '€' },
  { code: 'USD', label: 'Dollar américain', symbol: '$' },
  { code: 'GBP', label: 'Livre sterling', symbol: '£' },
  { code: 'CHF', label: 'Franc suisse', symbol: 'CHF' },
  { code: 'CAD', label: 'Dollar canadien', symbol: '$ CA' },
  { code: 'MAD', label: 'Dirham marocain', symbol: 'DH' },
  { code: 'XOF', label: 'Franc CFA (UEMOA)', symbol: 'F CFA' },
  { code: 'JPY', label: 'Yen', symbol: '¥' },
  { code: 'CNY', label: 'Yuan', symbol: '¥ CN' },
  { code: 'AED', label: 'Dirham des Émirats', symbol: 'AED' },
];

const CODES = CURRENCIES.map((c) => c.code);
const BASE_KEY = 'currency.base';

function isKnown(code) {
  return CODES.includes(String(code || '').toUpperCase());
}

function base() {
  const stored = settings.get(BASE_KEY);
  return isKnown(stored) ? stored : 'EUR';
}

function setBase(code) {
  if (!isKnown(code)) return false;
  settings.set(BASE_KEY, String(code).toUpperCase());
  return true;
}

function byCode(code) {
  return CURRENCIES.find((c) => c.code === String(code || '').toUpperCase()) || null;
}

// ---------- Taux ----------

function rates() {
  const stored = new Map(db.prepare('SELECT * FROM exchange_rates').all().map((r) => [r.code, r]));
  return CURRENCIES.map((currency) => {
    const row = stored.get(currency.code);
    return {
      ...currency,
      isBase: currency.code === base(),
      // La devise de référence vaut toujours 1 : le taux n'est pas une donnée
      // qu'on saisit, c'est une définition.
      rate: currency.code === base() ? 1 : (row ? row.rate : null),
      updatedAt: row ? row.updated_at : null,
    };
  });
}

/** Le taux d'une devise, ou null si personne ne l'a renseigné. */
function rateOf(code) {
  const wanted = String(code || '').toUpperCase();
  if (!isKnown(wanted)) return null;
  if (wanted === base()) return 1;

  const row = db.prepare('SELECT rate FROM exchange_rates WHERE code = ?').get(wanted);
  return row ? row.rate : null;
}

function setRate(code, rate, userId = null) {
  const wanted = String(code || '').toUpperCase();
  if (!isKnown(wanted)) return { ok: false, message: 'Devise inconnue.' };
  if (wanted === base()) return { ok: false, message: 'La devise de référence vaut 1 par définition.' };

  const value = Number(rate);
  if (!Number.isFinite(value) || value <= 0) return { ok: false, message: 'Un taux se saisit strictement positif.' };

  db.prepare(`
    INSERT INTO exchange_rates (code, rate, updated_by) VALUES (?, ?, ?)
    ON CONFLICT(code) DO UPDATE SET rate = excluded.rate, updated_at = datetime('now'), updated_by = excluded.updated_by
  `).run(wanted, value, userId);
  return { ok: true };
}

function forgetRate(code) {
  db.prepare('DELETE FROM exchange_rates WHERE code = ?').run(String(code || '').toUpperCase());
}

// ---------- Conversion ----------

/** Ramène un montant dans la devise de référence, au taux fourni ou au taux courant. */
function toBase(amount, code, rate = null) {
  const applied = rate === null || rate === undefined ? rateOf(code) : Number(rate);
  if (!Number.isFinite(applied) || applied <= 0) return null;
  return Math.round(Number(amount) * applied * 100) / 100;
}

/**
 * Le taux à figer sur une pièce émise aujourd'hui. Rendre null plutôt que 1
 * quand le taux manque : facturer en dollars sans taux connu doit être refusé,
 * pas converti au petit bonheur.
 */
function rateForNewDocument(code) {
  return rateOf(code);
}

function format(amount, code, locale = 'fr') {
  const currency = byCode(code);
  const value = Number(amount) || 0;
  try {
    return new Intl.NumberFormat(locale, { style: 'currency', currency: currency ? currency.code : 'EUR' }).format(value);
  } catch {
    // Une locale exotique ne doit pas casser une page de factures.
    return `${value.toFixed(2)} ${currency ? currency.symbol : ''}`.trim();
  }
}

/** Les devises utilisables : la référence, et celles dont le taux est connu. */
function usable() {
  return rates().filter((c) => c.rate !== null);
}

module.exports = {
  CURRENCIES, CODES, BASE_KEY,
  isKnown, base, setBase, byCode,
  rates, rateOf, setRate, forgetRate,
  toBase, rateForNewDocument, format, usable,
};
