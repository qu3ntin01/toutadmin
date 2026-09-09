const db = require('./db');

/**
 * Recouvrement : relances échelonnées des factures clients.
 *
 * Le retard seul ne dit pas quoi envoyer. Une facture en retard de deux mois à
 * qui l'on n'a jamais rien écrit appelle un rappel, pas une mise en demeure —
 * et l'inverse, relancer trois fois au même niveau, use la relance sans jamais
 * franchir de palier. Le niveau se déduit donc de ce qui a déjà été envoyé,
 * puis du délai écoulé depuis.
 */

// Les trois paliers, et le retard à partir duquel chacun se justifie. Le délai
// du palier suivant se compte depuis la relance précédente, pas depuis
// l'échéance : c'est le silence qui appelle l'escalade.
const LEVELS = [
  { level: 1, label: 'Rappel', afterDueDays: 7, afterPreviousDays: 0 },
  { level: 2, label: 'Relance', afterDueDays: 21, afterPreviousDays: 10 },
  { level: 3, label: 'Mise en demeure', afterDueDays: 45, afterPreviousDays: 15 },
];

// Tranches d'antériorité de la balance âgée.
const BUCKETS = [
  { key: 'courant', from: -100000, to: 0 },
  { key: 'j30', from: 1, to: 30 },
  { key: 'j60', from: 31, to: 60 },
  { key: 'j90', from: 61, to: 90 },
  { key: 'plus', from: 91, to: 1000000 },
];

const round = (n) => Math.round(n * 100) / 100;

function today() {
  return new Date().toISOString().slice(0, 10);
}

function daysBetween(from, to) {
  return Math.floor((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86400000);
}

/** Les factures clients non réglées, avec leur retard et leur dernière relance. */
function outstanding() {
  return db.prepare(`
    SELECT i.id, i.reference, i.label, i.issue_date, i.due_date, i.amount_ht, i.status,
           p.id AS partner_id, p.name AS partner_name, p.email AS partner_email,
           (SELECT MAX(level) FROM dunning_notices n WHERE n.invoice_id = i.id) AS last_level,
           (SELECT MAX(sent_on) FROM dunning_notices n WHERE n.invoice_id = i.id) AS last_sent
    FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
    WHERE i.direction = 'Client' AND i.status NOT IN ('Payée', 'Annulée', 'Brouillon')
    ORDER BY i.due_date IS NULL, i.due_date
  `).all().map((row) => ({
    ...row,
    overdueDays: row.due_date ? daysBetween(row.due_date, today()) : null,
  }));
}

/**
 * Le palier justifié pour une facture, ou null s'il n'y a rien à envoyer.
 * Rend aussi le motif, pour que l'écran explique au lieu d'ordonner.
 */
function nextLevel(invoice, at = today()) {
  if (invoice.overdueDays == null || invoice.overdueDays <= 0) return null;

  const sentLevel = invoice.last_level || 0;
  if (sentLevel >= 3) return null;

  const candidate = LEVELS[sentLevel];
  if (invoice.overdueDays < candidate.afterDueDays) return null;

  // Le palier suivant demande aussi qu'on ait laissé au client le temps de
  // répondre à la relance précédente.
  if (sentLevel > 0) {
    const since = daysBetween(invoice.last_sent, at);
    if (since < candidate.afterPreviousDays) return null;
  }
  return candidate;
}

/** Ce qui est à relancer aujourd'hui, avec le palier proposé pour chacune. */
function due() {
  return outstanding()
    .map((invoice) => ({ invoice, level: nextLevel(invoice) }))
    .filter((row) => row.level);
}

function noticesFor(invoiceId) {
  return db.prepare(`
    SELECT n.*, u.first_name, u.last_name
    FROM dunning_notices n LEFT JOIN users u ON u.id = n.created_by
    WHERE n.invoice_id = ? ORDER BY n.level, n.sent_on
  `).all(invoiceId);
}

/**
 * Consigne une relance. Le niveau ne saute pas : passer d'un rappel jamais
 * envoyé à une mise en demeure fragilise juridiquement la mise en demeure
 * elle-même, qui suppose des rappels restés sans effet.
 */
function record({ invoiceId, level, sentOn, note = '', createdBy = null }) {
  const invoice = db.prepare("SELECT * FROM invoices WHERE id = ? AND direction = 'Client'").get(invoiceId);
  if (!invoice) return { ok: false, reason: 'introuvable' };
  if (invoice.status === 'Payée' || invoice.status === 'Annulée') return { ok: false, reason: 'reglee' };

  const wanted = Number(level);
  if (!LEVELS.some((l) => l.level === wanted)) return { ok: false, reason: 'niveau' };

  const sent = db.prepare('SELECT MAX(level) AS level FROM dunning_notices WHERE invoice_id = ?').get(invoiceId).level || 0;
  if (wanted > sent + 1) return { ok: false, reason: 'saut', expected: sent + 1 };

  db.prepare(`
    INSERT INTO dunning_notices (invoice_id, level, sent_on, note, created_by)
    VALUES (?, ?, ?, ?, ?)
  `).run(invoiceId, wanted, sentOn, String(note).slice(0, 500), createdBy);
  return { ok: true };
}

function remove(id) {
  return db.prepare('DELETE FROM dunning_notices WHERE id = ?').run(id).changes > 0;
}

/** La balance âgée : l'encours client réparti par tranche de retard. */
function agedBalance() {
  const rows = outstanding();
  const buckets = Object.fromEntries(BUCKETS.map((b) => [b.key, { count: 0, amount: 0 }]));

  for (const invoice of rows) {
    const late = invoice.overdueDays == null ? 0 : invoice.overdueDays;
    const bucket = BUCKETS.find((b) => late >= b.from && late <= b.to) || BUCKETS[BUCKETS.length - 1];
    buckets[bucket.key].count += 1;
    buckets[bucket.key].amount = round(buckets[bucket.key].amount + invoice.amount_ht);
  }

  return {
    buckets,
    total: round(rows.reduce((sum, r) => sum + r.amount_ht, 0)),
    overdue: round(rows.filter((r) => r.overdueDays > 0).reduce((sum, r) => sum + r.amount_ht, 0)),
    count: rows.length,
  };
}

function summary() {
  const balance = agedBalance();
  return {
    outstanding: balance.total,
    overdue: balance.overdue,
    toSend: due().length,
    formalNotices: db.prepare('SELECT COUNT(*) AS n FROM dunning_notices WHERE level = 3').get().n,
  };
}

module.exports = { LEVELS, BUCKETS, outstanding, nextLevel, due, noticesFor, record, remove, agedBalance, summary, daysBetween };
