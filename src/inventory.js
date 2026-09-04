const db = require('./db');

const MOVEMENT_KINDS = ['Entrée', 'Sortie', 'Inventaire'];
const REQUEST_STATUSES = ['Manager', 'Gestion', 'Approuvée', 'Refusée', 'Annulée', 'Commandée'];

// Au-delà de ce montant, la gestion valide après le manager. En deçà, l'accord
// du manager suffit : c'est le seuil qui rend l'approbation « à plusieurs niveaux ».
const FINANCE_THRESHOLD = 500;

const round = (n) => Math.round(n * 100) / 100;

// ---------- Articles et stock ----------

/**
 * Le stock n'est pas une colonne : c'est la somme des mouvements. Un inventaire
 * repose le compteur, les entrées et sorties le font varier depuis là.
 */
function items({ activeOnly = false } = {}) {
  const where = activeOnly ? 'WHERE i.active = 1' : '';
  return db.prepare(`
    SELECT i.*, p.name AS partner_name,
      COALESCE((
        SELECT SUM(CASE m.kind WHEN 'Entrée' THEN m.quantity WHEN 'Sortie' THEN -m.quantity ELSE 0 END)
        FROM stock_movements m
        WHERE m.item_id = i.id AND m.id > COALESCE((
          SELECT MAX(r.id) FROM stock_movements r WHERE r.item_id = i.id AND r.kind = 'Inventaire'
        ), 0)
      ), 0)
      + COALESCE((
        SELECT r.quantity FROM stock_movements r
        WHERE r.item_id = i.id AND r.kind = 'Inventaire' ORDER BY r.id DESC LIMIT 1
      ), 0) AS stock
    FROM items i
    LEFT JOIN partners p ON p.id = i.partner_id
    ${where}
    ORDER BY i.category COLLATE NOCASE, i.label COLLATE NOCASE
  `).all().map((i) => ({ ...i, stock: round(i.stock), below: round(i.stock) < i.stock_min }));
}

function itemById(id) {
  return items().find((i) => i.id === id) || null;
}

function createItem(data) {
  return db.prepare(`
    INSERT INTO items (reference, label, unit, category, stock_min, unit_price, partner_id)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(data.reference || '', data.label, data.unit || 'unité', data.category || '',
         data.stockMin || 0, data.unitPrice ?? null, data.partnerId || null).lastInsertRowid;
}

function toggleItem(id) {
  const item = db.prepare('SELECT * FROM items WHERE id = ?').get(id);
  if (!item) return false;
  db.prepare('UPDATE items SET active = ? WHERE id = ?').run(item.active ? 0 : 1, id);
  return true;
}

function deleteItem(id) {
  db.prepare('DELETE FROM items WHERE id = ?').run(id);
}

function move({ itemId, kind, quantity, reason, movedOn, createdBy }) {
  if (!MOVEMENT_KINDS.includes(kind)) return { ok: false, reason: 'bad-kind' };
  const item = itemById(itemId);
  if (!item) return { ok: false, reason: 'not-found' };
  if (!Number.isFinite(quantity) || quantity < 0) return { ok: false, reason: 'bad-quantity' };
  if (kind !== 'Inventaire' && quantity <= 0) return { ok: false, reason: 'bad-quantity' };

  // Une sortie ne peut pas faire passer le stock sous zéro : on ne sort pas ce qu'on n'a pas.
  if (kind === 'Sortie' && quantity > item.stock) return { ok: false, reason: 'insufficient', stock: item.stock };

  db.prepare('INSERT INTO stock_movements (item_id, kind, quantity, reason, moved_on, created_by) VALUES (?, ?, ?, ?, ?, ?)')
    .run(itemId, kind, round(quantity), reason || '', movedOn || new Date().toISOString().slice(0, 10), createdBy);
  return { ok: true };
}

function movements({ itemId, limit = 100 } = {}) {
  const where = itemId ? 'WHERE m.item_id = ?' : '';
  const query = db.prepare(`
    SELECT m.*, i.label AS item_label, i.unit, u.first_name, u.last_name
    FROM stock_movements m
    JOIN items i ON i.id = m.item_id
    LEFT JOIN users u ON u.id = m.created_by
    ${where}
    ORDER BY m.moved_on DESC, m.id DESC
    LIMIT ?
  `);
  return itemId ? query.all(itemId, limit) : query.all(limit);
}

/** Valorisation au dernier prix unitaire connu — la seule que ce module promet. */
function stockValue() {
  return round(items({ activeOnly: true }).reduce((total, i) => total + i.stock * (i.unit_price || 0), 0));
}

// ---------- Demandes d'achat ----------

function requests({ status } = {}) {
  const where = status ? 'WHERE r.status = ?' : '';
  const query = db.prepare(`
    SELECT r.*, u.first_name, u.last_name, u.team_id, u.department_id,
           d.name AS department_name, i.label AS item_label
    FROM purchase_requests r
    JOIN users u ON u.id = r.requester_id
    LEFT JOIN departments d ON d.id = r.department_id
    LEFT JOIN items i ON i.id = r.item_id
    ${where}
    ORDER BY r.created_at DESC
  `);
  return status ? query.all(status) : query.all();
}

function requestById(id) {
  return db.prepare('SELECT * FROM purchase_requests WHERE id = ?').get(id) || null;
}

function requestsFor(employeeId) {
  return db.prepare(`
    SELECT r.*, i.label AS item_label FROM purchase_requests r
    LEFT JOIN items i ON i.id = r.item_id
    WHERE r.requester_id = ? ORDER BY r.created_at DESC
  `).all(employeeId);
}

function createRequest({ requesterId, itemId, label, quantity, estimatedAmount, departmentId, justification }) {
  if (!Number.isFinite(quantity) || quantity <= 0) return { ok: false, reason: 'bad-quantity' };
  if (!Number.isFinite(estimatedAmount) || estimatedAmount < 0) return { ok: false, reason: 'bad-amount' };

  db.prepare(`
    INSERT INTO purchase_requests (requester_id, item_id, label, quantity, estimated_amount, department_id, justification)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(requesterId, itemId || null, label, round(quantity), round(estimatedAmount), departmentId || null, justification || '');
  return { ok: true };
}

/**
 * Validation en deux temps : le manager d'abord, la gestion ensuite si le
 * montant dépasse le seuil. Sous le seuil, l'accord du manager approuve.
 */
function managerDecision(id, approve, reviewerId, note) {
  const request = requestById(id);
  if (!request) return { ok: false, reason: 'not-found' };
  if (request.status !== 'Manager') return { ok: false, reason: 'not-pending' };

  const next = approve ? (request.estimated_amount > FINANCE_THRESHOLD ? 'Gestion' : 'Approuvée') : 'Refusée';
  db.prepare(`
    UPDATE purchase_requests SET status = ?, manager_reviewed_by = ?, manager_reviewed_at = ?, review_note = ?
    WHERE id = ?
  `).run(next, reviewerId, new Date().toISOString(), note || request.review_note, id);
  return { ok: true, status: next };
}

function financeDecision(id, approve, reviewerId, note) {
  const request = requestById(id);
  if (!request) return { ok: false, reason: 'not-found' };
  if (request.status !== 'Gestion') return { ok: false, reason: 'not-pending' };

  db.prepare(`
    UPDATE purchase_requests SET status = ?, finance_reviewed_by = ?, finance_reviewed_at = ?, review_note = ?
    WHERE id = ?
  `).run(approve ? 'Approuvée' : 'Refusée', reviewerId, new Date().toISOString(), note || request.review_note, id);
  return { ok: true };
}

/** Une demande approuvée passe en commande, ce qui entre l'article en stock. */
function markOrdered(id, createdBy) {
  const request = requestById(id);
  if (!request) return { ok: false, reason: 'not-found' };
  if (request.status !== 'Approuvée') return { ok: false, reason: 'not-approved' };

  const commit = db.transaction(() => {
    db.prepare("UPDATE purchase_requests SET status = 'Commandée' WHERE id = ?").run(id);
    if (request.item_id) {
      db.prepare("INSERT INTO stock_movements (item_id, kind, quantity, reason, created_by) VALUES (?, 'Entrée', ?, ?, ?)")
        .run(request.item_id, request.quantity, `Demande d'achat #${id}`, createdBy);
    }
  });
  commit();
  return { ok: true, stocked: Boolean(request.item_id) };
}

function cancelOwnRequest(id, requesterId) {
  return db.prepare("UPDATE purchase_requests SET status = 'Annulée' WHERE id = ? AND requester_id = ? AND status = 'Manager'")
    .run(id, requesterId).changes > 0;
}

module.exports = {
  MOVEMENT_KINDS,
  REQUEST_STATUSES,
  FINANCE_THRESHOLD,
  items,
  itemById,
  createItem,
  toggleItem,
  deleteItem,
  move,
  movements,
  stockValue,
  requests,
  requestById,
  requestsFor,
  createRequest,
  managerDecision,
  financeDecision,
  markOrdered,
  cancelOwnRequest,
};
