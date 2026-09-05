const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const support = require('../src/support');

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
  const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: userByEmail(email).id };
}

test('Tickets et support', async (t) => {
  const admin = await loginAsAdmin();
  const demandeur = await makeMember(admin, 'demandeur@test.local');
  let ticketId;

  await t.test('le délai de première réponse découle de la priorité', async () => {
    await demandeur.client.refreshToken('/support');
    await demandeur.client.post('/support/tickets', {
      subject: 'Écran de portable cassé', category: 'Informatique', priority: 'Critique',
      body: 'La dalle est fendue depuis ce matin.',
    });

    const ticket = db.prepare('SELECT * FROM tickets').get();
    ticketId = ticket.id;
    assert.equal(ticket.reference, 'T-00001');
    assert.equal(ticket.status, 'Ouvert');

    // Critique : deux heures après l'ouverture.
    const ecart = (new Date(ticket.due_at) - new Date(ticket.created_at.replace(' ', 'T') + 'Z')) / 3600000;
    assert.ok(Math.abs(ecart - 2) < 0.05, `délai attendu ~2 h, obtenu ${ecart}`);
  });

  await t.test('changer la priorité recalcule le délai depuis l\'ouverture', async () => {
    await admin.refreshToken(`/support/tickets/${ticketId}`);
    await admin.post(`/support/tickets/${ticketId}/priorite`, { priority: 'Basse' });

    const ticket = db.prepare('SELECT * FROM tickets WHERE id = ?').get(ticketId);
    const ecart = (new Date(ticket.due_at) - new Date(ticket.created_at.replace(' ', 'T') + 'Z')) / 3600000;
    assert.ok(Math.abs(ecart - 72) < 0.05, `délai attendu ~72 h, obtenu ${ecart}`);
  });

  await t.test('un ticket sans réponse dépassant son délai est signalé en retard', () => {
    db.prepare("UPDATE tickets SET due_at = ? WHERE id = ?").run(new Date(Date.now() - 3600000).toISOString(), ticketId);
    assert.equal(support.isOverdue(support.byId(ticketId)), true);

    // Une réponse arrête le compteur, même si le ticket reste ouvert.
    support.reply({ ticketId, authorId: null, body: 'Nous regardons.' });
    assert.equal(support.isOverdue(support.byId(ticketId)), false);
  });

  await t.test('une note interne reste invisible pour le demandeur', async () => {
    await admin.refreshToken(`/support/tickets/${ticketId}`);
    await admin.post(`/support/tickets/${ticketId}/repondre`, { body: 'Stock de dalles épuisé, à commander.', internal: '1' });

    const { body: vuAdmin } = await admin.html(`/support/tickets/${ticketId}`);
    assert.match(vuAdmin, /Stock de dalles/);

    const { body: vuDemandeur } = await demandeur.client.html(`/support/tickets/${ticketId}`);
    assert.equal(/Stock de dalles/.test(vuDemandeur), false, 'la note interne ne fuit pas');
    assert.match(vuDemandeur, /Nous regardons/);
  });

  await t.test('une note interne ne compte pas comme première réponse', () => {
    const ticket = support.create({ subject: 'Second cas', category: 'Autre', priority: 'Normale', origin: 'Interne', requesterId: demandeur.id });
    support.reply({ ticketId: ticket, authorId: null, body: 'Note pour nous', internal: true });
    assert.equal(support.byId(ticket).first_reply_at, null);

    support.reply({ ticketId: ticket, authorId: null, body: 'Bonjour, nous prenons en charge.' });
    assert.ok(support.byId(ticket).first_reply_at);
    support.remove(ticket);
  });

  await t.test("un demandeur ne voit pas le ticket d'un autre", async () => {
    const autre = await makeMember(admin, 'autre-demandeur@test.local');
    assert.equal((await autre.client.get(`/support/tickets/${ticketId}`)).status, 403);

    // Ni ne le traite.
    await autre.client.refreshToken('/support');
    assert.equal((await autre.client.post(`/support/tickets/${ticketId}/statut`, { status: 'Clos' })).status, 403);
    assert.equal(db.prepare('SELECT status FROM tickets WHERE id = ?').get(ticketId).status, 'Ouvert');
  });

  await t.test('un salarié ne peut pas ouvrir un ticket au nom d\'un client', async () => {
    await demandeur.client.refreshToken('/support');
    await demandeur.client.post('/support/tickets', {
      subject: 'Tentative', category: 'Client', priority: 'Normale', origin: 'Client', partner_id: '1',
    });
    const ticket = db.prepare("SELECT * FROM tickets WHERE subject = 'Tentative'").get();
    assert.equal(ticket.origin, 'Interne');
    assert.equal(ticket.partner_id, null);
  });

  await t.test("une demande RH ne se lit pas depuis la gestion", async () => {
    // Un membre désigné à la gestion, mais pas aux RH.
    const gestionnaire = await makeMember(admin, 'gestionnaire@test.local');
    db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(gestionnaire.id);

    await demandeur.client.refreshToken('/support');
    await demandeur.client.post('/support/tickets', {
      subject: 'Erreur sur mon bulletin de paie', category: 'Ressources humaines', priority: 'Haute',
      body: 'Le net ne correspond pas.',
    });
    const rh = db.prepare("SELECT * FROM tickets WHERE category = 'Ressources humaines'").get();

    // La gestion ne le voit ni en fiche, ni en liste, ni ne le traite.
    assert.equal((await gestionnaire.client.get(`/support/tickets/${rh.id}`)).status, 403);
    const { body } = await gestionnaire.client.html('/support');
    assert.equal(/Erreur sur mon bulletin/.test(body), false);

    await gestionnaire.client.refreshToken('/support');
    assert.equal((await gestionnaire.client.post(`/support/tickets/${rh.id}/statut`, { status: 'Clos' })).status, 403);
    assert.equal(db.prepare('SELECT status FROM tickets WHERE id = ?').get(rh.id).status, 'Ouvert');

    // Un ticket informatique, lui, lui est ouvert.
    assert.equal((await gestionnaire.client.get(`/support/tickets/${ticketId}`)).status, 200);

    // Et l'administration voit tout.
    assert.equal((await admin.get(`/support/tickets/${rh.id}`)).status, 200);
  });

  await t.test('résoudre un ticket pose sa date de clôture', async () => {
    await admin.refreshToken(`/support/tickets/${ticketId}`);
    await admin.post(`/support/tickets/${ticketId}/statut`, { status: 'Résolu' });
    const ticket = support.byId(ticketId);
    assert.equal(ticket.status, 'Résolu');
    assert.ok(ticket.closed_at);

    // Rouvrir efface la clôture.
    await admin.refreshToken(`/support/tickets/${ticketId}`);
    await admin.post(`/support/tickets/${ticketId}/statut`, { status: 'En cours' });
    assert.equal(support.byId(ticketId).closed_at, null);
  });
});

test('Base de connaissances', async (t) => {
  const admin = await loginAsAdmin();

  await t.test("publie un article et le rend cherchable", async () => {
    await admin.refreshToken('/base-de-connaissances');
    await admin.post('/base-de-connaissances', {
      title: 'Demander un badge d\'accès', category: 'Moyens généraux', visibility: 'Entreprise',
      body: 'Adressez la demande au service des moyens généraux, avec votre photo.',
    });

    const article = db.prepare('SELECT * FROM kb_articles').get();
    assert.equal(article.published, 1);
    assert.equal(support.articles({ query: 'badge' }).length, 1);
    assert.equal(support.articles({ query: 'introuvable' }).length, 0);
  });

  await t.test('un article de portée service ne sort pas de ce service', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/services', { name: 'Studio' });
    const studio = db.prepare("SELECT id FROM departments WHERE name = 'Studio'").get().id;

    await admin.refreshToken('/base-de-connaissances');
    await admin.post('/base-de-connaissances', {
      title: 'Procédure de rendu', category: 'Studio', visibility: 'Service',
      scope_id: String(studio), body: 'Réservé au studio.',
    });

    const article = db.prepare("SELECT * FROM kb_articles WHERE title = 'Procédure de rendu'").get();
    assert.equal(support.canRead(article, { role: 'employee', department_id: studio, team_id: null }), true);
    assert.equal(support.canRead(article, { role: 'employee', department_id: null, team_id: null }), false);
    // L'administration voit tout : c'est elle qui répond des contenus.
    assert.equal(support.canRead(article, { role: 'admin' }), true);
  });

  await t.test("un article d'administration n'est jamais lu par un salarié", async () => {
    await admin.refreshToken('/base-de-connaissances');
    await admin.post('/base-de-connaissances', {
      title: 'Clés de secours', category: 'Interne', visibility: 'Administration', body: 'Coffre du bureau 2.',
    });
    const article = db.prepare("SELECT * FROM kb_articles WHERE title = 'Clés de secours'").get();

    const { client } = await (async () => {
      await admin.refreshToken('/admin');
      await admin.post('/admin/employes', {
        first_name: 'Curieux', last_name: 'Lecteur', grade: 'Employé', contract_type: 'CDI', email: 'lecteur@test.local',
      });
      const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
      const c = newClient();
      await c.firstAccess('lecteur@test.local', password);
      return { client: c };
    })();

    assert.equal((await client.get(`/base-de-connaissances/${article.id}`)).status, 403);
    const { body } = await client.html('/base-de-connaissances');
    assert.equal(/Clés de secours/.test(body), false);

    // Et il ne rédige pas non plus.
    await client.refreshToken('/base-de-connaissances');
    assert.equal((await client.post('/base-de-connaissances', { title: 'Faux', visibility: 'Entreprise' })).status, 403);
  });

  await t.test('un brouillon ne se lit pas et ne se liste pas', async () => {
    const article = db.prepare("SELECT * FROM kb_articles WHERE title = 'Demander un badge d''accès'").get();
    await admin.refreshToken(`/base-de-connaissances/${article.id}`);
    await admin.post(`/base-de-connaissances/${article.id}/modifier`, {
      title: article.title, category: article.category, visibility: 'Entreprise', body: article.body,
    });

    assert.equal(db.prepare('SELECT published FROM kb_articles WHERE id = ?').get(article.id).published, 0);
    assert.equal(support.articles({ query: 'badge' }).length, 0);
  });
});
