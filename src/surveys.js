const db = require('./db');
const org = require('./org');

/**
 * Sondages internes et baromètre social.
 *
 * Un baromètre qui n'est pas anonyme ne mesure rien : il mesure ce que les gens
 * acceptent de dire à leur employeur. L'anonymat est donc tenu par la structure
 * des tables, pas par une promesse — les réponses ne portent aucun identifiant
 * de personne, et la participation, elle nominative, ne dit que « a répondu ».
 * Une base saisie ne peut pas rendre ce qu'elle ne contient pas.
 *
 * Second garde-fou : sous un certain nombre de réponses, les résultats ne sont
 * pas affichés. Dans une équipe de trois, une moyenne suffit à désigner
 * quelqu'un.
 */

const KINDS = ['Baromètre social', 'Enquête', 'Vote consultatif', "Retour d'expérience"];
const STATUSES = ['Brouillon', 'Ouvert', 'Clos'];
const QUESTION_TYPES = [
  { key: 'echelle', label: 'Échelle de 1 à 5' },
  { key: 'oui_non', label: 'Oui / Non' },
  { key: 'choix', label: 'Choix multiple' },
  { key: 'texte', label: 'Réponse libre' },
];
const AUDIENCES = ['Tous', 'Service', 'Équipe'];

// En deçà, un résultat désigne quelqu'un plutôt qu'il ne décrit un groupe.
const ANONYMITY_THRESHOLD = 5;
const SCALE = [1, 2, 3, 4, 5];

// ---------- Questionnaires ----------

function list({ status = null } = {}) {
  const clause = status ? 'WHERE s.status = ?' : '';
  const params = status ? [status] : [];
  return db.prepare(`
    SELECT s.*,
           (SELECT COUNT(*) FROM survey_questions q WHERE q.survey_id = s.id) AS question_count,
           (SELECT COUNT(*) FROM survey_participations p WHERE p.survey_id = s.id) AS answer_count
    FROM surveys s ${clause} ORDER BY s.created_at DESC
  `).all(...params);
}

function byId(id) {
  return db.prepare('SELECT * FROM surveys WHERE id = ?').get(Number(id) || 0) || null;
}

function create({ title, intro, kind, audience, audienceId, opensOn, closesOn, createdBy }) {
  return db.prepare(`
    INSERT INTO surveys (title, intro, kind, audience, audience_id, opens_on, closes_on, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
  `).run(title, intro || '', kind, audience, audienceId || null, opensOn || null, closesOn || null, createdBy || null).lastInsertRowid;
}

function questions(surveyId) {
  return db.prepare('SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY position, id').all(surveyId)
    .map((q) => ({ ...q, choiceList: q.choices ? q.choices.split('|').filter(Boolean) : [] }));
}

function addQuestion(surveyId, { label, type, choices, required }) {
  const next = db.prepare('SELECT COALESCE(MAX(position), 0) + 1 AS p FROM survey_questions WHERE survey_id = ?').get(surveyId).p;
  return db.prepare(`
    INSERT INTO survey_questions (survey_id, position, label, type, choices, required)
    VALUES (?, ?, ?, ?, ?, ?)
  `).run(surveyId, next, label, type, (choices || []).join('|'), required ? 1 : 0).lastInsertRowid;
}

function removeQuestion(surveyId, questionId) {
  db.prepare('DELETE FROM survey_questions WHERE id = ? AND survey_id = ?').run(questionId, surveyId);
}

/**
 * Ouvrir fige le questionnaire : modifier les questions après les premières
 * réponses rendrait les résultats incomparables entre eux.
 */
function open(id) {
  const survey = byId(id);
  if (!survey || survey.status !== 'Brouillon') return { ok: false, message: 'Ce sondage ne peut plus être ouvert.' };
  if (questions(id).length === 0) return { ok: false, message: 'Ajoutez au moins une question avant d\'ouvrir le sondage.' };

  db.prepare("UPDATE surveys SET status = 'Ouvert' WHERE id = ?").run(id);
  return { ok: true };
}

function close(id) {
  return db.prepare("UPDATE surveys SET status = 'Clos' WHERE id = ? AND status = 'Ouvert'").run(id).changes > 0;
}

function remove(id) {
  db.prepare('DELETE FROM surveys WHERE id = ?').run(id);
}

// ---------- Participation ----------

/** Qui est convié : tout le monde, un service, ou une équipe. */
function audienceUsers(survey) {
  if (survey.audience === 'Service' && survey.audience_id) {
    return org.membersOfDepartment(survey.audience_id).filter((u) => u.active);
  }
  if (survey.audience === 'Équipe' && survey.audience_id) {
    return db.prepare("SELECT * FROM users WHERE active = 1 AND team_id = ? AND role = 'employee'").all(survey.audience_id);
  }
  return db.prepare("SELECT * FROM users WHERE active = 1 AND role = 'employee'").all();
}

function isInvited(survey, userId) {
  return audienceUsers(survey).some((u) => u.id === Number(userId));
}

function hasAnswered(surveyId, userId) {
  return Boolean(db.prepare('SELECT id FROM survey_participations WHERE survey_id = ? AND user_id = ?').get(surveyId, userId));
}

/**
 * Le compte des sondages en attente pour une personne, en une requête : la
 * navigation l'affiche à chaque page, elle ne peut pas se permettre de
 * recalculer une population entière à chaque fois.
 */
function pendingCountFor(userId) {
  return db.prepare(`
    SELECT COUNT(*) AS n FROM surveys s
    WHERE s.status = 'Ouvert'
      AND (s.closes_on IS NULL OR s.closes_on >= date('now'))
      AND (
        s.audience = 'Tous'
        OR (s.audience = 'Service' AND s.audience_id = (SELECT department_id FROM users WHERE id = ?))
        OR (s.audience = 'Équipe' AND s.audience_id = (SELECT team_id FROM users WHERE id = ?))
      )
      AND NOT EXISTS (SELECT 1 FROM survey_participations p WHERE p.survey_id = s.id AND p.user_id = ?)
  `).get(userId, userId, userId).n;
}

/** Les sondages ouverts qu'une personne peut encore remplir. */
function openFor(userId) {
  return list({ status: 'Ouvert' })
    .filter((s) => isInvited(s, userId) && !hasAnswered(s.id, userId))
    .filter((s) => !s.closes_on || s.closes_on >= new Date().toISOString().slice(0, 10));
}

/**
 * Enregistre une participation. Les deux écritures tiennent dans une seule
 * transaction : sans elle, un plantage entre les deux laisserait soit un vote
 * fantôme, soit la possibilité de voter deux fois.
 */
const submitTransaction = db.transaction((surveyId, userId, entries) => {
  db.prepare('INSERT INTO survey_participations (survey_id, user_id) VALUES (?, ?)').run(surveyId, userId);
  const insert = db.prepare('INSERT INTO survey_answers (question_id, value) VALUES (?, ?)');
  for (const entry of entries) insert.run(entry.questionId, entry.value);
});

function submit(surveyId, userId, values) {
  const survey = byId(surveyId);
  if (!survey || survey.status !== 'Ouvert') return { ok: false, message: "Ce sondage n'est pas ouvert." };
  if (!isInvited(survey, userId)) return { ok: false, message: "Ce sondage ne vous est pas destiné." };
  if (hasAnswered(surveyId, userId)) return { ok: false, message: 'Vous avez déjà répondu à ce sondage.' };

  const entries = [];
  for (const question of questions(surveyId)) {
    const raw = String(values[`q_${question.id}`] == null ? '' : values[`q_${question.id}`]).trim();
    if (!raw) {
      if (question.required) return { ok: false, message: `Question sans réponse : ${question.label}` };
      continue;
    }
    if (question.type === 'echelle' && !SCALE.includes(Number(raw))) {
      return { ok: false, message: 'Valeur hors de l\'échelle.' };
    }
    if (question.type === 'oui_non' && !['Oui', 'Non'].includes(raw)) {
      return { ok: false, message: 'Réponse attendue : Oui ou Non.' };
    }
    if (question.type === 'choix' && !question.choiceList.includes(raw)) {
      return { ok: false, message: 'Choix inconnu.' };
    }
    entries.push({ questionId: question.id, value: raw.slice(0, 2000) });
  }

  try {
    submitTransaction(surveyId, userId, entries);
  } catch {
    // Course entre deux envois simultanés : l'index unique tranche.
    return { ok: false, message: 'Vous avez déjà répondu à ce sondage.' };
  }
  return { ok: true };
}

// ---------- Résultats ----------

function participationCount(surveyId) {
  return db.prepare('SELECT COUNT(*) AS n FROM survey_participations WHERE survey_id = ?').get(surveyId).n;
}

/**
 * Les résultats agrégés. Sous le seuil d'anonymat, rien n'est rendu : ni
 * moyenne, ni verbatim. Le refus est explicite plutôt que silencieux, sinon on
 * croirait le sondage vide.
 */
function results(surveyId) {
  const survey = byId(surveyId);
  if (!survey) return null;

  const invited = audienceUsers(survey).length;
  const answered = participationCount(surveyId);
  const withheld = answered < ANONYMITY_THRESHOLD;

  const rows = questions(surveyId).map((question) => {
    const values = withheld ? [] : db.prepare('SELECT value FROM survey_answers WHERE question_id = ?').all(question.id).map((r) => r.value);
    const base = { ...question, count: values.length };

    if (question.type === 'echelle') {
      const numbers = values.map(Number).filter((n) => SCALE.includes(n));
      const distribution = SCALE.map((n) => ({ value: n, count: numbers.filter((x) => x === n).length }));
      const average = numbers.length ? Math.round((numbers.reduce((a, b) => a + b, 0) / numbers.length) * 100) / 100 : null;
      return { ...base, average, distribution };
    }
    if (question.type === 'oui_non') {
      return { ...base, distribution: ['Oui', 'Non'].map((v) => ({ value: v, count: values.filter((x) => x === v).length })) };
    }
    if (question.type === 'choix') {
      return { ...base, distribution: question.choiceList.map((v) => ({ value: v, count: values.filter((x) => x === v).length })) };
    }
    return { ...base, verbatims: values };
  });

  return {
    survey,
    invited,
    answered,
    rate: invited ? Math.round((answered / invited) * 100) : 0,
    withheld,
    threshold: ANONYMITY_THRESHOLD,
    questions: rows,
  };
}

/** L'indice du baromètre : la moyenne des échelles de tous les sondages clos. */
function barometer() {
  const closed = list({ status: 'Clos' }).filter((s) => s.kind === 'Baromètre social');
  const points = [];
  for (const survey of closed) {
    const result = results(survey.id);
    if (!result || result.withheld) continue;
    const scales = result.questions.filter((q) => q.type === 'echelle' && q.average !== null);
    if (!scales.length) continue;
    points.push({
      id: survey.id,
      title: survey.title,
      closedOn: survey.closes_on || survey.created_at.slice(0, 10),
      score: Math.round((scales.reduce((a, q) => a + q.average, 0) / scales.length) * 100) / 100,
      answered: result.answered,
      rate: result.rate,
    });
  }
  return points.sort((a, b) => a.closedOn.localeCompare(b.closedOn));
}

module.exports = {
  KINDS, STATUSES, QUESTION_TYPES, AUDIENCES, ANONYMITY_THRESHOLD, SCALE,
  list, byId, create, questions, addQuestion, removeQuestion, open, close, remove,
  audienceUsers, isInvited, hasAnswered, pendingCountFor, openFor, submit,
  participationCount, results, barometer,
};
