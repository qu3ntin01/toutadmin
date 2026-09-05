const db = require('./db');
const currency = require('./currency');

const PARTNER_KINDS = ['Client', 'Fournisseur', 'Client et fournisseur'];
const CONTRACT_STATUSES = ['Brouillon', 'Actif', 'Résilié', 'Échu'];
const BILLING_PERIODS = ['Ponctuel', 'Mensuel', 'Trimestriel', 'Annuel'];
const INVOICE_DIRECTIONS = ['Client', 'Fournisseur'];
const INVOICE_STATUSES = ['Brouillon', 'Émise', 'Payée', 'Annulée'];
const EXPENSE_CATEGORIES = ['Transport', 'Hébergement', 'Repas', 'Fournitures', 'Formation', 'Autre'];
const EXPENSE_STATUSES = ['En attente', 'Approuvée', 'Refusée', 'Remboursée'];

const today = () => new Date().toISOString().slice(0, 10);

// ---------- Tiers ----------

function partners({ kind } = {}) {
  const rows = db.prepare(`
    SELECT p.*,
      (SELECT COUNT(*) FROM partner_contracts c WHERE c.partner_id = p.id) AS contract_count,
      (SELECT COUNT(*) FROM invoices i WHERE i.partner_id = p.id) AS invoice_count
    FROM partners p
    ORDER BY p.name COLLATE NOCASE
  `).all();
  // « Client et fournisseur » relève des deux listes : le filtre le reflète.
  return kind ? rows.filter((p) => p.kind === kind || p.kind === 'Client et fournisseur') : rows;
}

function partnerById(id) {
  return db.prepare('SELECT * FROM partners WHERE id = ?').get(id) || null;
}

function createPartner(data) {
  return db.prepare(`
    INSERT INTO partners (kind, name, registration, contact_name, email, phone, address, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(data.kind, data.name, data.registration || '', data.contactName || '', data.email || '',
         data.phone || '', data.address || '', data.notes || '').lastInsertRowid;
}

function updatePartner(id, data) {
  db.prepare(`
    UPDATE partners SET kind = ?, name = ?, registration = ?, contact_name = ?, email = ?, phone = ?, address = ?, notes = ?, active = ?
    WHERE id = ?
  `).run(data.kind, data.name, data.registration || '', data.contactName || '', data.email || '',
         data.phone || '', data.address || '', data.notes || '', data.active ? 1 : 0, id);
}

function deletePartner(id) {
  db.prepare('DELETE FROM partners WHERE id = ?').run(id);
}

// ---------- Contrats ----------

function contracts() {
  return db.prepare(`
    SELECT c.*, p.name AS partner_name, p.kind AS partner_kind,
           u.first_name AS owner_first_name, u.last_name AS owner_last_name
    FROM partner_contracts c
    JOIN partners p ON p.id = c.partner_id
    LEFT JOIN users u ON u.id = c.owner_id
    ORDER BY COALESCE(c.end_date, '9999-12-31'), c.title COLLATE NOCASE
  `).all();
}

function contractById(id) {
  return db.prepare('SELECT * FROM partner_contracts WHERE id = ?').get(id) || null;
}

function createContract(data) {
  return db.prepare(`
    INSERT INTO partner_contracts (partner_id, reference, title, start_date, end_date, notice_days, amount, billing_period, owner_id, status, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(data.partnerId, data.reference || '', data.title, data.startDate || null, data.endDate || null,
         data.noticeDays || 0, data.amount ?? null, data.billingPeriod || 'Annuel', data.ownerId || null,
         data.status || 'Actif', data.notes || '').lastInsertRowid;
}

function setContractStatus(id, status) {
  if (!CONTRACT_STATUSES.includes(status)) return false;
  return db.prepare('UPDATE partner_contracts SET status = ? WHERE id = ?').run(status, id).changes > 0;
}

function deleteContract(id) {
  db.prepare('DELETE FROM partner_contracts WHERE id = ?').run(id);
}

/**
 * Contrats dont le préavis court déjà : passé cette date, la reconduction
 * tacite est acquise. C'est l'alerte qui justifie de tenir des contrats ici.
 */
function contractsToRenew(withinDays = 90) {
  const horizon = new Date();
  horizon.setUTCDate(horizon.getUTCDate() + withinDays);
  const limit = horizon.toISOString().slice(0, 10);

  return contracts().filter((c) => {
    if (c.status !== 'Actif' || !c.end_date) return false;
    // La date à ne pas dépasser pour dénoncer le contrat.
    const deadline = new Date(`${c.end_date}T00:00:00Z`);
    deadline.setUTCDate(deadline.getUTCDate() - (c.notice_days || 0));
    const deadlineISO = deadline.toISOString().slice(0, 10);
    return deadlineISO <= limit;
  }).map((c) => {
    const deadline = new Date(`${c.end_date}T00:00:00Z`);
    deadline.setUTCDate(deadline.getUTCDate() - (c.notice_days || 0));
    const noticeDeadline = deadline.toISOString().slice(0, 10);
    return { ...c, noticeDeadline, noticeElapsed: noticeDeadline < today() };
  });
}

// ---------- Factures ----------

const amountTtc = (invoice) => Math.round(invoice.amount_ht * (1 + invoice.vat_rate / 100) * 100) / 100;

/**
 * Les montants d'une facture existent en deux exemplaires : dans la devise de
 * la pièce, qui est ce que le client paie, et dans la devise de référence, qui
 * est ce qui s'additionne. La conversion utilise le taux figé à l'émission —
 * jamais le taux du jour, sinon les totaux de l'an dernier bougeraient encore.
 */
function withAmounts(invoice) {
  const ttc = amountTtc(invoice);
  const rate = Number(invoice.exchange_rate) || 1;
  return {
    ...invoice,
    amount_ttc: ttc,
    amount_base_ht: Math.round(invoice.amount_ht * rate * 100) / 100,
    amount_base_ttc: Math.round(ttc * rate * 100) / 100,
    foreign: invoice.currency !== currency.base(),
  };
}

function invoices({ direction } = {}) {
  const where = direction ? 'WHERE i.direction = ?' : '';
  const rows = db.prepare(`
    SELECT i.*, p.name AS partner_name, d.name AS department_name
    FROM invoices i
    LEFT JOIN partners p ON p.id = i.partner_id
    LEFT JOIN departments d ON d.id = i.department_id
    ${where}
    ORDER BY i.issue_date DESC, i.id DESC
  `);
  const list = direction ? rows.all(direction) : rows.all();

  // Le retard se déduit de la date d'échéance : aucun statut à maintenir à la main.
  return list.map((i) => ({
    ...withAmounts(i),
    overdue: i.status === 'Émise' && Boolean(i.due_date) && i.due_date < today(),
  }));
}

function invoiceById(id) {
  const invoice = db.prepare('SELECT * FROM invoices WHERE id = ?').get(id);
  return invoice ? withAmounts(invoice) : null;
}

function createInvoice(data) {
  const code = data.currency || currency.base();
  // Le taux est figé ici, une fois pour toutes.
  const rate = data.exchangeRate ?? currency.rateOf(code);
  if (rate === null) return null;

  const id = db.prepare(`
    INSERT INTO invoices (direction, partner_id, department_id, reference, label, issue_date, due_date, amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(data.direction, data.partnerId || null, data.departmentId || null, data.reference || '', data.label,
         data.issueDate, data.dueDate || null, data.amountHt, data.vatRate, data.status || 'Émise',
         data.notes || '', data.createdBy, code, rate).lastInsertRowid;

  require('./webhooks').emit('facture.creee', {
    id, reference: data.reference || '', libelle: data.label, sens: data.direction,
    montant_ht: data.amountHt, devise: code, echeance: data.dueDate || null,
  });
  return id;
}

function setInvoiceStatus(id, status) {
  if (!INVOICE_STATUSES.includes(status)) return false;

  const changed = db.prepare('UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?')
    .run(status, status === 'Payée' ? new Date().toISOString() : null, id).changes > 0;

  if (changed && status === 'Payée') {
    const invoice = invoiceById(id);
    require('./webhooks').emit('facture.payee', {
      id, reference: invoice.reference, libelle: invoice.label, sens: invoice.direction,
      montant_ttc: invoice.amount_ttc, devise: invoice.currency,
    });
  }
  return changed;
}

function deleteInvoice(id) {
  db.prepare('DELETE FROM invoices WHERE id = ?').run(id);
}

/** Recettes, dépenses et encours, sur une année civile. */
function financialSummary(year) {
  const from = `${year}-01-01`;
  const to = `${year}-12-31`;
  const all = invoices().filter((i) => i.issue_date >= from && i.issue_date <= to && i.status !== 'Annulée');

  // On additionne les montants ramenés en devise de référence : additionner des
  // euros et des dollars ne voudrait rien dire.
  const sum = (rows) => Math.round(rows.reduce((total, i) => total + i.amount_base_ttc, 0) * 100) / 100;
  const income = all.filter((i) => i.direction === 'Client');
  const spending = all.filter((i) => i.direction === 'Fournisseur');

  return {
    year,
    income: sum(income),
    spending: sum(spending),
    balance: Math.round((sum(income) - sum(spending)) * 100) / 100,
    unpaidIncome: sum(income.filter((i) => i.status !== 'Payée')),
    unpaidSpending: sum(spending.filter((i) => i.status !== 'Payée')),
    overdue: all.filter((i) => i.overdue).length,
    currency: currency.base(),
  };
}

// ---------- Budgets ----------

function budgets(year) {
  return db.prepare(`
    SELECT b.*, d.name AS department_name,
      (SELECT COALESCE(SUM(i.amount_ht * (1 + i.vat_rate / 100.0)), 0)
       FROM invoices i
       WHERE i.department_id = b.department_id AND i.direction = 'Fournisseur'
         AND i.status != 'Annulée' AND i.issue_date BETWEEN ? AND ?) AS invoiced,
      (SELECT COALESCE(SUM(e.amount), 0)
       FROM expense_claims e
       JOIN users u ON u.id = e.employee_id
       WHERE u.department_id = b.department_id AND e.status IN ('Approuvée','Remboursée')
         AND e.spent_on BETWEEN ? AND ?) AS claimed
    FROM budgets b
    JOIN departments d ON d.id = b.department_id
    WHERE b.year = ?
    ORDER BY d.name COLLATE NOCASE
  `).all(`${year}-01-01`, `${year}-12-31`, `${year}-01-01`, `${year}-12-31`, year)
    .map((b) => {
      // Le consommé agrège les factures fournisseurs du service et les frais de ses membres.
      const consumed = Math.round((b.invoiced + b.claimed) * 100) / 100;
      return {
        ...b,
        consumed,
        remaining: Math.round((b.amount - consumed) * 100) / 100,
        ratio: b.amount > 0 ? Math.round((consumed / b.amount) * 1000) / 10 : 0,
      };
    });
}

function setBudget({ departmentId, year, amount, notes }) {
  db.prepare(`
    INSERT INTO budgets (department_id, year, amount, notes) VALUES (?, ?, ?, ?)
    ON CONFLICT(department_id, year) DO UPDATE SET amount = excluded.amount, notes = excluded.notes
  `).run(departmentId, year, amount, notes || '');
}

function deleteBudget(id) {
  db.prepare('DELETE FROM budgets WHERE id = ?').run(id);
}

// ---------- Notes de frais ----------

function claimsFor(employeeId, limit = 100) {
  return db.prepare('SELECT * FROM expense_claims WHERE employee_id = ? ORDER BY spent_on DESC LIMIT ?').all(employeeId, limit);
}

function allClaims({ status } = {}) {
  const where = status ? 'WHERE e.status = ?' : '';
  const query = db.prepare(`
    SELECT e.*, u.first_name, u.last_name, d.name AS department_name
    FROM expense_claims e
    JOIN users u ON u.id = e.employee_id
    LEFT JOIN departments d ON d.id = u.department_id
    ${where}
    ORDER BY e.created_at DESC
  `);
  return status ? query.all(status) : query.all();
}

function createClaim({ employeeId, spentOn, category, description, amount }) {
  return db.prepare(`
    INSERT INTO expense_claims (employee_id, spent_on, category, description, amount)
    VALUES (?, ?, ?, ?, ?)
  `).run(employeeId, spentOn, category, description || '', amount).lastInsertRowid;
}

function claimById(id) {
  return db.prepare('SELECT * FROM expense_claims WHERE id = ?').get(id) || null;
}

/** Un salarié ne retire que sa propre note, et seulement tant qu'elle est en attente. */
function cancelOwnClaim(id, employeeId) {
  return db.prepare("DELETE FROM expense_claims WHERE id = ? AND employee_id = ? AND status = 'En attente'")
    .run(id, employeeId).changes > 0;
}

function reviewClaim(id, status, reviewerId, note) {
  if (!EXPENSE_STATUSES.includes(status)) return { ok: false, reason: 'bad-status' };
  const claim = claimById(id);
  if (!claim) return { ok: false, reason: 'not-found' };

  // Un remboursement suppose une note approuvée : on ne rembourse pas ce qui n'est pas validé.
  if (status === 'Remboursée' && claim.status !== 'Approuvée') return { ok: false, reason: 'not-approved' };
  if (status !== 'Remboursée' && claim.status !== 'En attente') return { ok: false, reason: 'not-pending' };

  db.prepare('UPDATE expense_claims SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ?, reimbursed_at = ? WHERE id = ?')
    .run(status, reviewerId, note || claim.review_note, new Date().toISOString(),
         status === 'Remboursée' ? new Date().toISOString() : null, id);
  return { ok: true };
}

module.exports = {
  PARTNER_KINDS,
  CONTRACT_STATUSES,
  BILLING_PERIODS,
  INVOICE_DIRECTIONS,
  INVOICE_STATUSES,
  EXPENSE_CATEGORIES,
  EXPENSE_STATUSES,
  partners,
  partnerById,
  createPartner,
  updatePartner,
  deletePartner,
  contracts,
  contractById,
  createContract,
  setContractStatus,
  deleteContract,
  contractsToRenew,
  invoices,
  invoiceById,
  createInvoice,
  setInvoiceStatus,
  deleteInvoice,
  financialSummary,
  budgets,
  setBudget,
  deleteBudget,
  claimsFor,
  allClaims,
  createClaim,
  claimById,
  cancelOwnClaim,
  reviewClaim,
};
