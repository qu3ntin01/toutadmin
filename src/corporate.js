const db = require('./db');

/**
 * Vie juridique et conformité.
 *
 * Le produit savait tout de l'entreprise employeur et rien de l'entreprise
 * société : qui la détient, qui la dirige, ce que les assemblées ont voté, et
 * qui a reçu le pouvoir d'engager quoi. C'est pourtant la couche qui répond à
 * la question la plus fréquente d'un contrôle ou d'une due diligence.
 *
 * Un choix de fond : la détention n'est pas une colonne, c'est une somme. Elle
 * se recalcule des mouvements de titres, comme un solde bancaire se recalcule
 * de ses écritures. Personne ne peut donc corriger un capital sans laisser la
 * ligne qui l'explique — ce qui est exactement l'objet d'un registre.
 */

const SHAREHOLDER_KINDS = ['Personne physique', 'Personne morale'];
const MOVEMENT_KINDS = ['Souscription', 'Cession', 'Acquisition', 'Réduction'];
const MANDATE_ROLES = ['Président', 'Directeur général', 'Directeur général délégué', 'Gérant', 'Membre du conseil', 'Commissaire aux comptes'];
const MANDATE_STATUSES = ['En cours', 'Échu', 'Révoqué'];
const MEETING_KINDS = ['Assemblée générale ordinaire', 'Assemblée générale extraordinaire', 'Assemblée générale mixte'];
const MEETING_STATUSES = ['Convoquée', 'Tenue', 'Annulée'];
const RESOLUTION_OUTCOMES = ['En attente', 'Adoptée', 'Rejetée'];

const INTEREST_KINDS = ['Intérêt financier', 'Mandat externe', 'Lien familial', 'Activité accessoire', 'Autre'];
const INTEREST_STATUSES = ['Déclaré', 'Examiné', 'Mesure prise', 'Clos'];
const GIFT_DIRECTIONS = ['Reçu', 'Offert'];
const GIFT_KINDS = ['Cadeau', 'Invitation', 'Voyage', 'Autre'];
const GIFT_STATUSES = ['Déclaré', 'Accepté', 'Refusé', 'Restitué'];
const DELEGATION_STATUSES = ['En vigueur', 'Suspendue', 'Échue', 'Révoquée'];

// Au-delà, un cadeau ou une invitation demande un examen : le seuil n'est pas
// une règle de droit mais un usage, et il vaut mieux l'écrire que le supposer.
const GIFT_REVIEW_THRESHOLD = 150;

const round = (n) => Math.round(n * 100) / 100;

// ---------------------------------------------------------------- capital

function shareholders() {
  return db.prepare(`
    SELECT s.*, u.first_name, u.last_name,
      (SELECT COALESCE(SUM(m.shares), 0) FROM share_movements m WHERE m.shareholder_id = s.id) AS shares
    FROM shareholders s LEFT JOIN users u ON u.id = s.user_id
    ORDER BY shares DESC, s.name COLLATE NOCASE
  `).all();
}

function shareholderById(id) {
  return db.prepare(`
    SELECT s.*, (SELECT COALESCE(SUM(m.shares), 0) FROM share_movements m WHERE m.shareholder_id = s.id) AS shares
    FROM shareholders s WHERE s.id = ?
  `).get(Number(id) || 0) || null;
}

function createShareholder(fields) {
  return db.prepare(`
    INSERT INTO shareholders (name, kind, user_id, registration, email, address, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(fields.name, fields.kind, fields.userId || null, fields.registration || '', fields.email || '', fields.address || '', fields.notes || '').lastInsertRowid;
}

function removeShareholder(id) {
  const held = shareholderById(id);
  // Un associé qui détient encore des titres ne s'efface pas : ses parts
  // disparaîtraient du capital sans qu'aucune cession ne l'explique.
  if (!held || held.shares !== 0) return false;
  db.prepare('DELETE FROM shareholders WHERE id = ?').run(id);
  return true;
}

function movements({ shareholderId = null, limit = 200 } = {}) {
  const clause = shareholderId ? 'WHERE m.shareholder_id = ?' : '';
  const params = shareholderId ? [shareholderId, limit] : [limit];
  return db.prepare(`
    SELECT m.*, s.name AS shareholder_name, c.name AS counterparty_name
    FROM share_movements m
    JOIN shareholders s ON s.id = m.shareholder_id
    LEFT JOIN shareholders c ON c.id = m.counterparty_id
    ${clause}
    ORDER BY m.moved_on DESC, m.id DESC LIMIT ?
  `).all(...params);
}

/**
 * Inscrit un mouvement. Une cession entre deux associés en écrit deux, dans la
 * même transaction : le registre ne doit jamais montrer des titres partis de
 * chez l'un sans être arrivés chez l'autre.
 */
const recordMovement = db.transaction(({ shareholderId, kind, movedOn, shares, unitPrice = null, counterpartyId = null, note = '' }) => {
  const holder = shareholderById(shareholderId);
  if (!holder) return { ok: false, reason: 'introuvable' };

  const signed = kind === 'Cession' || kind === 'Réduction' ? -Math.abs(shares) : Math.abs(shares);
  // On ne cède pas plus qu'on ne détient : le capital ne devient pas négatif.
  if (signed < 0 && holder.shares + signed < 0) return { ok: false, reason: 'insuffisant', held: holder.shares };

  const insert = db.prepare(`
    INSERT INTO share_movements (shareholder_id, kind, moved_on, shares, unit_price, counterparty_id, note)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `);
  insert.run(shareholderId, kind, movedOn, signed, unitPrice, counterpartyId, note.slice(0, 300));

  if (kind === 'Cession' && counterpartyId) {
    insert.run(counterpartyId, 'Acquisition', movedOn, Math.abs(shares), unitPrice, shareholderId, note.slice(0, 300));
  }
  return { ok: true };
});

function removeMovement(id) {
  db.prepare('DELETE FROM share_movements WHERE id = ?').run(id);
}

/** La répartition du capital, en titres et en pourcentage. */
function capital() {
  const holders = shareholders().filter((s) => s.shares !== 0);
  const total = holders.reduce((sum, s) => sum + s.shares, 0);
  return {
    total,
    holders: holders.map((s) => ({ ...s, share: total ? round((s.shares / total) * 100) : 0 })),
    // Le seuil au-delà duquel un associé décide seul en assemblée ordinaire.
    majority: holders.filter((s) => total && s.shares / total > 0.5).map((s) => s.name),
  };
}

// ---------------------------------------------------------------- mandats

function mandates({ includeEnded = true } = {}) {
  const clause = includeEnded ? '' : "WHERE m.status = 'En cours'";
  return db.prepare(`
    SELECT m.*, u.first_name, u.last_name
    FROM corporate_mandates m LEFT JOIN users u ON u.id = m.user_id
    ${clause}
    ORDER BY m.status = 'En cours' DESC, m.started_on DESC
  `).all();
}

function createMandate(fields) {
  return db.prepare(`
    INSERT INTO corporate_mandates (holder_name, user_id, role, started_on, ends_on, appointed_by, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(fields.holderName, fields.userId || null, fields.role, fields.startedOn, fields.endsOn || null, fields.appointedBy || '', fields.notes || '').lastInsertRowid;
}

function setMandateStatus(id, status) {
  if (!MANDATE_STATUSES.includes(status)) return false;
  db.prepare('UPDATE corporate_mandates SET status = ? WHERE id = ?').run(status, id);
  return true;
}

function removeMandate(id) {
  db.prepare('DELETE FROM corporate_mandates WHERE id = ?').run(id);
}

// ---------------------------------------------------------------- assemblées

function nextMeetingReference() {
  const year = new Date().getFullYear();
  const count = db.prepare('SELECT COUNT(*) AS n FROM general_meetings WHERE reference LIKE ?').get(`AG-${year}-%`).n;
  return `AG-${year}-${String(count + 1).padStart(3, '0')}`;
}

function meetings() {
  return db.prepare(`
    SELECT m.*, (SELECT COUNT(*) FROM meeting_resolutions r WHERE r.meeting_id = m.id) AS resolution_count
    FROM general_meetings m ORDER BY m.held_on DESC, m.id DESC
  `).all();
}

function meetingById(id) {
  return db.prepare('SELECT * FROM general_meetings WHERE id = ?').get(Number(id) || 0) || null;
}

function createMeeting(fields) {
  return db.prepare(`
    INSERT INTO general_meetings (reference, kind, held_on, location, quorum_required)
    VALUES (?, ?, ?, ?, ?)
  `).run(nextMeetingReference(), fields.kind, fields.heldOn, fields.location || '', fields.quorumRequired || 0).lastInsertRowid;
}

/** La fiche ne touche pas au procès-verbal : il se rédige de son côté. */
function updateMeeting(id, fields) {
  db.prepare(`
    UPDATE general_meetings
    SET kind = ?, held_on = ?, location = ?, quorum_required = ?, shares_present = ?, status = ?
    WHERE id = ?
  `).run(fields.kind, fields.heldOn, fields.location || '', fields.quorumRequired || 0,
    fields.sharesPresent || 0, fields.status, id);
}

/**
 * Le procès-verbal se modifie seul, sans repasser par la fiche. L'écran de
 * rédaction n'a alors rien à réémettre du statut ni de la date : un formulaire
 * qui renvoie des champs qu'il n'édite pas finit un jour par les écraser.
 */
function updateMinutes(id, minutes) {
  db.prepare('UPDATE general_meetings SET minutes = ? WHERE id = ?').run(String(minutes || '').slice(0, 20000), id);
}

function removeMeeting(id) {
  db.prepare('DELETE FROM general_meetings WHERE id = ?').run(id);
}

function resolutions(meetingId) {
  return db.prepare('SELECT * FROM meeting_resolutions WHERE meeting_id = ? ORDER BY position, id').all(meetingId);
}

function addResolution({ meetingId, label, majorityRequired }) {
  const position = db.prepare('SELECT COALESCE(MAX(position), 0) + 1 AS next FROM meeting_resolutions WHERE meeting_id = ?').get(meetingId).next;
  return db.prepare(`
    INSERT INTO meeting_resolutions (meeting_id, position, label, majority_required)
    VALUES (?, ?, ?, ?)
  `).run(meetingId, position, label, majorityRequired).lastInsertRowid;
}

/**
 * Enregistre un vote et en tire le sort de la résolution.
 *
 * La majorité se calcule sur les voix exprimées — pour et contre — les
 * abstentions étant écartées du dénominateur, ce qui est la règle statutaire
 * la plus répandue. Elle est écrite ici plutôt que supposée, parce que des
 * statuts peuvent en retenir une autre, et qu'il vaut mieux savoir laquelle
 * ce registre applique.
 */
function recordVote(id, { votesFor, votesAgainst, votesAbstain }) {
  const expressed = votesFor + votesAgainst;
  const share = expressed ? (votesFor / expressed) * 100 : 0;
  const resolution = db.prepare('SELECT majority_required FROM meeting_resolutions WHERE id = ?').get(id);
  if (!resolution) return false;

  const outcome = expressed === 0 ? 'En attente' : share >= resolution.majority_required ? 'Adoptée' : 'Rejetée';
  db.prepare(`
    UPDATE meeting_resolutions SET votes_for = ?, votes_against = ?, votes_abstain = ?, outcome = ? WHERE id = ?
  `).run(votesFor, votesAgainst, votesAbstain, outcome, id);
  return true;
}

function removeResolution(id) {
  db.prepare('DELETE FROM meeting_resolutions WHERE id = ?').run(id);
}

/** Le quorum d'une assemblée : atteint, ou de combien il manque. */
function quorum(meeting) {
  const total = capital().total;
  const required = meeting.quorum_required || 0;
  const present = meeting.shares_present || 0;
  return {
    total,
    present,
    required,
    share: total ? round((present / total) * 100) : 0,
    reached: present >= required,
    missing: Math.max(0, round(required - present)),
  };
}

// ---------------------------------------------------------------- conformité

function declarations({ userId = null } = {}) {
  const clause = userId ? 'WHERE d.user_id = ?' : '';
  const params = userId ? [userId] : [];
  return db.prepare(`
    SELECT d.*, u.first_name, u.last_name, p.name AS partner_name,
           r.first_name AS reviewer_first_name, r.last_name AS reviewer_last_name
    FROM interest_declarations d
    JOIN users u ON u.id = d.user_id
    LEFT JOIN partners p ON p.id = d.partner_id
    LEFT JOIN users r ON r.id = d.reviewed_by
    ${clause}
    ORDER BY d.status = 'Clos', d.declared_on DESC, d.id DESC
  `).all(...params);
}

function declareInterest(fields) {
  return db.prepare(`
    INSERT INTO interest_declarations (user_id, kind, entity, partner_id, description, declared_on, ends_on)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(fields.userId, fields.kind, fields.entity, fields.partnerId || null,
    fields.description || '', fields.declaredOn, fields.endsOn || null).lastInsertRowid;
}

function reviewDeclaration(id, { status, measure, reviewerId }) {
  if (!INTEREST_STATUSES.includes(status)) return false;
  db.prepare(`
    UPDATE interest_declarations
    SET status = ?, measure = ?, reviewed_by = ?, reviewed_at = datetime('now')
    WHERE id = ?
  `).run(status, String(measure || '').slice(0, 1000), reviewerId, id);
  return true;
}

function gifts({ userId = null } = {}) {
  const clause = userId ? 'WHERE g.user_id = ?' : '';
  const params = userId ? [userId] : [];
  return db.prepare(`
    SELECT g.*, u.first_name, u.last_name, p.name AS partner_name
    FROM gift_records g
    JOIN users u ON u.id = g.user_id
    LEFT JOIN partners p ON p.id = g.partner_id
    ${clause}
    ORDER BY g.occurred_on DESC, g.id DESC
  `).all(...params);
}

function declareGift(fields) {
  return db.prepare(`
    INSERT INTO gift_records (user_id, direction, kind, partner_id, third_party, occurred_on, value, description)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(fields.userId, fields.direction, fields.kind, fields.partnerId || null,
    fields.thirdParty || '', fields.occurredOn, fields.value || 0, fields.description || '').lastInsertRowid;
}

function reviewGift(id, { status, reviewerId }) {
  if (!GIFT_STATUSES.includes(status)) return false;
  db.prepare("UPDATE gift_records SET status = ?, reviewed_by = ?, reviewed_at = datetime('now') WHERE id = ?")
    .run(status, reviewerId, id);
  return true;
}

function delegations({ includeEnded = true } = {}) {
  const clause = includeEnded ? '' : "WHERE d.status = 'En vigueur'";
  return db.prepare(`
    SELECT d.*, u.first_name, u.last_name, g.first_name AS granted_first_name, g.last_name AS granted_last_name
    FROM power_delegations d
    JOIN users u ON u.id = d.holder_id
    LEFT JOIN users g ON g.id = d.granted_by_id
    ${clause}
    ORDER BY d.status = 'En vigueur' DESC, d.starts_on DESC
  `).all();
}

function createDelegation(fields) {
  return db.prepare(`
    INSERT INTO power_delegations (holder_id, granted_by_id, scope, amount_limit, starts_on, ends_on, notes)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(fields.holderId, fields.grantedById || null, fields.scope,
    fields.amountLimit == null ? null : fields.amountLimit, fields.startsOn, fields.endsOn || null, fields.notes || '').lastInsertRowid;
}

function setDelegationStatus(id, status) {
  if (!DELEGATION_STATUSES.includes(status)) return false;
  db.prepare('UPDATE power_delegations SET status = ? WHERE id = ?').run(status, id);
  return true;
}

function removeDelegation(id) {
  db.prepare('DELETE FROM power_delegations WHERE id = ?').run(id);
}

/** Les cadeaux au-delà du seuil que personne n'a encore examinés. */
function giftsToReview() {
  return gifts().filter((gift) => gift.value > GIFT_REVIEW_THRESHOLD && gift.status === 'Déclaré');
}

function summary() {
  const cap = capital();
  return {
    shareholders: cap.holders.length,
    shares: cap.total,
    mandates: db.prepare("SELECT COUNT(*) AS n FROM corporate_mandates WHERE status = 'En cours'").get().n,
    meetings: db.prepare('SELECT COUNT(*) AS n FROM general_meetings').get().n,
    openDeclarations: db.prepare("SELECT COUNT(*) AS n FROM interest_declarations WHERE status IN ('Déclaré','Examiné')").get().n,
    giftsToReview: giftsToReview().length,
    delegations: db.prepare("SELECT COUNT(*) AS n FROM power_delegations WHERE status = 'En vigueur'").get().n,
  };
}

module.exports = {
  SHAREHOLDER_KINDS,
  MOVEMENT_KINDS,
  MANDATE_ROLES,
  MANDATE_STATUSES,
  MEETING_KINDS,
  MEETING_STATUSES,
  RESOLUTION_OUTCOMES,
  INTEREST_KINDS,
  INTEREST_STATUSES,
  GIFT_DIRECTIONS,
  GIFT_KINDS,
  GIFT_STATUSES,
  DELEGATION_STATUSES,
  GIFT_REVIEW_THRESHOLD,
  shareholders,
  shareholderById,
  createShareholder,
  removeShareholder,
  movements,
  recordMovement,
  removeMovement,
  capital,
  mandates,
  createMandate,
  setMandateStatus,
  removeMandate,
  meetings,
  meetingById,
  createMeeting,
  updateMeeting,
  updateMinutes,
  removeMeeting,
  resolutions,
  addResolution,
  recordVote,
  removeResolution,
  quorum,
  declarations,
  declareInterest,
  reviewDeclaration,
  gifts,
  declareGift,
  reviewGift,
  giftsToReview,
  delegations,
  createDelegation,
  setDelegationStatus,
  removeDelegation,
  summary,
};
