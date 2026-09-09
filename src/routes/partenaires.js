const express = require('express');

const audit = require('../audit');
const partners = require('../partners');
const { requireFinance } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidEmail } = require('../utils');

const router = express.Router();

// Même périmètre que l'espace de gestion, dont la fiche est le prolongement.
router.use(requireFinance);

function fail(req, res, target, message) {
  setFlash(req, 'error', message);
  return res.redirect(target);
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function readMark(raw) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: true, value: null };
  const value = Number(trimmed);
  if (!Number.isInteger(value) || value < 1 || value > 5) return { ok: false };
  return { ok: true, value };
}

router.get('/:id', (req, res) => {
  const sheet = partners.sheet(req.params.id);
  if (!sheet) return res.status(404).render('error', { message: 'Tiers introuvable.' });

  res.render('partenaire', {
    ...sheet,
    documentKinds: partners.DOCUMENT_KINDS,
    warningDays: partners.EXPIRY_WARNING_DAYS,
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------------------------------------------------------------- contacts

router.post('/:id/contacts', (req, res) => {
  const sheet = partners.sheet(req.params.id);
  if (!sheet) return fail(req, res, '/gestion#tiers', 'Tiers introuvable.');

  const target = `/partenaires/${sheet.partner.id}#contacts`;
  const name = (req.body.name || '').trim().slice(0, 120);
  if (!name) return fail(req, res, target, 'Nom du contact obligatoire.');

  const email = (req.body.email || '').trim().slice(0, 254);
  if (email && !isValidEmail(email)) return fail(req, res, target, 'Adresse électronique invalide.');

  partners.addContact({
    partnerId: sheet.partner.id,
    name,
    role: (req.body.role || '').trim().slice(0, 80),
    email,
    phone: (req.body.phone || '').trim().slice(0, 40),
    isPrimary: req.body.is_primary === '1',
    notes: (req.body.notes || '').trim().slice(0, 500),
  });
  setFlash(req, 'success', 'Contact enregistré.');
  res.redirect(target);
});

router.post('/contacts/:id/principal', (req, res) => {
  const partnerId = Number(req.body.partner_id);
  if (!partners.setPrimary(partnerId, Number(req.params.id))) {
    return fail(req, res, '/gestion#tiers', 'Contact introuvable.');
  }
  setFlash(req, 'success', 'Interlocuteur principal mis à jour.');
  res.redirect(`/partenaires/${partnerId}#contacts`);
});

router.post('/contacts/:id/supprimer', (req, res) => {
  const partnerId = partners.removeContact(Number(req.params.id));
  if (!partnerId) return fail(req, res, '/gestion#tiers', 'Contact introuvable.');
  setFlash(req, 'success', 'Contact supprimé.');
  res.redirect(`/partenaires/${partnerId}#contacts`);
});

// ---------------------------------------------------------------- conformité

router.post('/:id/pieces', (req, res) => {
  const sheet = partners.sheet(req.params.id);
  if (!sheet) return fail(req, res, '/gestion#tiers', 'Tiers introuvable.');

  const target = `/partenaires/${sheet.partner.id}#conformite`;
  if (!partners.DOCUMENT_KINDS.includes(req.body.kind)) return fail(req, res, target, 'Nature de pièce invalide.');

  const issued = readDate(req.body.issued_on);
  const expires = readDate(req.body.expires_on);
  if (!issued.ok || !expires.ok) return fail(req, res, target, 'Date invalide.');
  // Une pièce périmée avant d'être émise trahit une inversion de saisie.
  if (issued.value && expires.value && expires.value < issued.value) {
    return fail(req, res, target, "La date de validité précède la date d'émission.");
  }

  const id = partners.addDocument({
    partnerId: sheet.partner.id,
    kind: req.body.kind,
    reference: (req.body.reference || '').trim().slice(0, 80),
    issuedOn: issued.value,
    expiresOn: expires.value,
    notes: (req.body.notes || '').trim().slice(0, 500),
  });
  audit.log(req, 'partenaires.piece_ajoutee', 'partner_documents', id, {
    tiers: sheet.partner.name, nature: req.body.kind,
  });
  setFlash(req, 'success', 'Pièce enregistrée.');
  res.redirect(target);
});

router.post('/pieces/:id/supprimer', (req, res) => {
  const partnerId = partners.removeDocument(Number(req.params.id));
  if (!partnerId) return fail(req, res, '/gestion#tiers', 'Pièce introuvable.');
  audit.log(req, 'partenaires.piece_supprimee', 'partner_documents', Number(req.params.id));
  setFlash(req, 'success', 'Pièce supprimée.');
  res.redirect(`/partenaires/${partnerId}#conformite`);
});

// ---------------------------------------------------------------- évaluation

router.post('/:id/evaluations', (req, res) => {
  const sheet = partners.sheet(req.params.id);
  if (!sheet) return fail(req, res, '/gestion#tiers', 'Tiers introuvable.');

  const target = `/partenaires/${sheet.partner.id}#evaluations`;
  const reviewed = readDate(req.body.reviewed_on, { required: true });
  const next = readDate(req.body.next_review);
  if (!reviewed.ok || !next.ok) return fail(req, res, target, 'Date invalide.');

  const quality = readMark(req.body.quality);
  const leadTime = readMark(req.body.lead_time);
  const price = readMark(req.body.price);
  if (!quality.ok || !leadTime.ok || !price.ok) return fail(req, res, target, 'Note invalide : de 1 à 5.');
  if (quality.value == null && leadTime.value == null && price.value == null) {
    return fail(req, res, target, 'Renseignez au moins une note.');
  }

  partners.addReview({
    partnerId: sheet.partner.id,
    reviewedOn: reviewed.value,
    reviewerId: req.session.user.id,
    quality: quality.value,
    leadTime: leadTime.value,
    price: price.value,
    comment: (req.body.comment || '').trim().slice(0, 2000),
    nextReview: next.value,
  });
  setFlash(req, 'success', 'Évaluation enregistrée.');
  res.redirect(target);
});

router.post('/evaluations/:id/supprimer', (req, res) => {
  const partnerId = partners.removeReview(Number(req.params.id));
  if (!partnerId) return fail(req, res, '/gestion#tiers', 'Évaluation introuvable.');
  setFlash(req, 'success', 'Évaluation supprimée.');
  res.redirect(`/partenaires/${partnerId}#evaluations`);
});

module.exports = router;
