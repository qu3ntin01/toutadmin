const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const governance = require('../src/governance');
const surveys = require('../src/surveys');
const deadlines = require('../src/deadlines');
const notifications = require('../src/notifications');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const userByEmail = (email) => db.prepare('SELECT * FROM users WHERE email = ?').get(email);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

async function makeMember(admin, email, extra = {}) {
  await admin.refreshToken('/admin');
  await admin.post('/admin/employes', {
    first_name: 'Test', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', email, ...extra,
  });
  const flash = await admin.flash('/admin');
  const password = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: userByEmail(email).id };
}

const day = (offset) => {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + offset);
  return date.toISOString().slice(0, 10);
};

test('Réunions, décisions et actions', async (t) => {
  const admin = await loginAsAdmin();
  const claire = await makeMember(admin, 'claire.direction@test.local', { first_name: 'Claire', last_name: 'Aubry' });
  let meetingId;

  await t.test('une réunion se convoque avec son ordre du jour', async () => {
    await admin.refreshToken('/direction');
    const res = await admin.post('/direction/reunions', {
      title: 'Comité de direction — septembre',
      kind: 'Comité de direction',
      held_on: day(0),
      starts_at: '09:30',
      ends_at: '11:00',
      location: 'Salle du conseil',
      agenda: 'Point trésorerie\nRecrutements',
    });
    assert.equal(res.status, 302);
    // La convocation ouvre directement la réunion : c'est là qu'on travaille.
    assert.match(res.headers.get('location'), /^\/direction\/reunions\/\d+$/);

    meetingId = Number(res.headers.get('location').split('/').pop());
    const meeting = governance.meetingById(meetingId);
    assert.equal(meeting.title, 'Comité de direction — septembre');
    assert.equal(meeting.status, 'Planifiée');
  });

  await t.test('les participants sont conviés puis pointés', async () => {
    await admin.refreshToken(`/direction/reunions/${meetingId}`);
    await admin.post(`/direction/reunions/${meetingId}/participants`, { user_ids: [String(claire.id)] });
    assert.deepEqual(governance.attendees(meetingId).map((a) => a.user_id), [claire.id]);
    assert.equal(governance.attendees(meetingId)[0].attendance, 'Attendu');

    await admin.post(`/direction/reunions/${meetingId}/participants/${claire.id}/presence`, { attendance: 'Excusé' });
    assert.equal(governance.attendees(meetingId)[0].attendance, 'Excusé');

    // Convier deux fois ne double pas la ligne.
    await admin.post(`/direction/reunions/${meetingId}/participants`, { user_ids: [String(claire.id)] });
    assert.equal(governance.attendees(meetingId).length, 1);
  });

  await t.test('une présence inventée est refusée', async () => {
    await admin.post(`/direction/reunions/${meetingId}/participants/${claire.id}/presence`, { attendance: 'Peut-être' });
    assert.equal(governance.attendees(meetingId)[0].attendance, 'Excusé');
  });

  await t.test('le compte rendu marque la réunion tenue', async () => {
    await admin.post(`/direction/reunions/${meetingId}/compte-rendu`, { minutes: 'La trésorerie tient jusqu\'en mars.' });
    const meeting = governance.meetingById(meetingId);
    assert.equal(meeting.status, 'Tenue');
    assert.match(meeting.minutes, /trésorerie/);
  });

  let decisionId;

  await t.test('une décision prise en séance rejoint le registre', async () => {
    await admin.refreshToken(`/direction/reunions/${meetingId}`);
    await admin.post('/direction/decisions', {
      meeting_id: String(meetingId),
      title: 'Ouvrir une agence à Nantes',
      body: 'Bail signé avant fin mars.',
      rationale: 'Trois clients majeurs y sont installés.',
      decided_on: day(0),
      scope: 'Entreprise',
      review_on: day(120),
    });
    const decisions = governance.decisions({ meetingId });
    assert.equal(decisions.length, 1);
    decisionId = decisions[0].id;
    assert.equal(decisions[0].status, 'En vigueur');
    assert.equal(decisions[0].meeting_title, 'Comité de direction — septembre');
  });

  await t.test('une décision survit à la suppression de sa réunion', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/reunions/${meetingId}/supprimer`, {});
    assert.equal(governance.meetingById(meetingId), null);

    const decision = governance.decisions().find((d) => d.id === decisionId);
    assert.ok(decision, 'le registre des décisions ne suit pas le sort de la réunion');
    assert.equal(decision.meeting_id, null);
  });

  await t.test('une action confiée a un porteur et une échéance', async () => {
    await admin.refreshToken('/direction');
    await admin.post('/direction/actions', {
      label: 'Chiffrer le coût du local',
      assignee_id: String(claire.id),
      due_date: day(5),
      decision_id: String(decisionId),
    });

    const actions = governance.actions({ openOnly: true });
    assert.equal(actions.length, 1);
    assert.equal(actions[0].assignee_id, claire.id);
    assert.equal(actions[0].decision_title, 'Ouvrir une agence à Nantes');
  });

  await t.test('l\'action rejoint les échéances et alerte son porteur', () => {
    const echeance = deadlines.collect().find((row) => row.source === 'Action de direction');
    assert.ok(echeance, 'une action datée doit remonter dans les échéances');
    assert.equal(echeance.label, 'Chiffrer le coût du local');
    assert.deepEqual(echeance.audience, [claire.id]);

    deadlines.notify({ withinDays: 15 });
    const recues = notifications.forUser(claire.id, { limit: 50 });
    assert.ok(recues.some((n) => n.title.includes('Chiffrer le coût du local')));
  });

  await t.test('une action faite sort de la liste et se date', async () => {
    const action = governance.actions({ openOnly: true })[0];
    await admin.refreshToken('/direction');
    await admin.post(`/direction/actions/${action.id}/statut`, { status: 'Faite' });

    assert.equal(governance.actions({ openOnly: true }).length, 0);
    assert.equal(governance.actions()[0].done_on, day(0));
  });

  await t.test('le registre est fermé aux salariés', async () => {
    assert.equal((await claire.client.get('/direction')).status, 403);
    assert.equal((await claire.client.get('/direction/reunions/1')).status, 403);
  });
});

test('Registre des risques', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('un risque coté hors échelle est refusé', async () => {
    await admin.refreshToken('/direction');
    await admin.post('/direction/risques', {
      title: 'Cotation impossible', category: 'Financier', treatment: 'Réduire',
      likelihood: '9', impact: '3',
    });
    const message = await admin.flash('/direction');
    assert.equal(message.type, 'error');
    assert.match(message.message, /échelle/);
    assert.equal(governance.risks().length, 0);
  });

  await t.test('la criticité retenue est la résiduelle quand elle est cotée', async () => {
    await admin.refreshToken('/direction');
    await admin.post('/direction/risques', {
      reference: 'R-2026-01',
      title: 'Dépendance à un client majeur',
      category: 'Stratégique',
      description: '40 % du chiffre d\'affaires sur un seul compte.',
      likelihood: '4', impact: '5',
      treatment: 'Réduire',
      action_plan: 'Prospecter deux comptes équivalents.',
      residual_likelihood: '2', residual_impact: '3',
      identified_on: day(0),
      next_review: day(10),
    });

    const risk = governance.risks()[0];
    assert.equal(risk.gross, 20);
    assert.equal(risk.residual, 6);
    assert.equal(risk.retained, 6, 'le résiduel dit ce qu\'il reste une fois le traitement en place');
    assert.equal(risk.critical, false);
  });

  await t.test('sans cotation résiduelle, c\'est la cotation brute qui compte', async () => {
    await admin.refreshToken('/direction');
    await admin.post('/direction/risques', {
      title: 'Panne du système d\'information', category: 'Informatique',
      likelihood: '3', impact: '5', treatment: 'Accepter',
    });

    const risk = governance.risks().find((r) => r.title.startsWith('Panne'));
    assert.equal(risk.residual, null);
    assert.equal(risk.retained, 15);
    assert.equal(risk.critical, true, 'ne pas coter le résiduel ne doit pas faire passer le risque pour traité');
  });

  await t.test('la matrice place chaque risque sur sa case', () => {
    const matrix = governance.matrix();
    // Probabilité 3, impact 5 : troisième ligne en partant du bas, dernière colonne.
    assert.equal(matrix[governance.SCALE.length - 3][4].length, 1);
    // Le risque traité est retombé sur sa cotation résiduelle (2 × 3).
    assert.equal(matrix[governance.SCALE.length - 2][2].length, 1);
  });

  await t.test('la revue d\'un risque remonte dans les échéances', () => {
    const echeance = deadlines.collect().find((row) => row.source === 'Risque');
    assert.ok(echeance);
    assert.match(echeance.detail, /Revue/);
  });

  await t.test('un risque se met à jour et se clôt', async () => {
    const risk = governance.risks().find((r) => r.reference === 'R-2026-01');
    await admin.refreshToken('/direction');
    await admin.post(`/direction/risques/${risk.id}/modifier`, {
      reference: 'R-2026-01', title: 'Dépendance à un client majeur', category: 'Stratégique',
      likelihood: '4', impact: '5', treatment: 'Réduire',
      residual_likelihood: '1', residual_impact: '2',
      status: 'Maîtrisé', next_review: '',
    });

    const updated = governance.riskById(risk.id);
    assert.equal(updated.status, 'Maîtrisé');
    assert.equal(updated.retained, 2);
  });

  await t.test('le résumé compte ce qui est critique', () => {
    const summary = governance.summary();
    assert.equal(summary.risks, 1, 'un risque maîtrisé ne compte plus comme ouvert');
    assert.equal(summary.criticalRisks, 1);
  });
});

test('Sondages anonymes', async (t) => {
  const admin = await loginAsAdmin();
  const members = [];
  for (const [index, prenom] of ['Alice', 'Bruno', 'Chloé', 'David', 'Elsa'].entries()) {
    members.push(await makeMember(admin, `sondage${index}@test.local`, { first_name: prenom, last_name: `Sond${index}` }));
  }
  let surveyId;

  await t.test('un sondage sans question ne peut pas être ouvert', async () => {
    await admin.refreshToken('/direction');
    await admin.post('/direction/sondages', {
      title: 'Baromètre social — automne', kind: 'Baromètre social', audience: 'Tous',
      intro: 'Cinq minutes, sans votre nom.', closes_on: day(30),
    });
    surveyId = surveys.list()[0].id;

    await admin.post(`/direction/sondages/${surveyId}/ouvrir`, {});
    const message = await admin.flash('/direction');
    assert.equal(message.type, 'error');
    assert.match(message.message, /au moins une question/);
    assert.equal(surveys.byId(surveyId).status, 'Brouillon');
  });

  await t.test('un choix multiple exige au moins deux réponses possibles', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/sondages/${surveyId}/questions`, {
      label: 'Votre service ?', type: 'choix', choices: 'Unique', required: '1',
    });
    const message = await admin.flash('/direction');
    assert.equal(message.type, 'error');
    assert.equal(surveys.questions(surveyId).length, 0);
  });

  await t.test('les questions se posent puis le sondage s\'ouvre', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/sondages/${surveyId}/questions`, {
      label: 'Recommanderiez-vous l\'entreprise ?', type: 'echelle', required: '1',
    });
    await admin.post(`/direction/sondages/${surveyId}/questions`, {
      label: 'Un mot sur l\'ambiance ?', type: 'texte', required: '',
    });
    assert.equal(surveys.questions(surveyId).length, 2);

    await admin.post(`/direction/sondages/${surveyId}/ouvrir`, {});
    assert.equal(surveys.byId(surveyId).status, 'Ouvert');
  });

  await t.test('un sondage ouvert ne se modifie plus', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/sondages/${surveyId}/questions`, {
      label: 'Question tardive', type: 'oui_non', required: '1',
    });
    const message = await admin.flash('/direction');
    assert.equal(message.type, 'error');
    assert.match(message.message, /comparables/);
    assert.equal(surveys.questions(surveyId).length, 2);
  });

  await t.test('le salarié voit le sondage et y répond', async () => {
    const alice = members[0];
    const { body } = await alice.client.html('/sondages');
    assert.match(body, /Baromètre social/);

    const questions = surveys.questions(surveyId);
    await alice.client.refreshToken(`/sondages/${surveyId}`);
    const res = await alice.client.post(`/sondages/${surveyId}`, {
      [`q_${questions[0].id}`]: '4',
      [`q_${questions[1].id}`]: 'Bonne ambiance, trop de réunions.',
    });
    assert.equal(res.status, 302);
    assert.equal(surveys.hasAnswered(surveyId, alice.id), true);
  });

  await t.test('la réponse ne porte aucun lien vers son auteur', () => {
    const columns = db.prepare('PRAGMA table_info(survey_answers)').all().map((c) => c.name);
    assert.equal(columns.includes('user_id'), false, 'l\'anonymat tient à la structure, pas à une promesse');
    assert.deepEqual(columns.sort(), ['id', 'question_id', 'submitted_at', 'value']);

    // Le journal d'audit ne doit pas rendre par la bande ce que les tables refusent.
    const trace = db.prepare("SELECT * FROM audit_log WHERE action = 'sondage.repondu'").get();
    assert.ok(trace, 'la participation est tracée');
    assert.equal(trace.detail.includes('Bonne ambiance'), false);
    assert.equal(trace.detail.includes('4'), false);
  });

  await t.test('on ne répond pas deux fois', async () => {
    const alice = members[0];
    const questions = surveys.questions(surveyId);
    await alice.client.refreshToken('/sondages');
    const res = await alice.client.post(`/sondages/${surveyId}`, { [`q_${questions[0].id}`]: '1' });
    assert.equal(res.status, 302);
    assert.equal(surveys.participationCount(surveyId), 1);

    assert.equal((await alice.client.get(`/sondages/${surveyId}`)).status, 302);
  });

  await t.test('une réponse hors échelle est refusée', () => {
    const questions = surveys.questions(surveyId);
    const verdict = surveys.submit(surveyId, members[1].id, { [`q_${questions[0].id}`]: '9' });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /échelle/);
    assert.equal(surveys.participationCount(surveyId), 1);
  });

  await t.test('une question obligatoire sans réponse est refusée', () => {
    const questions = surveys.questions(surveyId);
    const verdict = surveys.submit(surveyId, members[1].id, { [`q_${questions[1].id}`]: 'Rien à dire' });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /sans réponse/);
  });

  await t.test('sous le seuil, aucun résultat n\'est rendu', () => {
    const result = surveys.results(surveyId);
    assert.equal(result.answered, 1);
    assert.equal(result.withheld, true);
    assert.equal(result.questions[0].average, null);
    assert.deepEqual(result.questions[0].distribution, [1, 2, 3, 4, 5].map((v) => ({ value: v, count: 0 })));
  });

  await t.test('au-delà du seuil, les résultats s\'agrègent', () => {
    const questions = surveys.questions(surveyId);
    for (const [index, note] of [3, 5, 4, 2].entries()) {
      const verdict = surveys.submit(surveyId, members[index + 1].id, {
        [`q_${questions[0].id}`]: String(note),
        [`q_${questions[1].id}`]: index === 0 ? 'Beaucoup de réunions.' : '',
      });
      assert.equal(verdict.ok, true, verdict.message);
    }

    const result = surveys.results(surveyId);
    assert.equal(result.answered, 5);
    assert.equal(result.withheld, false);
    assert.equal(result.questions[0].average, 3.6);
    assert.equal(result.questions[0].distribution.find((d) => d.value === 4).count, 2);
    assert.equal(result.questions[1].verbatims.length, 2);
    // Six salariés conviés à ce stade, cinq ont répondu.
    assert.equal(result.invited, 6);
    assert.equal(result.rate, 83);
  });

  await t.test('l\'écran de résultats est réservé à la direction', async () => {
    const admin2 = await loginAsAdmin();
    const { res, body } = await admin2.html(`/direction/sondages/${surveyId}/resultats`);
    assert.equal(res.status, 200);
    assert.match(body, /3\.6/);

    assert.equal((await members[0].client.get(`/direction/sondages/${surveyId}/resultats`)).status, 403);
  });

  await t.test('le baromètre suit les sondages clos', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/sondages/${surveyId}/clore`, {});
    assert.equal(surveys.byId(surveyId).status, 'Clos');

    const points = surveys.barometer();
    assert.equal(points.length, 1);
    assert.equal(points[0].score, 3.6);
    assert.equal(points[0].answered, 5);
  });

  await t.test('un sondage clos ne compte plus dans les invitations', () => {
    assert.equal(surveys.pendingCountFor(members[0].id), 0);
    assert.equal(surveys.openFor(members[1].id).length, 0);
  });

  await t.test('la suppression emporte les réponses', async () => {
    await admin.refreshToken('/direction');
    await admin.post(`/direction/sondages/${surveyId}/supprimer`, {});
    assert.equal(surveys.byId(surveyId), null);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM survey_answers').get().n, 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM survey_participations').get().n, 0);
  });
});

test('Sondage restreint à un service', async (t) => {
  const admin = await loginAsAdmin();
  const departmentId = db.prepare("INSERT INTO departments (name, description) VALUES ('Atelier', '')").run().lastInsertRowid;
  const dedans = await makeMember(admin, 'atelier.dedans@test.local', { first_name: 'Iris', last_name: 'Atelier', department_id: String(departmentId) });
  const dehors = await makeMember(admin, 'atelier.dehors@test.local', { first_name: 'Hugo', last_name: 'Ailleurs' });

  await admin.refreshToken('/direction');
  await admin.post('/direction/sondages', {
    title: 'Conditions de travail — atelier', kind: 'Enquête', audience: 'Service',
    audience_id: String(departmentId),
  });
  const survey = surveys.list()[0];
  await admin.post(`/direction/sondages/${survey.id}/questions`, { label: 'Le poste est-il confortable ?', type: 'oui_non', required: '1' });
  await admin.post(`/direction/sondages/${survey.id}/ouvrir`, {});

  await t.test('seule la population conviée le voit', () => {
    assert.equal(surveys.pendingCountFor(dedans.id), 1);
    assert.equal(surveys.pendingCountFor(dehors.id), 0);
    assert.equal(surveys.isInvited(surveys.byId(survey.id), dehors.id), false);
  });

  await t.test('un salarié hors population ne peut pas répondre', async () => {
    assert.equal((await dehors.client.get(`/sondages/${survey.id}`)).status, 404);

    const verdict = surveys.submit(survey.id, dehors.id, {});
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /ne vous est pas destiné/);
  });

  await t.test('la participation se compte sur la population conviée', () => {
    const questions = surveys.questions(survey.id);
    surveys.submit(survey.id, dedans.id, { [`q_${questions[0].id}`]: 'Oui' });

    const result = surveys.results(survey.id);
    assert.equal(result.invited, 1);
    assert.equal(result.answered, 1);
    assert.equal(result.rate, 100);
    assert.equal(result.withheld, true, 'un service de une personne ne peut pas rendre de résultat anonyme');
  });
});
