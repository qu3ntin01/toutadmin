const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const deadlines = require('../src/deadlines');
const notifications = require('../src/notifications');
const steering = require('../src/steering');
const privacy = require('../src/privacy');
const search = require('../src/search');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const userByEmail = (email) => db.prepare('SELECT * FROM users WHERE email = ?').get(email);
const inDays = (n) => {
  const d = new Date();
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
};

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

test('Échéances et notifications', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'echeances@test.local', { first_name: 'Elsa', last_name: 'Echeance' });

  await t.test('rassemble les échéances de tous les espaces', async () => {
    // Une habilitation qui expire dans dix jours.
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences', { name: 'Habilitation test', category: 'Sécurité', validity_months: '12' });
    const skill = db.prepare("SELECT * FROM skills WHERE name = 'Habilitation test'").get();
    await admin.refreshToken('/parcours');
    await admin.post('/parcours/competences/attribuer', {
      user_id: String(membre.id), skill_id: String(skill.id), level: '2', obtained_on: inDays(-355),
    });

    // Un véhicule dont le contrôle technique est dépassé.
    await admin.refreshToken('/flotte');
    await admin.post('/flotte', { registration: 'ZZ-999-ZZ', kind: 'Voiture', inspection_due: inDays(-5) });

    const collected = deadlines.collect();
    const sources = new Set(collected.map((row) => row.source));
    assert.ok(sources.has('Habilitation'), 'les habilitations remontent');
    assert.ok(sources.has('Véhicule'), 'les véhicules remontent');
    assert.equal(collected.some((row) => row.source === 'Véhicule' && row.overdue), true);
    // La liste est triée par date, la plus proche d'abord.
    const dates = collected.map((row) => row.due);
    assert.deepEqual(dates, [...dates].sort());
  });

  await t.test("une même échéance n'alerte qu'une fois, même rejouée", () => {
    const first = deadlines.notify({ withinDays: 45 });
    assert.ok(first > 0, 'le premier passage crée des notifications');
    assert.equal(deadlines.notify({ withinDays: 45 }), 0, 'le second passage n\'en crée aucune');
  });

  await t.test('la personne concernée est notifiée, et peut marquer lu', async () => {
    assert.ok(notifications.unreadCount(membre.id) > 0, 'le porteur de l\'habilitation est prévenu');

    const { body } = await membre.client.html('/notifications');
    assert.match(body, /Habilitation/);

    const item = notifications.forUser(membre.id)[0];
    await membre.client.refreshToken('/notifications');
    await membre.client.post(`/notifications/${item.id}/lue`);
    assert.ok(db.prepare('SELECT read_at FROM notifications WHERE id = ?').get(item.id).read_at);
  });

  await t.test("on ne marque pas lue la notification d'un autre", async () => {
    const autre = await makeMember(admin, 'voisin@test.local');
    const cible = notifications.forUser(membre.id, { unreadOnly: true })[0];
    if (!cible) return;

    await autre.client.refreshToken('/notifications');
    await autre.client.post(`/notifications/${cible.id}/lue`);
    assert.equal(db.prepare('SELECT read_at FROM notifications WHERE id = ?').get(cible.id).read_at, null);
  });
});

test('Pilotage et objectifs', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('un résultat clé se mesure sur sa propre échelle', () => {
    // De 40 vers 60 : à 50, on est à la moitié, pas à 83 %.
    assert.equal(steering.progressOf([{ start_value: 40, target_value: 60, current_value: 50 }]), 50);
    // Une cible décroissante compte aussi bien : de 24 h vers 4 h, à 14 h on est à la moitié.
    assert.equal(steering.progressOf([{ start_value: 24, target_value: 4, current_value: 14 }]), 50);
    // Un dépassement ne fait pas plus de 100 %.
    assert.equal(steering.progressOf([{ start_value: 0, target_value: 10, current_value: 25 }]), 100);
    assert.equal(steering.progressOf([]), 0);
  });

  await t.test("un objectif prend l'avancement moyen de ses résultats", async () => {
    await admin.refreshToken('/pilotage');
    await admin.post('/pilotage/objectifs', {
      title: 'Réduire le délai de réponse', scope: 'Entreprise', period: '2026-T1',
    });
    const objective = db.prepare('SELECT * FROM objectives').get();

    for (const [title, start, target, current] of [['Délai médian', '24', '4', '14'], ['Tickets hors délai', '20', '0', '20']]) {
      await admin.refreshToken('/pilotage');
      await admin.post(`/pilotage/objectifs/${objective.id}/resultats`, {
        title, start_value: start, target_value: target, current_value: current, unit: 'h',
      });
    }

    const loaded = steering.objectives().find((o) => o.id === objective.id);
    assert.equal(loaded.results.length, 2);
    assert.equal(loaded.progress, 25);  // moyenne de 50 % et 0 %
  });

  await t.test('refuse un résultat clé dont le départ égale la cible', async () => {
    const objective = db.prepare('SELECT * FROM objectives').get();
    await admin.refreshToken('/pilotage');
    await admin.post(`/pilotage/objectifs/${objective.id}/resultats`, {
      title: 'Immobile', start_value: '10', target_value: '10', current_value: '10',
    });
    assert.match((await admin.flash('/pilotage')).message, /rien à mesurer/);
  });

  await t.test('le pilotage est fermé au personnel', async () => {
    const { client } = await makeMember(admin, 'sans-pilotage@test.local');
    assert.equal((await client.get('/pilotage')).status, 403);
  });
});

test('Recherche globale', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'chercheur@test.local', { first_name: 'Cyril', last_name: 'Chercheur' });

  await t.test('ignore une requête trop courte', () => {
    assert.deepEqual(search.search('a', db.prepare('SELECT * FROM users WHERE id = ?').get(membre.id)), []);
  });

  await t.test("un salarié ne trouve ni les tiers ni les candidatures", async () => {
    await admin.refreshToken('/gestion');
    await admin.post('/gestion/tiers', { name: 'Fournisseur Confidentiel', kind: 'Fournisseur', email: 'x@y.test' });

    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(membre.id);
    const sources = search.search('Confidentiel', user).map((g) => g.source);
    assert.equal(sources.includes('Tiers'), false);

    const adminUser = db.prepare('SELECT * FROM users WHERE email = ?').get(ADMIN_EMAIL);
    assert.equal(search.search('Confidentiel', adminUser).some((g) => g.source === 'Tiers'), true);
  });

  await t.test("ne rend pas les projets auxquels on n'appartient pas", async () => {
    await admin.refreshToken('/projets');
    await admin.post('/projets', { name: 'Projet Cloisonné' });

    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(membre.id);
    assert.equal(search.search('Cloisonné', user).some((g) => g.source === 'Projets'), false);

    const project = db.prepare("SELECT id FROM projects WHERE name = 'Projet Cloisonné'").get();
    await admin.refreshToken(`/projets/${project.id}`);
    await admin.post(`/projets/${project.id}/membres`, { user_id: String(membre.id) });
    assert.equal(search.search('Cloisonné', user).some((g) => g.source === 'Projets'), true);
  });

  await t.test("un membre masqué de l'annuaire n'y est pas trouvé", async () => {
    const masque = await makeMember(admin, 'invisible@test.local', { first_name: 'Iris', last_name: 'Invisible' });
    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(membre.id);
    assert.equal(search.search('Invisible', user).some((g) => g.source === 'Annuaire'), true);

    await admin.refreshToken('/admin');
    await admin.post(`/admin/employes/${masque.id}/annuaire`);
    assert.equal(search.search('Invisible', user).some((g) => g.source === 'Annuaire'), false);
  });
});

test('Données personnelles', async (t) => {
  const admin = await loginAsAdmin();
  const membre = await makeMember(admin, 'a-effacer@test.local', { first_name: 'Paul', last_name: 'Partant' });

  await t.test('le registre se préremplit une seule fois', async () => {
    await admin.refreshToken('/rgpd');
    await admin.post('/rgpd/traitements/amorcer');
    const first = privacy.records().length;
    assert.ok(first >= 6);

    await admin.refreshToken('/rgpd');
    await admin.post('/rgpd/traitements/amorcer');
    assert.equal(privacy.records().length, first, 'le second amorçage n\'ajoute rien');
  });

  await t.test("l'export rassemble ce que l'instance détient", async () => {
    await membre.client.refreshToken('/mon-espace');
    await membre.client.post('/mon-espace/demandes', {
      type: 'Congés payés', start_date: inDays(20), end_date: inDays(22), reason: 'Vacances',
    });

    const res = await admin.get(`/rgpd/personnes/${membre.id}/export.json`);
    assert.equal(res.status, 200);
    const data = JSON.parse(await res.text());
    assert.equal(data.personne.email, 'a-effacer@test.local');
    assert.ok(data.donnees['Demandes de congés et absences'].length >= 1);
  });

  await t.test("l'effacement demande l'adresse exacte", async () => {
    await admin.refreshToken('/rgpd');
    await admin.post(`/rgpd/personnes/${membre.id}/effacer`, { confirmation: 'pas-la-bonne@test.local' });
    assert.match((await admin.flash('/rgpd')).message, /adresse exacte/);
    assert.equal(db.prepare('SELECT first_name FROM users WHERE id = ?').get(membre.id).first_name, 'Paul');
  });

  await t.test("efface l'effaçable, conserve l'obligatoire, anonymise le compte", async () => {
    // Un bulletin de paie : il doit survivre à l'effacement.
    db.prepare("INSERT INTO payslips (employee_id, period, gross_amount, net_amount, status) VALUES (?, '2026-01', 3000, 2350, 'Émise')").run(membre.id);

    await admin.refreshToken('/rgpd');
    await admin.post(`/rgpd/personnes/${membre.id}/effacer`, { confirmation: 'a-effacer@test.local' });

    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM hr_requests WHERE employee_id = ?').get(membre.id).n, 0, 'les demandes sont effacées');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM payslips WHERE employee_id = ?').get(membre.id).n, 1, 'le bulletin est conservé');

    const after = db.prepare('SELECT * FROM users WHERE id = ?').get(membre.id);
    assert.equal(after.last_name, 'anonymisé');
    assert.equal(after.active, 0);
    assert.equal(after.directory_hidden, 1);
    assert.equal(after.email.includes('a-effacer'), false, "l'adresse d'origine ne subsiste pas");

    // La session de la personne effacée est fermée.
    assert.equal((await membre.client.get('/mon-espace')).headers.get('location'), '/connexion');
  });

  await t.test("un administrateur ne s'efface pas lui-même", async () => {
    const moi = userByEmail(ADMIN_EMAIL);
    await admin.refreshToken('/rgpd');
    await admin.post(`/rgpd/personnes/${moi.id}/effacer`, { confirmation: moi.email });
    assert.match((await admin.flash('/rgpd')).message, /propre compte/);
    assert.equal(db.prepare('SELECT last_name FROM users WHERE id = ?').get(moi.id).last_name !== 'anonymisé', true);
  });

  await t.test("l'espace est réservé à l'administration", async () => {
    const { client } = await makeMember(admin, 'curieux-rgpd@test.local');
    assert.equal((await client.get('/rgpd')).status, 403);
    assert.equal((await client.get(`/rgpd/personnes/1/export.json`)).status, 403);
  });
});
