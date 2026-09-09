const express = require('express');

const db = require('../db');
const org = require('../org');
const finance = require('../finance');
const inventory = require('../inventory');
const purchasing = require('../purchasing');
const audit = require('../audit');
const modules = require('../modules');
const { requireAuth, requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('stock'), requireAuth);

const back = (anchor) => `/stock#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

/** Les demandes qu'un manager doit arbitrer : celles de ses collaborateurs. */
function requestsForManager(userId) {
  const managed = new Set(org.membersManagedBy(userId).map((m) => m.id));
  return inventory.requests({ status: 'Manager' }).filter((r) => managed.has(r.requester_id));
}

router.get('/', (req, res) => {
  const user = req.session.user;
  const isFinance = res.locals.isFinance || user.role === 'admin';

  res.render('stock', {
    isFinance,
    items: inventory.items(),
    movementKinds: inventory.MOVEMENT_KINDS,
    movements: inventory.movements(),
    stockValue: inventory.stockValue(),
    lowStock: inventory.items({ activeOnly: true }).filter((i) => i.below),
    partners: finance.partners(),
    departments: org.departments(),
    myRequests: inventory.requestsFor(user.id),
    managerRequests: requestsForManager(user.id),
    financeRequests: isFinance ? inventory.requests({ status: 'Gestion' }) : [],
    allRequests: isFinance ? inventory.requests() : [],
    threshold: inventory.FINANCE_THRESHOLD,
    orderList: isFinance ? purchasing.orders() : [],
    purchaseSummary: isFinance ? purchasing.summary() : null,
    discrepancies: isFinance ? purchasing.discrepancies() : [],
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Bons de commande : le rapprochement à trois vit ici ----------

router.get('/commandes/:id', requireFinance, (req, res) => {
  const order = purchasing.orderById(req.params.id);
  if (!order) return res.status(404).render('error', { message: 'Bon de commande introuvable.' });

  res.render('commande', {
    order,
    lineList: purchasing.lines(order.id),
    receiptList: purchasing.receipts(order.id),
    invoiceList: purchasing.invoicesOf(order.id),
    reconciliation: purchasing.match(order),
    statuses: purchasing.ORDER_STATUSES,
    partners: finance.partners(),
    departments: org.departments(),
    items: inventory.items({ activeOnly: true }),
    today: new Date().toISOString().slice(0, 10),
  });
});

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

router.post('/commandes', requireFinance, (req, res) => {
  const partnerId = Number(req.body.partner_id);
  if (!finance.partnerById(partnerId)) return fail(req, res, 'commandes', 'Fournisseur introuvable.');

  const ordered = readDate(req.body.ordered_on, { required: true });
  const expected = readDate(req.body.expected_on);
  if (!ordered.ok || !expected.ok) return fail(req, res, 'commandes', 'Date invalide.');

  const id = purchasing.createOrder({
    partnerId,
    departmentId: Number(req.body.department_id) || null,
    orderedOn: ordered.value,
    expectedOn: expected.value,
    notes: (req.body.notes || '').trim().slice(0, 1000),
    createdBy: req.session.user.id,
  });
  audit.log(req, 'achats.commande_creee', 'purchase_orders', id);
  setFlash(req, 'success', 'Bon de commande créé. Ajoutez ses lignes, puis envoyez-le.');
  res.redirect(`/stock/commandes/${id}`);
});

router.post('/commandes/:id/modifier', requireFinance, (req, res) => {
  const order = purchasing.orderById(req.params.id);
  if (!order) return fail(req, res, 'commandes', 'Bon de commande introuvable.');

  const target = `/stock/commandes/${order.id}`;
  if (!purchasing.ORDER_STATUSES.includes(req.body.status)) {
    setFlash(req, 'error', 'Statut invalide.');
    return res.redirect(target);
  }
  const ordered = readDate(req.body.ordered_on, { required: true });
  const expected = readDate(req.body.expected_on);
  if (!ordered.ok || !expected.ok) {
    setFlash(req, 'error', 'Date invalide.');
    return res.redirect(target);
  }

  purchasing.updateOrder(order.id, {
    partnerId: Number(req.body.partner_id) || order.partner_id,
    departmentId: Number(req.body.department_id) || null,
    orderedOn: ordered.value,
    expectedOn: expected.value,
    notes: (req.body.notes || '').trim().slice(0, 1000),
    status: req.body.status,
  });
  purchasing.syncOrderStatus(order.id);
  setFlash(req, 'success', 'Bon de commande mis à jour.');
  res.redirect(target);
});

router.post('/commandes/:id/supprimer', requireFinance, (req, res) => {
  const order = purchasing.orderById(req.params.id);
  if (!order) return fail(req, res, 'commandes', 'Bon de commande introuvable.');
  // Une commande déjà réceptionnée a laissé des mouvements de stock : la
  // supprimer laisserait ces entrées sans origine.
  if (order.received_amount > 0) return fail(req, res, 'commandes', 'Cette commande a été réceptionnée : elle ne peut plus être supprimée.');

  purchasing.removeOrder(order.id);
  audit.log(req, 'achats.commande_supprimee', 'purchase_orders', order.id, { reference: order.reference });
  setFlash(req, 'success', 'Bon de commande supprimé.');
  res.redirect(back('commandes'));
});

router.post('/commandes/:id/lignes', requireFinance, (req, res) => {
  const order = purchasing.orderById(req.params.id);
  if (!order) return fail(req, res, 'commandes', 'Bon de commande introuvable.');

  const target = `/stock/commandes/${order.id}`;
  const label = (req.body.label || '').trim().slice(0, 160);
  const quantity = parseAmount(req.body.quantity || '');
  const unitPrice = parseAmount(req.body.unit_price || '0');
  if (!label) { setFlash(req, 'error', "L'intitulé de la ligne est obligatoire."); return res.redirect(target); }
  if (!Number.isFinite(quantity) || quantity <= 0) { setFlash(req, 'error', 'Quantité invalide.'); return res.redirect(target); }
  if (!Number.isFinite(unitPrice) || unitPrice < 0) { setFlash(req, 'error', 'Prix unitaire invalide.'); return res.redirect(target); }

  purchasing.addLine({
    orderId: order.id,
    itemId: Number(req.body.item_id) || null,
    label, quantity, unitPrice,
  });
  setFlash(req, 'success', 'Ligne ajoutée.');
  res.redirect(target);
});

router.post('/lignes/:id/supprimer', requireFinance, (req, res) => {
  const line = db.prepare('SELECT * FROM purchase_order_lines WHERE id = ?').get(Number(req.params.id));
  if (!line) return fail(req, res, 'commandes', 'Ligne introuvable.');

  const target = `/stock/commandes/${line.order_id}`;
  if (!purchasing.removeLine(line.id)) {
    setFlash(req, 'error', 'Cette ligne a déjà été réceptionnée : elle ne se retire plus.');
  } else {
    setFlash(req, 'success', 'Ligne retirée.');
  }
  res.redirect(target);
});

router.post('/lignes/:id/reception', requireFinance, (req, res) => {
  const line = db.prepare('SELECT * FROM purchase_order_lines WHERE id = ?').get(Number(req.params.id));
  if (!line) return fail(req, res, 'commandes', 'Ligne introuvable.');

  const target = `/stock/commandes/${line.order_id}`;
  const quantity = parseAmount(req.body.quantity || '');
  const on = readDate(req.body.received_on, { required: true });
  if (!Number.isFinite(quantity) || quantity <= 0) { setFlash(req, 'error', 'Quantité invalide.'); return res.redirect(target); }
  if (!on.ok) { setFlash(req, 'error', 'Date invalide.'); return res.redirect(target); }

  const result = purchasing.receive({
    lineId: line.id, quantity, receivedOn: on.value,
    receivedBy: req.session.user.id, note: (req.body.note || '').trim(),
  });
  if (!result.ok) {
    setFlash(req, 'error', result.reason === 'depassement'
      ? `Il ne reste que ${result.remaining} à recevoir sur cette ligne.`
      : 'Réception impossible.');
    return res.redirect(target);
  }
  audit.log(req, 'achats.reception', 'purchase_order_lines', line.id, { quantite: quantity });
  setFlash(req, 'success', 'Réception enregistrée.');
  res.redirect(target);
});

// ---------- Articles et mouvements : réservés à la gestion ----------

router.post('/articles', requireFinance, (req, res) => {
  const label = (req.body.label || '').trim().slice(0, 160);
  const stockMin = parseAmount(req.body.stock_min || '0');
  const unitPrice = req.body.unit_price ? parseAmount(req.body.unit_price) : null;

  if (!label) return fail(req, res, 'articles', "L'intitulé de l'article est obligatoire.");
  if (!Number.isFinite(stockMin) || stockMin < 0) return fail(req, res, 'articles', "Seuil d'alerte invalide.");
  if (unitPrice !== null && (!Number.isFinite(unitPrice) || unitPrice < 0)) return fail(req, res, 'articles', 'Prix unitaire invalide.');

  inventory.createItem({
    label, stockMin, unitPrice,
    reference: (req.body.reference || '').trim().slice(0, 60),
    unit: (req.body.unit || 'unité').trim().slice(0, 20),
    category: (req.body.category || '').trim().slice(0, 80),
    partnerId: Number(req.body.partner_id) || null,
  });
  setFlash(req, 'success', 'Article créé.');
  res.redirect(back('articles'));
});

router.post('/articles/:id/statut', requireFinance, (req, res) => {
  if (!inventory.toggleItem(Number(req.params.id))) return fail(req, res, 'articles', 'Article introuvable.');
  setFlash(req, 'success', 'Article mis à jour.');
  res.redirect(back('articles'));
});

router.post('/articles/:id/supprimer', requireFinance, (req, res) => {
  inventory.deleteItem(Number(req.params.id));
  setFlash(req, 'success', 'Article supprimé, avec ses mouvements.');
  res.redirect(back('articles'));
});

router.post('/mouvements', requireFinance, (req, res) => {
  const movedOn = (req.body.moved_on || '').trim();
  if (movedOn && !isValidDateString(movedOn)) return fail(req, res, 'mouvements', 'Date de mouvement invalide.');

  const result = inventory.move({
    itemId: Number(req.body.item_id),
    kind: (req.body.kind || '').trim(),
    quantity: parseAmount(req.body.quantity || ''),
    reason: (req.body.reason || '').trim().slice(0, 200),
    movedOn,
    createdBy: req.session.user.id,
  });

  const messages = {
    'bad-kind': 'Type de mouvement invalide.',
    'not-found': 'Article introuvable.',
    'bad-quantity': 'Quantité invalide.',
  };
  if (!result.ok) {
    if (result.reason === 'insufficient') {
      return fail(req, res, 'mouvements', `Stock insuffisant : il reste ${result.stock}.`);
    }
    return fail(req, res, 'mouvements', messages[result.reason] || 'Mouvement impossible.');
  }

  setFlash(req, 'success', 'Mouvement enregistré.');
  res.redirect(back('mouvements'));
});

// ---------- Demandes d'achat ----------

router.post('/demandes', (req, res) => {
  const label = (req.body.label || '').trim().slice(0, 160);
  const quantity = parseAmount(req.body.quantity || '1');
  const estimatedAmount = parseAmount(req.body.estimated_amount || '0');

  if (!label) return fail(req, res, 'demandes', "L'intitulé de la demande est obligatoire.");

  const employee = require('../db').prepare('SELECT department_id FROM users WHERE id = ?').get(req.session.user.id);
  const result = inventory.createRequest({
    requesterId: req.session.user.id,
    itemId: Number(req.body.item_id) || null,
    label, quantity, estimatedAmount,
    departmentId: employee ? employee.department_id : null,
    justification: (req.body.justification || '').trim().slice(0, 1000),
  });

  const messages = { 'bad-quantity': 'Quantité invalide.', 'bad-amount': 'Montant estimé invalide.' };
  if (!result.ok) return fail(req, res, 'demandes', messages[result.reason] || 'Demande impossible.');

  setFlash(req, 'success', estimatedAmount > inventory.FINANCE_THRESHOLD
    ? `Demande envoyée : validation du manager puis de la gestion (au-delà de ${inventory.FINANCE_THRESHOLD} €).`
    : 'Demande envoyée à votre manager.');
  res.redirect(back('demandes'));
});

router.post('/demandes/:id/annuler', (req, res) => {
  if (!inventory.cancelOwnRequest(Number(req.params.id), req.session.user.id)) {
    return fail(req, res, 'demandes', "Cette demande n'est plus annulable.");
  }
  setFlash(req, 'success', 'Demande annulée.');
  res.redirect(back('demandes'));
});

/** Premier niveau : le manager du demandeur, et lui seul. */
router.post('/demandes/:id/manager', (req, res) => {
  const id = Number(req.params.id);
  if (!requestsForManager(req.session.user.id).some((r) => r.id === id)) {
    return fail(req, res, 'demandes', "Cette demande ne relève pas de vos collaborateurs.");
  }

  const result = inventory.managerDecision(id, req.body.decision === 'approuver', req.session.user.id,
    (req.body.review_note || '').trim().slice(0, 500));
  if (!result.ok) return fail(req, res, 'demandes', "Cette demande n'attend plus votre arbitrage.");

  setFlash(req, 'success', result.status === 'Gestion'
    ? 'Accord donné : la demande passe à la gestion pour validation finale.'
    : `Demande ${result.status.toLowerCase()}.`);
  res.redirect(back('demandes'));
});

/** Second niveau : la gestion, au-delà du seuil. */
router.post('/demandes/:id/gestion', requireFinance, (req, res) => {
  const result = inventory.financeDecision(Number(req.params.id), req.body.decision === 'approuver',
    req.session.user.id, (req.body.review_note || '').trim().slice(0, 500));
  if (!result.ok) return fail(req, res, 'demandes', "Cette demande n'attend pas la gestion.");

  setFlash(req, 'success', 'Décision enregistrée.');
  res.redirect(back('demandes'));
});

router.post('/demandes/:id/commander', requireFinance, (req, res) => {
  const result = inventory.markOrdered(Number(req.params.id), req.session.user.id);
  const messages = { 'not-found': 'Demande introuvable.', 'not-approved': "Cette demande n'est pas approuvée." };
  if (!result.ok) return fail(req, res, 'demandes', messages[result.reason] || 'Commande impossible.');

  setFlash(req, 'success', result.stocked
    ? "Demande commandée : l'article est entré en stock."
    : 'Demande commandée.');
  res.redirect(back('demandes'));
});

module.exports = router;
