const db = require('./db');
const societes = require('./societes');
const currency = require('./currency');

/**
 * Le cycle de vente : le catalogue, la commande, la livraison, la facture.
 *
 * Une commande ne recopie jamais ce qui a été livré ou facturé : ces quantités
 * se calculent, ligne par ligne, sur les bons de livraison et sur les factures
 * qui ne sont pas annulées. Une facture annulée rend donc sa quantité d'elle-
 * même, et un chiffre ne peut pas dire autre chose que les pièces.
 *
 * La facture reste ce qu'elle est dans le produit : un montant hors taxes et un
 * taux. Une commande qui mêle les taux en fait donc une par taux — c'est la
 * façon honnête de ne pas inventer une TVA moyenne.
 */

const ici = () => societes.courant();
const round2 = (n) => Math.round(n * 100) / 100;
const round3 = (n) => Math.round(n * 1000) / 1000;
const today = () => new Date().toISOString().slice(0, 10);

const KINDS = ['Service', 'Bien'];
const UNITS = ['unité', 'heure', 'jour', 'forfait', 'mois', 'kg', 'm', 'm²', 'litre'];

// ---------------------------------------------------------------- catalogue

function produits({ actifs = false } = {}) {
  return db.prepare(`
    SELECT p.*, i.label AS item_label FROM products p
    LEFT JOIN items i ON i.id = p.item_id
    WHERE p.company_id = ? ${actifs ? 'AND p.active = 1' : ''}
    ORDER BY p.active DESC, p.reference COLLATE NOCASE
  `).all(ici());
}

function produitParId(id) {
  return db.prepare('SELECT * FROM products WHERE id = ? AND company_id = ?').get(Number(id) || 0, ici()) || null;
}

function creerProduit({ reference, name, description = '', kind = 'Service', unit = 'unité', priceHt, vatRate = 20, itemId = null }) {
  const ref = String(reference || '').trim();
  const nom = String(name || '').trim();
  if (!/^[A-Za-z0-9._-]{1,40}$/.test(ref)) return { ok: false, reason: 'reference' };
  if (!nom) return { ok: false, reason: 'name' };
  if (!KINDS.includes(kind)) return { ok: false, reason: 'kind' };
  if (!UNITS.includes(unit)) return { ok: false, reason: 'unit' };
  if (!Number.isFinite(priceHt) || priceHt < 0 || priceHt > 1e9) return { ok: false, reason: 'price' };
  if (!Number.isFinite(vatRate) || vatRate < 0 || vatRate > 100) return { ok: false, reason: 'vat' };
  // Un article de stock ne se lie qu'à un bien, et seulement de la même société.
  let article = null;
  if (itemId) {
    if (kind !== 'Bien') return { ok: false, reason: 'item-service' };
    article = db.prepare('SELECT id FROM items WHERE id = ? AND company_id = ?').get(Number(itemId), ici());
    if (!article) return { ok: false, reason: 'item' };
  }
  if (db.prepare('SELECT 1 AS ok FROM products WHERE reference = ? AND company_id = ?').get(ref, ici())) {
    return { ok: false, reason: 'duplicate' };
  }
  const id = db.prepare(`
    INSERT INTO products (company_id, reference, name, description, kind, unit, price_ht, vat_rate, item_id)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), ref, nom.slice(0, 160), String(description).slice(0, 1000), kind, unit, round2(priceHt), vatRate,
    article ? article.id : null).lastInsertRowid;
  return { ok: true, id };
}

function basculerProduit(id) {
  return db.prepare('UPDATE products SET active = 1 - active WHERE id = ? AND company_id = ?').run(Number(id) || 0, ici()).changes > 0;
}

/** Un produit déjà commandé ne s'efface pas : il se retire du catalogue. */
function supprimerProduit(id) {
  const produit = produitParId(id);
  if (!produit) return { ok: false, reason: 'not-found' };
  if (db.prepare('SELECT 1 AS ok FROM sales_order_lines WHERE product_id = ?').get(produit.id)) {
    db.prepare('UPDATE products SET active = 0 WHERE id = ? AND company_id = ?').run(produit.id, ici());
    return { ok: true, deactivated: true };
  }
  db.prepare('DELETE FROM products WHERE id = ? AND company_id = ?').run(produit.id, ici());
  return { ok: true, deactivated: false };
}

// ---------------------------------------------------------------- commandes

function prochaineReference(prefixe, table, date) {
  const annee = String(date).slice(0, 4);
  const derniere = db.prepare(`
    SELECT reference FROM ${table} WHERE company_id = ? AND reference LIKE ? ORDER BY id DESC LIMIT 1
  `).get(ici(), `${prefixe}-${annee}-%`);
  const n = derniere ? Number(derniere.reference.split('-').pop()) + 1 : 1;
  return `${prefixe}-${annee}-${String(n).padStart(4, '0')}`;
}

/** Les lignes d'une commande, avec ce qui en est livré et facturé — calculé. */
function lignes(orderId) {
  return db.prepare(`
    SELECT l.*, p.reference AS product_reference, p.item_id,
      COALESCE((SELECT SUM(d.quantity) FROM delivery_note_lines d WHERE d.order_line_id = l.id), 0) AS delivered,
      COALESCE((SELECT SUM(s.quantity) FROM sales_invoice_lines s JOIN invoices f ON f.id = s.invoice_id
                WHERE s.order_line_id = l.id AND f.status <> 'Annulée'), 0) AS invoiced
    FROM sales_order_lines l
    JOIN sales_orders o ON o.id = l.order_id
    LEFT JOIN products p ON p.id = l.product_id
    WHERE l.order_id = ? AND o.company_id = ?
    ORDER BY l.position, l.id
  `).all(orderId, ici()).map((l) => ({
    ...l,
    delivered: round3(l.delivered),
    invoiced: round3(l.invoiced),
    remaining: round3(l.quantity - l.delivered),
    toInvoice: round3(l.delivered - l.invoiced),
    total: round2(l.quantity * l.unit_price_ht),
  }));
}

/** L'état d'une commande, lu sur ses lignes : la décision, puis l'avancement. */
function etat(commande, ls) {
  if (commande.status !== 'Confirmée') return commande.status;
  const commande_ = ls.reduce((s, l) => s + l.quantity, 0);
  const livre = ls.reduce((s, l) => s + l.delivered, 0);
  const facture = ls.reduce((s, l) => s + l.invoiced, 0);
  if (commande_ > 0 && facture >= commande_) return 'Facturée';
  if (commande_ > 0 && livre >= commande_) return 'Livrée';
  if (livre > 0) return 'Partiellement livrée';
  return 'Confirmée';
}

function enrichir(commande) {
  const ls = lignes(commande.id);
  const totalHt = round2(ls.reduce((s, l) => s + l.total, 0));
  const totalTva = round2(ls.reduce((s, l) => s + l.total * (l.vat_rate / 100), 0));
  return { ...commande, lignes: ls, etat: etat(commande, ls), totalHt, totalTtc: round2(totalHt + totalTva) };
}

function commandes() {
  return db.prepare(`
    SELECT o.*, p.name AS partner_name FROM sales_orders o JOIN partners p ON p.id = o.partner_id
    WHERE o.company_id = ? ORDER BY o.order_date DESC, o.id DESC
  `).all(ici()).map(enrichir);
}

function commandeParId(id) {
  const commande = db.prepare(`
    SELECT o.*, p.name AS partner_name FROM sales_orders o JOIN partners p ON p.id = o.partner_id
    WHERE o.id = ? AND o.company_id = ?
  `).get(Number(id) || 0, ici());
  if (!commande) return null;
  return {
    ...enrichir(commande),
    livraisons: db.prepare(`
      SELECT n.*, (SELECT COUNT(*) FROM delivery_note_lines d WHERE d.note_id = n.id) AS line_count
      FROM delivery_notes n WHERE n.order_id = ? AND n.company_id = ? ORDER BY n.id
    `).all(commande.id, ici()),
    factures: db.prepare(`
      SELECT id, reference, label, issue_date, amount_ht, vat_rate, status FROM invoices
      WHERE sales_order_id = ? AND company_id = ? ORDER BY id
    `).all(commande.id, ici()),
  };
}

function creerCommande({ partnerId, orderDate = today(), notes = '', quoteId = null, createdBy = null }) {
  if (!db.prepare('SELECT 1 AS ok FROM partners WHERE id = ? AND company_id = ?').get(Number(partnerId) || 0, ici())) {
    return { ok: false, reason: 'partner' };
  }
  if (!/^\d{4}-\d{2}-\d{2}$/.test(orderDate)) return { ok: false, reason: 'date' };
  const reference = prochaineReference('CMD', 'sales_orders', orderDate);
  const id = db.prepare(`
    INSERT INTO sales_orders (company_id, reference, partner_id, quote_id, order_date, notes, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  `).run(ici(), reference, Number(partnerId), quoteId, orderDate, String(notes).slice(0, 1000), createdBy).lastInsertRowid;
  return { ok: true, id, reference };
}

function commandeEnBrouillon(orderId) {
  const c = db.prepare('SELECT * FROM sales_orders WHERE id = ? AND company_id = ?').get(Number(orderId) || 0, ici());
  if (!c) return { erreur: { ok: false, reason: 'not-found' } };
  if (c.status !== 'Brouillon') return { erreur: { ok: false, reason: 'not-draft' } };
  return { commande: c };
}

function ajouterLigne(orderId, { productId = null, label = '', quantity, unitPriceHt = null, vatRate = null }) {
  const { commande, erreur } = commandeEnBrouillon(orderId);
  if (erreur) return erreur;
  const produit = productId ? produitParId(productId) : null;
  if (productId && (!produit || !produit.active)) return { ok: false, reason: 'product' };
  const libelle = String(label || '').trim() || (produit ? produit.name : '');
  const prix = unitPriceHt === null || unitPriceHt === undefined ? (produit ? produit.price_ht : NaN) : unitPriceHt;
  const tva = vatRate === null || vatRate === undefined ? (produit ? produit.vat_rate : 20) : vatRate;
  if (!libelle) return { ok: false, reason: 'label' };
  if (!Number.isFinite(quantity) || quantity <= 0 || quantity > 1e7) return { ok: false, reason: 'quantity' };
  if (!Number.isFinite(prix) || prix < 0 || prix > 1e9) return { ok: false, reason: 'price' };
  if (!Number.isFinite(tva) || tva < 0 || tva > 100) return { ok: false, reason: 'vat' };
  const position = db.prepare('SELECT COALESCE(MAX(position), 0) + 1 AS n FROM sales_order_lines WHERE order_id = ?').get(commande.id).n;
  const id = db.prepare(`
    INSERT INTO sales_order_lines (order_id, product_id, label, quantity, unit, unit_price_ht, vat_rate, position)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(commande.id, produit ? produit.id : null, libelle.slice(0, 200), round3(quantity), produit ? produit.unit : 'unité',
    round2(prix), tva, position).lastInsertRowid;
  return { ok: true, id };
}

function retirerLigne(lineId) {
  const ligne = db.prepare(`
    SELECT l.id, l.order_id FROM sales_order_lines l JOIN sales_orders o ON o.id = l.order_id
    WHERE l.id = ? AND o.company_id = ?
  `).get(Number(lineId) || 0, ici());
  if (!ligne) return { ok: false, reason: 'not-found' };
  const { erreur } = commandeEnBrouillon(ligne.order_id);
  if (erreur) return erreur;
  db.prepare('DELETE FROM sales_order_lines WHERE id = ?').run(ligne.id);
  return { ok: true, orderId: ligne.order_id };
}

function confirmer(orderId) {
  const { commande, erreur } = commandeEnBrouillon(orderId);
  if (erreur) return erreur;
  if (!db.prepare('SELECT 1 AS ok FROM sales_order_lines WHERE order_id = ?').get(commande.id)) return { ok: false, reason: 'empty' };
  db.prepare("UPDATE sales_orders SET status = 'Confirmée' WHERE id = ? AND company_id = ?").run(commande.id, ici());
  return { ok: true };
}

/** Une commande s'annule tant que rien n'en est parti : ni livraison, ni facture. */
function annuler(orderId) {
  const c = commandeParId(orderId);
  if (!c) return { ok: false, reason: 'not-found' };
  if (c.status === 'Annulée') return { ok: false, reason: 'already' };
  if (c.lignes.some((l) => l.delivered > 0 || l.invoiced > 0)) return { ok: false, reason: 'started' };
  db.prepare("UPDATE sales_orders SET status = 'Annulée' WHERE id = ? AND company_id = ?").run(c.id, ici());
  return { ok: true };
}

/** Un devis accepté devient une commande d'une ligne, s'il n'a pas déjà été facturé ni commandé. */
function depuisDevis(quoteId, createdBy = null) {
  const devis = db.prepare('SELECT * FROM quotes WHERE id = ? AND company_id = ?').get(Number(quoteId) || 0, ici());
  if (!devis) return { ok: false, reason: 'not-found' };
  if (devis.status !== 'Accepté') return { ok: false, reason: 'not-accepted' };
  if (devis.invoice_id) return { ok: false, reason: 'already-invoiced' };
  if (db.prepare("SELECT 1 AS ok FROM sales_orders WHERE quote_id = ? AND company_id = ? AND status <> 'Annulée'").get(devis.id, ici())) {
    return { ok: false, reason: 'already-ordered' };
  }
  const faire = db.transaction(() => {
    const commande = creerCommande({ partnerId: devis.partner_id, quoteId: devis.id, createdBy,
      notes: `Issue du devis ${devis.reference || '#' + devis.id}` });
    if (!commande.ok) return commande;
    ajouterLigne(commande.id, { label: devis.label, quantity: 1, unitPriceHt: devis.amount_ht, vatRate: devis.vat_rate });
    return commande;
  });
  return faire();
}

// ---------------------------------------------------------------- livraisons

/**
 * Livrer : un bon de livraison pour les quantités données, ligne par ligne.
 *
 * Chaque quantité est au plus ce qui reste à livrer. Un bien lié au stock en
 * sort d'autant ; si le stock n'y suffit pas pour une seule ligne, rien n'est
 * livré — un bon à moitié écrit serait pire qu'un refus.
 */
function livrer(orderId, quantites, { date = today(), createdBy = null } = {}) {
  const inventory = require('./inventory');
  const c = commandeParId(orderId);
  if (!c) return { ok: false, reason: 'not-found' };
  if (c.status !== 'Confirmée') return { ok: false, reason: 'not-confirmed' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date > today()) return { ok: false, reason: 'date' };

  const retenues = [];
  for (const l of c.lignes) {
    const brut = quantites[l.id];
    if (brut === undefined || brut === '' || brut === null) continue;
    const q = round3(Number(String(brut).replace(',', '.')));
    if (!Number.isFinite(q) || q < 0) return { ok: false, reason: 'quantity', line: l.label };
    if (q === 0) continue;
    if (q > l.remaining) return { ok: false, reason: 'too-much', line: l.label, remaining: l.remaining };
    retenues.push({ ligne: l, quantite: q });
  }
  if (retenues.length === 0) return { ok: false, reason: 'nothing' };

  // Le stock d'abord, pour tout : on ne sort rien tant que tout n'est pas là.
  const parArticle = new Map();
  for (const { ligne, quantite } of retenues) {
    if (ligne.item_id) parArticle.set(ligne.item_id, (parArticle.get(ligne.item_id) || 0) + quantite);
  }
  for (const [itemId, total] of parArticle) {
    const article = inventory.itemById(itemId);
    if (article && total > article.stock) return { ok: false, reason: 'stock', item: article.label, stock: article.stock };
  }

  const faire = db.transaction(() => {
    const reference = prochaineReference('BL', 'delivery_notes', date);
    const noteId = db.prepare(`
      INSERT INTO delivery_notes (company_id, order_id, reference, delivered_on, created_by) VALUES (?, ?, ?, ?, ?)
    `).run(ici(), c.id, reference, date, createdBy).lastInsertRowid;
    const poser = db.prepare('INSERT INTO delivery_note_lines (note_id, order_line_id, quantity) VALUES (?, ?, ?)');
    for (const { ligne, quantite } of retenues) {
      poser.run(noteId, ligne.id, quantite);
      if (ligne.item_id) {
        const sortie = inventory.move({ itemId: ligne.item_id, kind: 'Sortie', quantity: quantite,
          reason: `${reference} — ${c.reference}`, movedOn: date, createdBy });
        if (!sortie.ok) throw new Error(`stock:${ligne.label}`);
      }
    }
    return { noteId, reference };
  });
  try {
    const { noteId, reference } = faire();
    return { ok: true, id: noteId, reference, lignes: retenues.length };
  } catch (err) {
    if (String(err.message).startsWith('stock:')) return { ok: false, reason: 'stock', item: err.message.slice(6) };
    throw err;
  }
}

function bonDeLivraison(id) {
  const note = db.prepare(`
    SELECT n.*, o.reference AS order_reference, o.partner_id, p.name AS partner_name, p.address AS partner_address
    FROM delivery_notes n JOIN sales_orders o ON o.id = n.order_id JOIN partners p ON p.id = o.partner_id
    WHERE n.id = ? AND n.company_id = ?
  `).get(Number(id) || 0, ici());
  if (!note) return null;
  note.lignes = db.prepare(`
    SELECT d.quantity, l.label, l.unit, pr.reference AS product_reference
    FROM delivery_note_lines d JOIN sales_order_lines l ON l.id = d.order_line_id
    LEFT JOIN products pr ON pr.id = l.product_id
    WHERE d.note_id = ? ORDER BY l.position, l.id
  `).all(note.id);
  note.societe = db.prepare('SELECT name, registration FROM companies WHERE id = ?').get(ici()) || {};
  return note;
}

// ---------------------------------------------------------------- facturation

/**
 * Facturer ce qui est livré et pas encore facturé : une facture par taux de
 * TVA, en devise de référence, à trente jours. Une prestation se « livre »
 * comme un bien — c'est le bon qui atteste qu'elle a été rendue.
 */
function facturer(orderId, { date = today(), createdBy = null } = {}) {
  const finance = require('./finance');
  const c = commandeParId(orderId);
  if (!c) return { ok: false, reason: 'not-found' };
  if (c.status !== 'Confirmée') return { ok: false, reason: 'not-confirmed' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) return { ok: false, reason: 'date' };
  const aFacturer = c.lignes.filter((l) => l.toInvoice > 0);
  if (aFacturer.length === 0) return { ok: false, reason: 'nothing' };

  const parTaux = new Map();
  for (const l of aFacturer) {
    if (!parTaux.has(l.vat_rate)) parTaux.set(l.vat_rate, []);
    parTaux.get(l.vat_rate).push(l);
  }
  const echeance = new Date(`${date}T00:00:00Z`);
  echeance.setUTCDate(echeance.getUTCDate() + 30);

  const faire = db.transaction(() => {
    const deja = db.prepare('SELECT COUNT(*) AS n FROM invoices WHERE sales_order_id = ? AND company_id = ?').get(c.id, ici()).n;
    const ids = [];
    let rang = deja;
    for (const [taux, ls] of parTaux) {
      rang += 1;
      const montant = round2(ls.reduce((s, l) => s + l.toInvoice * l.unit_price_ht, 0));
      const id = finance.createInvoice({
        direction: 'Client', partnerId: c.partner_id, reference: `${c.reference}-F${rang}`,
        label: `Commande ${c.reference} — ${ls.map((l) => l.label).join(', ')}`.slice(0, 200),
        issueDate: date, dueDate: echeance.toISOString().slice(0, 10), amountHt: montant, vatRate: taux,
        status: 'Émise', notes: parTaux.size > 1 ? `TVA ${taux} % — une facture par taux` : '',
        createdBy, currency: currency.base(), exchangeRate: 1,
      });
      db.prepare('UPDATE invoices SET sales_order_id = ? WHERE id = ? AND company_id = ?').run(c.id, id, ici());
      const lier = db.prepare('INSERT INTO sales_invoice_lines (invoice_id, order_line_id, quantity) VALUES (?, ?, ?)');
      for (const l of ls) lier.run(id, l.id, l.toInvoice);
      ids.push(id);
    }
    return ids;
  });
  const ids = faire();
  return { ok: true, invoiceIds: ids };
}

module.exports = {
  KINDS, UNITS,
  produits, produitParId, creerProduit, basculerProduit, supprimerProduit,
  commandes, commandeParId, creerCommande, ajouterLigne, retirerLigne, confirmer, annuler, depuisDevis,
  livrer, bonDeLivraison, facturer,
};
