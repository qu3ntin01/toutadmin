const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD, MEMBER_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const totp = require('../src/totp');
const twoFactor = require('../src/two-factor');
const fileType = require('../src/file-type');
const audit = require('../src/audit');
const { checkPassword, safeRedirect, generatePassword } = require('../src/utils');

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
  return { client, id: userByEmail(email).id, password };
}

test('Une session ouverte suit les droits réels du compte', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('désactiver un compte coupe sa session en cours', async () => {
    const { client, id } = await makeMember(admin, 'revoque@test.local');
    assert.equal((await client.get('/mon-espace')).status, 200);

    // Le membre ne se déconnecte pas : c'est l'administration qui le désactive.
    db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(id);

    const res = await client.get('/mon-espace');
    assert.equal(res.status, 302);
    assert.equal(res.headers.get('location'), '/connexion');
  });

  await t.test('un contrat arrivé à terme coupe la session en cours', async () => {
    const { client, id } = await makeMember(admin, 'fin-contrat@test.local');
    assert.equal((await client.get('/mon-espace')).status, 200);

    db.prepare("UPDATE users SET contract_end_date = '2020-01-01' WHERE id = ?").run(id);

    const res = await client.get('/mon-espace');
    assert.equal(res.status, 302);
    assert.equal(res.headers.get('location'), '/connexion');
  });

  await t.test('verrouiller un compte coupe sa session en cours', async () => {
    const { client, id } = await makeMember(admin, 'verrouille@test.local');
    const until = new Date(Date.now() + 60 * 60 * 1000).toISOString();
    db.prepare('UPDATE users SET locked_until = ? WHERE id = ?').run(until, id);

    assert.equal((await client.get('/mon-espace')).status, 302);
  });

  await t.test('rétrograder un administrateur retire ses droits sans reconnexion', async () => {
    const { client, id } = await makeMember(admin, 'second-admin@test.local');
    db.prepare("UPDATE users SET role = 'admin' WHERE id = ?").run(id);
    assert.equal((await client.get('/admin')).status, 200);

    db.prepare("UPDATE users SET role = 'employee' WHERE id = ?").run(id);

    // La session porte encore role=admin ; le serveur, lui, doit relire la base.
    assert.equal((await client.get('/admin')).status, 403);
  });

  await t.test("accorder le rôle RH prend effet sans reconnexion, le retirer aussi", async () => {
    const { client, id } = await makeMember(admin, 'rh-bascule@test.local');
    assert.equal((await client.get('/rh')).status, 403);

    db.prepare('UPDATE users SET is_hr = 1 WHERE id = ?').run(id);
    assert.equal((await client.get('/rh')).status, 200);

    db.prepare('UPDATE users SET is_hr = 0 WHERE id = ?').run(id);
    assert.equal((await client.get('/rh')).status, 403);
  });
});

test('Redirections', async (t) => {
  await t.test('le choix de langue ne redirige jamais hors du site', async () => {
    const client = newClient();
    await client.refreshToken('/connexion');

    for (const cible of ['//exemple-malveillant.test/phishing', '/\\exemple-malveillant.test', 'https://exemple-malveillant.test']) {
      const res = await client.post('/langue', { locale: 'en', retour: cible });
      assert.equal(res.headers.get('location'), '/connexion', `refusé : ${cible}`);
    }

    // Un chemin interne reste honoré.
    const res = await client.post('/langue', { locale: 'fr', retour: '/mon-profil' });
    assert.equal(res.headers.get('location'), '/mon-profil');
  });
});


test('Politique de mot de passe', async (t) => {
  await t.test('refuse ce qui se devine', () => {
    const contexte = { email: 'claire.moreau@test.local', firstName: 'Claire', lastName: 'Moreau' };
    assert.equal(checkPassword('Ab1!', contexte).ok, false, 'trop court');
    assert.equal(checkPassword('rienquedesminuscules', contexte).ok, false, 'une seule catégorie');
    assert.equal(checkPassword('Claire-Grand-2026', contexte).ok, false, 'contient le prénom');
    assert.equal(checkPassword('claire.moreau-XY9', contexte).ok, false, "contient l'identifiant");
    assert.equal(checkPassword('Azertyuiop-2026', contexte).ok, false, 'suite de clavier');
    assert.equal(checkPassword('aaaaaaaaaaaaaaa', contexte).ok, false, 'un seul caractère répété');
    assert.equal(checkPassword('Bruyere-Haute-88', contexte).ok, true);
  });

  await t.test('les mots de passe temporaires satisfont la politique qu\'ils devront respecter', () => {
    for (let i = 0; i < 50; i++) {
      const generated = generatePassword();
      assert.equal(generated.length, 16);
      assert.equal(checkPassword(generated).ok, true, `refusé : ${generated}`);
    }
  });

  await t.test('un mot de passe temporaire doit être remplacé avant toute autre page', async () => {
    const admin = await loginAsAdmin();
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Neuf', last_name: 'Venu', grade: 'Employé', contract_type: 'CDI', email: 'neuf@test.local',
    });
    const temporaire = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];

    const client = newClient();
    await client.login('neuf@test.local', temporaire);

    // Toute autre page renvoie vers la page de premier accès.
    for (const page of ['/mon-espace', '/annuaire', '/agenda', '/mon-profil']) {
      const res = await client.get(page);
      assert.equal(res.status, 302, page);
      assert.equal(res.headers.get('location'), '/mon-profil/premier-acces', page);
    }
    assert.equal((await client.get('/mon-profil/premier-acces')).status, 200);

    // Le nouveau mot de passe doit lui aussi passer la politique.
    await client.refreshToken('/mon-profil/premier-acces');
    await client.post('/mon-profil/premier-acces', {
      current_password: temporaire, new_password: 'trop-simple', confirm_password: 'trop-simple',
    });
    assert.match((await client.flash('/mon-profil/premier-acces')).message, /au moins 12 caractères/);

    await client.post('/mon-profil/premier-acces', {
      current_password: temporaire, new_password: MEMBER_PASSWORD, confirm_password: MEMBER_PASSWORD,
    });
    assert.equal(db.prepare("SELECT must_change_password FROM users WHERE email = 'neuf@test.local'").get().must_change_password, 0);

    // Le mot de passe temporaire ne vaut plus.
    const refuse = newClient();
    await refuse.login('neuf@test.local', temporaire);
    assert.equal((await refuse.get('/mon-espace')).headers.get('location'), '/connexion');
  });
});

test('Double authentification', async (t) => {
  await t.test('suit les vecteurs de la RFC 6238', () => {
    const secret = totp.base32Encode(Buffer.from('12345678901234567890'));
    assert.equal(totp.currentCode(secret, 59 * 1000), '287082');
    assert.equal(totp.currentCode(secret, 1111111109 * 1000), '081804');
    assert.equal(totp.currentCode(secret, 2000000000 * 1000), '279037');
  });

  await t.test('tolère une dérive d\'horloge d\'une période, pas de trois', () => {
    const secret = totp.generateSecret();
    assert.equal(totp.verify(secret, totp.currentCode(secret, Date.now() - 30000)), true);
    assert.equal(totp.verify(secret, totp.currentCode(secret, Date.now() - 90000)), false);
    assert.equal(totp.verify(secret, '000000') && totp.currentCode(secret) === '000000', false);
  });

  await t.test('le mot de passe seul n\'ouvre plus de session une fois activée', async () => {
    const admin = await loginAsAdmin();
    const { client, id } = await makeMember(admin, 'deux-facteurs@test.local');

    // Mise en service depuis le profil, comme le ferait la personne.
    await client.refreshToken('/mon-profil');
    await client.post('/mon-profil/2fa/preparer');
    const secret = db.prepare('SELECT totp_secret FROM users WHERE id = ?').get(id).totp_secret;
    assert.ok(secret, 'un secret est préparé');
    assert.equal(db.prepare('SELECT totp_enabled FROM users WHERE id = ?').get(id).totp_enabled, 0, "pas encore active tant qu'aucun code n'a été validé");

    await client.refreshToken('/mon-profil');
    await client.post('/mon-profil/2fa/activer', { code: '000000' });
    assert.equal(db.prepare('SELECT totp_enabled FROM users WHERE id = ?').get(id).totp_enabled, 0, 'un code faux n\'active rien');

    await client.refreshToken('/mon-profil');
    await client.post('/mon-profil/2fa/activer', { code: totp.currentCode(secret) });
    assert.equal(db.prepare('SELECT totp_enabled FROM users WHERE id = ?').get(id).totp_enabled, 1);

    // Reconnexion : le mot de passe mène à l'étape du code, pas à l'espace.
    const suivant = newClient();
    const res = await suivant.login('deux-facteurs@test.local', MEMBER_PASSWORD);
    assert.equal(res.headers.get('location'), '/connexion/code');
    assert.equal((await suivant.get('/mon-espace')).headers.get('location'), '/connexion');

    await suivant.refreshToken('/connexion/code');
    await suivant.post('/connexion/code', { code: '111111' });
    assert.equal((await suivant.get('/mon-espace')).headers.get('location'), '/connexion');

    await suivant.refreshToken('/connexion/code');
    const ouvert = await suivant.post('/connexion/code', { code: totp.currentCode(secret) });
    assert.equal(ouvert.headers.get('location'), '/mon-espace');
    assert.equal((await suivant.get('/mon-espace')).status, 200);
  });

  await t.test('un code de secours ouvre la session, une seule fois', () => {
    const id = db.prepare("SELECT id FROM users WHERE email = 'deux-facteurs@test.local'").get().id;
    const codes = twoFactor.regenerateRecoveryCodes(id);
    const user = db.prepare('SELECT * FROM users WHERE id = ?').get(id);

    const premier = twoFactor.verifyLogin(user, codes[0]);
    assert.equal(premier.ok, true);
    assert.equal(premier.usedRecovery, true);
    assert.equal(twoFactor.verifyLogin(user, codes[0]).ok, false, 'un code de secours ne ressert pas');
    assert.equal(twoFactor.verifyLogin(user, codes[1]).ok, true);
  });
});

test('Type réel des fichiers reçus', async (t) => {
  await t.test('un exécutable déguisé est refusé, quel que soit le type annoncé', () => {
    const exe = Buffer.from('4d5a90000300000004000000ffff', 'hex');
    assert.equal(fileType.matches(exe, 'image/png'), false);
    assert.equal(fileType.matches(exe, 'application/pdf'), false);
    assert.equal(fileType.matches(exe, 'text/plain'), false);
  });

  await t.test('les formats attendus sont reconnus à leur signature', () => {
    assert.equal(fileType.matches(Buffer.from('89504e470d0a1a0a0000', 'hex'), 'image/png'), true);
    assert.equal(fileType.matches(Buffer.from('%PDF-1.7\n%...'), 'application/pdf'), true);
    assert.equal(fileType.matches(Buffer.from('Automate Siemens, hydraulique.'), 'text/plain'), true);
    assert.equal(fileType.matches(Buffer.alloc(0), 'text/plain'), false, 'un fichier vide ne prouve rien');
  });
});

test("Journal d'audit", async (t) => {
  const admin = await loginAsAdmin();

  await t.test('consigne connexions réussies et manquées', async () => {
    const avant = audit.list({ action: 'connexion.echec' }).total;
    const intrus = newClient();
    await intrus.login('inconnu-au-bataillon@test.local', 'peu-importe');
    assert.equal(audit.list({ action: 'connexion.echec' }).total, avant + 1);

    const derniere = audit.list({ action: 'connexion.echec' }).rows[0];
    assert.match(derniere.detail, /compte inconnu/);
    // L'adresse essayée est tracée, jamais le mot de passe.
    assert.equal(derniere.detail.includes('peu-importe'), false);
  });

  await t.test('consigne les actions de l\'administration avec leur auteur', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/outils', { name: 'Outil audité', url: 'https://exemple.test' });

    const trace = audit.list({ action: '/admin/outils' }).rows[0];
    assert.ok(trace, "l'action est au journal");
    assert.match(trace.actor_label, new RegExp(ADMIN_EMAIL));
  });

  await t.test("n'est accessible qu'à l'administration", async () => {
    const { client } = await makeMember(admin, 'curieux-journal@test.local');
    assert.equal((await client.get('/securite')).status, 403);
    assert.equal((await client.get('/securite/journal.csv')).status, 403);
    assert.equal((await admin.get('/securite')).status, 200);
  });

  await t.test('s\'exporte en CSV', async () => {
    const res = await admin.get('/securite/journal.csv');
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type'), /text\/csv/);
    const body = await res.text();
    assert.match(body, /"Date";"Auteur";"Action"/);
  });
});

test('Administrateurs multiples', async (t) => {
  const admin = await loginAsAdmin();
  const { id } = await makeMember(admin, 'second@test.local');

  await t.test('un membre peut être nommé administrateur', async () => {
    await admin.refreshToken('/securite');
    await admin.post('/securite/administrateurs', { user_id: String(id) });
    assert.equal(db.prepare('SELECT role FROM users WHERE id = ?').get(id).role, 'admin');
  });

  await t.test('un administrateur ne se retire pas lui-même ses droits', async () => {
    const moi = db.prepare('SELECT id FROM users WHERE email = ?').get(ADMIN_EMAIL).id;
    await admin.refreshToken('/securite');
    await admin.post(`/securite/administrateurs/${moi}/retirer`);
    assert.equal(db.prepare('SELECT role FROM users WHERE id = ?').get(moi).role, 'admin');
  });

  await t.test('le dernier administrateur ne peut pas être rétrogradé', async () => {
    await admin.refreshToken('/securite');
    await admin.post(`/securite/administrateurs/${id}/retirer`);
    assert.equal(db.prepare('SELECT role FROM users WHERE id = ?').get(id).role, 'employee');

    const moi = db.prepare('SELECT id FROM users WHERE email = ?').get(ADMIN_EMAIL).id;
    await admin.refreshToken('/securite');
    const res = await admin.post(`/securite/administrateurs/${moi}/retirer`);
    assert.equal(res.headers.get('location'), '/securite#administrateurs');
    assert.match((await admin.flash('/securite')).message, /dernier administrateur/);
    assert.equal(db.prepare('SELECT role FROM users WHERE id = ?').get(moi).role, 'admin');
  });
});
