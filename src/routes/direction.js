const express = require('express');

const db = require('../db');
const audit = require('../audit');
const governance = require('../governance');
const surveys = require('../surveys');
const org = require('../org');
const { requireAdmin } = require('../middleware/auth');
const { setFlash, isValidDateString } = require('../utils');

const router = express.Router();

// La gouvernance de l'entreprise — ce qui est décidé et ce qui est risqué —
// relève de la direction. Elle n'est pas déléguée par un droit : c'est
// l'administration de l'instance.
router.use(requireAdmin);

const back = (anchor) => `/direction#${anchor}`;

function fail(req, res, anchor, message) {
  setFlash(req, 'error', message);
  return res.redirect(back(anchor));
}

function readDate(raw, { required = false } = {}) {
  const trimmed = (raw || '').trim();
  if (!trimmed) return { ok: !required, value: null };
  if (!isValidDateString(trimmed)) return { ok: false };
  return { ok: true, value: trimmed };
}

const text = (raw, max) => (raw || '').trim().slice(0, max);

function people() {
  return db.prepare('SELECT id, first_name, last_name, grade FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE').all();
}

function scaleValue(raw) {
  const value = Number(raw);
  return governance.SCALE.includes(value) ? value : null;
}

router.get('/', (req, res) => {
  res.render('direction', {
    stats: governance.summary(),
    meetingList: governance.meetings(),
    decisionList: governance.decisions(),
    actionList: governance.actions({ openOnly: true }),
    riskList: governance.risks(),
    matrix: governance.matrix(),
    surveyList: surveys.list(),
    barometer: surveys.barometer(),
    meetingKinds: governance.MEETING_KINDS,
    decisionScopes: governance.DECISION_SCOPES,
    decisionStatuses: governance.DECISION_STATUSES,
    actionStatuses: governance.ACTION_STATUSES,
    riskCategories: governance.RISK_CATEGORIES,
    treatments: governance.TREATMENTS,
    riskStatuses: governance.RISK_STATUSES,
    scale: governance.SCALE,
    criticalThreshold: governance.CRITICAL_THRESHOLD,
    surveyKinds: surveys.KINDS,
    surveyAudiences: surveys.AUDIENCES,
    questionTypes: surveys.QUESTION_TYPES,
    anonymityThreshold: surveys.ANONYMITY_THRESHOLD,
    departments: org.departments(),
    teams: org.teams(),
    employees: people(),
    today: new Date().toISOString().slice(0, 10),
  });
});

// ---------- Réunions ----------

router.post('/reunions', (req, res) => {
  const title = text(req.body.title, 200);
  if (!title) return fail(req, res, 'reunions', 'Un intitulé est requis.');
  if (!governance.MEETING_KINDS.includes(req.body.kind)) return fail(req, res, 'reunions', 'Type de réunion inconnu.');

  const held = readDate(req.body.held_on, { required: true });
  if (!held.ok) return fail(req, res, 'reunions', 'Date de réunion invalide.');

  const chairId = Number(req.body.chair_id) || null;
  const id = governance.createMeeting({
    title,
    kind: req.body.kind,
    heldOn: held.value,
    startsAt: text(req.body.starts_at, 5),
    endsAt: text(req.body.ends_at, 5),
    location: text(req.body.location, 160),
    agenda: text(req.body.agenda, 5000),
    chairId,
    createdBy: req.session.user.id,
  });

  audit.log(req, 'reunion.creee', 'meetings', id, { titre: title, date: held.value });
  setFlash(req, 'success', 'Réunion inscrite à l\'agenda de direction.');
  res.redirect(`/direction/reunions/${id}`);
});

router.get('/reunions/:id', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return res.status(404).render('error', { message: 'Réunion introuvable.' });

  res.render('reunion', {
    meeting,
    attendeeList: governance.attendees(meeting.id),
    decisionList: governance.decisions({ meetingId: meeting.id }),
    actionList: governance.actions({ meetingId: meeting.id }),
    meetingKinds: governance.MEETING_KINDS,
    meetingStatuses: governance.MEETING_STATUSES,
    attendances: governance.ATTENDANCES,
    decisionScopes: governance.DECISION_SCOPES,
    decisionStatuses: governance.DECISION_STATUSES,
    actionStatuses: governance.ACTION_STATUSES,
    employees: people(),
    today: new Date().toISOString().slice(0, 10),
  });
});

const meetingBack = (id, anchor) => `/direction/reunions/${id}#${anchor}`;

router.post('/reunions/:id/modifier', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'reunions', 'Réunion introuvable.');
  if (!governance.MEETING_KINDS.includes(req.body.kind) || !governance.MEETING_STATUSES.includes(req.body.status)) {
    return fail(req, res, 'reunions', 'Valeur inconnue.');
  }

  const held = readDate(req.body.held_on, { required: true });
  const title = text(req.body.title, 200);
  if (!held.ok || !title) return fail(req, res, 'reunions', 'Intitulé ou date invalide.');

  governance.updateMeeting(meeting.id, {
    title,
    kind: req.body.kind,
    heldOn: held.value,
    startsAt: text(req.body.starts_at, 5),
    endsAt: text(req.body.ends_at, 5),
    location: text(req.body.location, 160),
    agenda: text(req.body.agenda, 5000),
    chairId: Number(req.body.chair_id) || null,
    status: req.body.status,
  });
  audit.log(req, 'reunion.modifiee', 'meetings', meeting.id, { titre: title });
  setFlash(req, 'success', 'Réunion mise à jour.');
  res.redirect(meetingBack(meeting.id, 'ordre-du-jour'));
});

router.post('/reunions/:id/compte-rendu', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'reunions', 'Réunion introuvable.');

  governance.setMinutes(meeting.id, text(req.body.minutes, 20000));
  audit.log(req, 'reunion.compte_rendu', 'meetings', meeting.id, {});
  setFlash(req, 'success', 'Compte rendu enregistré. La réunion est marquée tenue.');
  res.redirect(meetingBack(meeting.id, 'compte-rendu'));
});

router.post('/reunions/:id/participants', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'reunions', 'Réunion introuvable.');

  const invited = [].concat(req.body.user_ids || []).map(Number).filter(Boolean);
  let added = 0;
  for (const userId of invited) {
    if (db.prepare('SELECT id FROM users WHERE id = ? AND active = 1').get(userId) && governance.invite(meeting.id, userId)) added += 1;
  }
  setFlash(req, added ? 'success' : 'error', added ? `${added} participant(s) convié(s).` : 'Aucun nouveau participant.');
  res.redirect(meetingBack(meeting.id, 'participants'));
});

router.post('/reunions/:id/participants/:userId/presence', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'reunions', 'Réunion introuvable.');
  if (!governance.setAttendance(meeting.id, Number(req.params.userId), req.body.attendance)) {
    return fail(req, res, 'reunions', 'Présence inconnue.');
  }
  res.redirect(meetingBack(meeting.id, 'participants'));
});

router.post('/reunions/:id/participants/:userId/retirer', (req, res) => {
  governance.removeAttendee(Number(req.params.id), Number(req.params.userId));
  res.redirect(meetingBack(Number(req.params.id), 'participants'));
});

router.post('/reunions/:id/supprimer', (req, res) => {
  const meeting = governance.meetingById(req.params.id);
  if (!meeting) return fail(req, res, 'reunions', 'Réunion introuvable.');

  governance.deleteMeeting(meeting.id);
  audit.log(req, 'reunion.supprimee', 'meetings', meeting.id, { titre: meeting.title });
  setFlash(req, 'success', 'Réunion supprimée. Les décisions prises restent au registre.');
  res.redirect(back('reunions'));
});

// ---------- Décisions ----------

router.post('/decisions', (req, res) => {
  const title = text(req.body.title, 200);
  if (!title) return fail(req, res, 'decisions', 'Un intitulé est requis.');
  if (!governance.DECISION_SCOPES.includes(req.body.scope)) return fail(req, res, 'decisions', 'Portée inconnue.');

  const decided = readDate(req.body.decided_on, { required: true });
  const review = readDate(req.body.review_on);
  if (!decided.ok || !review.ok) return fail(req, res, 'decisions', 'Date invalide.');

  const meetingId = Number(req.body.meeting_id) || null;
  if (meetingId && !governance.meetingById(meetingId)) return fail(req, res, 'decisions', 'Réunion inconnue.');

  const id = governance.createDecision({
    meetingId,
    title,
    body: text(req.body.body, 5000),
    rationale: text(req.body.rationale, 5000),
    decidedOn: decided.value,
    decidedBy: Number(req.body.decided_by) || req.session.user.id,
    scope: req.body.scope,
    reviewOn: review.value,
  });
  audit.log(req, 'decision.enregistree', 'decisions', id, { titre: title, portee: req.body.scope });
  setFlash(req, 'success', 'Décision inscrite au registre.');
  res.redirect(meetingId ? meetingBack(meetingId, 'decisions') : back('decisions'));
});

router.post('/decisions/:id/statut', (req, res) => {
  if (!governance.setDecisionStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'decisions', 'Statut inconnu.');
  }
  audit.log(req, 'decision.statut', 'decisions', Number(req.params.id), { statut: req.body.status });
  res.redirect(back('decisions'));
});

router.post('/decisions/:id/supprimer', (req, res) => {
  governance.deleteDecision(Number(req.params.id));
  audit.log(req, 'decision.supprimee', 'decisions', Number(req.params.id), {});
  setFlash(req, 'success', 'Décision retirée du registre.');
  res.redirect(back('decisions'));
});

// ---------- Actions ----------

router.post('/actions', (req, res) => {
  const label = text(req.body.label, 300);
  if (!label) return fail(req, res, 'actions', 'Un libellé est requis.');

  const due = readDate(req.body.due_date);
  if (!due.ok) return fail(req, res, 'actions', 'Échéance invalide.');

  const meetingId = Number(req.body.meeting_id) || null;
  const decisionId = Number(req.body.decision_id) || null;
  const id = governance.createAction({
    meetingId,
    decisionId,
    label,
    assigneeId: Number(req.body.assignee_id) || null,
    dueDate: due.value,
  });
  audit.log(req, 'action.creee', 'meeting_actions', id, { libelle: label });
  setFlash(req, 'success', 'Action confiée.');
  res.redirect(meetingId ? meetingBack(meetingId, 'actions') : back('actions'));
});

router.post('/actions/:id/statut', (req, res) => {
  if (!governance.setActionStatus(Number(req.params.id), req.body.status)) {
    return fail(req, res, 'actions', 'Statut inconnu.');
  }
  res.redirect(req.body.back === 'reunion' && req.body.meeting_id
    ? meetingBack(Number(req.body.meeting_id), 'actions')
    : back('actions'));
});

router.post('/actions/:id/supprimer', (req, res) => {
  governance.deleteAction(Number(req.params.id));
  res.redirect(back('actions'));
});

// ---------- Registre des risques ----------

function readRisk(req) {
  const title = text(req.body.title, 200);
  if (!title) return { ok: false, message: 'Un intitulé est requis.' };
  if (!governance.RISK_CATEGORIES.includes(req.body.category)) return { ok: false, message: 'Catégorie inconnue.' };
  if (!governance.TREATMENTS.includes(req.body.treatment)) return { ok: false, message: 'Traitement inconnu.' };

  const likelihood = scaleValue(req.body.likelihood);
  const impact = scaleValue(req.body.impact);
  if (!likelihood || !impact) return { ok: false, message: 'Cotation hors échelle.' };

  const residualLikelihood = req.body.residual_likelihood ? scaleValue(req.body.residual_likelihood) : null;
  const residualImpact = req.body.residual_impact ? scaleValue(req.body.residual_impact) : null;
  if ((req.body.residual_likelihood && !residualLikelihood) || (req.body.residual_impact && !residualImpact)) {
    return { ok: false, message: 'Cotation résiduelle hors échelle.' };
  }

  const identified = readDate(req.body.identified_on);
  const review = readDate(req.body.next_review);
  if (!identified.ok || !review.ok) return { ok: false, message: 'Date invalide.' };

  return {
    ok: true,
    fields: {
      reference: text(req.body.reference, 40),
      category: req.body.category,
      title,
      description: text(req.body.description, 3000),
      likelihood,
      impact,
      ownerId: Number(req.body.owner_id) || null,
      treatment: req.body.treatment,
      actionPlan: text(req.body.action_plan, 3000),
      residualLikelihood,
      residualImpact,
      identifiedOn: identified.value,
      nextReview: review.value,
    },
  };
}

router.post('/risques', (req, res) => {
  const read = readRisk(req);
  if (!read.ok) return fail(req, res, 'risques', read.message);

  const id = governance.createRisk(read.fields);
  audit.log(req, 'risque.enregistre', 'enterprise_risks', id, { titre: read.fields.title, categorie: read.fields.category });
  setFlash(req, 'success', 'Risque inscrit au registre.');
  res.redirect(back('risques'));
});

router.post('/risques/:id/modifier', (req, res) => {
  const risk = governance.riskById(req.params.id);
  if (!risk) return fail(req, res, 'risques', 'Risque introuvable.');
  if (!governance.RISK_STATUSES.includes(req.body.status)) return fail(req, res, 'risques', 'Statut inconnu.');

  const read = readRisk(req);
  if (!read.ok) return fail(req, res, 'risques', read.message);

  governance.updateRisk(risk.id, { ...read.fields, status: req.body.status });
  audit.log(req, 'risque.modifie', 'enterprise_risks', risk.id, { titre: read.fields.title, statut: req.body.status });
  setFlash(req, 'success', 'Risque mis à jour.');
  res.redirect(back('risques'));
});

router.post('/risques/:id/supprimer', (req, res) => {
  const risk = governance.riskById(req.params.id);
  if (!risk) return fail(req, res, 'risques', 'Risque introuvable.');

  governance.deleteRisk(risk.id);
  audit.log(req, 'risque.supprime', 'enterprise_risks', risk.id, { titre: risk.title });
  setFlash(req, 'success', 'Risque retiré du registre.');
  res.redirect(back('risques'));
});

// ---------- Sondages ----------

router.post('/sondages', (req, res) => {
  const title = text(req.body.title, 200);
  if (!title) return fail(req, res, 'sondages', 'Un intitulé est requis.');
  if (!surveys.KINDS.includes(req.body.kind)) return fail(req, res, 'sondages', 'Type de sondage inconnu.');
  if (!surveys.AUDIENCES.includes(req.body.audience)) return fail(req, res, 'sondages', 'Population inconnue.');

  const opens = readDate(req.body.opens_on);
  const closes = readDate(req.body.closes_on);
  if (!opens.ok || !closes.ok) return fail(req, res, 'sondages', 'Date invalide.');

  const audienceId = req.body.audience === 'Tous' ? null : Number(req.body.audience_id) || null;
  if (req.body.audience !== 'Tous' && !audienceId) return fail(req, res, 'sondages', 'Précisez le service ou l\'équipe concernée.');

  const id = surveys.create({
    title,
    intro: text(req.body.intro, 2000),
    kind: req.body.kind,
    audience: req.body.audience,
    audienceId,
    opensOn: opens.value,
    closesOn: closes.value,
    createdBy: req.session.user.id,
  });
  audit.log(req, 'sondage.cree', 'surveys', id, { titre: title });
  setFlash(req, 'success', 'Sondage créé. Ajoutez ses questions, puis ouvrez-le.');
  res.redirect(back('sondages'));
});

router.post('/sondages/:id/questions', (req, res) => {
  const survey = surveys.byId(req.params.id);
  if (!survey) return fail(req, res, 'sondages', 'Sondage introuvable.');
  if (survey.status !== 'Brouillon') return fail(req, res, 'sondages', 'Un sondage ouvert ne se modifie plus : les réponses ne seraient plus comparables.');

  const label = text(req.body.label, 300);
  const type = req.body.type;
  if (!label) return fail(req, res, 'sondages', 'Une question est requise.');
  if (!surveys.QUESTION_TYPES.some((t) => t.key === type)) return fail(req, res, 'sondages', 'Type de question inconnu.');

  const choices = type === 'choix'
    ? text(req.body.choices, 1000).split('\n').map((c) => c.trim()).filter(Boolean).slice(0, 12)
    : [];
  if (type === 'choix' && choices.length < 2) return fail(req, res, 'sondages', 'Un choix multiple demande au moins deux réponses possibles.');

  surveys.addQuestion(survey.id, { label, type, choices, required: req.body.required === '1' });
  setFlash(req, 'success', 'Question ajoutée.');
  res.redirect(back('sondages'));
});

router.post('/sondages/:id/questions/:questionId/supprimer', (req, res) => {
  const survey = surveys.byId(req.params.id);
  if (!survey) return fail(req, res, 'sondages', 'Sondage introuvable.');
  if (survey.status !== 'Brouillon') return fail(req, res, 'sondages', 'Un sondage ouvert ne se modifie plus.');

  surveys.removeQuestion(survey.id, Number(req.params.questionId));
  res.redirect(back('sondages'));
});

router.post('/sondages/:id/ouvrir', (req, res) => {
  const verdict = surveys.open(Number(req.params.id));
  if (!verdict.ok) return fail(req, res, 'sondages', verdict.message);

  audit.log(req, 'sondage.ouvert', 'surveys', Number(req.params.id), {});
  setFlash(req, 'success', 'Sondage ouvert. Les personnes conviées le voient dans leur espace.');
  res.redirect(back('sondages'));
});

router.post('/sondages/:id/clore', (req, res) => {
  if (!surveys.close(Number(req.params.id))) return fail(req, res, 'sondages', 'Ce sondage n\'est pas ouvert.');

  audit.log(req, 'sondage.clos', 'surveys', Number(req.params.id), {});
  setFlash(req, 'success', 'Sondage clos.');
  res.redirect(back('sondages'));
});

router.post('/sondages/:id/supprimer', (req, res) => {
  const survey = surveys.byId(req.params.id);
  if (!survey) return fail(req, res, 'sondages', 'Sondage introuvable.');

  surveys.remove(survey.id);
  audit.log(req, 'sondage.supprime', 'surveys', survey.id, { titre: survey.title });
  setFlash(req, 'success', 'Sondage supprimé, réponses comprises.');
  res.redirect(back('sondages'));
});

router.get('/sondages/:id/resultats', (req, res) => {
  const result = surveys.results(Number(req.params.id));
  if (!result) return res.status(404).render('error', { message: 'Sondage introuvable.' });

  res.render('sondage-resultats', { result, scale: surveys.SCALE });
});

module.exports = router;
