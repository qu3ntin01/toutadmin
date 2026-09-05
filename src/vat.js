const db = require('./db');
const currency = require('./currency');

/**
 * Déclarations de TVA.
 *
 * La TVA collectée est celle des factures client, la déductible celle des
 * factures fournisseur ; la différence est due à l'État, ou lui reste due.
 * Le CMS calcule, ventile par taux et conserve la déclaration ; il ne
 * télétransmet pas — l'échange avec l'administration demande un agrément et un
 * format qui change chaque année.
 *
 * Deux partis pris qui comptent :
 * — On déclare **sur les débits** (la facture, pas l'encaissement), le régime
 *   le plus courant pour les ventes de biens et les prestations sur option.
 *   Un cabinet qui déclare sur les encaissements ne doit pas s'y fier.
 * — Une facture en devise est ramenée en devise de référence **au taux figé à
 *   son émission**, celui-là même qui a servi à la comptabiliser.
 */

const REGIMES = [
  { key: 'Mensuel', months: 1 },
  { key: 'Trimestriel', months: 3 },
];
const STATUSES = ['Brouillon', 'Déclarée', 'Payée'];

const round = (value) => Math.round(value * 100) / 100;

function lastDayOf(year, month) {
  return new Date(Date.UTC(year, month, 0)).toISOString().slice(0, 10);
}

/** Les périodes d'un exercice, dans le régime choisi. */
function periods(year, regime = 'Mensuel') {
  const step = (REGIMES.find((r) => r.key === regime) || REGIMES[0]).months;
  const rows = [];
  for (let month = 1; month <= 12; month += step) {
    const end = month + step - 1;
    rows.push({
      regime,
      label: step === 1
        ? new Date(Date.UTC(year, month - 1, 1)).toLocaleDateString('fr', { month: 'long', year: 'numeric', timeZone: 'UTC' })
        : `T${Math.ceil(month / 3)} ${year}`,
      start: `${year}-${String(month).padStart(2, '0')}-01`,
      end: lastDayOf(year, end),
    });
  }
  return rows.map((row) => ({ ...row, key: `${row.regime}:${row.start}:${row.end}` }));
}

/**
 * Retrouve une période à partir de la clé envoyée par le formulaire. Le serveur
 * reconstruit la liste et exige une correspondance exacte : une période ne se
 * saisit pas à la main, elle se choisit — trois jours de décalage feraient une
 * déclaration fausse que personne ne verrait passer.
 */
function periodByKey(key) {
  const parts = String(key || '').split(':');
  if (parts.length !== 3) return null;

  const year = Number(parts[1].slice(0, 4));
  if (!Number.isInteger(year) || year < 2000 || year > 2100) return null;

  return REGIMES.flatMap((regime) => periods(year, regime.key)).find((p) => p.key === key) || null;
}

/**
 * Le calcul de la période. Les factures annulées et les brouillons en sont
 * exclus : une facture qui n'a pas été émise n'a pas généré de TVA.
 */
function compute(from, to) {
  const rows = db.prepare(`
    SELECT direction, amount_ht, vat_rate, currency, exchange_rate FROM invoices
    WHERE status IN ('Émise','Payée') AND issue_date >= ? AND issue_date <= ?
  `).all(from, to);

  const byRate = new Map();
  let collected = 0;
  let deductible = 0;
  let baseCollected = 0;
  let baseDeductible = 0;

  for (const row of rows) {
    const ht = currency.toBase(row.amount_ht, row.currency, row.exchange_rate);
    if (ht === null) continue;
    const vat = round(ht * (row.vat_rate / 100));

    const entry = byRate.get(row.vat_rate) || { rate: row.vat_rate, baseCollected: 0, collected: 0, baseDeductible: 0, deductible: 0 };
    if (row.direction === 'Client') {
      collected += vat;
      baseCollected += ht;
      entry.collected += vat;
      entry.baseCollected += ht;
    } else {
      deductible += vat;
      baseDeductible += ht;
      entry.deductible += vat;
      entry.baseDeductible += ht;
    }
    byRate.set(row.vat_rate, entry);
  }

  const balance = round(collected - deductible);
  return {
    from,
    to,
    invoices: rows.length,
    currency: currency.base(),
    baseCollected: round(baseCollected),
    baseDeductible: round(baseDeductible),
    collected: round(collected),
    deductible: round(deductible),
    // Une TVA négative n'est pas une dette : c'est un crédit reportable.
    due: balance > 0 ? balance : 0,
    credit: balance < 0 ? round(-balance) : 0,
    byRate: [...byRate.values()]
      .map((e) => ({ ...e, collected: round(e.collected), deductible: round(e.deductible), baseCollected: round(e.baseCollected), baseDeductible: round(e.baseDeductible) }))
      .sort((a, b) => b.rate - a.rate),
  };
}

function list({ limit = 60 } = {}) {
  return db.prepare('SELECT * FROM vat_returns ORDER BY period_start DESC LIMIT ?').all(limit)
    .map((row) => ({ ...row, detail: row.breakdown ? JSON.parse(row.breakdown) : [] }));
}

function byId(id) {
  const row = db.prepare('SELECT * FROM vat_returns WHERE id = ?').get(Number(id) || 0);
  return row ? { ...row, detail: row.breakdown ? JSON.parse(row.breakdown) : [] } : null;
}

/**
 * Arrête la déclaration d'une période. Une déclaration déjà déposée n'est pas
 * réécrite en silence : c'est une pièce, pas un tableau de bord.
 */
function save({ regime, label, from, to, notes = '', createdBy = null }) {
  const existing = db.prepare('SELECT * FROM vat_returns WHERE period_start = ? AND period_end = ?').get(from, to);
  if (existing && existing.status !== 'Brouillon') {
    return { ok: false, message: `La déclaration ${existing.period_label} est déjà ${existing.status.toLowerCase()} : elle ne se recalcule plus.` };
  }

  const totals = compute(from, to);
  const values = [regime, label, from, to, totals.collected, totals.deductible, totals.due, totals.credit,
    JSON.stringify(totals.byRate), notes, createdBy];

  if (existing) {
    db.prepare(`
      UPDATE vat_returns SET regime = ?, period_label = ?, period_start = ?, period_end = ?, collected = ?,
             deductible = ?, due = ?, credit = ?, breakdown = ?, notes = ?, created_by = ? WHERE id = ?
    `).run(...values, existing.id);
    return { ok: true, id: existing.id, totals, updated: true };
  }

  const id = db.prepare(`
    INSERT INTO vat_returns (regime, period_label, period_start, period_end, collected, deductible, due, credit, breakdown, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(...values).lastInsertRowid;
  return { ok: true, id, totals };
}

function setStatus(id, status) {
  if (!STATUSES.includes(status)) return { ok: false, message: 'Statut inconnu.' };

  const stamp = new Date().toISOString().slice(0, 10);
  if (status === 'Déclarée') db.prepare('UPDATE vat_returns SET status = ?, filed_on = ? WHERE id = ?').run(status, stamp, id);
  else if (status === 'Payée') db.prepare('UPDATE vat_returns SET status = ?, paid_on = ?, filed_on = COALESCE(filed_on, ?) WHERE id = ?').run(status, stamp, stamp, id);
  else db.prepare('UPDATE vat_returns SET status = ?, filed_on = NULL, paid_on = NULL WHERE id = ?').run(status, id);
  return { ok: true };
}

function remove(id) {
  db.prepare('DELETE FROM vat_returns WHERE id = ?').run(id);
}

/** Ce qui reste à déposer ou à payer : c'est ce qui intéresse la direction. */
function summary() {
  const pending = db.prepare("SELECT COUNT(*) AS n, COALESCE(SUM(due), 0) AS total FROM vat_returns WHERE status != 'Payée'").get();
  return {
    pending: pending.n,
    pendingAmount: round(pending.total),
    lastFiled: db.prepare("SELECT period_label, filed_on FROM vat_returns WHERE status != 'Brouillon' ORDER BY period_end DESC LIMIT 1").get() || null,
    currency: currency.base(),
  };
}

module.exports = { REGIMES, STATUSES, periods, periodByKey, compute, list, byId, save, setStatus, remove, summary };
