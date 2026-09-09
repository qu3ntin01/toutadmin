const express = require('express');

const db = require('../db');
const audit = require('../audit');
const corporate = require('../corporate');
const finance = require('../finance');
const { requireAuth, requireAdmin } = require('../middleware/auth');
const { setFlash, isValidDateString, isValidEmail, parseAmount } = require('../utils');

const router = express.Router();

/**
 * L'espace juridique est réservé à l'administration — sauf les déclarations,
 * que chacun dépose pour soi. Un registre de conflits d'intérêts que seuls les
 * dirigeants peuvent alimenter ne recense que les leurs.
 */
router.use(requireAuth);

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(`/juridique#${anchor}`);
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

function readNumber(raw, { min = 0, max = 1e12, required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  const value = parseAmount(trimmed);
  if (!Number.isFinite(value) || value < min || value > max) return { ok: false };
  return { ok: true, value: Math.round(value * 100) / 100 };
}

function employees() {
  return db.prepare('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

router.get('/', (req, res) => {
  const isAdmin = req.session.user.role === 'admin';
  const me = req.session.user.id;

  res.render('juridique', {
    isAdmin,
    summary: isAdmin ? corporate.summary() : null,
    capital: isAdmin ? corporate.capital() : null,
    shareholderList: isAdmin ? corporate.shareholders() : [],
    movementList: isAdmin ? corporate.movements() : [],
    mandateList: isAdmin ? corporate.mandates() : [],
    meetingList: isAdmin ? corporate.meetings() : [],
    delegationList: isAdmin ? corporate.delegations() : [],
    // Chacun voit ses propres déclarations ; l'administration les voit toutes.
    declarationList: isAdmin ? corporate.declarations() : corporate.declarations({ userId: me }),
    giftList: isAdmin ? corporate.gifts() : corporate.gifts({ userId: me }),
    giftsToReview: isAdmin ? corporate.giftsToReview() : [],
    shareholderKinds: corporate.SHAREHOLDER_KINDS,
    movementKinds: corporate.MOVEMENT_KINDS,
    mandateRoles: corporate.MANDATE_ROLES,
    mandateStatuses: corporate.MANDATE_STATUSES,
    meetingKinds: corporate.MEETING_KINDS,
    interestKinds: corporate.INTEREST_KINDS,
    interestStatuses: corporate.INTEREST_STATUSES,
    giftDirections: corporate.GIFT_DIRECTIONS,
    giftKinds: corporate.GIFT_KINDS,
    giftStatuses: corporate.GIFT_STATUSES,
    delegationStatuses: corporate.DELEGATION_STATUSES,
    giftThreshold: corporate.GIFT_REVIEW_THRESHOLD,
    partners: finance.partners(),
    employees: employees(),
    today: new Date().toISOString().slice(0, 10),
  });
});

router.get('/assemblees/:id', requireAdmin, (req, res) => {
  const meeting = corporate.meetingById(req.params.id);
  if (!meeting) return res.status(404).render('error', { message: 'Assemblée introuvable.' });

  res.render('assemblee', {
    meeting,
    resolutionList: corporate.resolutions(meeting.id),
    quorum: corporate.quorum(meeting),
    kinds: corporate.MEETING_KINDS,
    statuses: corporate.MEETING_STATUSES,
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------------------------------------------------------------- capital

router.post('/associes', requireAdmin, (req, res) => {
  const name = (req.body.name || '').trim().slice(0, 150);
  if (!name) return fail(req, res, 'capital', "Nom de l'associé obligatoire.");
  if (!corporate.SHAREHOLDER_KINDS.includes(req.body.kind)) return fail(req, res, 'capital', 'Nature invalide.');

  const email = (req.body.email || '').trim().slice(0, 254);
  if (email && !isValidEmail(email)) return fail(req, res, 'capital', 'Adresse électronique invalide.');

  const id = corporate.createShareholder({
    name,
    kind: req.body.kind,
    userId: Number(req.body.user_id) || null,
    registration: (req.body.registration || '').trim().slice(0, 40),
    email,
    address: (req.body.address || '').trim().slice(0, 300),
    notes: (req.body.notes || '').trim().slice(0, 500),
  });
  audit.log(req, 'juridique.associe_ajoute', 'shareholders', id, { nom: name });
  setFlash(req, 'success', 'Associé enregistré.');
  res.redirect('/juridique#capital');
});

router.post('/associes/:id/supprimer', requireAdmin, (req, res) => {
  if (!corporate.removeShareholder(Number(req.params.id))) {
    return fail(req, res, 'capital', 'Cet associé détient encore des titres : cédez-les avant de le retirer du registre.');
  }
  audit.log(req, 'juridique.associe_supprime', 'shareholders', Number(req.params.id));
  setFlash(req, 'success', 'Associé retiré du registre.');
  res.redirect('/juridique#capital');
});

router.post('/mouvements', requireAdmin, (req, res) => {
  if (!corporate.MOVEMENT_KINDS.includes(req.body.kind)) return fail(req, res, 'capital', 'Nature de mouvement invalide.');

  const on = readDate(req.body.moved_on, { required: true });
  const shares = readNumber(req.body.shares, { min: 0.0001, required: true });
  const price = readNumber(req.body.unit_price);
  if (!on.ok) return fail(req, res, 'capital', 'Date invalide.');
  if (!shares.ok) return fail(req, res, 'capital', 'Nombre de titres invalide.');
  if (!price.ok) return fail(req, res, 'capital', 'Prix unitaire invalide.');

  const result = corporate.recordMovement({
    shareholderId: Number(req.body.shareholder_id),
    kind: req.body.kind,
    movedOn: on.value,
    shares: shares.value,
    unitPrice: price.value,
    counterpartyId: Number(req.body.counterparty_id) || null,
    note: (req.body.note || '').trim(),
  });
  if (!result.ok) {
    return fail(req, res, 'capital', result.reason === 'insuffisant'
      ? `Cet associé ne détient que ${result.held} titres.`
      : 'Associé introuvable.');
  }
  audit.log(req, 'juridique.mouvement_titres', 'share_movements', null, { nature: req.body.kind, titres: shares.value });
  setFlash(req, 'success', 'Mouvement inscrit au registre.');
  res.redirect('/juridique#capital');
});

router.post('/mouvements/:id/supprimer', requireAdmin, (req, res) => {
  corporate.removeMovement(Number(req.params.id));
  audit.log(req, 'juridique.mouvement_supprime', 'share_movements', Number(req.params.id));
  setFlash(req, 'success', 'Mouvement retiré.');
  res.redirect('/juridique#capital');
});

// ---------------------------------------------------------------- mandats

router.post('/mandats', requireAdmin, (req, res) => {
  const holderName = (req.body.holder_name || '').trim().slice(0, 150);
  if (!holderName) return fail(req, res, 'mandats', 'Nom du mandataire obligatoire.');
  if (!corporate.MANDATE_ROLES.includes(req.body.role)) return fail(req, res, 'mandats', 'Fonction invalide.');

  const started = readDate(req.body.started_on, { required: true });
  const ends = readDate(req.body.ends_on);
  if (!started.ok || !ends.ok) return fail(req, res, 'mandats', 'Date invalide.');

  const id = corporate.createMandate({
    holderName,
    userId: Number(req.body.user_id) || null,
    role: req.body.role,
    startedOn: started.value,
    endsOn: ends.value,
    appointedBy: (req.body.appointed_by || '').trim().slice(0, 150),
    notes: (req.body.notes || '').trim().slice(0, 500),
  });
  audit.log(req, 'juridique.mandat_cree', 'corporate_mandates', id, { fonction: req.body.role });
  setFlash(req, 'success', 'Mandat enregistré.');
  res.redirect('/juridique#mandats');
});

router.post('/mandats/:id/statut', requireAdmin, (req, res) => {
  if (!corporate.setMandateStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'mandats', 'Statut invalide.');
  }
  audit.log(req, 'juridique.mandat_statut', 'corporate_mandates', Number(req.params.id), { statut: req.body.status });
  setFlash(req, 'success', 'Mandat mis à jour.');
  res.redirect('/juridique#mandats');
});

router.post('/mandats/:id/supprimer', requireAdmin, (req, res) => {
  corporate.removeMandate(Number(req.params.id));
  setFlash(req, 'success', 'Mandat supprimé.');
  res.redirect('/juridique#mandats');
});

// ---------------------------------------------------------------- assemblées

router.post('/assemblees', requireAdmin, (req, res) => {
  if (!corporate.MEETING_KINDS.includes(req.body.kind)) return fail(req, res, 'assemblees', "Nature d'assemblée invalide.");

  const on = readDate(req.body.held_on, { required: true });
  const quorumRequired = readNumber(req.body.quorum_required);
  if (!on.ok) return fail(req, res, 'assemblees', 'Date invalide.');
  if (!quorumRequired.ok) return fail(req, res, 'assemblees', 'Quorum invalide.');

  const id = corporate.createMeeting({
    kind: req.body.kind,
    heldOn: on.value,
    location: (req.body.location || '').trim().slice(0, 200),
    quorumRequired: quorumRequired.value || 0,
  });
  audit.log(req, 'juridique.assemblee_creee', 'general_meetings', id);
  setFlash(req, 'success', 'Assemblée convoquée.');
  res.redirect(`/juridique/assemblees/${id}`);
});

router.post('/assemblees/:id/modifier', requireAdmin, (req, res) => {
  const meeting = corporate.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'assemblees', 'Assemblée introuvable.');

  const target = `/juridique/assemblees/${meeting.id}`;
  if (!corporate.MEETING_KINDS.includes(req.body.kind) || !corporate.MEETING_STATUSES.includes(req.body.status)) {
    setFlash(req, 'error', 'Saisie invalide.');
    return res.redirect(target);
  }
  const on = readDate(req.body.held_on, { required: true });
  const quorumRequired = readNumber(req.body.quorum_required);
  const present = readNumber(req.body.shares_present);
  if (!on.ok || !quorumRequired.ok || !present.ok) {
    setFlash(req, 'error', 'Valeur invalide.');
    return res.redirect(target);
  }

  corporate.updateMeeting(meeting.id, {
    kind: req.body.kind,
    heldOn: on.value,
    location: (req.body.location || '').trim().slice(0, 200),
    quorumRequired: quorumRequired.value || 0,
    sharesPresent: present.value || 0,
    status: req.body.status,
  });
  setFlash(req, 'success', 'Assemblée mise à jour.');
  res.redirect(target);
});

router.post('/assemblees/:id/proces-verbal', requireAdmin, (req, res) => {
  const meeting = corporate.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'assemblees', 'Assemblée introuvable.');

  corporate.updateMinutes(meeting.id, req.body.minutes);
  setFlash(req, 'success', 'Procès-verbal enregistré.');
  res.redirect(`/juridique/assemblees/${meeting.id}`);
});

router.post('/assemblees/:id/supprimer', requireAdmin, (req, res) => {
  corporate.removeMeeting(Number(req.params.id));
  audit.log(req, 'juridique.assemblee_supprimee', 'general_meetings', Number(req.params.id));
  setFlash(req, 'success', 'Assemblée supprimée.');
  res.redirect('/juridique#assemblees');
});

router.post('/assemblees/:id/resolutions', requireAdmin, (req, res) => {
  const meeting = corporate.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'assemblees', 'Assemblée introuvable.');

  const target = `/juridique/assemblees/${meeting.id}`;
  const label = (req.body.label || '').trim().slice(0, 300);
  const majority = readNumber(req.body.majority_required, { min: 1, max: 100 });
  if (!label || !majority.ok || majority.value == null) {
    setFlash(req, 'error', 'Résolution ou majorité invalide.');
    return res.redirect(target);
  }

  corporate.addResolution({ meetingId: meeting.id, label, majorityRequired: majority.value });
  setFlash(req, 'success', 'Résolution ajoutée.');
  res.redirect(target);
});

router.post('/resolutions/:id/vote', requireAdmin, (req, res) => {
  const resolution = db.prepare('SELECT * FROM meeting_resolutions WHERE id = ?').get(Number(req.params.id));
  if (!resolution) return fail(req, res, 'assemblees', 'Résolution introuvable.');

  const target = `/juridique/assemblees/${resolution.meeting_id}`;
  const forVotes = readNumber(req.body.votes_for);
  const against = readNumber(req.body.votes_against);
  const abstain = readNumber(req.body.votes_abstain);
  if (!forVotes.ok || !against.ok || !abstain.ok) {
    setFlash(req, 'error', 'Nombre de voix invalide.');
    return res.redirect(target);
  }

  corporate.recordVote(resolution.id, {
    votesFor: forVotes.value || 0,
    votesAgainst: against.value || 0,
    votesAbstain: abstain.value || 0,
  });
  setFlash(req, 'success', 'Vote enregistré.');
  res.redirect(target);
});

router.post('/resolutions/:id/supprimer', requireAdmin, (req, res) => {
  const resolution = db.prepare('SELECT meeting_id FROM meeting_resolutions WHERE id = ?').get(Number(req.params.id));
  corporate.removeResolution(Number(req.params.id));
  setFlash(req, 'success', 'Résolution supprimée.');
  res.redirect(resolution ? `/juridique/assemblees/${resolution.meeting_id}` : '/juridique#assemblees');
});

// ---------------------------------------------------------------- conformité

router.post('/interets', (req, res) => {
  const entity = (req.body.entity || '').trim().slice(0, 200);
  if (!entity) return fail(req, res, 'interets', "L'organisme concerné est obligatoire.");
  if (!corporate.INTEREST_KINDS.includes(req.body.kind)) return fail(req, res, 'interets', 'Nature invalide.');

  const declared = readDate(req.body.declared_on, { required: true });
  const ends = readDate(req.body.ends_on);
  if (!declared.ok || !ends.ok) return fail(req, res, 'interets', 'Date invalide.');

  corporate.declareInterest({
    // Chacun déclare pour soi : l'identifiant vient de la session, jamais du formulaire.
    userId: req.session.user.id,
    kind: req.body.kind,
    entity,
    partnerId: Number(req.body.partner_id) || null,
    description: (req.body.description || '').trim().slice(0, 2000),
    declaredOn: declared.value,
    endsOn: ends.value,
  });
  setFlash(req, 'success', 'Déclaration enregistrée.');
  res.redirect('/juridique#interets');
});

router.post('/interets/:id/examen', requireAdmin, (req, res) => {
  if (!corporate.reviewDeclaration(Number(req.params.id), {
    status: req.body.status,
    measure: req.body.measure,
    reviewerId: req.session.user.id,
  })) return fail(req, res, 'interets', 'Statut invalide.');

  audit.log(req, 'juridique.interet_examine', 'interest_declarations', Number(req.params.id), { statut: req.body.status });
  setFlash(req, 'success', 'Déclaration examinée.');
  res.redirect('/juridique#interets');
});

router.post('/cadeaux', (req, res) => {
  if (!corporate.GIFT_DIRECTIONS.includes(req.body.direction)) return fail(req, res, 'cadeaux', 'Sens invalide.');
  if (!corporate.GIFT_KINDS.includes(req.body.kind)) return fail(req, res, 'cadeaux', 'Nature invalide.');

  const on = readDate(req.body.occurred_on, { required: true });
  const value = readNumber(req.body.value, { max: 1e6 });
  if (!on.ok) return fail(req, res, 'cadeaux', 'Date invalide.');
  if (!value.ok) return fail(req, res, 'cadeaux', 'Valeur invalide.');

  corporate.declareGift({
    userId: req.session.user.id,
    direction: req.body.direction,
    kind: req.body.kind,
    partnerId: Number(req.body.partner_id) || null,
    thirdParty: (req.body.third_party || '').trim().slice(0, 200),
    occurredOn: on.value,
    value: value.value || 0,
    description: (req.body.description || '').trim().slice(0, 1000),
  });
  setFlash(req, 'success', 'Déclaration enregistrée.');
  res.redirect('/juridique#cadeaux');
});

router.post('/cadeaux/:id/examen', requireAdmin, (req, res) => {
  if (!corporate.reviewGift(Number(req.params.id), { status: req.body.status, reviewerId: req.session.user.id })) {
    return fail(req, res, 'cadeaux', 'Statut invalide.');
  }
  audit.log(req, 'juridique.cadeau_examine', 'gift_records', Number(req.params.id), { statut: req.body.status });
  setFlash(req, 'success', 'Déclaration examinée.');
  res.redirect('/juridique#cadeaux');
});

// ---------------------------------------------------------------- délégations

router.post('/delegations', requireAdmin, (req, res) => {
  const scope = (req.body.scope || '').trim().slice(0, 300);
  if (!scope) return fail(req, res, 'delegations', "L'objet de la délégation est obligatoire.");

  const holderId = Number(req.body.holder_id);
  if (!db.prepare('SELECT 1 FROM users WHERE id = ? AND active = 1').get(holderId)) {
    return fail(req, res, 'delegations', 'Délégataire introuvable.');
  }

  const starts = readDate(req.body.starts_on, { required: true });
  const ends = readDate(req.body.ends_on);
  const limit = readNumber(req.body.amount_limit, { max: 1e9 });
  if (!starts.ok || !ends.ok) return fail(req, res, 'delegations', 'Date invalide.');
  if (!limit.ok) return fail(req, res, 'delegations', 'Plafond invalide.');
  // Une délégation qui s'achève avant de commencer n'a jamais eu d'effet.
  if (ends.value && ends.value < starts.value) return fail(req, res, 'delegations', "La fin précède le début.");

  const id = corporate.createDelegation({
    holderId,
    grantedById: req.session.user.id,
    scope,
    amountLimit: limit.value,
    startsOn: starts.value,
    endsOn: ends.value,
    notes: (req.body.notes || '').trim().slice(0, 1000),
  });
  audit.log(req, 'juridique.delegation_creee', 'power_delegations', id, { delegataire: holderId, plafond: limit.value });
  setFlash(req, 'success', 'Délégation enregistrée.');
  res.redirect('/juridique#delegations');
});

router.post('/delegations/:id/statut', requireAdmin, (req, res) => {
  if (!corporate.setDelegationStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'delegations', 'Statut invalide.');
  }
  audit.log(req, 'juridique.delegation_statut', 'power_delegations', Number(req.params.id), { statut: req.body.status });
  setFlash(req, 'success', 'Délégation mise à jour.');
  res.redirect('/juridique#delegations');
});

router.post('/delegations/:id/supprimer', requireAdmin, (req, res) => {
  corporate.removeDelegation(Number(req.params.id));
  setFlash(req, 'success', 'Délégation supprimée.');
  res.redirect('/juridique#delegations');
});

module.exports = router;
