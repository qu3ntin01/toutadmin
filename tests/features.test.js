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

async function createEmployee(admin, fields) {
  await admin.post('/admin/employes', {
    first_name: 'Test', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', ...fields,
  });
  const flash = await admin.flash('/admin');
  const match = flash && flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/);
  return match ? match[1] : null;
}

async function makeMember(admin, email, extra = {}) {
  const password = await createEmployee(admin, { email, ...extra });
  const client = newClient();
  await client.login(email, password);
  return { client, password, id: userByEmail(email).id };
}

test('internationalisation', async (t) => {
  await t.test('sert la page de connexion en français par défaut', async () => {
    const { body } = await newClient().html('/connexion');
    assert.match(body, /Accédez à votre espace/);
    assert.match(body, /lang="fr"/);
  });

  await t.test("suit la langue du navigateur via l'en-tête Accept-Language", async () => {
    const res = await fetch(`${baseUrl}/connexion`, { headers: { 'accept-language': 'de-DE,de;q=0.9' } });
    const body = await res.text();
    assert.match(body, /lang="de"/);
    assert.match(body, /Anmeldung/);
  });

  await t.test('bascule la langue depuis l\'écran de connexion et la retient', async () => {
    const client = newClient();
    await client.refreshToken('/connexion');
    await client.post('/langue', { locale: 'es', retour: '/connexion' });

    const { body } = await client.html('/connexion');
    assert.match(body, /lang="es"/);
    assert.match(body, /Iniciar sesión/);
  });

  await t.test("passe l'arabe en écriture de droite à gauche", async () => {
    const client = newClient();
    await client.refreshToken('/connexion');
    await client.post('/langue', { locale: 'ar', retour: '/connexion' });

    const { body } = await client.html('/connexion');
    assert.match(body, /dir="rtl"/);
  });

  await t.test('refuse une langue inconnue sans casser la page', async () => {
    const client = newClient();
    await client.refreshToken('/connexion');
    await client.post('/langue', { locale: 'xx', retour: '/connexion' });

    const { res, body } = await client.html('/connexion');
    assert.equal(res.status, 200);
    assert.match(body, /lang="fr"/);
  });

  await t.test('mémorise la langue choisie dans le profil du compte', async () => {
    const admin = await loginAsAdmin();
    const { client, id } = await makeMember(admin, 'langue@test.local', { first_name: 'Lena', last_name: 'Langue' });

    await client.post('/mon-profil/informations', { bio: '', phone: '', locale: 'ja' });
    assert.equal(db.prepare('SELECT locale FROM users WHERE id = ?').get(id).locale, 'ja');

    const { body } = await client.html('/mon-espace');
    assert.match(body, /lang="ja"/);
  });
});

test('profil du collaborateur', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'profil@test.local';
  const { client, password, id } = await makeMember(admin, email, { first_name: 'Paul', last_name: 'Profil' });

  await t.test('enregistre présentation et téléphone', async () => {
    await client.post('/mon-profil/informations', {
      bio: 'Responsable des déploiements terrain.', phone: '+33 6 11 22 33 44', locale: 'fr',
    });

    const user = db.prepare('SELECT bio, phone FROM users WHERE id = ?').get(id);
    assert.equal(user.bio, 'Responsable des déploiements terrain.');
    assert.equal(user.phone, '+33 6 11 22 33 44');
  });

  await t.test('refuse un changement de mot de passe si l\'actuel est faux', async () => {
    await client.post('/mon-profil/mot-de-passe', {
      current_password: 'pas-le-bon', new_password: 'nouveau-mot-de-passe', confirm_password: 'nouveau-mot-de-passe',
    });
    assert.match((await client.flash('/mon-profil')).message, /actuel incorrect/);
  });

  await t.test('refuse un mot de passe trop court ou mal confirmé', async () => {
    await client.post('/mon-profil/mot-de-passe', {
      current_password: password, new_password: 'court', confirm_password: 'court',
    });
    assert.match((await client.flash('/mon-profil')).message, /au moins 12 caractères/);

    await client.post('/mon-profil/mot-de-passe', {
      current_password: password, new_password: 'mot-de-passe-valide', confirm_password: 'autre-chose-encore',
    });
    assert.match((await client.flash('/mon-profil')).message, /confirmation ne correspond pas/);
  });

  await t.test('change le mot de passe et permet de se reconnecter avec', async () => {
    const nextPassword = 'nouveau-mot-de-passe-solide';
    await client.post('/mon-profil/mot-de-passe', {
      current_password: password, new_password: nextPassword, confirm_password: nextPassword,
    });
    assert.match((await client.flash('/mon-profil')).message, /mis à jour/);

    const again = newClient();
    const res = await again.login(email, nextPassword);
    assert.equal(res.headers.get('location'), '/mon-espace');
  });
});

test('annuaire', async (t) => {
  const admin = await loginAsAdmin();
  const visible = await makeMember(admin, 'visible@test.local', { first_name: 'Vera', last_name: 'Visible', department: 'Studio' });
  const hidden = await makeMember(admin, 'masque@test.local', { first_name: 'Marc', last_name: 'Masque' });

  await t.test('liste les collaborateurs actifs', async () => {
    const { body } = await visible.client.html('/annuaire');
    assert.match(body, /Vera Visible/);
    assert.match(body, /Marc Masque/);
  });

  await t.test("l'administrateur peut retirer quelqu'un de l'annuaire", async () => {
    await admin.post(`/admin/employes/${hidden.id}/annuaire`);
    assert.equal(db.prepare('SELECT directory_hidden FROM users WHERE id = ?').get(hidden.id).directory_hidden, 1);

    const { body } = await visible.client.html('/annuaire');
    assert.match(body, /Vera Visible/);
    assert.doesNotMatch(body, /Marc Masque/);
  });

  await t.test("le membre masqué ne se voit pas non plus et en est informé", async () => {
    const { body } = await hidden.client.html('/annuaire');
    // Son nom reste dans la barre de navigation : on vérifie la liste elle-même.
    assert.doesNotMatch(body, /person-name">Marc Masque/);
    assert.match(body, /apparaissez pas/);
  });

  await t.test('filtre par service', async () => {
    const { body } = await visible.client.html('/annuaire?q=studio');
    assert.match(body, /Vera Visible/);
  });

  await t.test("un membre ne peut pas se retirer lui-même de l'annuaire", async () => {
    const res = await hidden.client.post(`/admin/employes/${hidden.id}/annuaire`);
    assert.equal(res.status, 403);
  });
});

test('messagerie interne', async (t) => {
  const admin = await loginAsAdmin();
  const alice = await makeMember(admin, 'alice@test.local', { first_name: 'Alice', last_name: 'Anon' });
  const bob = await makeMember(admin, 'bob@test.local', { first_name: 'Bob', last_name: 'Bertin' });

  await t.test('envoie un message et le compte comme non lu', async () => {
    await alice.client.post('/messagerie', {
      recipient_id: String(bob.id), subject: 'Point projet', body: 'On se cale demain ?',
    });

    const message = db.prepare('SELECT * FROM messages WHERE recipient_id = ?').get(bob.id);
    assert.equal(message.subject, 'Point projet');
    assert.equal(message.read_at, null);

    const { body } = await bob.client.html('/mon-espace');
    assert.match(body, /nav-badge/);
  });

  await t.test("marque le message lu à l'ouverture par son destinataire", async () => {
    const message = db.prepare('SELECT * FROM messages WHERE recipient_id = ?').get(bob.id);
    await bob.client.html(`/messagerie?message=${message.id}`);

    assert.ok(db.prepare('SELECT read_at FROM messages WHERE id = ?').get(message.id).read_at);
  });

  await t.test("un tiers ne peut pas ouvrir un message qui ne le concerne pas", async () => {
    const carol = await makeMember(admin, 'carol@test.local', { first_name: 'Carol', last_name: 'Curieuse' });
    const message = db.prepare('SELECT * FROM messages WHERE recipient_id = ?').get(bob.id);

    const { body } = await carol.client.html(`/messagerie?message=${message.id}`);
    assert.doesNotMatch(body, /On se cale demain/);
  });

  await t.test('refuse un envoi à soi-même ou sans objet', async () => {
    const before = db.prepare('SELECT COUNT(*) AS n FROM messages').get().n;

    await alice.client.post('/messagerie', { recipient_id: String(alice.id), subject: 'Note', body: 'x' });
    assert.match((await alice.client.flash('/messagerie')).message, /Destinataire invalide/);

    await alice.client.post('/messagerie', { recipient_id: String(bob.id), subject: '', body: 'x' });
    assert.match((await alice.client.flash('/messagerie')).message, /objet du message/);

    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM messages').get().n, before);
  });

  await t.test("l'adresse professionnelle est configurée par l'administration", async () => {
    await admin.post(`/admin/employes/${bob.id}/messagerie`, {
      mail_address: 'bob.bertin@entreprise.com', mail_imap_host: 'imap.entreprise.com',
      mail_imap_port: '993', mail_smtp_host: 'smtp.entreprise.com', mail_smtp_port: '587',
    });

    const { body } = await bob.client.html('/messagerie');
    assert.match(body, /bob\.bertin@entreprise\.com/);

    // Le membre ne dispose d'aucune route pour modifier ces réglages.
    const res = await bob.client.post(`/admin/employes/${bob.id}/messagerie`, { mail_address: 'pirate@ailleurs.com' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT mail_address FROM users WHERE id = ?').get(bob.id).mail_address, 'bob.bertin@entreprise.com');
  });
});

test('managers, équipes et actualités', async (t) => {
  const admin = await loginAsAdmin();
  const chief = await makeMember(admin, 'chef@test.local', { first_name: 'Chloé', last_name: 'Chef', grade: 'Manager' });
  const report = await makeMember(admin, 'equipier@test.local', { first_name: 'Ravi', last_name: 'Equipier' });


  let teamId;
  let departmentId;

  await t.test("l'administrateur crée un service et une équipe", async () => {
    await admin.post('/admin/services', { name: 'Support', description: 'Assistance et maintenance.' });
    departmentId = db.prepare("SELECT id FROM departments WHERE name = 'Support'").get().id;

    await admin.post('/admin/equipes', { name: 'Astreinte', department_id: String(departmentId) });
    teamId = db.prepare("SELECT id FROM teams WHERE name = 'Astreinte'").get().id;
    assert.equal(db.prepare('SELECT department_id FROM teams WHERE id = ?').get(teamId).department_id, departmentId);
  });

  await t.test('refuse deux services de même nom', async () => {
    await admin.post('/admin/services', { name: 'support' });
    assert.match((await admin.flash('/admin')).message, /porte déjà ce nom/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM departments WHERE lower(name) = 'support'").get().n, 1);
  });

  await t.test("rattacher à une équipe rattache aussi à son service", async () => {
    await admin.post(`/admin/employes/${report.id}/rattachement`, { team_id: String(teamId) });
    const row = db.prepare('SELECT department_id, team_id FROM users WHERE id = ?').get(report.id);
    assert.equal(row.team_id, teamId);
    assert.equal(row.department_id, departmentId);
  });

  await t.test('refuse un rattachement à une équipe inconnue', async () => {
    await admin.post(`/admin/employes/${report.id}/rattachement`, { team_id: '99999' });
    assert.match((await admin.flash('/admin')).message, /Équipe introuvable/);
    assert.equal(db.prepare('SELECT team_id FROM users WHERE id = ?').get(report.id).team_id, teamId);
  });

  await t.test("l'espace équipe reste fermé tant qu'aucun périmètre n'est encadré", async () => {
    assert.equal((await chief.client.get('/mon-equipe')).status, 403);
  });

  await t.test('une équipe accepte plusieurs managers', async () => {
    const second = await makeMember(admin, 'codirection@test.local', { first_name: 'Sam', last_name: 'Codir', grade: 'Manager' });
    await admin.post('/admin/encadrement', { scope: 'team', scope_id: String(teamId), user_id: String(chief.id) });
    await admin.post('/admin/encadrement', { scope: 'team', scope_id: String(teamId), user_id: String(second.id) });

    const managers = db.prepare("SELECT user_id FROM org_managers WHERE scope = 'team' AND scope_id = ?").all(teamId);
    assert.equal(managers.length, 2);

    // Les deux voient la même équipe, sans reconnexion : les droits sont recalculés à chaque requête.
    for (const client of [chief.client, second.client]) {
      const { res, body } = await client.html('/mon-equipe');
      assert.equal(res.status, 200);
      assert.match(body, /Ravi Equipier/);
    }
  });

  await t.test('un manager de service encadre aussi les équipes de ce service', async () => {
    const head = await makeMember(admin, 'chefservice@test.local', { first_name: 'Nour', last_name: 'Service', grade: 'Directeur' });
    await admin.post('/admin/encadrement', { scope: 'department', scope_id: String(departmentId), user_id: String(head.id) });

    const { res, body } = await head.client.html('/mon-equipe');
    assert.equal(res.status, 200);
    assert.match(body, /Ravi Equipier/);
  });

  await t.test("les managers de son équipe et de son service apparaissent sur l'accueil", async () => {
    const { body } = await report.client.html('/mon-espace');
    assert.match(body, /Chloé Chef/);
    assert.match(body, /Nour Service/);
  });

  await t.test("retirer l'encadrement referme l'espace manager", async () => {
    const solo = await makeMember(admin, 'ephemere@test.local', { first_name: 'Iris', last_name: 'Passage', grade: 'Manager' });
    await admin.post('/admin/encadrement', { scope: 'team', scope_id: String(teamId), user_id: String(solo.id) });
    assert.equal((await solo.client.get('/mon-equipe')).status, 200);

    await admin.post('/admin/encadrement/retirer', { scope: 'team', scope_id: String(teamId), user_id: String(solo.id) });
    assert.equal((await solo.client.get('/mon-equipe')).status, 403);
  });

  await t.test("supprimer une équipe en détache ses membres sans les effacer", async () => {
    const doomed = await makeMember(admin, 'detache@test.local', { first_name: 'Léo', last_name: 'Détaché' });
    await admin.post('/admin/equipes', { name: 'Éphémère' });
    const doomedTeam = db.prepare("SELECT id FROM teams WHERE name = 'Éphémère'").get().id;
    await admin.post(`/admin/employes/${doomed.id}/rattachement`, { team_id: String(doomedTeam) });

    await admin.post(`/admin/equipes/${doomedTeam}/supprimer`, {});
    assert.equal(db.prepare('SELECT id FROM teams WHERE id = ?').get(doomedTeam), undefined);
    assert.ok(db.prepare('SELECT id FROM users WHERE id = ?').get(doomed.id), 'le membre doit survivre à son équipe');
    assert.equal(db.prepare('SELECT team_id FROM users WHERE id = ?').get(doomed.id).team_id, null);
  });

  await t.test("une actualité d'équipe n'est visible que par cette équipe", async () => {
    await chief.client.refreshToken('/mon-equipe');
    await chief.client.post('/mon-equipe/actualites', { title: 'Réunion hebdo déplacée', body: 'Jeudi 10 h.', target: `team:${teamId}` });

    const forReport = await report.client.html('/mon-espace');
    assert.match(forReport.body, /Réunion hebdo déplacée/);

    const outsider = await makeMember(admin, 'exterieur@test.local', { first_name: 'Otto', last_name: 'Externe' });
    const forOutsider = await outsider.client.html('/mon-espace');
    assert.doesNotMatch(forOutsider.body, /Réunion hebdo déplacée/);
  });

  await t.test("une actualité d'entreprise est visible par tout le monde", async () => {
    await admin.post('/admin/actualites', { title: 'Fermeture estivale', body: 'Du 1er au 15 août.' });

    for (const member of [report, chief]) {
      const { body } = await member.client.html('/mon-espace');
      assert.match(body, /Fermeture estivale/);
    }
  });

  await t.test("un collaborateur ne peut pas publier d'actualité d'entreprise", async () => {
    const res = await report.client.post('/admin/actualites', { title: 'Faux', body: 'x' });
    assert.equal(res.status, 403);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM announcements WHERE scope = 'company'").get().n, 1);
  });
});

test('la rémunération a bien migré vers l\'espace RH', async (t) => {
  const admin = await loginAsAdmin();
  const email = 'freelance-rh@test.local';
  await createEmployee(admin, {
    email, first_name: 'Fara', last_name: 'Freelance', contract_type: 'Freelance', daily_rate: '480',
  });
  const id = userByEmail(email).id;

  await t.test("l'onglet rémunération est servi par l'espace RH", async () => {
    const { body } = await admin.html('/rh');
    assert.match(body, /id="remuneration"/);
    assert.match(body, /Fara Freelance/);
  });

  await t.test('la console admin ne porte plus cet onglet', async () => {
    const { body } = await admin.html('/admin');
    assert.doesNotMatch(body, /id="remuneration"/);
  });

  await t.test('le détail du pointage répond sous /rh', async () => {
    const { res } = await admin.html(`/rh/temps/${id}`);
    assert.equal(res.status, 200);
  });

  await t.test("un collaborateur sans droit RH n'y accède pas", async () => {
    const intruder = await makeMember(admin, 'curieux@test.local', { first_name: 'Ivan', last_name: 'Intrus' });
    const res = await intruder.client.get(`/rh/temps/${id}`);
    assert.equal(res.status, 403);
  });
});
