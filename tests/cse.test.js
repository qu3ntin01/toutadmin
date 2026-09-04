const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');

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
  await admin.post('/admin/employes', {
    first_name: 'Test', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', email, ...extra,
  });
  const flash = await admin.flash('/admin');
  const password = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.login(email, password);
  return { client, id: userByEmail(email).id };
}

test('CSE', async (t) => {
  const admin = await loginAsAdmin();
  const alice = await makeMember(admin, 'alice.cse@test.local', { first_name: 'Alice', last_name: 'Renard' });
  const bruno = await makeMember(admin, 'bruno.cse@test.local', { first_name: 'Bruno', last_name: 'Milan' });
  const freelance = await makeMember(admin, 'freelance.cse@test.local', {
    first_name: 'Fara', last_name: 'Nova', contract_type: 'Freelance', daily_rate: '500',
  });

  await t.test('ferme le CSE aux freelances, que le comité ne représente pas', async () => {
    const res = await freelance.client.get('/cse');
    assert.equal(res.status, 403);

    const { body } = await freelance.client.html('/mon-espace');
    assert.doesNotMatch(body, /href="\/cse"/);
  });

  await t.test("ouvre le CSE aux salariés, sans espace de gestion tant qu'ils ne sont pas élus", async () => {
    const { res, body } = await alice.client.html('/cse');
    assert.equal(res.status, 200);
    assert.match(body, /Comité social et économique/);

    const gestion = await alice.client.get('/cse/gestion');
    assert.equal(gestion.status, 403);
  });

  await t.test("refuse l'espace de gestion à un salarié non élu même en accès direct", async () => {
    const res = await bruno.client.post('/cse/gestion/avantages', { title: 'Avantage pirate' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_benefits').get().n, 0);
  });

  let electionId;

  await t.test('crée une élection depuis les ressources humaines', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/elections', { title: 'Élection CSE 2026', seats: '2', candidacy_deadline: '2030-01-31' });

    const election = db.prepare('SELECT * FROM cse_elections').get();
    assert.equal(election.title, 'Élection CSE 2026');
    assert.equal(election.status, 'Candidatures');
    electionId = election.id;
  });

  await t.test('rejette une élection sans sièges valides', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/elections', { title: 'Scrutin bancal', seats: '0' });
    assert.match((await admin.flash('/rh')).message, /sièges/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_elections').get().n, 1);
  });

  await t.test('permet à un salarié de se présenter', async () => {
    await alice.client.refreshToken('/cse');
    await alice.client.post('/cse/candidature', { statement: 'Je porte le budget des activités sociales.' });

    const candidacy = db.prepare('SELECT * FROM cse_candidacies WHERE user_id = ?').get(alice.id);
    assert.equal(candidacy.status, 'En attente');
    assert.match(candidacy.statement, /activités sociales/);
  });

  await t.test('refuse une seconde candidature du même salarié', async () => {
    await alice.client.refreshToken('/cse');
    await alice.client.post('/cse/candidature', { statement: 'Encore moi.' });
    assert.match((await alice.client.flash('/cse')).message, /déjà déposé/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_candidacies').get().n, 1);
  });

  await t.test("empêche d'ouvrir le vote sans candidature validée", async () => {
    await admin.refreshToken('/rh');
    await admin.post(`/rh/cse/elections/${electionId}/statut`, { status: 'Vote' });
    assert.match((await admin.flash('/rh')).message, /scrutin serait vide/);
    assert.equal(db.prepare('SELECT status FROM cse_elections WHERE id = ?').get(electionId).status, 'Candidatures');
  });

  await t.test('valide la candidature puis ouvre le vote', async () => {
    const candidacy = db.prepare('SELECT * FROM cse_candidacies').get();
    await admin.refreshToken('/rh');
    await admin.post(`/rh/cse/candidatures/${candidacy.id}/statut`, { status: 'Validée' });
    assert.equal(db.prepare('SELECT status FROM cse_candidacies WHERE id = ?').get(candidacy.id).status, 'Validée');

    await admin.refreshToken('/rh');
    await admin.post(`/rh/cse/elections/${electionId}/statut`, { status: 'Vote' });
    assert.equal(db.prepare('SELECT status FROM cse_elections WHERE id = ?').get(electionId).status, 'Vote');
  });

  await t.test('interdit de retirer sa candidature une fois le vote ouvert', async () => {
    await alice.client.refreshToken('/cse');
    await alice.client.post('/cse/candidature/retirer');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_candidacies').get().n, 1);
  });

  await t.test('enregistre un vote sans relier le bulletin à son électeur', async () => {
    const candidacy = db.prepare('SELECT * FROM cse_candidacies').get();
    await bruno.client.refreshToken('/cse');
    await bruno.client.post('/cse/vote', { candidacy_id: String(candidacy.id) });

    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_ballots WHERE candidacy_id = ?').get(candidacy.id).n, 1);
    // L'émargement dit qui a voté ; le bulletin ne dit pas pour qui.
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_voters WHERE user_id = ?').get(bruno.id).n, 1);
    const ballotColumns = db.prepare('PRAGMA table_info(cse_ballots)').all().map((c) => c.name);
    assert.ok(!ballotColumns.includes('user_id'), 'un bulletin ne doit porter aucune référence à son électeur');
  });

  await t.test('refuse un second vote du même salarié', async () => {
    const candidacy = db.prepare('SELECT * FROM cse_candidacies').get();
    await bruno.client.refreshToken('/cse');
    await bruno.client.post('/cse/vote', { candidacy_id: String(candidacy.id) });
    assert.match((await bruno.client.flash('/cse')).message, /déjà voté/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_ballots').get().n, 1);
  });

  await t.test('refuse le vote d\'un freelance, hors du corps électoral', async () => {
    const candidacy = db.prepare('SELECT * FROM cse_candidacies').get();
    await freelance.client.refreshToken('/mon-espace');
    const res = await freelance.client.post('/cse/vote', { candidacy_id: String(candidacy.id) });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_ballots').get().n, 1);
  });

  await t.test('clôture le scrutin et publie les résultats', async () => {
    await admin.refreshToken('/rh');
    await admin.post(`/rh/cse/elections/${electionId}/statut`, { status: 'Clôturée' });

    const { body } = await alice.client.html('/cse');
    assert.match(body, /Résultats/);
    assert.match(body, /Alice Renard/);
  });

  await t.test('ouvre la gestion aux élus enregistrés par les RH', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/mandats', {
      user_id: String(alice.id), mandate_role: 'Secrétaire', started_on: '2026-01-01',
    });

    const { res, body } = await alice.client.html('/cse/gestion');
    assert.equal(res.status, 200);
    assert.match(body, /Gestion du CSE/);

    // Le mandat n'ouvre rien aux autres salariés.
    assert.equal((await bruno.client.get('/cse/gestion')).status, 403);
  });

  await t.test('ferme la gestion dès que le mandat est échu', async () => {
    db.prepare("UPDATE cse_mandates SET ends_on = '2020-12-31' WHERE user_id = ?").run(alice.id);
    assert.equal((await alice.client.get('/cse/gestion')).status, 403);
    db.prepare('UPDATE cse_mandates SET ends_on = NULL WHERE user_id = ?').run(alice.id);
  });

  await t.test('publie un avantage, visible par les salariés', async () => {
    await alice.client.refreshToken('/cse/gestion');
    await alice.client.post('/cse/gestion/avantages', {
      title: 'Cinéma à tarif comité', category: 'Culture', partner: 'Grand Écran',
      discount: '-40 %', code: 'CSE-CINE', url: 'https://exemple.test/cine',
    });

    const { body } = await bruno.client.html('/cse');
    assert.match(body, /Cinéma à tarif comité/);
    assert.match(body, /CSE-CINE/);
  });

  await t.test('rejette un lien qui n\'est pas une adresse http(s)', async () => {
    await alice.client.refreshToken('/cse/gestion');
    await alice.client.post('/cse/gestion/avantages', { title: 'Piège', url: 'javascript:alert(1)' });
    assert.match((await alice.client.flash('/cse/gestion')).message, /Lien invalide/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM cse_benefits WHERE title = 'Piège'").get().n, 0);
  });

  await t.test('masque un avantage dont la validité est passée', async () => {
    db.prepare("UPDATE cse_benefits SET valid_until = '2020-01-01' WHERE title = 'Cinéma à tarif comité'").run();
    const { body } = await bruno.client.html('/cse');
    assert.doesNotMatch(body, /Cinéma à tarif comité/);
    db.prepare("UPDATE cse_benefits SET valid_until = NULL WHERE title = 'Cinéma à tarif comité'").run();
  });

  await t.test('convoque une réunion côté RH et publie son compte-rendu côté élus', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/reunions', {
      title: 'Réunion mensuelle', meeting_date: '2030-03-12', meeting_time: '14:00', location: 'Salle Atlas',
      agenda: 'Budget des activités sociales.',
    });

    const meeting = db.prepare('SELECT * FROM cse_meetings').get();
    assert.equal(meeting.minutes_published, 0);

    // Tant que le compte-rendu n'est pas publié, le salarié ne le voit pas.
    let staffView = await bruno.client.html('/cse');
    assert.doesNotMatch(staffView.body, /porté à 0,8 %/);

    await alice.client.refreshToken('/cse/gestion');
    await alice.client.post(`/cse/gestion/reunions/${meeting.id}/compte-rendu`, {
      minutes: 'Le budget est porté à 0,8 % de la masse salariale.', publish: 'on',
    });

    staffView = await bruno.client.html('/cse');
    assert.match(staffView.body, /porté à 0,8 %/);
  });

  await t.test('rejette une heure de réunion invalide', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/reunions', { title: 'Réunion bancale', meeting_date: '2030-04-01', meeting_time: '25:99' });
    assert.match((await admin.flash('/rh')).message, /Heure de réunion/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM cse_meetings').get().n, 1);
  });
});

test('Agenda', async (t) => {
  const admin = await loginAsAdmin();
  const claire = await makeMember(admin, 'claire.agenda@test.local', { first_name: 'Claire', last_name: 'Aubert' });

  await t.test('affiche le mois courant et navigue de mois en mois', async () => {
    const { res, body } = await claire.client.html('/agenda?mois=2026-02');
    assert.equal(res.status, 200);
    assert.match(body, /mois=2026-01/);
    assert.match(body, /mois=2026-03/);
  });

  await t.test('retombe sur le mois courant quand le paramètre est absurde', async () => {
    const { res } = await claire.client.html('/agenda?mois=2026-13');
    assert.equal(res.status, 200);
  });

  await t.test('ajoute un événement personnel et le montre dans la grille', async () => {
    await claire.client.refreshToken('/agenda?mois=2026-05');
    await claire.client.post('/agenda', {
      mois: '2026-05', title: 'Point mensuel', start_date: '2026-05-14', end_date: '2026-05-14',
      start_time: '09:30', end_time: '10:30', category: 'Réunion', location: 'Salle Vega',
    });

    const { body } = await claire.client.html('/agenda?mois=2026-05');
    assert.match(body, /Point mensuel/);
    assert.match(body, /Salle Vega/);
    assert.match(body, /09:30/);
  });

  await t.test('refuse une fin antérieure au début', async () => {
    await claire.client.refreshToken('/agenda?mois=2026-05');
    await claire.client.post('/agenda', {
      mois: '2026-05', title: 'À rebours', start_date: '2026-05-20', end_date: '2026-05-10',
    });
    assert.match((await claire.client.flash('/agenda')).message, /précède la date de début/);
  });

  await t.test('refuse une heure de fin antérieure sur une même journée', async () => {
    await claire.client.refreshToken('/agenda?mois=2026-05');
    await claire.client.post('/agenda', {
      mois: '2026-05', title: 'Créneau impossible', start_date: '2026-05-20', end_date: '2026-05-20',
      start_time: '15:00', end_time: '09:00',
    });
    assert.match((await claire.client.flash('/agenda')).message, /heure de fin/i);
  });

  await t.test('étale un événement de plusieurs jours sur chacun d\'eux', async () => {
    await claire.client.refreshToken('/agenda?mois=2026-06');
    await claire.client.post('/agenda', {
      mois: '2026-06', title: 'Séminaire', start_date: '2026-06-02', end_date: '2026-06-04', all_day: 'on',
    });

    const { body } = await claire.client.html('/agenda?mois=2026-06');
    assert.equal((body.match(/Séminaire/g) || []).length >= 3, true);
  });

  await t.test('reprend les congés approuvés sans les ressaisir', async () => {
    await claire.client.refreshToken('/mon-espace');
    await claire.client.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: '2026-07-06', end_date: '2026-07-10', reason: 'Vacances',
    });

    const request = db.prepare('SELECT * FROM hr_requests WHERE employee_id = ?').get(claire.id);
    await admin.refreshToken('/rh');
    await admin.post(`/rh/demandes/${request.id}/approuver`, {});

    const { body } = await claire.client.html('/agenda?mois=2026-07');
    assert.match(body, /Congés payés/);
  });

  await t.test('ne laisse pas supprimer une entrée dérivée ni l\'événement d\'autrui', async () => {
    const event = db.prepare("SELECT * FROM calendar_events WHERE title = 'Point mensuel'").get();
    const other = await makeMember(admin, 'autre.agenda@test.local', { first_name: 'Autre', last_name: 'Membre' });

    await other.client.refreshToken('/agenda');
    await other.client.post(`/agenda/${event.id}/supprimer`, { mois: '2026-05' });
    assert.ok(db.prepare('SELECT * FROM calendar_events WHERE id = ?').get(event.id), "l'événement d'un autre membre doit rester");

    await claire.client.refreshToken('/agenda?mois=2026-05');
    await claire.client.post(`/agenda/${event.id}/supprimer`, { mois: '2026-05' });
    assert.equal(db.prepare('SELECT * FROM calendar_events WHERE id = ?').get(event.id), undefined);
  });

  await t.test('inscrit les réunions du CSE à l\'agenda des salariés représentés', async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/cse/reunions', { title: 'Réunion CSE de septembre', meeting_date: '2026-09-24', meeting_time: '14:00' });

    const { body } = await claire.client.html('/agenda?mois=2026-09');
    assert.match(body, /Réunion CSE de septembre/);
  });
});
