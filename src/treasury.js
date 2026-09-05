const db = require('./db');

/**
 * Trésorerie et immobilisations.
 *
 * La comptabilité dit ce qui a été engagé ; la trésorerie dit ce qu'il reste en
 * banque et ce qui va en sortir. Les deux ne se confondent pas : une facture
 * comptabilisée n'est pas une facture encaissée.
 */

const CERTAINTIES = ['Certain', 'Probable', 'Éventuel'];
const DEPRECIATION_METHODS = ['Linéaire', 'Dégressif'];
// Coefficients du dégressif selon la durée, tels que l'usage les fixe.
const DEGRESSIVE_COEFFICIENTS = [
  { maxYears: 4, coefficient: 1.25 },
  { maxYears: 6, coefficient: 1.75 },
  { maxYears: Infinity, coefficient: 2.25 },
];

// ---------- Comptes bancaires ----------

function accounts({ includeClosed = false } = {}) {
  const where = includeClosed ? '' : 'WHERE active = 1';
  return db.prepare(`SELECT * FROM bank_accounts ${where} ORDER BY label COLLATE NOCASE`).all()
    .map((account) => ({ ...account, balance: balanceOf(account) }));
}

function accountById(id) {
  return db.prepare('SELECT * FROM bank_accounts WHERE id = ?').get(Number(id) || 0) || null;
}

function createAccount({ label, bank, ibanLast4, openingBalance }) {
  return db.prepare('INSERT INTO bank_accounts (label, bank, iban_last4, opening_balance) VALUES (?, ?, ?, ?)')
    .run(label, bank || '', ibanLast4 || '', openingBalance || 0).lastInsertRowid;
}

function closeAccount(id) {
  db.prepare('UPDATE bank_accounts SET active = 0 WHERE id = ?').run(id);
}

function deleteAccount(id) {
  db.prepare('DELETE FROM bank_accounts WHERE id = ?').run(id);
}

/** Le solde n'est jamais stocké : il se recalcule des mouvements, toujours juste. */
function balanceOf(account) {
  const moved = db.prepare('SELECT COALESCE(SUM(amount), 0) AS total FROM bank_transactions WHERE account_id = ?').get(account.id).total;
  return Math.round((account.opening_balance + moved) * 100) / 100;
}

function totalBalance() {
  return Math.round(accounts().reduce((sum, a) => sum + a.balance, 0) * 100) / 100;
}

// ---------- Mouvements ----------

function transactions({ accountId = null, limit = 200 } = {}) {
  const clause = accountId ? 'WHERE t.account_id = ?' : '';
  const params = accountId ? [accountId, limit] : [limit];
  return db.prepare(`
    SELECT t.*, a.label AS account_label, i.reference AS invoice_reference
    FROM bank_transactions t
    JOIN bank_accounts a ON a.id = t.account_id
    LEFT JOIN invoices i ON i.id = t.invoice_id
    ${clause}
    ORDER BY t.value_date DESC, t.id DESC LIMIT ?
  `).all(...params);
}

function addTransaction({ accountId, valueDate, label, amount, category, invoiceId, claimId }) {
  return db.prepare(`
    INSERT INTO bank_transactions (account_id, value_date, label, amount, category, invoice_id, claim_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(accountId, valueDate, label, amount, category || '', invoiceId || null, claimId || null).lastInsertRowid;
}

function deleteTransaction(id) {
  db.prepare('DELETE FROM bank_transactions WHERE id = ?').run(id);
}

/** Rapproche un mouvement d'une facture : c'est ce qui distingue émis d'encaissé. */
function reconcile(transactionId, invoiceId) {
  const transaction = db.prepare('SELECT * FROM bank_transactions WHERE id = ?').get(transactionId);
  const invoice = db.prepare('SELECT * FROM invoices WHERE id = ?').get(invoiceId);
  if (!transaction || !invoice) return { ok: false, message: 'Mouvement ou facture introuvable.' };

  // Un encaissement est positif pour une facture client, négatif pour une facture fournisseur.
  const expectedSign = invoice.direction === 'Client' ? 1 : -1;
  if (Math.sign(transaction.amount) !== expectedSign) {
    return { ok: false, message: "Le sens du mouvement ne correspond pas à celui de la facture." };
  }

  db.prepare('UPDATE bank_transactions SET invoice_id = ? WHERE id = ?').run(invoiceId, transactionId);
  db.prepare("UPDATE invoices SET status = 'Payée', paid_at = ? WHERE id = ?").run(transaction.value_date, invoiceId);
  return { ok: true };
}

function unreconciled() {
  return db.prepare(`
    SELECT t.*, a.label AS account_label FROM bank_transactions t
    JOIN bank_accounts a ON a.id = t.account_id
    WHERE t.invoice_id IS NULL AND t.claim_id IS NULL
    ORDER BY t.value_date DESC LIMIT 100
  `).all();
}

// ---------- Prévisionnel ----------

function forecasts() {
  return db.prepare('SELECT * FROM cash_forecasts ORDER BY expected_on').all();
}

function addForecast({ label, expectedOn, amount, certainty, note }) {
  return db.prepare('INSERT INTO cash_forecasts (label, expected_on, amount, certainty, note) VALUES (?, ?, ?, ?, ?)')
    .run(label, expectedOn, amount, certainty, note || '').lastInsertRowid;
}

function deleteForecast(id) {
  db.prepare('DELETE FROM cash_forecasts WHERE id = ?').run(id);
}

/**
 * Projection à N semaines : solde courant, puis chaque échéance prévue et
 * chaque facture encore due. Ce qui compte est le creux, pas le total.
 */
function projection({ weeks = 12 } = {}) {
  const start = totalBalance();
  const horizon = new Date();
  horizon.setUTCDate(horizon.getUTCDate() + weeks * 7);
  const limit = horizon.toISOString().slice(0, 10);

  // Le TTC est ce qui bouge en banque, pas le HT.
  const dues = db.prepare(`
    SELECT due_date AS on_date,
           COALESCE(NULLIF(reference, ''), label) AS label,
           ROUND(amount_ht * (1 + vat_rate / 100.0), 2) * (CASE WHEN direction = 'Client' THEN 1 ELSE -1 END) AS amount,
           'Facture' AS origin
    FROM invoices
    WHERE status != 'Payée' AND due_date IS NOT NULL AND due_date <= ?
  `).all(limit);

  const planned = db.prepare(`
    SELECT expected_on AS on_date, label, amount, certainty AS origin
    FROM cash_forecasts WHERE expected_on <= ?
  `).all(limit);

  const events = [...dues, ...planned].sort((a, b) => a.on_date.localeCompare(b.on_date));

  let running = start;
  const points = events.map((event) => {
    running = Math.round((running + event.amount) * 100) / 100;
    return { ...event, balance: running };
  });

  const lowest = points.reduce((min, p) => (p.balance < min.balance ? p : min), { balance: start, on_date: null });
  return { start, points, end: running, lowest };
}

// ---------- Immobilisations ----------

function coefficientFor(years) {
  return (DEGRESSIVE_COEFFICIENTS.find((c) => years <= c.maxYears) || { coefficient: 2.25 }).coefficient;
}

/**
 * Tableau d'amortissement d'une immobilisation. En linéaire, la dotation est
 * constante ; en dégressif, elle s'applique à la valeur résiduelle jusqu'à ce
 * que le linéaire sur les années restantes devienne plus favorable — c'est la
 * règle, et elle change l'année de bascule.
 */
function schedule(asset) {
  const years = Math.max(1, Math.round(Number(asset.duration_years) || 1));
  const base = Number(asset.amount) || 0;
  const startYear = Number(String(asset.acquired_on).slice(0, 4));
  const rows = [];

  let residual = base;
  for (let index = 0; index < years; index++) {
    const remaining = years - index;
    let charge;

    if (asset.method === 'Dégressif') {
      const degressive = residual * (1 / years) * coefficientFor(years);
      const linearOnRemaining = residual / remaining;
      charge = Math.max(degressive, linearOnRemaining);
    } else {
      charge = base / years;
    }

    charge = Math.min(charge, residual);
    charge = Math.round(charge * 100) / 100;
    residual = Math.round((residual - charge) * 100) / 100;
    rows.push({ year: startYear + index, charge, residual });
  }

  // L'arrondi peut laisser quelques centimes : ils tombent sur la dernière année.
  if (rows.length && residual !== 0) {
    rows[rows.length - 1].charge = Math.round((rows[rows.length - 1].charge + residual) * 100) / 100;
    rows[rows.length - 1].residual = 0;
  }
  return rows;
}

function assets() {
  return db.prepare('SELECT * FROM fixed_assets ORDER BY acquired_on DESC, id DESC').all()
    .map((asset) => {
      const rows = schedule(asset);
      const currentYear = new Date().getUTCFullYear();
      const past = rows.filter((r) => r.year < currentYear);
      const cumulated = Math.round(past.reduce((sum, r) => sum + r.charge, 0) * 100) / 100;
      const thisYear = rows.find((r) => r.year === currentYear);
      return {
        ...asset,
        schedule: rows,
        cumulated,
        currentCharge: thisYear ? thisYear.charge : 0,
        bookValue: Math.round((asset.amount - cumulated - (thisYear ? thisYear.charge : 0)) * 100) / 100,
      };
    });
}

function assetById(id) {
  return db.prepare('SELECT * FROM fixed_assets WHERE id = ?').get(Number(id) || 0) || null;
}

function createAsset({ label, category, acquiredOn, amount, durationYears, method, note }) {
  return db.prepare(`
    INSERT INTO fixed_assets (label, category, acquired_on, amount, duration_years, method, note)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(label, category, acquiredOn, amount, durationYears, method, note || '').lastInsertRowid;
}

function disposeAsset(id, on) {
  db.prepare('UPDATE fixed_assets SET disposed_on = ? WHERE id = ?').run(on, id);
}

function deleteAsset(id) {
  db.prepare('DELETE FROM fixed_assets WHERE id = ?').run(id);
}

function assetSummary() {
  const rows = assets().filter((a) => !a.disposed_on);
  return {
    count: rows.length,
    gross: Math.round(rows.reduce((sum, a) => sum + a.amount, 0) * 100) / 100,
    net: Math.round(rows.reduce((sum, a) => sum + a.bookValue, 0) * 100) / 100,
    charge: Math.round(rows.reduce((sum, a) => sum + a.currentCharge, 0) * 100) / 100,
  };
}

module.exports = {
  CERTAINTIES, DEPRECIATION_METHODS, coefficientFor,
  accounts, accountById, createAccount, closeAccount, deleteAccount, balanceOf, totalBalance,
  transactions, addTransaction, deleteTransaction, reconcile, unreconciled,
  forecasts, addForecast, deleteForecast, projection,
  schedule, assets, assetById, createAsset, disposeAsset, deleteAsset, assetSummary,
};
