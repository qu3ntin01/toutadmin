const db = require('./db');

const ACCOUNT_KINDS = ['Actif', 'Passif', 'Charge', 'Produit'];

// Un plan et des journaux minimaux, posés à l'activation du module pour que
// l'écran ne s'ouvre pas sur une page vide. Tout reste modifiable ensuite.
const DEFAULT_ACCOUNTS = [
  ['401', 'Fournisseurs', 'Passif'],
  ['411', 'Clients', 'Actif'],
  ['4456', 'TVA déductible', 'Actif'],
  ['4457', 'TVA collectée', 'Passif'],
  ['512', 'Banque', 'Actif'],
  ['606', 'Achats non stockés', 'Charge'],
  ['613', 'Locations', 'Charge'],
  ['641', 'Rémunérations du personnel', 'Charge'],
  ['645', 'Charges de sécurité sociale', 'Charge'],
  ['706', 'Prestations de services', 'Produit'],
];

const DEFAULT_JOURNALS = [
  ['VE', 'Ventes'],
  ['AC', 'Achats'],
  ['BQ', 'Banque'],
  ['OD', 'Opérations diverses'],
];

const round = (n) => Math.round(n * 100) / 100;

/** Idempotent : réactiver le module ne duplique pas le plan. */
function seedDefaults() {
  const account = db.prepare('INSERT OR IGNORE INTO accounts (code, label, kind) VALUES (?, ?, ?)');
  const journal = db.prepare('INSERT OR IGNORE INTO journals (code, label) VALUES (?, ?)');
  const seed = db.transaction(() => {
    for (const row of DEFAULT_ACCOUNTS) account.run(...row);
    for (const row of DEFAULT_JOURNALS) journal.run(...row);
  });
  seed();
}

// ---------- Plan comptable ----------

function accounts({ activeOnly = false } = {}) {
  const where = activeOnly ? 'WHERE active = 1' : '';
  return db.prepare(`SELECT * FROM accounts ${where} ORDER BY code`).all();
}

function accountByCode(code) {
  return db.prepare('SELECT * FROM accounts WHERE code = ?').get(code) || null;
}

function createAccount({ code, label, kind }) {
  if (!ACCOUNT_KINDS.includes(kind)) return { ok: false, reason: 'bad-kind' };
  if (accountByCode(code)) return { ok: false, reason: 'duplicate' };
  db.prepare('INSERT INTO accounts (code, label, kind) VALUES (?, ?, ?)').run(code, label, kind);
  return { ok: true };
}

/** Un compte mouvementé n'est pas supprimable : il est désactivé. */
function deleteAccount(id) {
  const used = db.prepare('SELECT 1 AS ok FROM entry_lines WHERE account_id = ? LIMIT 1').get(id);
  if (used) {
    db.prepare('UPDATE accounts SET active = 0 WHERE id = ?').run(id);
    return { ok: true, deactivated: true };
  }
  db.prepare('DELETE FROM accounts WHERE id = ?').run(id);
  return { ok: true, deactivated: false };
}

function journals() {
  return db.prepare('SELECT * FROM journals ORDER BY code').all();
}

function createJournal({ code, label }) {
  if (db.prepare('SELECT 1 AS ok FROM journals WHERE code = ?').get(code)) return { ok: false, reason: 'duplicate' };
  db.prepare('INSERT INTO journals (code, label) VALUES (?, ?)').run(code, label);
  return { ok: true };
}

// ---------- Écritures ----------

function entries({ limit = 200 } = {}) {
  return db.prepare(`
    SELECT e.*, j.code AS journal_code, j.label AS journal_label,
      (SELECT COALESCE(SUM(l.debit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_debit,
      (SELECT COALESCE(SUM(l.credit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_credit
    FROM entries e JOIN journals j ON j.id = e.journal_id
    ORDER BY e.entry_date DESC, e.id DESC
    LIMIT ?
  `).all(limit);
}

function linesOf(entryId) {
  return db.prepare(`
    SELECT l.*, a.code, a.label AS account_label
    FROM entry_lines l JOIN accounts a ON a.id = l.account_id
    WHERE l.entry_id = ? ORDER BY l.id
  `).all(entryId);
}

/**
 * Une écriture n'est enregistrée que si elle est équilibrée : c'est la règle
 * qui fait la différence entre une comptabilité et une liste de montants.
 */
function createEntry({ journalId, entryDate, reference, label, invoiceId, lines, createdBy }) {
  if (!db.prepare('SELECT 1 AS ok FROM journals WHERE id = ?').get(journalId)) return { ok: false, reason: 'no-journal' };

  const clean = lines
    .map((l) => ({
      accountId: Number(l.accountId),
      label: (l.label || '').slice(0, 160),
      debit: round(Number(l.debit) || 0),
      credit: round(Number(l.credit) || 0),
    }))
    .filter((l) => l.accountId && (l.debit > 0 || l.credit > 0));

  if (clean.length < 2) return { ok: false, reason: 'too-few-lines' };
  if (clean.some((l) => l.debit > 0 && l.credit > 0)) return { ok: false, reason: 'both-sides' };
  if (clean.some((l) => l.debit < 0 || l.credit < 0)) return { ok: false, reason: 'negative' };

  const known = new Set(accounts().map((a) => a.id));
  if (clean.some((l) => !known.has(l.accountId))) return { ok: false, reason: 'no-account' };

  const totalDebit = round(clean.reduce((sum, l) => sum + l.debit, 0));
  const totalCredit = round(clean.reduce((sum, l) => sum + l.credit, 0));
  if (totalDebit !== totalCredit) return { ok: false, reason: 'unbalanced', totalDebit, totalCredit };
  if (totalDebit === 0) return { ok: false, reason: 'empty' };

  const commit = db.transaction(() => {
    const entryId = db.prepare(`
      INSERT INTO entries (journal_id, entry_date, reference, label, invoice_id, created_by)
      VALUES (?, ?, ?, ?, ?, ?)
    `).run(journalId, entryDate, reference || '', label, invoiceId || null, createdBy).lastInsertRowid;

    const line = db.prepare('INSERT INTO entry_lines (entry_id, account_id, label, debit, credit) VALUES (?, ?, ?, ?, ?)');
    for (const l of clean) line.run(entryId, l.accountId, l.label, l.debit, l.credit);
    return entryId;
  });

  return { ok: true, id: commit() };
}

function deleteEntry(id) {
  db.prepare('DELETE FROM entries WHERE id = ?').run(id);
}

/**
 * Passe une facture en écriture : la vente débite le client et crédite le
 * produit et la TVA collectée ; l'achat fait l'inverse.
 */
function entryFromInvoice(invoice, createdBy) {
  if (db.prepare('SELECT 1 AS ok FROM entries WHERE invoice_id = ?').get(invoice.id)) {
    return { ok: false, reason: 'already-posted' };
  }

  const isSale = invoice.direction === 'Client';
  const journal = db.prepare('SELECT id FROM journals WHERE code = ?').get(isSale ? 'VE' : 'AC');
  if (!journal) return { ok: false, reason: 'no-journal' };

  const ht = round(invoice.amount_ht);
  const vat = round(ht * (invoice.vat_rate / 100));
  const ttc = round(ht + vat);

  const codes = isSale
    ? { third: '411', income: '706', vat: '4457' }
    : { third: '401', income: '606', vat: '4456' };

  const third = accountByCode(codes.third);
  const result = accountByCode(codes.income);
  const vatAccount = accountByCode(codes.vat);
  if (!third || !result || !vatAccount) return { ok: false, reason: 'missing-accounts' };

  const lines = isSale
    ? [
        { accountId: third.id, label: invoice.label, debit: ttc, credit: 0 },
        { accountId: result.id, label: invoice.label, debit: 0, credit: ht },
        { accountId: vatAccount.id, label: 'TVA', debit: 0, credit: vat },
      ]
    : [
        { accountId: result.id, label: invoice.label, debit: ht, credit: 0 },
        { accountId: vatAccount.id, label: 'TVA', debit: vat, credit: 0 },
        { accountId: third.id, label: invoice.label, debit: 0, credit: ttc },
      ];

  return createEntry({
    journalId: journal.id,
    entryDate: invoice.issue_date,
    reference: invoice.reference,
    label: invoice.label,
    invoiceId: invoice.id,
    lines: lines.filter((l) => l.debit > 0 || l.credit > 0),
    createdBy,
  });
}

// ---------- Restitutions ----------

/** Balance générale : un solde par compte mouvementé, sur une période. */
function balance({ from, to } = {}) {
  const clauses = [];
  const params = [];
  if (from) { clauses.push('e.entry_date >= ?'); params.push(from); }
  if (to) { clauses.push('e.entry_date <= ?'); params.push(to); }

  return db.prepare(`
    SELECT a.id, a.code, a.label, a.kind,
      COALESCE(SUM(l.debit), 0) AS debit,
      COALESCE(SUM(l.credit), 0) AS credit
    FROM accounts a
    JOIN entry_lines l ON l.account_id = a.id
    JOIN entries e ON e.id = l.entry_id
    ${clauses.length ? 'WHERE ' + clauses.join(' AND ') : ''}
    GROUP BY a.id
    ORDER BY a.code
  `).all(...params).map((row) => ({ ...row, balance: round(row.debit - row.credit) }));
}

/** Grand livre d'un compte : le détail qui explique son solde. */
function ledger(accountId, { from, to } = {}) {
  const clauses = ['l.account_id = ?'];
  const params = [accountId];
  if (from) { clauses.push('e.entry_date >= ?'); params.push(from); }
  if (to) { clauses.push('e.entry_date <= ?'); params.push(to); }

  const rows = db.prepare(`
    SELECT l.*, e.entry_date, e.label AS entry_label, e.reference, j.code AS journal_code
    FROM entry_lines l
    JOIN entries e ON e.id = l.entry_id
    JOIN journals j ON j.id = e.journal_id
    WHERE ${clauses.join(' AND ')}
    ORDER BY e.entry_date, l.id
  `).all(...params);

  let running = 0;
  return rows.map((row) => {
    running = round(running + row.debit - row.credit);
    return { ...row, running };
  });
}

/** Le résultat de l'exercice : produits moins charges. */
function income({ from, to } = {}) {
  const rows = balance({ from, to });
  const sum = (kind) => round(rows.filter((r) => r.kind === kind).reduce((total, r) => total + (kind === 'Produit' ? r.credit - r.debit : r.debit - r.credit), 0));
  const revenue = sum('Produit');
  const expenses = sum('Charge');
  return { revenue, expenses, result: round(revenue - expenses) };
}

/** Export CSV de la balance, pour l'expert-comptable. */
function balanceCsv({ from, to } = {}) {
  const header = 'Compte;Libellé;Type;Débit;Crédit;Solde';
  const lines = balance({ from, to }).map((r) =>
    [r.code, r.label.replace(/;/g, ','), r.kind, r.debit.toFixed(2), r.credit.toFixed(2), r.balance.toFixed(2)].join(';')
  );
  return [header, ...lines].join('\n');
}

module.exports = {
  ACCOUNT_KINDS,
  seedDefaults,
  accounts,
  accountByCode,
  createAccount,
  deleteAccount,
  journals,
  createJournal,
  entries,
  linesOf,
  createEntry,
  deleteEntry,
  entryFromInvoice,
  balance,
  ledger,
  income,
  balanceCsv,
};
