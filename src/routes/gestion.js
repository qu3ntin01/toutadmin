const express = require('express');

const org = require('../org');
const finance = require('../finance');
const resources = require('../resources');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidEmail, isValidUrl, parseAmount } = require('../utils');

const router = express.Router();

// Espace de gestion administrative et financière : administrateurs et membres désignés.
router.use(requireFinance);

const back = (anchor) => `/gestion#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function ok(req, res, anchor, message) {
  setFlash(req, 'success', message);
  return res.redirect(back(anchor));
}

/** Un montant de formulaire : virgule décimale acceptée, bornes explicites. */
function readAmount(raw, { required = true, max = 1e9 } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return required ? { ok: false } : { ok: true, value: null };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || value < 0 || value > max) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

router.get('/', (req, res) => {
  const year = Number(req.query.annee) || new Date().getUTCFullYear();
  const summary = finance.financialSummary(year);
  const claims = finance.allClaims();
  const renewals = finance.contractsToRenew();

  res.render('gestion', {
    year,
    years: [year + 1, year, year - 1, year - 2],
    summary,
    partners: finance.partners(),
    partnerKinds: finance.PARTNER_KINDS,
    contracts: finance.contracts(),
    contractStatuses: finance.CONTRACT_STATUSES,
    billingPeriods: finance.BILLING_PERIODS,
    renewals,
    invoices: finance.invoices(),
    invoiceDirections: finance.INVOICE_DIRECTIONS,
    invoiceStatuses: finance.INVOICE_STATUSES,
    budgets: finance.budgets(year),
    claims,
    expenseStatuses: finance.EXPENSE_STATUSES,
    assets: resources.assets(),
    assetStatuses: resources.ASSET_STATUSES,
    assetCategories: resources.ASSET_CATEGORIES,
    rooms: resources.rooms(),
    bookings: resources.bookings({ from: new Date().toISOString().slice(0, 10) }),
    departments: org.departments(),
    employees: require('../db').prepare("SELECT * FROM users WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE").all(),
    stats: {
      partnerCount: finance.partners().length,
      renewalCount: renewals.length,
      pendingClaims: claims.filter((c) => c.status === 'En attente').length,
      assetCount: resources.assets().length,
    },
  });
});

// ---------- Tiers ----------

router.post('/tiers', (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 160);
  const kind = (req.body.kind || '').trim();
  const email = (req.body.email || '').trim().slice(0, 254);

  if (!name) return fail(req, res, 'tiers', 'Le nom du tiers est obligatoire.');
  if (!finance.PARTNER_KINDS.includes(kind)) return fail(req, res, 'tiers', 'Type de tiers invalide.');
  if (email && !isValidEmail(email)) return fail(req, res, 'tiers', 'Adresse email invalide.');

  finance.createPartner({
    kind,
    name,
    registration: (req.body.registration || '').trim().slice(0, 60),
    contactName: (req.body.contact_name || '').trim().slice(0, 120),
    email,
    phone: (req.body.phone || '').trim().slice(0, 40),
    address: (req.body.address || '').trim().slice(0, 300),
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  return ok(req, res, 'tiers', `Tiers « ${name} » créé.`);
});

router.post('/tiers/:id/statut', (req, res) => {
  const partner = finance.partnerById(Number(req.params.id));
  if (!partner) return fail(req, res, 'tiers', 'Tiers introuvable.');

  finance.updatePartner(partner.id, {
    kind: partner.kind,
    name: partner.name,
    registration: partner.registration,
    contactName: partner.contact_name,
    email: partner.email,
    phone: partner.phone,
    address: partner.address,
    notes: partner.notes,
    active: !partner.active,
  });
  return ok(req, res, 'tiers', partner.active ? 'Tiers désactivé.' : 'Tiers réactivé.');
});

router.post('/tiers/:id/supprimer', (req, res) => {
  const partner = finance.partnerById(Number(req.params.id));
  if (!partner) return fail(req, res, 'tiers', 'Tiers introuvable.');

  // Supprimer un tiers emporte ses contrats : mieux vaut le désactiver s'il a une histoire.
  finance.deletePartner(partner.id);
  return ok(req, res, 'tiers', 'Tiers supprimé, avec ses contrats.');
});

// ---------- Contrats ----------

router.post('/contrats', (req, res) => {
  const partnerId = Number(req.body.partner_id);
  const title = (req.body.title || '').trim().slice(0, 160);
  const startDate = (req.body.start_date || '').trim();
  const endDate = (req.body.end_date || '').trim();
  const noticeDays = Number(req.body.notice_days) || 0;
  const amount = readAmount(req.body.amount, { required: false });
  const billingPeriod = (req.body.billing_period || '').trim();
  const ownerId = Number(req.body.owner_id) || null;

  if (!finance.partnerById(partnerId)) return fail(req, res, 'contrats', 'Tiers introuvable.');
  if (!title) return fail(req, res, 'contrats', "L'intitulé du contrat est obligatoire.");
  if (startDate && !isValidDateString(startDate)) return fail(req, res, 'contrats', 'Date de début invalide.');
  if (endDate && !isValidDateString(endDate)) return fail(req, res, 'contrats', 'Date de fin invalide.');
  if (startDate && endDate && endDate < startDate) return fail(req, res, 'contrats', 'La fin précède le début.');
  if (noticeDays < 0 || noticeDays > 365) return fail(req, res, 'contrats', 'Préavis invalide (0 à 365 jours).');
  if (!amount.ok) return fail(req, res, 'contrats', 'Montant invalide.');
  if (!finance.BILLING_PERIODS.includes(billingPeriod)) return fail(req, res, 'contrats', 'Périodicité invalide.');

  finance.createContract({
    partnerId, title, startDate, endDate, noticeDays, amount: amount.value, billingPeriod, ownerId,
    reference: (req.body.reference || '').trim().slice(0, 60),
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  return ok(req, res, 'contrats', 'Contrat enregistré.');
});

router.post('/contrats/:id/statut', (req, res) => {
  if (!finance.setContractStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return fail(req, res, 'contrats', 'Statut invalide ou contrat introuvable.');
  }
  return ok(req, res, 'contrats', 'Statut du contrat mis à jour.');
});

router.post('/contrats/:id/supprimer', (req, res) => {
  finance.deleteContract(Number(req.params.id));
  return ok(req, res, 'contrats', 'Contrat supprimé.');
});

// ---------- Factures ----------

router.post('/factures', (req, res) => {
  const direction = (req.body.direction || '').trim();
  const label = (req.body.label || '').trim().slice(0, 160);
  const issueDate = (req.body.issue_date || '').trim();
  const dueDate = (req.body.due_date || '').trim();
  const amountHt = readAmount(req.body.amount_ht);
  const vatRate = Number(req.body.vat_rate);
  const partnerId = Number(req.body.partner_id) || null;
  const departmentId = Number(req.body.department_id) || null;
  const status = (req.body.status || 'Émise').trim();

  if (!finance.INVOICE_DIRECTIONS.includes(direction)) return fail(req, res, 'factures', 'Sens de facture invalide.');
  if (!label) return fail(req, res, 'factures', "L'intitulé de la facture est obligatoire.");
  if (!isValidDateString(issueDate)) return fail(req, res, 'factures', "Date d'émission invalide.");
  if (dueDate && !isValidDateString(dueDate)) return fail(req, res, 'factures', "Date d'échéance invalide.");
  if (dueDate && dueDate < issueDate) return fail(req, res, 'factures', "L'échéance précède l'émission.");
  if (!amountHt.ok) return fail(req, res, 'factures', 'Montant HT invalide.');
  if (!Number.isFinite(vatRate) || vatRate < 0 || vatRate > 100) return fail(req, res, 'factures', 'Taux de TVA invalide.');
  if (!finance.INVOICE_STATUSES.includes(status)) return fail(req, res, 'factures', 'Statut invalide.');
  if (partnerId && !finance.partnerById(partnerId)) return fail(req, res, 'factures', 'Tiers introuvable.');
  if (departmentId && !org.departmentById(departmentId)) return fail(req, res, 'factures', 'Service introuvable.');

  finance.createInvoice({
    direction, partnerId, departmentId, label, issueDate, dueDate,
    amountHt: amountHt.value, vatRate, status,
    reference: (req.body.reference || '').trim().slice(0, 60),
    notes: (req.body.notes || '').trim().slice(0, 1000),
    createdBy: req.session.user.id,
  });
  return ok(req, res, 'factures', 'Facture enregistrée.');
});

router.post('/factures/:id/statut', (req, res) => {
  if (!finance.setInvoiceStatus(Number(req.params.id), (req.body.status || '').trim())) {
    return fail(req, res, 'factures', 'Statut invalide ou facture introuvable.');
  }
  return ok(req, res, 'factures', 'Statut de la facture mis à jour.');
});

router.post('/factures/:id/supprimer', (req, res) => {
  finance.deleteInvoice(Number(req.params.id));
  return ok(req, res, 'factures', 'Facture supprimée.');
});

// ---------- Budgets ----------

router.post('/budgets', (req, res) => {
  const departmentId = Number(req.body.department_id);
  const year = Number(req.body.year);
  const amount = readAmount(req.body.amount);

  if (!org.departmentById(departmentId)) return fail(req, res, 'budgets', 'Service introuvable.');
  if (!Number.isInteger(year) || year < 2000 || year > 2100) return fail(req, res, 'budgets', 'Exercice invalide.');
  if (!amount.ok) return fail(req, res, 'budgets', 'Montant de budget invalide.');

  finance.setBudget({ departmentId, year, amount: amount.value, notes: (req.body.notes || '').trim().slice(0, 500) });
  return ok(req, res, 'budgets', 'Budget enregistré.');
});

router.post('/budgets/:id/supprimer', (req, res) => {
  finance.deleteBudget(Number(req.params.id));
  return ok(req, res, 'budgets', 'Budget supprimé.');
});

// ---------- Notes de frais ----------

router.post('/frais/:id/statut', (req, res) => {
  const status = (req.body.status || '').trim();
  const result = finance.reviewClaim(Number(req.params.id), status, req.session.user.id, (req.body.review_note || '').trim().slice(0, 500));

  const messages = {
    'not-found': 'Note de frais introuvable.',
    'not-pending': "Cette note n'est plus en attente.",
    'not-approved': 'Une note doit être approuvée avant d\'être remboursée.',
    'bad-status': 'Décision invalide.',
  };
  if (!result.ok) return fail(req, res, 'frais', messages[result.reason] || 'Décision impossible.');
  return ok(req, res, 'frais', `Note de frais : ${status.toLowerCase()}.`);
});

// ---------- Parc matériel ----------

router.post('/equipements', (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 160);
  const category = (req.body.category || '').trim();
  const purchaseDate = (req.body.purchase_date || '').trim();
  const warrantyEnd = (req.body.warranty_end || '').trim();
  const value = readAmount(req.body.value, { required: false });

  if (!name) return fail(req, res, 'equipements', "Le nom de l'équipement est obligatoire.");
  if (category && !resources.ASSET_CATEGORIES.includes(category)) return fail(req, res, 'equipements', 'Catégorie invalide.');
  if (purchaseDate && !isValidDateString(purchaseDate)) return fail(req, res, 'equipements', "Date d'achat invalide.");
  if (warrantyEnd && !isValidDateString(warrantyEnd)) return fail(req, res, 'equipements', 'Fin de garantie invalide.');
  if (!value.ok) return fail(req, res, 'equipements', 'Valeur invalide.');

  resources.createAsset({
    name, category, purchaseDate, warrantyEnd, value: value.value,
    reference: (req.body.reference || '').trim().slice(0, 60),
    serialNumber: (req.body.serial_number || '').trim().slice(0, 80),
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  return ok(req, res, 'equipements', 'Équipement ajouté au parc.');
});

router.post('/equipements/:id/affecter', (req, res) => {
  const result = resources.assignAsset({
    assetId: Number(req.params.id),
    employeeId: Number(req.body.employee_id),
    note: (req.body.note || '').trim().slice(0, 300),
  });

  const messages = {
    'not-found': 'Équipement introuvable.',
    retired: 'Un équipement réformé ne peut pas être affecté.',
    'already-assigned': 'Cet équipement est déjà affecté : reprenez-le d\'abord.',
    'no-employee': 'Membre introuvable.',
  };
  if (!result.ok) return fail(req, res, 'equipements', messages[result.reason] || 'Affectation impossible.');
  return ok(req, res, 'equipements', 'Équipement affecté.');
});

router.post('/equipements/:id/reprendre', (req, res) => {
  if (!resources.returnAsset(Number(req.params.id)).ok) {
    return fail(req, res, 'equipements', "Cet équipement n'est affecté à personne.");
  }
  return ok(req, res, 'equipements', 'Équipement repris et rendu disponible.');
});

router.post('/equipements/:id/statut', (req, res) => {
  const result = resources.setAssetStatus(Number(req.params.id), (req.body.status || '').trim());
  const messages = {
    'not-found': 'Équipement introuvable.',
    'bad-status': 'Statut invalide.',
    'assign-instead': "« Affecté » découle d'une affectation : passez par le bouton Affecter.",
    'return-first': 'Reprenez d\'abord cet équipement à son détenteur.',
  };
  if (!result.ok) return fail(req, res, 'equipements', messages[result.reason] || 'Changement impossible.');
  return ok(req, res, 'equipements', 'Statut mis à jour.');
});

router.post('/equipements/:id/supprimer', (req, res) => {
  resources.deleteAsset(Number(req.params.id));
  return ok(req, res, 'equipements', 'Équipement retiré du parc.');
});

// ---------- Salles ----------

router.post('/salles', (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 120);
  const capacity = Number(req.body.capacity) || 0;

  if (!name) return fail(req, res, 'salles', 'Le nom de la salle est obligatoire.');
  if (capacity < 0 || capacity > 10000) return fail(req, res, 'salles', 'Capacité invalide.');
  if (resources.rooms().some((r) => r.name.toLowerCase() === name.toLowerCase())) {
    return fail(req, res, 'salles', 'Une salle porte déjà ce nom.');
  }

  resources.createRoom({
    name, capacity,
    location: (req.body.location || '').trim().slice(0, 140),
    equipment: (req.body.equipment || '').trim().slice(0, 300),
  });
  return ok(req, res, 'salles', `Salle « ${name} » créée.`);
});

router.post('/salles/:id/statut', (req, res) => {
  if (!resources.toggleRoom(Number(req.params.id))) return fail(req, res, 'salles', 'Salle introuvable.');
  return ok(req, res, 'salles', 'Disponibilité de la salle mise à jour.');
});

router.post('/salles/:id/supprimer', (req, res) => {
  resources.deleteRoom(Number(req.params.id));
  return ok(req, res, 'salles', 'Salle supprimée, avec ses réservations.');
});

// La gestion peut libérer n'importe quelle réservation, pas seulement les siennes.
router.post('/reservations/:id/annuler', (req, res) => {
  if (!resources.cancelBooking(Number(req.params.id), req.session.user.id, { force: true })) {
    return fail(req, res, 'salles', 'Réservation introuvable.');
  }
  return ok(req, res, 'salles', 'Réservation annulée.');
});

module.exports = router;
