const db = require('./db');

/**
 * Fiche d'un tiers : ses interlocuteurs, ses pièces de conformité et son
 * évaluation.
 *
 * Un partenaire n'était jusqu'ici qu'une ligne dans un tableau, reliée à des
 * contrats et à des factures. Il manquait ce qui fait qu'on travaille avec lui
 * en confiance : à qui l'on parle, ce qu'il doit fournir et jusqu'à quand cela
 * vaut, et ce qu'on a pensé de lui la dernière fois. Une attestation de
 * vigilance périmée engage la responsabilité du donneur d'ordre : ce n'est pas
 * une pièce jointe, c'est une échéance.
 */

const DOCUMENT_KINDS = [
  'Attestation de vigilance',
  'Assurance',
  'Kbis',
  'Coordonnées bancaires',
  'Certification',
  'Autre',
];

// En deçà, une pièce est « bientôt périmée » : le temps d'en redemander une.
const EXPIRY_WARNING_DAYS = 45;

// ---------------------------------------------------------------- contacts

function contacts(partnerId) {
  return db.prepare(`
    SELECT * FROM partner_contacts WHERE partner_id = ?
    ORDER BY is_primary DESC, name COLLATE NOCASE
  `).all(partnerId);
}

function addContact({ partnerId, name, role = '', email = '', phone = '', isPrimary = false, notes = '' }) {
  const id = db.prepare(`
    INSERT INTO partner_contacts (partner_id, name, role, email, phone, is_primary, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(partnerId, name, role, email, phone, isPrimary ? 1 : 0, notes).lastInsertRowid;
  if (isPrimary) setPrimary(partnerId, id);
  return id;
}

/** Un seul interlocuteur principal par tiers : désigner le nouveau retire l'ancien. */
function setPrimary(partnerId, contactId) {
  db.prepare('UPDATE partner_contacts SET is_primary = 0 WHERE partner_id = ?').run(partnerId);
  return db.prepare('UPDATE partner_contacts SET is_primary = 1 WHERE id = ? AND partner_id = ?')
    .run(contactId, partnerId).changes > 0;
}

function removeContact(id) {
  const row = db.prepare('SELECT partner_id FROM partner_contacts WHERE id = ?').get(id);
  db.prepare('DELETE FROM partner_contacts WHERE id = ?').run(id);
  return row ? row.partner_id : null;
}

// ---------------------------------------------------------------- conformité

function documents(partnerId) {
  return db.prepare('SELECT * FROM partner_documents WHERE partner_id = ? ORDER BY expires_on IS NULL, expires_on, kind').all(partnerId);
}

function addDocument({ partnerId, kind, reference = '', issuedOn = null, expiresOn = null, notes = '' }) {
  return db.prepare(`
    INSERT INTO partner_documents (partner_id, kind, reference, issued_on, expires_on, notes)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(partnerId, kind, reference, issuedOn, expiresOn, notes).lastInsertRowid;
}

function removeDocument(id) {
  const row = db.prepare('SELECT partner_id FROM partner_documents WHERE id = ?').get(id);
  db.prepare('DELETE FROM partner_documents WHERE id = ?').run(id);
  return row ? row.partner_id : null;
}

/** L'état d'une pièce : valable, bientôt périmée, périmée, ou sans date. */
function documentState(document, today = new Date().toISOString().slice(0, 10)) {
  if (!document.expires_on) return 'sans_date';
  if (document.expires_on < today) return 'perime';

  const limit = new Date(`${today}T00:00:00Z`);
  limit.setUTCDate(limit.getUTCDate() + EXPIRY_WARNING_DAYS);
  return document.expires_on <= limit.toISOString().slice(0, 10) ? 'bientot' : 'valable';
}

// ---------------------------------------------------------------- évaluation

function reviews(partnerId) {
  return db.prepare(`
    SELECT r.*, u.first_name, u.last_name
    FROM partner_reviews r LEFT JOIN users u ON u.id = r.reviewer_id
    WHERE r.partner_id = ? ORDER BY r.reviewed_on DESC, r.id DESC
  `).all(partnerId);
}

function addReview({ partnerId, reviewedOn, reviewerId = null, quality = null, leadTime = null, price = null, comment = '', nextReview = null }) {
  return db.prepare(`
    INSERT INTO partner_reviews (partner_id, reviewed_on, reviewer_id, quality, lead_time, price, comment, next_review)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(partnerId, reviewedOn, reviewerId, quality, leadTime, price, comment, nextReview).lastInsertRowid;
}

function removeReview(id) {
  const row = db.prepare('SELECT partner_id FROM partner_reviews WHERE id = ?').get(id);
  db.prepare('DELETE FROM partner_reviews WHERE id = ?').run(id);
  return row ? row.partner_id : null;
}

/** La note d'une évaluation : moyenne des critères renseignés, sur cinq. */
function reviewScore(review) {
  const marks = [review.quality, review.lead_time, review.price].filter((m) => m != null);
  if (!marks.length) return null;
  return Math.round((marks.reduce((a, b) => a + b, 0) / marks.length) * 10) / 10;
}

// ---------------------------------------------------------------- vue d'ensemble

function sheet(partnerId) {
  const partner = db.prepare('SELECT * FROM partners WHERE id = ?').get(Number(partnerId) || 0);
  if (!partner) return null;

  const today = new Date().toISOString().slice(0, 10);
  const documentList = documents(partner.id).map((d) => ({ ...d, state: documentState(d, today) }));
  const reviewList = reviews(partner.id).map((r) => ({ ...r, score: reviewScore(r) }));

  return {
    partner,
    contacts: contacts(partner.id),
    documents: documentList,
    reviews: reviewList,
    contracts: db.prepare(`
      SELECT * FROM partner_contracts WHERE partner_id = ?
      ORDER BY end_date IS NULL, end_date DESC, id DESC
    `).all(partner.id),
    invoices: db.prepare(`
      SELECT id, reference, label, direction, issue_date, due_date, amount_ht, status
      FROM invoices WHERE partner_id = ? ORDER BY issue_date DESC, id DESC LIMIT 30
    `).all(partner.id),
    compliance: {
      expired: documentList.filter((d) => d.state === 'perime').length,
      soon: documentList.filter((d) => d.state === 'bientot').length,
      total: documentList.length,
    },
    // La note retenue est celle de la dernière évaluation : une moyenne de tout
    // l'historique lisserait justement ce qu'on cherche à voir, une dégradation.
    lastScore: reviewList.length ? reviewList[0].score : null,
    nextReview: reviewList.map((r) => r.next_review).filter(Boolean).sort()[0] || null,
  };
}

module.exports = {
  DOCUMENT_KINDS,
  EXPIRY_WARNING_DAYS,
  contacts,
  addContact,
  setPrimary,
  removeContact,
  documents,
  addDocument,
  removeDocument,
  documentState,
  reviews,
  addReview,
  removeReview,
  reviewScore,
  sheet,
};
