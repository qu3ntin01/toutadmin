const db = require('./db');
const societes = require('./societes');

/** Le périmètre courant : le plan, les journaux et les écritures sont par société. */
const ici = () => societes.courant();

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
  const account = db.prepare('INSERT OR IGNORE INTO accounts (company_id, code, label, kind) VALUES (?, ?, ?, ?)');
  const journal = db.prepare('INSERT OR IGNORE INTO journals (company_id, code, label) VALUES (?, ?, ?)');
  const seed = db.transaction(() => {
    /* Par société : chaque entité tient son plan, et activer le module dans la
       seconde ne doit pas la laisser devant une page vide parce que la première
       a déjà posé le sien. */
    for (const row of DEFAULT_ACCOUNTS) account.run(ici(), ...row);
    for (const row of DEFAULT_JOURNALS) journal.run(ici(), ...row);
  });
  seed();
}

// ---------- Plan comptable ----------

function accounts({ activeOnly = false } = {}) {
  const where = activeOnly ? 'AND active = 1' : '';
  return db.prepare(`SELECT * FROM accounts WHERE company_id = ? ${where} ORDER BY code`).all(ici());
}

function accountByCode(code) {
  return db.prepare('SELECT * FROM accounts WHERE code = ? AND company_id = ?').get(code, ici()) || null;
}

function createAccount({ code, label, kind }) {
  if (!ACCOUNT_KINDS.includes(kind)) return { ok: false, reason: 'bad-kind' };
  if (accountByCode(code)) return { ok: false, reason: 'duplicate' };
  db.prepare('INSERT INTO accounts (company_id, code, label, kind) VALUES (?, ?, ?, ?)')
    .run(ici(), code, label, kind);
  return { ok: true };
}

/** Un compte mouvementé n'est pas supprimable : il est désactivé. */
function deleteAccount(id) {
  if (!db.prepare('SELECT 1 AS ok FROM accounts WHERE id = ? AND company_id = ?').get(id, ici())) return { ok: false };

  const used = db.prepare('SELECT 1 AS ok FROM entry_lines WHERE account_id = ? LIMIT 1').get(id);
  if (used) {
    db.prepare('UPDATE accounts SET active = 0 WHERE id = ? AND company_id = ?').run(id, ici());
    return { ok: true, deactivated: true };
  }
  db.prepare('DELETE FROM accounts WHERE id = ? AND company_id = ?').run(id, ici());
  return { ok: true, deactivated: false };
}

function journals() {
  return db.prepare('SELECT * FROM journals WHERE company_id = ? ORDER BY code').all(ici());
}

function createJournal({ code, label }) {
  if (db.prepare('SELECT 1 AS ok FROM journals WHERE code = ? AND company_id = ?').get(code, ici())) {
    return { ok: false, reason: 'duplicate' };
  }
  db.prepare('INSERT INTO journals (company_id, code, label) VALUES (?, ?, ?)').run(ici(), code, label);
  return { ok: true };
}

// ---------- Écritures ----------

function entries({ limit = 200 } = {}) {
  return db.prepare(`
    SELECT e.*, j.code AS journal_code, j.label AS journal_label,
      (SELECT r.id FROM entries r WHERE r.reverses_id = e.id LIMIT 1) AS reversed_by,
      (SELECT COALESCE(SUM(l.debit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_debit,
      (SELECT COALESCE(SUM(l.credit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_credit
    FROM entries e JOIN journals j ON j.id = e.journal_id
    WHERE e.company_id = ?
    ORDER BY e.entry_date DESC, e.id DESC
    LIMIT ?
  `).all(ici(), limit);
}

function linesOf(entryId) {
  return db.prepare(`
    SELECT l.*, a.code, a.label AS account_label
    FROM entry_lines l JOIN accounts a ON a.id = l.account_id
    JOIN entries e ON e.id = l.entry_id
    WHERE l.entry_id = ? AND e.company_id = ? ORDER BY l.id
  `).all(entryId, ici());
}

/**
 * Une écriture n'est enregistrée que si elle est équilibrée : c'est la règle
 * qui fait la différence entre une comptabilité et une liste de montants.
 */
function createEntry({ journalId, entryDate, reference, label, invoiceId, lines, createdBy, reversesId = null }) {
  if (!db.prepare('SELECT 1 AS ok FROM journals WHERE id = ? AND company_id = ?').get(journalId, ici())) {
    return { ok: false, reason: 'no-journal' };
  }
  // Une période validée est close : on n'y écrit plus, pas même pour corriger.
  // La correction se passe à une date ouverte, par une contrepassation.
  const verrou = lockedUntil();
  if (verrou && entryDate <= verrou) return { ok: false, reason: 'locked', lockedUntil: verrou };

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
      INSERT INTO entries (company_id, journal_id, entry_date, reference, label, invoice_id, created_by, reverses_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    `).run(ici(), journalId, entryDate, reference || '', label, invoiceId || null, createdBy, reversesId).lastInsertRowid;

    const line = db.prepare('INSERT INTO entry_lines (entry_id, account_id, label, debit, credit) VALUES (?, ?, ?, ?, ?)');
    for (const l of clean) line.run(entryId, l.accountId, l.label, l.debit, l.credit);
    return entryId;
  });

  return { ok: true, id: commit() };
}

/**
 * Supprimer une écriture du brouillard.
 *
 * Une écriture validée ne se supprime pas : c'est ce qui fait d'elle une pièce
 * comptable (article 420-5 du plan comptable général). Elle s'annule par une
 * contrepassation, qui laisse la trace des deux.
 */
function deleteEntry(id) {
  const entree = entryById(id);
  if (!entree) return { ok: false, reason: 'not-found' };
  if (entree.validated_at) return { ok: false, reason: 'validated' };
  // Une ligne lettrée tient un lettrage équilibré : la supprimer le casserait
  // sans un mot. On délettre d'abord, en le voyant.
  if (db.prepare("SELECT 1 AS ok FROM entry_lines WHERE entry_id = ? AND reconciliation <> ''").get(id)) {
    return { ok: false, reason: 'lettered' };
  }
  db.prepare('DELETE FROM entries WHERE id = ? AND company_id = ? AND validated_at IS NULL').run(id, ici());
  return { ok: true };
}

function entryById(id) {
  return db.prepare('SELECT * FROM entries WHERE id = ? AND company_id = ?').get(id, ici()) || null;
}

/** La date jusqu'à laquelle la comptabilité de la société courante est validée, ou null. */
function lockedUntil() {
  const row = db.prepare('SELECT locked_until FROM companies WHERE id = ?').get(ici());
  return row && row.locked_until ? String(row.locked_until) : null;
}

/**
 * Contrepasser une écriture validée : la même, débits et crédits inversés, à
 * une date ouverte. Elle entre au brouillard comme toute écriture, et se
 * valide à son tour.
 */
function reverseEntry(id, entryDate, createdBy) {
  const entree = entryById(id);
  if (!entree) return { ok: false, reason: 'not-found' };
  if (!entree.validated_at) return { ok: false, reason: 'draft' };
  if (db.prepare('SELECT 1 AS ok FROM entries WHERE reverses_id = ? AND company_id = ?').get(id, ici())) {
    return { ok: false, reason: 'already-reversed' };
  }
  const lignes = db.prepare('SELECT * FROM entry_lines WHERE entry_id = ? ORDER BY id').all(id);
  return createEntry({
    journalId: entree.journal_id,
    entryDate,
    reference: entree.reference,
    label: `Contrepassation n° ${entree.number} — ${entree.label}`.slice(0, 160),
    lines: lignes.map((l) => ({ accountId: l.account_id, label: l.label, debit: l.credit, credit: l.debit })),
    createdBy,
    reversesId: id,
  });
}

/**
 * Valider le brouillard jusqu'à une date, et verrouiller la période.
 *
 * Les écritures du brouillard datées au plus tard ce jour-là reçoivent leur
 * numéro, dans l'ordre chronologique puis de saisie, à la suite du dernier
 * numéro de la société : une numérotation continue, sans trou, est ce que la
 * loi demande (article A47 A-1 du livre des procédures fiscales) et ce que le
 * FEC restitue. Ensuite, plus rien ne s'écrit à ces dates-là.
 *
 * Une date à venir est refusée : valider demain, ce serait fermer aujourd'hui
 * une journée qui n'a pas encore eu lieu.
 */
function validateUntil(date, today = new Date().toISOString().slice(0, 10)) {
  if (date > today) return { ok: false, reason: 'future' };
  const verrou = lockedUntil();
  if (verrou && date < verrou) return { ok: false, reason: 'before-lock', lockedUntil: verrou };

  const run = db.transaction(() => {
    // Les à-nouveaux d'abord, à date égale : ils ouvrent l'exercice, et le FEC
    // doit les restituer en tête.
    const brouillard = db.prepare(`
      SELECT e.id FROM entries e JOIN journals j ON j.id = e.journal_id
      WHERE e.company_id = ? AND e.validated_at IS NULL AND e.entry_date <= ?
      ORDER BY e.entry_date, CASE WHEN j.code = 'AN' THEN 0 ELSE 1 END, e.id
    `).all(ici(), date);
    let suivant = Number(db.prepare('SELECT COALESCE(MAX(number), 0) AS n FROM entries WHERE company_id = ?').get(ici()).n) + 1;
    const maintenant = new Date().toISOString().slice(0, 19).replace('T', ' ');
    const poser = db.prepare('UPDATE entries SET number = ?, validated_at = ? WHERE id = ? AND company_id = ? AND validated_at IS NULL');
    for (const { id } of brouillard) poser.run(suivant++, maintenant, id, ici());
    db.prepare('UPDATE companies SET locked_until = ? WHERE id = ?').run(date, ici());
    return brouillard.length;
  });
  return { ok: true, validated: run(), lockedUntil: date };
}

/** Les écritures du brouillard, toutes dates. */
function draftCount() {
  return db.prepare('SELECT COUNT(*) AS n FROM entries WHERE company_id = ? AND validated_at IS NULL').get(ici()).n;
}

/**
 * Passe une facture en écriture : la vente débite le client et crédite le
 * produit et la TVA collectée ; l'achat fait l'inverse.
 */
function entryFromInvoice(invoice, createdBy) {
  if (db.prepare('SELECT 1 AS ok FROM entries WHERE invoice_id = ? AND company_id = ?').get(invoice.id, ici())) {
    return { ok: false, reason: 'already-posted' };
  }

  const isSale = invoice.direction === 'Client';
  const journal = db.prepare('SELECT id FROM journals WHERE code = ? AND company_id = ?')
    .get(isSale ? 'VE' : 'AC', ici());
  if (!journal) return { ok: false, reason: 'no-journal' };

  // Une écriture se passe dans la devise de tenue des comptes : le montant est
  // converti au taux figé sur la facture, pas au taux du jour.
  const ht = round(invoice.amount_ht * (Number(invoice.exchange_rate) || 1));
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

// ---------- À-nouveaux ----------

/*
 * L'exercice suit l'année civile. Le passer, c'est reporter au 1er janvier les
 * soldes des comptes de bilan, dans une écriture d'à-nouveaux (journal « AN ») :
 * sans elle, la banque et les tiers repartiraient de zéro chaque année.
 *
 * - les comptes de charges et de produits ne se reportent pas : leur solde fait
 *   le résultat, porté en 120 (bénéfice) ou 129 (perte) ;
 * - le résultat de l'exercice d'avant, resté en 12, et le report à nouveau (11)
 *   passent ensemble en 110 ou 119 : l'affectation du résultat se décide en
 *   assemblée, et se passe ensuite en opérations diverses ;
 * - l'exercice qui se termine doit être validé jusqu'au 31 décembre : un solde
 *   reporté qui pourrait encore changer ne vaudrait rien.
 *
 * L'écriture entre au brouillard comme une autre, se relit, et se valide en tête
 * des écritures du 1er janvier.
 */
const COMPTES_DE_CLOTURE = {
  110: ['Report à nouveau (solde créditeur)', 'Passif'],
  119: ['Report à nouveau (solde débiteur)', 'Passif'],
  120: ["Résultat de l'exercice (bénéfice)", 'Passif'],
  129: ["Résultat de l'exercice (perte)", 'Passif'],
};

function compteDeCloture(code) {
  if (!accountByCode(code)) createAccount({ code, label: COMPTES_DE_CLOTURE[code][0], kind: COMPTES_DE_CLOTURE[code][1] });
  return accountByCode(code).id;
}

/** L'écriture d'à-nouveaux d'une année, si elle existe. */
function aNouveaux(annee) {
  return db.prepare(`
    SELECT e.* FROM entries e JOIN journals j ON j.id = e.journal_id
    WHERE e.company_id = ? AND j.code = 'AN' AND e.entry_date = ?
    ORDER BY e.id LIMIT 1
  `).get(ici(), `${annee}-01-01`) || null;
}

function reporterANouveaux(annee, createdBy) {
  const an = Number(annee);
  if (!Number.isInteger(an) || an < 1900 || an > 9999) return { ok: false, reason: 'bad-year' };
  const debut = `${an}-01-01`;
  const fin = `${an - 1}-12-31`;
  const verrou = lockedUntil();
  if (!verrou || verrou < fin) return { ok: false, reason: 'not-closed', fin };
  if (verrou >= debut) return { ok: false, reason: 'locked', lockedUntil: verrou };
  if (aNouveaux(an)) return { ok: false, reason: 'already' };

  // La fenêtre : depuis les derniers à-nouveaux, qui portent tout ce qui précède,
  // ou depuis le début s'il n'y en a jamais eu.
  const precedent = db.prepare(`
    SELECT MAX(e.entry_date) AS d FROM entries e JOIN journals j ON j.id = e.journal_id
    WHERE e.company_id = ? AND j.code = 'AN' AND e.entry_date < ?
  `).get(ici(), debut).d;
  const fenetre = { from: precedent || undefined, to: fin };

  const soldes = balance(fenetre).filter((r) => r.kind === 'Actif' || r.kind === 'Passif');
  const report = round(soldes.filter((r) => /^1[12]/.test(r.code)).reduce((s, r) => s + r.balance, 0));
  const resultat = income(fenetre).result;

  const lignes = soldes
    .filter((r) => !/^1[12]/.test(r.code) && r.balance !== 0)
    .map((r) => ({ accountId: r.id, label: r.label, debit: r.balance > 0 ? r.balance : 0, credit: r.balance < 0 ? -r.balance : 0 }));
  if (report !== 0) {
    lignes.push({ accountId: compteDeCloture(report < 0 ? '110' : '119'), label: 'Report à nouveau',
      debit: report > 0 ? report : 0, credit: report < 0 ? -report : 0 });
  }
  if (resultat !== 0) {
    lignes.push({ accountId: compteDeCloture(resultat > 0 ? '120' : '129'), label: `Résultat ${an - 1}`,
      debit: resultat < 0 ? -resultat : 0, credit: resultat > 0 ? resultat : 0 });
  }
  if (lignes.length < 2) return { ok: false, reason: 'empty' };

  if (!db.prepare("SELECT 1 AS ok FROM journals WHERE code = 'AN' AND company_id = ?").get(ici())) {
    createJournal({ code: 'AN', label: 'À-nouveaux' });
  }
  const journal = db.prepare("SELECT id FROM journals WHERE code = 'AN' AND company_id = ?").get(ici()).id;
  const result = createEntry({
    journalId: journal, entryDate: debut, reference: `AN-${an}`,
    label: `À-nouveaux de l'exercice ${an}`, lines: lignes, createdBy,
  });
  return result.ok ? { ok: true, id: result.id, lignes: lignes.length, resultat } : result;
}

// ---------- Lettrage ----------

/*
 * Lettrer, c'est rapprocher sur un compte de tiers les lignes qui se soldent :
 * la facture d'un client et son règlement, deux acomptes et la facture qu'ils
 * couvrent. Les lignes rapprochées reçoivent le même code (A, B… Z, AA…), et
 * ce qui reste non lettré est ce qui reste dû — c'est tout l'intérêt.
 *
 * Le code et sa date sont ceux que le FEC restitue (EcritureLet, DateLet). Le
 * lettrage ne touche ni aux montants ni aux comptes : il se pose et se retire
 * même sur une période validée.
 */

/** Les comptes qui se lettrent : ceux de tiers, la classe 4. */
function lettrables() {
  return accounts({ activeOnly: true }).filter((a) => /^4/.test(a.code));
}

/** Le rang d'un code — A = 1, Z = 26, AA = 27 — et l'inverse. */
function rangDuCode(code) {
  return [...code].reduce((n, c) => n * 26 + (c.charCodeAt(0) - 64), 0);
}

function codeDuRang(rang) {
  let code = '';
  for (let n = rang; n > 0; n = Math.floor((n - 1) / 26)) code = String.fromCharCode(65 + ((n - 1) % 26)) + code;
  return code;
}

function compteLettrable(accountId) {
  return lettrables().find((a) => a.id === Number(accountId)) || null;
}

/** Les lignes d'un compte, lettrées ou non, avec le tiers de la facture quand il y en a un. */
function lignesDuCompte(accountId) {
  return db.prepare(`
    SELECT l.id, l.label, l.debit, l.credit, l.reconciliation, l.lettered_at,
           e.id AS entry_id, e.entry_date, e.number, e.reference, e.label AS entry_label,
           j.code AS journal_code, p.name AS partner_name
    FROM entry_lines l
    JOIN entries e ON e.id = l.entry_id
    JOIN journals j ON j.id = e.journal_id
    LEFT JOIN invoices i ON i.id = e.invoice_id
    LEFT JOIN partners p ON p.id = i.partner_id
    WHERE l.account_id = ? AND e.company_id = ?
    ORDER BY e.entry_date, l.id
  `).all(accountId, ici());
}

/** Ce que l'écran montre : les lignes à lettrer, et les lettrages faits. */
function lettrage(accountId) {
  if (!compteLettrable(accountId)) return null;
  const lignes = lignesDuCompte(accountId);
  const ouvertes = lignes.filter((l) => !l.reconciliation);
  const groupes = new Map();
  for (const l of lignes.filter((x) => x.reconciliation)) {
    const g = groupes.get(l.reconciliation) || { code: l.reconciliation, date: l.lettered_at, lignes: 0, debit: 0, credit: 0 };
    g.lignes += 1;
    g.debit = round(g.debit + l.debit);
    g.credit = round(g.credit + l.credit);
    groupes.set(l.reconciliation, g);
  }
  return {
    ouvertes,
    solde: round(ouvertes.reduce((s, l) => s + l.debit - l.credit, 0)),
    lettrages: [...groupes.values()].sort((a, b) => rangDuCode(b.code) - rangDuCode(a.code)),
  };
}

/**
 * Lettrer une sélection de lignes : toutes du compte, toutes libres, au moins
 * deux, et qui se soldent au centime. Un lettrage partiel n'existe pas ici —
 * il mentirait sur ce qui reste dû.
 */
function lettrer(accountId, lineIds, today = new Date().toISOString().slice(0, 10)) {
  const compte = compteLettrable(accountId);
  if (!compte) return { ok: false, reason: 'account' };
  const voulues = new Set([].concat(lineIds || []).map(Number).filter(Boolean));
  const lignes = lignesDuCompte(compte.id).filter((l) => voulues.has(l.id));
  if (lignes.length < 2 || lignes.length !== voulues.size) return { ok: false, reason: 'selection' };
  if (lignes.some((l) => l.reconciliation)) return { ok: false, reason: 'already' };
  const ecart = round(lignes.reduce((s, l) => s + l.debit - l.credit, 0));
  if (ecart !== 0) return { ok: false, reason: 'unbalanced', ecart };

  const poser = db.transaction(() => {
    const codes = db.prepare(`
      SELECT DISTINCT l.reconciliation AS code FROM entry_lines l JOIN entries e ON e.id = l.entry_id
      WHERE l.account_id = ? AND e.company_id = ? AND l.reconciliation <> ''
    `).all(compte.id, ici()).map((r) => r.code);
    const code = codeDuRang(codes.reduce((m, c) => Math.max(m, rangDuCode(c)), 0) + 1);
    const maj = db.prepare("UPDATE entry_lines SET reconciliation = ?, lettered_at = ? WHERE id = ? AND account_id = ? AND reconciliation = ''");
    for (const l of lignes) maj.run(code, today, l.id, compte.id);
    return code;
  });
  return { ok: true, code: poser(), lignes: lignes.length };
}

/** Retirer un lettrage : ses lignes redeviennent dues. */
function delettrer(accountId, code) {
  const compte = compteLettrable(accountId);
  if (!compte || !/^[A-Z]{1,3}$/.test(String(code || ''))) return { ok: false, reason: 'not-found' };
  const n = db.prepare(`
    UPDATE entry_lines SET reconciliation = '', lettered_at = NULL
    WHERE account_id = ? AND reconciliation = ?
      AND entry_id IN (SELECT id FROM entries WHERE company_id = ?)
  `).run(compte.id, code, ici()).changes;
  return n > 0 ? { ok: true, lignes: n } : { ok: false, reason: 'not-found' };
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
    WHERE a.company_id = ? ${clauses.length ? 'AND ' + clauses.join(' AND ') : ''}
    GROUP BY a.id
    ORDER BY a.code
  `).all(ici(), ...params).map((row) => ({ ...row, balance: round(row.debit - row.credit) }));
}

/** Grand livre d'un compte : le détail qui explique son solde. */
function ledger(accountId, { from, to } = {}) {
  // Le périmètre se pose ici, et non à l'appel : un numéro de compte venu de
  // l'adresse ne doit pas ouvrir le grand livre d'une autre société.
  const clauses = ['l.account_id = ?', 'e.company_id = ?'];
  const params = [accountId, ici()];
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
  entryById,
  lockedUntil,
  reverseEntry,
  validateUntil,
  draftCount,
  aNouveaux,
  reporterANouveaux,
  lettrables,
  lettrage,
  lettrer,
  delettrer,
  codeDuRang,
  rangDuCode,
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
