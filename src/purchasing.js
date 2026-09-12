const db = require('./db');
const inventory = require('./inventory');

/**
 * Achats : bons de commande, réceptions, et rapprochement à trois.
 *
 * Le bon de commande n'est pas la fonction utile — c'est le rapprochement qui
 * l'est. Trois chiffres doivent s'accorder : ce qui a été commandé, ce qui a
 * été effectivement reçu, ce qui est facturé. Quand ils divergent, on paie soit
 * ce qu'on n'a pas commandé, soit ce qui n'est jamais arrivé, et on ne s'en
 * aperçoit qu'à l'inventaire ou au bilan.
 *
 * Le module ne bloque pas le règlement : il n'en a pas le pouvoir, et une
 * livraison partielle facturée d'avance est parfois convenue. Il nomme l'écart
 * au moment où quelqu'un regarde la facture, ce qui suffit à ce qu'on décide.
 */

const ORDER_STATUSES = ['Brouillon', 'Envoyée', 'Reçue partiellement', 'Reçue', 'Annulée'];

const round = (n) => Math.round(n * 100) / 100;

function nextReference() {
  const year = new Date().getFullYear();
  const count = db.prepare('SELECT COUNT(*) AS n FROM purchase_orders WHERE reference LIKE ?').get(`BC-${year}-%`).n;
  return `BC-${year}-${String(count + 1).padStart(4, '0')}`;
}

// ---------------------------------------------------------------- commandes

const ORDER_COLUMNS = `
  o.*, p.name AS partner_name, d.name AS department_name,
  u.first_name, u.last_name,
  (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM purchase_order_lines l WHERE l.order_id = o.id) AS ordered_amount,
  (SELECT COALESCE(SUM(l.received_quantity * l.unit_price), 0) FROM purchase_order_lines l WHERE l.order_id = o.id) AS received_amount,
  (SELECT COALESCE(SUM(i.amount_ht), 0) FROM invoices i WHERE i.purchase_order_id = o.id AND i.status != 'Annulée') AS invoiced_amount
`;

function orders({ includeClosed = true } = {}) {
  const clause = includeClosed ? '' : "WHERE o.status NOT IN ('Reçue','Annulée')";
  return db.prepare(`
    SELECT ${ORDER_COLUMNS}
    FROM purchase_orders o
    JOIN partners p ON p.id = o.partner_id
    LEFT JOIN departments d ON d.id = o.department_id
    LEFT JOIN users u ON u.id = o.created_by
    ${clause}
    ORDER BY o.ordered_on DESC, o.id DESC
  `).all();
}

function orderById(id) {
  return db.prepare(`
    SELECT ${ORDER_COLUMNS}
    FROM purchase_orders o
    JOIN partners p ON p.id = o.partner_id
    LEFT JOIN departments d ON d.id = o.department_id
    LEFT JOIN users u ON u.id = o.created_by
    WHERE o.id = ?
  `).get(Number(id) || 0) || null;
}

function lines(orderId) {
  return db.prepare(`
    SELECT l.*, i.label AS item_name
    FROM purchase_order_lines l LEFT JOIN items i ON i.id = l.item_id
    WHERE l.order_id = ? ORDER BY l.id
  `).all(orderId);
}

function createOrder(fields) {
  return db.prepare(`
    INSERT INTO purchase_orders (reference, partner_id, request_id, department_id, ordered_on, expected_on, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(
    nextReference(), fields.partnerId, fields.requestId || null, fields.departmentId || null,
    fields.orderedOn, fields.expectedOn || null, fields.notes || '', fields.createdBy || null
  ).lastInsertRowid;
}

function updateOrder(id, fields) {
  db.prepare(`
    UPDATE purchase_orders
    SET partner_id = ?, department_id = ?, ordered_on = ?, expected_on = ?, notes = ?, status = ?
    WHERE id = ?
  `).run(fields.partnerId, fields.departmentId || null, fields.orderedOn, fields.expectedOn || null, fields.notes || '', fields.status, id);
}

function removeOrder(id) {
  db.prepare('DELETE FROM purchase_orders WHERE id = ?').run(id);
}

function addLine({ orderId, itemId = null, label, quantity, unitPrice }) {
  return db.prepare(`
    INSERT INTO purchase_order_lines (order_id, item_id, label, quantity, unit_price)
    VALUES (?, ?, ?, ?, ?)
  `).run(orderId, itemId, label, quantity, unitPrice).lastInsertRowid;
}

/** Une ligne déjà réceptionnée ne se retire pas : la réception l'a engagée. */
function removeLine(id) {
  const line = db.prepare('SELECT * FROM purchase_order_lines WHERE id = ?').get(id);
  if (!line || line.received_quantity > 0) return false;
  db.prepare('DELETE FROM purchase_order_lines WHERE id = ?').run(id);
  return true;
}

// ---------------------------------------------------------------- réceptions

function receipts(orderId) {
  return db.prepare(`
    SELECT r.*, l.label, u.first_name, u.last_name
    FROM purchase_receipts r
    JOIN purchase_order_lines l ON l.id = r.line_id
    LEFT JOIN users u ON u.id = r.received_by
    WHERE l.order_id = ? ORDER BY r.received_on DESC, r.id DESC
  `).all(orderId);
}

/**
 * Réceptionne une quantité sur une ligne. Recevoir plus que commandé est refusé :
 * c'est le plus souvent une erreur de saisie, et quand ce n'en est pas une, la
 * commande doit être corrigée pour que le rapprochement garde un sens.
 *
 * Une ligne rattachée à un article entre aussi en stock, dans le même geste :
 * ressaisir la même réception deux fois est le meilleur moyen de ne jamais
 * savoir ce qu'on a.
 */
const receive = db.transaction(({ lineId, quantity, receivedOn, receivedBy, note = '' }) => {
  const line = db.prepare('SELECT * FROM purchase_order_lines WHERE id = ?').get(lineId);
  if (!line) return { ok: false, reason: 'introuvable' };

  const remaining = round(line.quantity - line.received_quantity);
  if (quantity > remaining) return { ok: false, reason: 'depassement', remaining };

  db.prepare(`
    INSERT INTO purchase_receipts (line_id, quantity, received_on, received_by, note)
    VALUES (?, ?, ?, ?, ?)
  `).run(lineId, quantity, receivedOn, receivedBy || null, note.slice(0, 300));
  db.prepare('UPDATE purchase_order_lines SET received_quantity = received_quantity + ? WHERE id = ?').run(quantity, lineId);

  if (line.item_id) {
    inventory.move({
      itemId: line.item_id,
      kind: 'Entrée',
      quantity,
      reason: `Réception ${db.prepare('SELECT reference FROM purchase_orders WHERE id = ?').get(line.order_id).reference}`,
      movedOn: receivedOn,
      createdBy: receivedBy || null,
    });
  }

  syncOrderStatus(line.order_id);
  return { ok: true };
});

/** Le statut suit les réceptions : il n'est pas à tenir à la main. */
function syncOrderStatus(orderId) {
  const order = db.prepare('SELECT status FROM purchase_orders WHERE id = ?').get(orderId);
  if (!order || order.status === 'Annulée' || order.status === 'Brouillon') return;

  const rows = db.prepare('SELECT quantity, received_quantity FROM purchase_order_lines WHERE order_id = ?').all(orderId);
  if (!rows.length) return;

  const complete = rows.every((l) => l.received_quantity >= l.quantity);
  const started = rows.some((l) => l.received_quantity > 0);
  const status = complete ? 'Reçue' : started ? 'Reçue partiellement' : 'Envoyée';
  db.prepare('UPDATE purchase_orders SET status = ? WHERE id = ?').run(status, orderId);
}

// ---------------------------------------------------------------- rapprochement

/**
 * Le rapprochement à trois, rendu sous forme d'écarts nommés plutôt que d'un
 * verdict binaire. Trois situations méritent d'être distinguées :
 *
 * - facturé au-delà du commandé : écart de prix ou ligne ajoutée sans commande ;
 * - facturé au-delà du reçu : on règle ce qui n'est pas encore arrivé ;
 * - reçu au-delà du facturé : la facture reste à venir, ce qui est normal.
 */
function match(order) {
  const ordered = round(order.ordered_amount);
  const received = round(order.received_amount);
  const invoiced = round(order.invoiced_amount);

  const issues = [];
  if (invoiced > ordered + 0.01) issues.push({ kind: 'sur_commande', gap: round(invoiced - ordered) });
  if (invoiced > received + 0.01) issues.push({ kind: 'sur_reception', gap: round(invoiced - received) });

  return {
    ordered,
    received,
    invoiced,
    pending: round(received - invoiced),
    issues,
    ok: issues.length === 0,
  };
}

/**
 * Les factures fournisseur qu'on peut encore rattacher à cette commande :
 * celles du même tiers, ou sans tiers, qui ne sont rattachées nulle part.
 * Une facture client n'a rien à faire ici : le bon de commande est celui que
 * nous avons passé, pas celui d'un client.
 */
function attachableInvoices(order) {
  return db.prepare(`
    SELECT id, reference, label, issue_date, amount_ht, status
    FROM invoices
    WHERE direction = 'Fournisseur' AND purchase_order_id IS NULL
      AND status != 'Annulée'
      AND (partner_id IS NULL OR partner_id = ?)
    ORDER BY issue_date DESC, id DESC
  `).all(order.partner_id);
}

/**
 * Rattache une facture à un bon de commande : c'est ce rattachement, et lui
 * seul, qui donne au rapprochement à trois de quoi comparer.
 */
function attachInvoice(orderId, invoiceId) {
  const order = db.prepare('SELECT * FROM purchase_orders WHERE id = ?').get(orderId);
  const invoice = db.prepare('SELECT * FROM invoices WHERE id = ?').get(invoiceId);
  if (!order || !invoice) return { ok: false, reason: 'introuvable' };
  if (invoice.direction !== 'Fournisseur') return { ok: false, reason: 'sens' };
  if (invoice.purchase_order_id && invoice.purchase_order_id !== order.id) return { ok: false, reason: 'deja_rattachee' };
  // Le tiers de la facture doit être celui de la commande : rapprocher la
  // facture d'un autre fournisseur ne compare rien.
  if (invoice.partner_id && invoice.partner_id !== order.partner_id) return { ok: false, reason: 'tiers' };

  db.prepare('UPDATE invoices SET purchase_order_id = ? WHERE id = ?').run(order.id, invoice.id);
  return { ok: true };
}

function detachInvoice(invoiceId) {
  return db.prepare('UPDATE invoices SET purchase_order_id = NULL WHERE id = ?').run(invoiceId).changes > 0;
}

function invoicesOf(orderId) {
  return db.prepare(`
    SELECT id, reference, label, issue_date, amount_ht, status
    FROM invoices WHERE purchase_order_id = ? ORDER BY issue_date, id
  `).all(orderId);
}

/** Les commandes dont la facturation s'écarte de ce qui a été commandé ou reçu. */
function discrepancies() {
  return orders()
    .filter((order) => order.status !== 'Annulée' && order.invoiced_amount > 0)
    .map((order) => ({ order, match: match(order) }))
    .filter((row) => !row.match.ok);
}

function summary() {
  const open = db.prepare("SELECT COUNT(*) AS n FROM purchase_orders WHERE status IN ('Envoyée','Reçue partiellement')").get().n;
  const engaged = db.prepare(`
    SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) AS total
    FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id
    WHERE o.status IN ('Envoyée','Reçue partiellement')
  `).get().total;
  const awaited = db.prepare(`
    SELECT COALESCE(SUM((l.quantity - l.received_quantity) * l.unit_price), 0) AS total
    FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id
    WHERE o.status IN ('Envoyée','Reçue partiellement')
  `).get().total;
  return { open, engaged: round(engaged), awaited: round(awaited), discrepancies: discrepancies().length };
}

module.exports = {
  ORDER_STATUSES,
  orders,
  orderById,
  lines,
  createOrder,
  updateOrder,
  removeOrder,
  addLine,
  removeLine,
  receipts,
  receive,
  syncOrderStatus,
  match,
  invoicesOf,
  attachableInvoices,
  attachInvoice,
  detachInvoice,
  discrepancies,
  summary,
};
