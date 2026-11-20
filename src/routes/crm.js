const express = require('express');

const db = require('../db');
const crm = require('../crm');
const ventes = require('../ventes');
const finance = require('../finance');
const modules = require('../modules');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidEmail, parseAmount } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('crm'), requireFinance);

const back = (anchor) => `/crm#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

router.get('/', (req, res) => {
  res.render('crm', {
    clients: finance.partners({ kind: 'Client' }),
    contacts: crm.contacts(),
    opportunities: crm.opportunities(),
    stages: crm.STAGES,
    lostReasons: crm.LOST_REASONS,
    // Le cycle de vente : le catalogue et les commandes.
    produits: ventes.produits(),
    produitsActifs: ventes.produits({ actifs: true }),
    productKinds: ventes.KINDS,
    productUnits: ventes.UNITS,
    articles: require('../inventory').items({ activeOnly: true }),
    commandes: ventes.commandes(),
    openStages: crm.OPEN_STAGES,
    pipeline: crm.pipeline(),
    quotes: crm.quotes(),
    quoteStatuses: crm.QUOTE_STATUSES,
    activities: crm.activities(),
    activityKinds: crm.ACTIVITY_KINDS,
    employees: db.prepare("SELECT * FROM users WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE").all(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Contacts ----------

router.post('/contacts', (req, res) => {
  const firstName = (req.body.first_name || '').trim().slice(0, 100);
  const lastName = (req.body.last_name || '').trim().slice(0, 100);
  const email = (req.body.email || '').trim().slice(0, 254);

  if (!firstName || !lastName) return fail(req, res, 'contacts', 'Prénom et nom sont obligatoires.');
  if (email && !isValidEmail(email)) return fail(req, res, 'contacts', 'Adresse email invalide.');

  const result = crm.createContact({
    partnerId: Number(req.body.partner_id),
    firstName, lastName, email,
    role: (req.body.role || '').trim().slice(0, 100),
    phone: (req.body.phone || '').trim().slice(0, 40),
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  if (!result.ok) return fail(req, res, 'contacts', 'Client introuvable.');

  setFlash(req, 'success', 'Contact enregistré.');
  res.redirect(back('contacts'));
});

router.post('/contacts/:id/supprimer', (req, res) => {
  if (!crm.deleteContact(Number(req.params.id))) return fail(req, res, 'contacts', 'Contact introuvable.');

  setFlash(req, 'success', 'Contact supprimé.');
  res.redirect(back('contacts'));
});

// ---------- Opportunités ----------

router.post('/opportunites', (req, res) => {
  const title = (req.body.title || '').trim().slice(0, 160);
  const amount = parseAmount(req.body.amount || '0');
  const probability = Number(req.body.probability);
  const expectedClose = (req.body.expected_close || '').trim();

  if (!title) return fail(req, res, 'pipeline', "L'intitulé de l'affaire est obligatoire.");
  if (expectedClose && !isValidDateString(expectedClose)) return fail(req, res, 'pipeline', 'Date de clôture prévue invalide.');

  const result = crm.createOpportunity({
    partnerId: Number(req.body.partner_id),
    title, amount, probability, expectedClose,
    ownerId: Number(req.body.owner_id) || null,
    notes: (req.body.notes || '').trim().slice(0, 2000),
  });

  const messages = {
    'no-partner': 'Client introuvable.',
    'bad-amount': 'Montant invalide.',
    'bad-probability': 'Probabilité invalide (0 à 100 %).',
  };
  if (!result.ok) return fail(req, res, 'pipeline', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', 'Affaire ajoutée au pipeline.');
  res.redirect(back('pipeline'));
});

router.post('/opportunites/:id/etape', (req, res) => {
  const result = crm.setStage(Number(req.params.id), (req.body.stage || '').trim(), (req.body.lost_reason || '').trim());
  if (!result.ok && result.reason === 'need-reason') {
    return fail(req, res, 'pipeline', "Une affaire perdue dit pourquoi : choisissez la raison de la perte.");
  }
  if (!result.ok) return fail(req, res, 'pipeline', 'Étape invalide ou affaire introuvable.');

  setFlash(req, 'success', 'Étape mise à jour.');
  res.redirect(back('pipeline'));
});

router.post('/opportunites/:id/supprimer', (req, res) => {
  if (!crm.deleteOpportunity(Number(req.params.id))) return fail(req, res, 'pipeline', 'Affaire introuvable.');

  setFlash(req, 'success', 'Affaire supprimée.');
  res.redirect(back('pipeline'));
});

// ---------- Devis ----------

router.post('/devis', (req, res) => {
  const label = (req.body.label || '').trim().slice(0, 160);
  const issueDate = (req.body.issue_date || '').trim();
  const validUntil = (req.body.valid_until || '').trim();

  if (!label) return fail(req, res, 'devis', "L'intitulé du devis est obligatoire.");
  if (!isValidDateString(issueDate)) return fail(req, res, 'devis', "Date d'émission invalide.");
  if (validUntil && !isValidDateString(validUntil)) return fail(req, res, 'devis', 'Date de validité invalide.');

  const result = crm.createQuote({
    partnerId: Number(req.body.partner_id),
    opportunityId: Number(req.body.opportunity_id) || null,
    reference: (req.body.reference || '').trim().slice(0, 60),
    label, issueDate, validUntil,
    amountHt: parseAmount(req.body.amount_ht || '0'),
    vatRate: Number(req.body.vat_rate),
    createdBy: req.session.user.id,
  });

  const messages = {
    'no-partner': 'Client introuvable.',
    'bad-amount': 'Montant HT invalide.',
    'bad-vat': 'Taux de TVA invalide.',
    'bad-validity': "La date de validité précède l'émission.",
  };
  if (!result.ok) return fail(req, res, 'devis', messages[result.reason] || 'Création impossible.');

  setFlash(req, 'success', 'Devis enregistré.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/statut', (req, res) => {
  if (!crm.setQuoteStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return fail(req, res, 'devis', 'Statut invalide ou devis introuvable.');
  }
  setFlash(req, 'success', 'Devis mis à jour.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/facturer', (req, res) => {
  const result = crm.convertToInvoice(Number(req.params.id), req.session.user.id);
  const messages = {
    'not-found': 'Devis introuvable.',
    'not-accepted': "Seul un devis accepté se transforme en facture.",
    'already-invoiced': 'Ce devis a déjà été facturé.',
    ordered: 'Ce devis est passé en commande : il se facture par sa commande, au fil des livraisons.',
  };
  if (!result.ok) return fail(req, res, 'devis', messages[result.reason] || 'Facturation impossible.');

  setFlash(req, 'success', 'Facture client créée depuis le devis. Elle est visible dans la gestion.');
  res.redirect(back('devis'));
});

router.post('/devis/:id/supprimer', (req, res) => {
  if (!crm.deleteQuote(Number(req.params.id))) return fail(req, res, 'devis', 'Devis introuvable.');

  setFlash(req, 'success', 'Devis supprimé.');
  res.redirect(back('devis'));
});

// ---------- Relances ----------

router.post('/relances', (req, res) => {
  const dueOn = (req.body.due_on || '').trim();
  if (!isValidDateString(dueOn)) return fail(req, res, 'relances', "Date d'échéance invalide.");

  const result = crm.createActivity({
    partnerId: Number(req.body.partner_id) || null,
    opportunityId: Number(req.body.opportunity_id) || null,
    kind: (req.body.kind || 'Relance').trim(),
    dueOn,
    note: (req.body.note || '').trim().slice(0, 1000),
    ownerId: Number(req.body.owner_id) || req.session.user.id,
  });
  if (!result.ok) return fail(req, res, 'relances', 'Une relance vise un client ou une affaire.');

  setFlash(req, 'success', 'Relance planifiée.');
  res.redirect(back('relances'));
});

router.post('/relances/:id/faite', (req, res) => {
  if (!crm.completeActivity(Number(req.params.id))) return fail(req, res, 'relances', 'Relance introuvable ou déjà faite.');
  setFlash(req, 'success', 'Relance marquée comme faite.');
  res.redirect(back('relances'));
});

router.post('/relances/:id/supprimer', (req, res) => {
  if (!crm.deleteActivity(Number(req.params.id))) return fail(req, res, 'relances', 'Relance introuvable.');

  setFlash(req, 'success', 'Relance supprimée.');
  res.redirect(back('relances'));
});

// ---------- Catalogue ----------

const MESSAGES_PRODUIT = {
  reference: 'Référence invalide : 1 à 40 caractères, lettres, chiffres, point, tiret.',
  name: 'Le nom du produit est obligatoire.',
  kind: 'Type invalide.',
  unit: 'Unité invalide.',
  price: 'Prix hors taxes invalide.',
  vat: 'Taux de TVA invalide.',
  item: 'Article de stock introuvable.',
  'item-service': 'Seul un bien se lie à un article de stock.',
  duplicate: 'Cette référence existe déjà au catalogue.',
};

router.post('/produits', (req, res) => {
  const prixSaisi = (req.body.price_ht || '').trim();
  const result = ventes.creerProduit({
    reference: req.body.reference, name: req.body.name, description: req.body.description || '',
    kind: (req.body.kind || '').trim(), unit: (req.body.unit || '').trim(),
    priceHt: prixSaisi === '' ? NaN : parseAmount(prixSaisi), vatRate: Number(req.body.vat_rate),
    itemId: Number(req.body.item_id) || null,
  });
  if (!result.ok) return fail(req, res, 'catalogue', MESSAGES_PRODUIT[result.reason] || 'Produit refusé.');
  setFlash(req, 'success', 'Produit ajouté au catalogue.');
  res.redirect(back('catalogue'));
});

router.post('/produits/:id/basculer', (req, res) => {
  if (!ventes.basculerProduit(req.params.id)) return fail(req, res, 'catalogue', 'Produit introuvable.');
  setFlash(req, 'success', 'Produit mis à jour.');
  res.redirect(back('catalogue'));
});

router.post('/produits/:id/supprimer', (req, res) => {
  const result = ventes.supprimerProduit(req.params.id);
  if (!result.ok) return fail(req, res, 'catalogue', 'Produit introuvable.');
  setFlash(req, 'success', result.deactivated
    ? 'Produit déjà commandé : il a été retiré du catalogue plutôt que supprimé.'
    : 'Produit supprimé.');
  res.redirect(back('catalogue'));
});

// ---------- Commandes ----------

const versCommande = (id) => `/crm/commandes/${Number(id)}`;
const echecCommande = (req, res, id, message) => {
  setFlash(req, 'error', message);
  return res.redirect(id ? versCommande(id) : back('commandes'));
};

router.post('/commandes', (req, res) => {
  const date = (req.body.order_date || '').trim() || new Date().toISOString().slice(0, 10);
  if (!isValidDateString(date)) return fail(req, res, 'commandes', 'Date de commande invalide.');
  const result = ventes.creerCommande({ partnerId: Number(req.body.partner_id), orderDate: date,
    notes: (req.body.notes || '').trim(), createdBy: req.session.user.id });
  if (!result.ok) return fail(req, res, 'commandes', 'Client introuvable.');
  setFlash(req, 'success', `Commande ${result.reference} créée : ajoutez-y ses lignes, puis confirmez-la.`);
  res.redirect(versCommande(result.id));
});

router.post('/devis/:id/commander', (req, res) => {
  const result = ventes.depuisDevis(req.params.id, req.session.user.id);
  const messages = {
    'not-found': 'Devis introuvable.',
    'not-accepted': 'Seul un devis accepté devient une commande.',
    'already-invoiced': 'Ce devis a déjà été facturé directement.',
    'already-ordered': 'Ce devis est déjà passé en commande.',
  };
  if (!result.ok) return fail(req, res, 'devis', messages[result.reason] || 'Commande impossible.');
  setFlash(req, 'success', `Commande ${result.reference} créée depuis le devis.`);
  res.redirect(versCommande(result.id));
});

router.get('/commandes/:id', (req, res) => {
  const commande = ventes.commandeParId(req.params.id);
  if (!commande) return fail(req, res, 'commandes', 'Commande introuvable.');
  res.render('commande-client', {
    commande,
    produits: ventes.produits({ actifs: true }),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.post('/commandes/:id/lignes', (req, res) => {
  const id = Number(req.params.id);
  const quantite = Number(String(req.body.quantity || '').replace(',', '.'));
  const prixSaisi = (req.body.unit_price_ht || '').trim();

  const tvaSaisie = (req.body.vat_rate || '').trim();
  const result = ventes.ajouterLigne(id, {
    productId: Number(req.body.product_id) || null,
    label: (req.body.label || '').trim(),
    quantity: quantite,
    unitPriceHt: prixSaisi === '' ? null : parseAmount(prixSaisi),
    vatRate: tvaSaisie === '' ? null : Number(tvaSaisie),
  });
  const messages = {
    'not-found': 'Commande introuvable.',
    'not-draft': 'Une commande confirmée ne change plus de lignes.',
    product: 'Produit introuvable ou retiré du catalogue.',
    label: 'Choisissez un produit ou écrivez un libellé.',
    quantity: 'Quantité invalide.',
    price: 'Prix unitaire invalide : choisissez un produit ou donnez un prix.',
    vat: 'Taux de TVA invalide.',
  };
  if (!result.ok) return echecCommande(req, res, result.reason === 'not-found' ? null : id, messages[result.reason] || 'Ligne refusée.');
  setFlash(req, 'success', 'Ligne ajoutée.');
  res.redirect(versCommande(id));
});

router.post('/commandes/lignes/:id/retirer', (req, res) => {
  const result = ventes.retirerLigne(req.params.id);
  if (!result.ok) {
    return fail(req, res, 'commandes', result.reason === 'not-draft'
      ? 'Une commande confirmée ne change plus de lignes.' : 'Ligne introuvable.');
  }
  setFlash(req, 'success', 'Ligne retirée.');
  res.redirect(versCommande(result.orderId));
});

router.post('/commandes/:id/confirmer', (req, res) => {
  const id = Number(req.params.id);
  const result = ventes.confirmer(id);
  const messages = { 'not-found': 'Commande introuvable.', 'not-draft': 'Cette commande est déjà confirmée ou annulée.', empty: 'Une commande sans ligne ne se confirme pas.' };
  if (!result.ok) return echecCommande(req, res, result.reason === 'not-found' ? null : id, messages[result.reason]);
  setFlash(req, 'success', 'Commande confirmée : elle peut être livrée.');
  res.redirect(versCommande(id));
});

router.post('/commandes/:id/annuler', (req, res) => {
  const id = Number(req.params.id);
  const result = ventes.annuler(id);
  const messages = { 'not-found': 'Commande introuvable.', already: 'Cette commande est déjà annulée.', started: 'Une commande déjà livrée ou facturée ne s\'annule plus.' };
  if (!result.ok) return echecCommande(req, res, result.reason === 'not-found' ? null : id, messages[result.reason]);
  setFlash(req, 'success', 'Commande annulée.');
  res.redirect(versCommande(id));
});

router.post('/commandes/:id/livrer', (req, res) => {
  const id = Number(req.params.id);
  const quantites = {};
  for (const [cle, valeur] of Object.entries(req.body)) {
    const m = /^q_(\d+)$/.exec(cle);
    if (m) quantites[Number(m[1])] = valeur;
  }
  const date = (req.body.delivered_on || '').trim() || new Date().toISOString().slice(0, 10);
  const result = ventes.livrer(id, quantites, { date, createdBy: req.session.user.id });
  if (!result.ok) {
    const messages = {
      'not-found': 'Commande introuvable.',
      'not-confirmed': 'Seule une commande confirmée se livre.',
      date: 'Date de livraison invalide : au plus tard aujourd\'hui.',
      quantity: `Quantité invalide sur « ${result.line} ».`,
      'too-much': `« ${result.line} » : il ne reste que ${result.remaining} à livrer.`,
      nothing: 'Indiquez au moins une quantité à livrer.',
      stock: `Stock insuffisant pour « ${result.item} »${result.stock !== undefined ? ` (${result.stock} en stock)` : ''} : rien n'a été livré.`,
    };
    return echecCommande(req, res, result.reason === 'not-found' ? null : id, messages[result.reason] || 'Livraison refusée.');
  }
  setFlash(req, 'success', `Bon de livraison ${result.reference} établi.`);
  res.redirect(versCommande(id));
});

router.post('/commandes/:id/facturer', (req, res) => {
  const id = Number(req.params.id);
  const result = ventes.facturer(id, { createdBy: req.session.user.id });
  const messages = { 'not-found': 'Commande introuvable.', 'not-confirmed': 'Seule une commande confirmée se facture.', nothing: 'Rien de livré qui ne soit déjà facturé.' };
  if (!result.ok) return echecCommande(req, res, result.reason === 'not-found' ? null : id, messages[result.reason]);
  setFlash(req, 'success', `${result.invoiceIds.length} facture(s) émise(s) : elles sont dans la gestion.`);
  res.redirect(versCommande(id));
});

router.get('/livraisons/:id', (req, res) => {
  const bon = ventes.bonDeLivraison(req.params.id);
  if (!bon) return fail(req, res, 'commandes', 'Bon de livraison introuvable.');
  res.render('bon-livraison', { bon });
});

module.exports = router;
