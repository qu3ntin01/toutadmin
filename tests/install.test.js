const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

// Instance vierge : ni base, ni administrateur d'environnement. C'est
// exactement la situation d'un client qui vient de déposer le CMS sur son
// serveur, donc la seule où l'assistant doit s'ouvrir.
const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'pm-install-'));
process.env.DB_PATH = path.join(dir, 'test.sqlite');
process.env.NODE_ENV = 'test';
process.env.SESSION_SECRET = 'secret-de-test-installation';
process.env.INSTALL_TOKEN = 'jeton-de-test-installation';
process.env.GLOBAL_RATE_LIMIT = '100000';
process.env.LOGIN_RATE_LIMIT = '5000';
delete process.env.ADMIN_EMAIL;
delete process.env.ADMIN_PASSWORD;

const { startServer, Client } = require('./helpers');
const createApp = require('../src/app');
const install = require('../src/install');
const settings = require('../src/settings');

const ADMIN = { email: 'dirigeant@acme.test', password: 'MotDePasseAssezLong1' };

let server;
let client;

test.before(async () => {
  server = await startServer(createApp());
  client = new Client(`http://127.0.0.1:${server.address().port}`);
});

test.after(() => {
  server.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

test("Assistant d'installation", async (t) => {
  await t.test("redirige toute l'application vers l'assistant tant que rien n'est installé", async () => {
    for (const route of ['/connexion', '/admin', '/mon-espace', '/rh']) {
      const res = await client.get(route);
      assert.equal(res.status, 302, route);
      assert.equal(res.headers.get('location'), '/installation');
    }
  });

  await t.test('ouvre sur le choix de la langue', async () => {
    const { res, body } = await client.html('/installation/langue');
    assert.equal(res.status, 200);
    assert.match(body, /name="locale" value="fr"/);
    assert.match(body, /name="locale" value="en"/);
  });

  await t.test("applique la langue choisie à l'étape suivante", async () => {
    await client.refreshToken('/installation/langue');
    const res = await client.post('/installation/langue', { locale: 'en' });
    assert.equal(res.headers.get('location'), '/installation/prerequis');

    const { body } = await client.html('/installation/prerequis');
    assert.match(body, /Environment check/);
  });

  await t.test('interdit de sauter des étapes', async () => {
    for (const [step, expected] of [
      ['administrateur', '/installation/entreprise'],
      ['termine', '/installation/administrateur'],
    ]) {
      const res = await client.get(`/installation/${step}`);
      assert.equal(res.headers.get('location'), expected);
    }
  });

  await t.test("refuse un jeton d'installation incorrect", async () => {
    await client.refreshToken('/installation/prerequis');
    const res = await client.post('/installation/prerequis', { install_token: 'mauvais-jeton' });
    assert.equal(res.headers.get('location'), '/installation/prerequis');

    const flash = await client.flash('/installation/prerequis');
    assert.equal(flash.type, 'error');
    assert.match(flash.message, /Jeton/);
  });

  await t.test('accepte le bon jeton et déverrouille la suite', async () => {
    await client.refreshToken('/installation/prerequis');
    const res = await client.post('/installation/prerequis', { install_token: process.env.INSTALL_TOKEN });
    assert.equal(res.headers.get('location'), '/installation/entreprise');
  });

  await t.test('valide les informations de l\'organisation', async () => {
    await client.refreshToken('/installation/entreprise');
    const vide = await client.post('/installation/entreprise', { company_name: '', annual_leave_days: '25' });
    assert.equal(vide.headers.get('location'), '/installation/entreprise');

    await client.refreshToken('/installation/entreprise');
    const absurde = await client.post('/installation/entreprise', { company_name: 'Acme', annual_leave_days: '400' });
    assert.equal(absurde.headers.get('location'), '/installation/entreprise');
    const flash = await client.flash('/installation/entreprise');
    assert.match(flash.message, /congés/);

    await client.refreshToken('/installation/entreprise');
    const ok = await client.post('/installation/entreprise', { company_name: 'Acme Industries', annual_leave_days: '30' });
    assert.equal(ok.headers.get('location'), '/installation/administrateur');
  });

  await t.test('refuse un mot de passe administrateur trop court ou mal confirmé', async () => {
    await client.refreshToken('/installation/administrateur');
    const court = await client.post('/installation/administrateur', {
      first_name: 'Ada', last_name: 'Martin', email: ADMIN.email, password: 'court', confirm_password: 'court',
    });
    assert.equal(court.headers.get('location'), '/installation/administrateur');
    assert.match((await client.flash('/installation/administrateur')).message, /12 caractères/);

    await client.refreshToken('/installation/administrateur');
    const discordant = await client.post('/installation/administrateur', {
      first_name: 'Ada', last_name: 'Martin', email: ADMIN.email, password: ADMIN.password, confirm_password: 'AutreMotDePasse1',
    });
    assert.equal(discordant.headers.get('location'), '/installation/administrateur');
    assert.match((await client.flash('/installation/administrateur')).message, /confirmation/);
  });

  await t.test('accepte un administrateur valide et affiche le récapitulatif', async () => {
    await client.refreshToken('/installation/administrateur');
    const res = await client.post('/installation/administrateur', {
      first_name: 'Ada', last_name: 'Martin', email: ADMIN.email, password: ADMIN.password, confirm_password: ADMIN.password,
    });
    assert.equal(res.headers.get('location'), '/installation/termine');

    const { body } = await client.html('/installation/termine');
    assert.match(body, /Acme Industries/);
    assert.match(body, new RegExp(ADMIN.email));
    // Le mot de passe ne doit jamais être réaffiché.
    assert.doesNotMatch(body, new RegExp(ADMIN.password));
  });

  await t.test('exige un jeton CSRF pour terminer', async () => {
    const res = await client.post('/installation/terminer', {}, { withToken: false });
    assert.equal(res.status, 403);
    assert.equal(install.isInstalled(), false);
  });

  await t.test("crée l'instance, verrouille l'assistant et enregistre les réglages", async () => {
    await client.refreshToken('/installation/termine');
    const res = await client.post('/installation/terminer');
    assert.equal(res.headers.get('location'), '/connexion');

    assert.equal(install.isInstalled(), true);
    assert.equal(settings.get('company_name'), 'Acme Industries');
    assert.equal(settings.get('annual_leave_days'), '30');
    assert.equal(settings.get('default_locale'), 'en');
  });

  await t.test("referme définitivement l'assistant une fois installé", async () => {
    for (const step of ['/installation', '/installation/langue', '/installation/entreprise']) {
      const res = await client.get(step);
      assert.equal(res.status, 302, step);
      assert.equal(res.headers.get('location'), '/connexion');
    }

    await client.refreshToken('/connexion');
    const rejoue = await client.post('/installation/terminer');
    assert.equal(rejoue.headers.get('location'), '/connexion');
  });

  await t.test("connecte l'administrateur créé par l'assistant", async () => {
    const res = await client.login(ADMIN.email, ADMIN.password);
    assert.equal(res.headers.get('location'), '/admin');

    const { body } = await client.html('/admin');
    assert.match(body, /Ada/);
    // Le nom de l'organisation remplace la marque produit dans l'interface.
    assert.match(body, /brand-name">Acme Industries</);
  });

  await t.test('applique le quota de congés choisi aux nouveaux salariés', async () => {
    await client.refreshToken('/admin');
    const res = await client.post('/admin/employes', {
      first_name: 'Jean', last_name: 'Dupont', email: 'jean@acme.test',
      grade: 'Technicien', department: 'Technique', contract_type: 'CDI',
    });
    assert.equal(res.status, 302);

    const db = require('../src/db');
    const salarie = db.prepare('SELECT leave_balance FROM users WHERE email = ?').get('jean@acme.test');
    assert.equal(salarie.leave_balance, 30);
  });
});
