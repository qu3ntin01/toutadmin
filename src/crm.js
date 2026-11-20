const db = require('./db');
const rattachees = require('./rattachees');
const societes = require('./societes');

/** Le périmètre courant : un tiers et une facture appartiennent à une société. */
const ici = () => societes.courant();

const STAGES = ['Qualification', 'Proposition', 'Négociation', 'Gagnée', 'Perdue'];
const OPEN_STAGES = ['Qualification', 'Proposition', 'Négociation'];
const QUOTE_STATUSES = ['Brouillon', 'Envoyé', 'Accepté', 'Refusé', 'Expiré'];
const ACTIVITY_KINDS = ['Relance', 'Appel', 'Rendez-vous', 'Email', 'Autre'];
// Pourquoi une affaire est perdue : une liste courte et fermée, pour que les
// raisons se comptent. Un texte libre ne se compterait pas.
const LOST_REASONS = ['prix', 'concurrent', 'budget', 'besoin', 'delai', 'silence', 'autre'];

const round = (n) => Math.round(n * 100) / 100;
const today = () => new Date().toISOString().slice(0, 10);

// ---------- Contacts ----------

function contacts(partnerId) {
  const where = partnerId ? 'WHERE c.partner_id = ?' : '';
  const query = db.prepare(`
    SELECT c.*, p.name AS partner_name
    FROM crm_contacts c JOIN partners p ON p.id = c.partner_id
    ${where}
    ORDER BY p.name COLLATE NOCASE, c.last_name COLLATE NOCASE
  `);
  return partnerId ? query.all(partnerId) : query.all();
}

function createContact(data) {
  if (!db.prepare('SELECT 1 AS ok FROM partners WHERE id = ? AND company_id = ?').get(data.partnerId, ici())) return { ok: false, reason: 'no-partner' };
  db.prepare(`
    INSERT INTO crm_contacts (partner_id, first_name, last_name, role, email, phone, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(data.partnerId, data.firstName, data.lastName, data.role || '', data.email || '', data.phone || '', data.notes || '');
  return { ok: true };
}

function deleteContact(id) {
  if (!rattachees.visible('crm_contacts', id)) return false;
  return db.prepare('DELETE FROM crm_contacts WHERE id = ?').run(id).changes > 0;
}

// ---------- Opportunités ----------

function opportunities({ openOnly = false } = {}) {
  const where = openOnly ? `AND o.stage IN (${OPEN_STAGES.map(() => '?').join(',')})` : '';
  const query = db.prepare(`
    SELECT o.*, p.name AS partner_name, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
      (SELECT COUNT(*) FROM quotes q WHERE q.opportunity_id = o.id) AS quote_count
    FROM opportunities o
    JOIN partners p ON p.id = o.partner_id
    LEFT JOIN users u ON u.id = o.owner_id
    WHERE o.company_id = ? ${where}
    ORDER BY COALESCE(o.expected_close, '9999-12-31'), o.id DESC
  `);
  return openOnly ? query.all(ici(), ...OPEN_STAGES) : query.all(ici());
}

function opportunityById(id) {
  return db.prepare('SELECT * FROM opportunities WHERE id = ? AND company_id = ?').get(id, ici()) || null;
}

function createOpportunity(data) {
  if (!db.prepare('SELECT 1 AS ok FROM partners WHERE id = ? AND company_id = ?').get(data.partnerId, ici())) return { ok: false, reason: 'no-partner' };
  if (!Number.isFinite(data.amount) || data.amount < 0) return { ok: false, reason: 'bad-amount' };
  if (!Number.isInteger(data.probability) || data.probability < 0 || data.probability > 100) return { ok: false, reason: 'bad-probability' };

  db.prepare(`
    INSERT INTO opportunities (company_id, partner_id, title, amount, probability, expected_close, owner_id, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), data.partnerId, data.title, round(data.amount), data.probability, data.expectedClose || null,
         data.ownerId || null, data.notes || '');
  return { ok: true };
}

/** Gagner ou perdre une affaire la ferme et date sa clôture. */
function setStage(id, stage, lostReason = '') {
  if (!STAGES.includes(stage)) return { ok: false, reason: 'bad-stage' };
  const opportunity = opportunityById(id);
  if (!opportunity) return { ok: false, reason: 'not-found' };
  // Une affaire perdue dit pourquoi : c'est ce qui permet, au bout de l'année,
  // de savoir si l'on perd sur le prix ou sur le délai.
  const raison = stage === 'Perdue' ? String(lostReason || '') : '';
  if (stage === 'Perdue' && !LOST_REASONS.includes(raison)) return { ok: false, reason: 'need-reason' };

  const closed = ['Gagnée', 'Perdue'].includes(stage);
  db.prepare(`
    UPDATE opportunities SET stage = ?, probability = ?, closed_at = ?, lost_reason = ? WHERE id = ? AND company_id = ?
  `).run(stage, stage === 'Gagnée' ? 100 : (stage === 'Perdue' ? 0 : opportunity.probability),
         closed ? new Date().toISOString() : null, raison, id, ici());
  return { ok: true };
}

function deleteOpportunity(id) {
  return db.prepare('DELETE FROM opportunities WHERE id = ? AND company_id = ?').run(id, ici()).changes > 0;
}

/** Le pipeline : le montant en jeu et sa pondération par la probabilité. */
function pipeline() {
  const open = opportunities({ openOnly: true });
  const byStage = OPEN_STAGES.map((stage) => {
    const rows = open.filter((o) => o.stage === stage);
    return { stage, count: rows.length, amount: round(rows.reduce((sum, o) => sum + o.amount, 0)) };
  });

  const all = opportunities();
  const won = all.filter((o) => o.stage === 'Gagnée');
  const lost = all.filter((o) => o.stage === 'Perdue');

  // La prévision : les affaires ouvertes rangées par mois de signature attendu,
  // sur six mois. Ce qui est daté d'avant ce mois-ci est en retard ; ce qui
  // n'est pas daté ne se prévoit pas, et se dit à part.
  const moisCourant = today().slice(0, 7);
  const mois = [];
  for (let i = 0; i < 6; i += 1) {
    const d = new Date(`${moisCourant}-01T00:00:00Z`);
    d.setUTCMonth(d.getUTCMonth() + i);
    mois.push(d.toISOString().slice(0, 7));
  }
  const tranche = (rows) => ({
    count: rows.length,
    amount: round(rows.reduce((sum, o) => sum + o.amount, 0)),
    weighted: round(rows.reduce((sum, o) => sum + o.amount * (o.probability / 100), 0)),
  });
  const forecast = {
    late: tranche(open.filter((o) => o.expected_close && o.expected_close.slice(0, 7) < moisCourant)),
    months: mois.map((m) => ({ month: m, ...tranche(open.filter((o) => o.expected_close && o.expected_close.slice(0, 7) === m)) })),
    later: tranche(open.filter((o) => o.expected_close && o.expected_close.slice(0, 7) > mois[mois.length - 1])),
    undated: tranche(open.filter((o) => !o.expected_close)),
  };
  const lostByReason = LOST_REASONS
    .map((r) => ({ reason: r, count: lost.filter((o) => o.lost_reason === r).length }))
    .filter((r) => r.count > 0)
    .sort((a, b) => b.count - a.count);

  return {
    byStage,
    forecast,
    lostByReason,
    total: round(open.reduce((sum, o) => sum + o.amount, 0)),
    weighted: round(open.reduce((sum, o) => sum + o.amount * (o.probability / 100), 0)),
    won: round(won.reduce((sum, o) => sum + o.amount, 0)),
    wonCount: won.length,
    lostCount: lost.length,
    winRate: won.length + lost.length > 0 ? Math.round((won.length / (won.length + lost.length)) * 1000) / 10 : 0,
  };
}

// ---------- Devis ----------

function quotes() {
  return db.prepare(`
    SELECT q.*, p.name AS partner_name, o.title AS opportunity_title
    FROM quotes q
    JOIN partners p ON p.id = q.partner_id
    LEFT JOIN opportunities o ON o.id = q.opportunity_id
    WHERE q.company_id = ?
    ORDER BY q.issue_date DESC, q.id DESC
  `).all(ici()).map((q) => ({
    ...q,
    amount_ttc: round(q.amount_ht * (1 + q.vat_rate / 100)),
    expired: q.status === 'Envoyé' && Boolean(q.valid_until) && q.valid_until < today(),
  }));
}

function quoteById(id) {
  return db.prepare('SELECT * FROM quotes WHERE id = ? AND company_id = ?').get(id, ici()) || null;
}

function createQuote(data) {
  if (!db.prepare('SELECT 1 AS ok FROM partners WHERE id = ? AND company_id = ?').get(data.partnerId, ici())) return { ok: false, reason: 'no-partner' };
  if (!Number.isFinite(data.amountHt) || data.amountHt < 0) return { ok: false, reason: 'bad-amount' };
  if (!Number.isFinite(data.vatRate) || data.vatRate < 0 || data.vatRate > 100) return { ok: false, reason: 'bad-vat' };
  if (data.validUntil && data.validUntil < data.issueDate) return { ok: false, reason: 'bad-validity' };

  db.prepare(`
    INSERT INTO quotes (company_id, partner_id, opportunity_id, reference, label, issue_date, valid_until, amount_ht, vat_rate, status, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), data.partnerId, data.opportunityId || null, data.reference || '', data.label, data.issueDate,
         data.validUntil || null, round(data.amountHt), data.vatRate, data.status || 'Brouillon', data.createdBy);
  return { ok: true };
}

function setQuoteStatus(id, status) {
  if (!QUOTE_STATUSES.includes(status)) return false;
  return db.prepare('UPDATE quotes SET status = ? WHERE id = ? AND company_id = ?')
    .run(status, id, ici()).changes > 0;
}

function deleteQuote(id) {
  return db.prepare('DELETE FROM quotes WHERE id = ? AND company_id = ?').run(id, ici()).changes > 0;
}

/**
 * Un devis accepté devient une facture client : c'est le point de jonction
 * entre le commercial et la gestion. Un devis déjà facturé ne l'est pas deux fois.
 */
function convertToInvoice(id, createdBy) {
  const quote = quoteById(id);
  if (!quote) return { ok: false, reason: 'not-found' };
  if (quote.status !== 'Accepté') return { ok: false, reason: 'not-accepted' };
  if (quote.invoice_id) return { ok: false, reason: 'already-invoiced' };
  // Un devis passé en commande se facture par sa commande, au fil des
  // livraisons : le facturer ici le ferait payer deux fois.
  if (db.prepare("SELECT 1 AS ok FROM sales_orders WHERE quote_id = ? AND company_id = ? AND status <> 'Annulée'").get(id, ici())) {
    return { ok: false, reason: 'ordered' };
  }

  const commit = db.transaction(() => {
    const invoiceId = db.prepare(`
      INSERT INTO invoices (company_id, direction, partner_id, reference, label, issue_date, due_date, amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate)
      VALUES (?, 'Client', ?, ?, ?, date('now'), date('now', '+30 days'), ?, ?, 'Émise', ?, ?, ?, 1)
    `).run(ici(), quote.partner_id, quote.reference, quote.label, quote.amount_ht, quote.vat_rate,
           `Issue du devis ${quote.reference || '#' + quote.id}`, createdBy,
           // Un devis se chiffre dans la devise de tenue des comptes : la
           // facture qui en sort n'a donc pas de conversion à faire.
           require('./currency').base()).lastInsertRowid;

    db.prepare('UPDATE quotes SET invoice_id = ? WHERE id = ? AND company_id = ?').run(invoiceId, id, ici());
    return invoiceId;
  });

  return { ok: true, invoiceId: commit() };
}

// ---------- Relances ----------

function activities({ pendingOnly = false } = {}) {
  const where = pendingOnly ? 'WHERE a.done_at IS NULL' : '';
  return db.prepare(`
    SELECT a.*, p.name AS partner_name, o.title AS opportunity_title,
           u.first_name AS owner_first_name, u.last_name AS owner_last_name
    FROM crm_activities a
    LEFT JOIN partners p ON p.id = a.partner_id
    LEFT JOIN opportunities o ON o.id = a.opportunity_id
    LEFT JOIN users u ON u.id = a.owner_id
    ${where}
    ORDER BY a.due_on, a.id
  `).all().map((a) => ({ ...a, overdue: !a.done_at && a.due_on < today() }));
}

function createActivity(data) {
  if (!data.partnerId && !data.opportunityId) return { ok: false, reason: 'no-target' };
  db.prepare(`
    INSERT INTO crm_activities (partner_id, opportunity_id, kind, due_on, note, owner_id)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(data.partnerId || null, data.opportunityId || null, data.kind || 'Relance', data.dueOn,
         data.note || '', data.ownerId || null);
  return { ok: true };
}

function completeActivity(id) {
  if (!rattachees.visible('crm_activities', id)) return false;
  return db.prepare('UPDATE crm_activities SET done_at = ? WHERE id = ? AND done_at IS NULL')
    .run(new Date().toISOString(), id).changes > 0;
}

function deleteActivity(id) {
  if (!rattachees.visible('crm_activities', id)) return false;
  return db.prepare('DELETE FROM crm_activities WHERE id = ?').run(id).changes > 0;
}

module.exports = {
  LOST_REASONS,
  STAGES,
  OPEN_STAGES,
  QUOTE_STATUSES,
  ACTIVITY_KINDS,
  contacts,
  createContact,
  deleteContact,
  opportunities,
  opportunityById,
  createOpportunity,
  setStage,
  deleteOpportunity,
  pipeline,
  quotes,
  quoteById,
  createQuote,
  setQuoteStatus,
  deleteQuote,
  convertToInvoice,
  activities,
  createActivity,
  completeActivity,
  deleteActivity,
};
