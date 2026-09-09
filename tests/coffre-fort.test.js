const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD, MEMBER_PASSWORD } = require('./helpers');

const dir = prepareEnvironment();
process.env.VAULT_DIR = `${dir}/coffre`;

const createApp = require('../src/app');
const db = require('../src/db');
const vault = require('../src/vault');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const userByEmail = (email) => db.prepare('SELECT * FROM users WHERE email = ?').get(email);

// Un PDF minimal, mais un vrai PDF : la signature est contrôlée au dépôt.
const PDF = Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n');

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

/** Dépôt d'un document par les RH : multipart, comme le formulaire réel. */
async function depose(client, fields, { content = PDF, fileName = 'bulletin.pdf', mimetype = 'application/pdf', token } = {}) {
  await client.refreshToken('/coffre-fort/gestion');
  const boundary = '----coffre' + Math.random().toString(16).slice(2);
  const parts = [];
  if (token !== null) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n${token || client.csrfToken}\r\n`));
  }
  for (const [key, value] of Object.entries(fields)) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${key}"\r\n\r\n${value}\r\n`));
  }
  parts.push(Buffer.from(
    `--${boundary}\r\nContent-Disposition: form-data; name="document"; filename="${fileName}"\r\n` +
    `Content-Type: ${mimetype}\r\n\r\n`
  ));
  parts.push(Buffer.from(content), Buffer.from(`\r\n--${boundary}--\r\n`));

  const cookie = [...client.cookies].map(([n, v]) => `${n}=${v}`).join('; ');
  return fetch(`${baseUrl}/coffre-fort/gestion/depots`, {
    method: 'POST',
    headers: { cookie, 'content-type': `multipart/form-data; boundary=${boundary}` },
    body: Buffer.concat(parts),
    redirect: 'manual',
  });
}

test('Dépôt au coffre-fort', async (t) => {
  const admin = await loginAsAdmin();
  const salarie = await makeMember(admin, 'coffre@test.local', { first_name: 'Camille', last_name: 'Coffre' });
  let documentId;

  await t.test('scelle le document par son empreinte et fixe sa conservation', async () => {
    const res = await depose(admin, {
      user_id: String(salarie.id), title: 'Bulletin de paie — janvier 2026',
      category: 'Bulletin de paie', period: '2026-01',
    });
    assert.equal(res.status, 302);

    const doc = db.prepare('SELECT * FROM vault_documents').get();
    documentId = doc.id;
    assert.equal(doc.user_id, salarie.id);
    assert.equal(doc.sha256, vault.fingerprint(PDF));
    assert.equal(doc.byte_size, PDF.length);
    assert.equal(doc.original_name, 'bulletin.pdf');

    // Cinquante ans de conservation, comptés depuis le dépôt.
    const deposit = new Date();
    assert.equal(doc.retention_until.slice(0, 4), String(deposit.getUTCFullYear() + vault.RETENTION_YEARS));

    // Le fichier est écrit hors du dépôt, sous un nom aléatoire, en 0600.
    const onDisk = vault.pathOf(doc);
    assert.ok(fs.existsSync(onDisk));
    assert.notEqual(doc.file_name, 'bulletin.pdf');
    assert.equal(fs.statSync(onDisk).mode & 0o777, 0o600);
  });

  await t.test('refuse un exécutable déguisé en PDF', async () => {
    const res = await depose(admin, {
      user_id: String(salarie.id), title: 'Faux bulletin', category: 'Bulletin de paie', period: '2026-02',
    }, { content: Buffer.from('4d5a90000300', 'hex') });
    assert.equal(res.status, 302);
    assert.match((await admin.flash('/coffre-fort/gestion')).message, /pas du type annoncé/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM vault_documents').get().n, 1);
  });

  await t.test('refuse un dépôt multipart sans jeton CSRF', async () => {
    const res = await depose(admin, {
      user_id: String(salarie.id), title: 'Sans jeton', category: 'Bulletin de paie',
    }, { token: null });
    assert.equal(res.status, 403);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM vault_documents').get().n, 1);
  });

  await t.test("ne dépose pas deux fois le même document", async () => {
    const res = await depose(admin, {
      user_id: String(salarie.id), title: 'Le même en double', category: 'Bulletin de paie', period: '2026-01',
    });
    assert.equal(res.status, 302);
    assert.match((await admin.flash('/coffre-fort/gestion')).message, /déjà au coffre/);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM vault_documents').get().n, 1);
  });

  await t.test('refuse une période mal formée', async () => {
    await depose(admin, {
      user_id: String(salarie.id), title: 'Période douteuse', category: 'Bulletin de paie', period: 'janvier',
    }, { content: Buffer.from('%PDF-1.4 autre contenu\n%%EOF\n') });
    assert.match((await admin.flash('/coffre-fort/gestion')).message, /Période invalide/);
  });

  await t.test('le titulaire télécharge son document, un autre non', async () => {
    const download = await salarie.client.get(`/coffre-fort/documents/${documentId}`);
    assert.equal(download.status, 200);
    assert.equal(Buffer.from(await download.arrayBuffer()).equals(PDF), true);

    const voisin = await makeMember(admin, 'voisin-coffre@test.local');
    assert.equal((await voisin.client.get(`/coffre-fort/documents/${documentId}`)).status, 403);
  });

  await t.test("un document altéré n'est pas servi", async () => {
    const doc = vault.byId(documentId);
    const onDisk = vault.pathOf(doc);
    const original = fs.readFileSync(onDisk);

    fs.writeFileSync(onDisk, Buffer.concat([original, Buffer.from(' altéré')]));
    assert.equal(vault.verify(doc).ok, false);
    assert.equal((await salarie.client.get(`/coffre-fort/documents/${documentId}`)).status, 500);
    assert.equal(vault.audit().broken.length, 1);

    fs.writeFileSync(onDisk, original);
    assert.equal(vault.verify(vault.byId(documentId)).ok, true);
    assert.equal((await salarie.client.get(`/coffre-fort/documents/${documentId}`)).status, 200);
  });

  await t.test('le dépôt est réservé aux RH, le retrait à l\'administration', async () => {
    const employe = await makeMember(admin, 'sans-coffre@test.local');
    assert.equal((await employe.client.get('/coffre-fort/gestion')).status, 403);

    // Un RH dépose, mais ne retire pas.
    const rh = await makeMember(admin, 'rh-coffre@test.local');
    db.prepare('UPDATE users SET is_hr = 1 WHERE id = ?').run(rh.id);
    assert.equal((await rh.client.get('/coffre-fort/gestion')).status, 200);

    await rh.client.refreshToken('/coffre-fort/gestion');
    const res = await rh.client.post(`/coffre-fort/gestion/documents/${documentId}/retirer`, { reason: 'Erreur de dépôt' });
    assert.equal(res.status, 403);
    assert.equal(vault.byId(documentId).removed_at, null);
  });

  await t.test('un retrait exige un motif et laisse sa trace', async () => {
    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/documents/${documentId}/retirer`, { reason: 'x' });
    assert.match((await admin.flash('/coffre-fort/gestion')).message, /motif explicite/);
    assert.equal(vault.byId(documentId).removed_at, null);

    const onDisk = vault.pathOf(vault.byId(documentId));
    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/documents/${documentId}/retirer`, { reason: 'Déposé sur le mauvais compte' });

    const removed = vault.byId(documentId);
    assert.ok(removed.removed_at, 'la ligne reste au coffre');
    assert.equal(removed.removal_reason, 'Déposé sur le mauvais compte');
    assert.equal(fs.existsSync(onDisk), false, 'le fichier est effacé du disque');
    assert.equal(vault.documentsFor(salarie.id).length, 0);
    assert.equal(vault.documentsFor(salarie.id, { includeRemoved: true }).length, 1);
  });
});

test('Accès après le départ', async (t) => {
  const admin = await loginAsAdmin();
  const parti = await makeMember(admin, 'parti@test.local', { first_name: 'Paul', last_name: 'Parti' });

  await depose(admin, {
    user_id: String(parti.id), title: 'Bulletin de paie — décembre 2025',
    category: 'Bulletin de paie', period: '2025-12',
  }, { content: Buffer.from('%PDF-1.4 bulletin decembre\n%%EOF\n') });

  await t.test("le compte fermé ouvre le coffre, et rien d'autre", async () => {
    db.prepare("UPDATE users SET active = 0, contract_end_date = '2026-01-31' WHERE id = ?").run(parti.id);

    const ancien = newClient();
    const res = await ancien.login('parti@test.local', MEMBER_PASSWORD);
    assert.equal(res.headers.get('location'), '/coffre-fort');
    assert.equal((await ancien.get('/coffre-fort')).status, 200);

    // Tout le reste ramène au coffre, et rien ne s'écrit.
    for (const page of ['/mon-espace', '/annuaire', '/messagerie', '/agenda', '/projets']) {
      const blocked = await ancien.get(page);
      assert.equal(blocked.status, 302, page);
      assert.equal(blocked.headers.get('location'), '/coffre-fort', page);
    }
    await ancien.refreshToken('/coffre-fort');
    assert.equal((await ancien.post('/mon-profil/informations', { bio: 'tentative' })).status, 403);

    const { body } = await ancien.html('/coffre-fort');
    assert.match(body, /décembre 2025/);
  });

  await t.test("un compte fermé sans coffre n'entre pas", async () => {
    const vide = await makeMember(admin, 'vide@test.local');
    db.prepare('UPDATE users SET active = 0 WHERE id = ?').run(vide.id);

    const ancien = newClient();
    await ancien.login('vide@test.local', MEMBER_PASSWORD);
    assert.match((await ancien.flash('/connexion')).message, /compte est fermé/);
    assert.equal((await ancien.get('/coffre-fort')).headers.get('location'), '/connexion');
  });

  await t.test("un code d'accès ouvre le coffre, l'adresse seule non", async () => {
    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/acces/${parti.id}`, { days: '30' });

    // Le code n'existe qu'en session, le temps d'un seul affichage : il faut
    // donc le lire sur le même chargement que le message, pas sur le suivant.
    const { body } = await admin.html(`/coffre-fort/gestion?personne=${parti.id}`);
    assert.match(body, /Code émis/);
    const code = body.match(/<li>([A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5})<\/li>/)[1];

    // Et il a bien disparu au rechargement suivant.
    const { body: encore } = await admin.html(`/coffre-fort/gestion?personne=${parti.id}`);
    assert.doesNotMatch(encore, /[A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5}/);

    const visiteur = newClient();
    await visiteur.refreshToken('/coffre-fort/acces');
    await visiteur.post('/coffre-fort/acces', { email: 'parti@test.local', code: 'AAAAA-BBBBB-CCCCC' });
    assert.match((await visiteur.flash('/coffre-fort/acces')).message, /invalide/);

    await visiteur.refreshToken('/coffre-fort/acces');
    const opened = await visiteur.post('/coffre-fort/acces', { email: 'parti@test.local', code });
    assert.equal(opened.headers.get('location'), '/coffre-fort');
    assert.equal((await visiteur.get('/coffre-fort')).status, 200);
    assert.equal((await visiteur.get('/mon-espace')).headers.get('location'), '/coffre-fort');

    // L'usage est compté.
    assert.equal(db.prepare('SELECT uses FROM vault_access_grants WHERE user_id = ? ORDER BY id DESC LIMIT 1').get(parti.id).uses, 1);
  });

  await t.test('émettre un nouveau code révoque le précédent', async () => {
    const before = db.prepare('SELECT * FROM vault_access_grants WHERE user_id = ? ORDER BY id DESC LIMIT 1').get(parti.id);
    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/acces/${parti.id}`, { days: '30' });

    assert.ok(db.prepare('SELECT revoked_at FROM vault_access_grants WHERE id = ?').get(before.id).revoked_at);
    assert.equal(vault.grantsFor(parti.id).filter((g) => !g.revoked_at).length, 1);
  });

  await t.test('un code révoqué ou expiré n\'ouvre plus rien', async () => {
    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/acces/${parti.id}`, { days: '30' });
    const { body } = await admin.html(`/coffre-fort/gestion?personne=${parti.id}`);
    const code = body.match(/<li>([A-Z0-9]{5}-[A-Z0-9]{5}-[A-Z0-9]{5})<\/li>/)[1];

    await admin.refreshToken('/coffre-fort/gestion');
    await admin.post(`/coffre-fort/gestion/acces/${parti.id}/revoquer`);

    const visiteur = newClient();
    await visiteur.refreshToken('/coffre-fort/acces');
    await visiteur.post('/coffre-fort/acces', { email: 'parti@test.local', code });
    assert.match((await visiteur.flash('/coffre-fort/acces')).message, /invalide/);
    assert.equal(visiteur.cookies.has('pm.sid') && (await visiteur.get('/coffre-fort')).status, 302);
  });

  await t.test("un code ne dit pas si le compte existe", async () => {
    const visiteur = newClient();
    await visiteur.refreshToken('/coffre-fort/acces');
    await visiteur.post('/coffre-fort/acces', { email: 'jamais-vu@test.local', code: 'AAAAA-BBBBB-CCCCC' });
    const inconnu = (await visiteur.flash('/coffre-fort/acces')).message;

    await visiteur.refreshToken('/coffre-fort/acces');
    await visiteur.post('/coffre-fort/acces', { email: 'parti@test.local', code: 'ZZZZZ-YYYYY-XXXXX' });
    const connu = (await visiteur.flash('/coffre-fort/acces')).message;

    assert.equal(inconnu, connu, 'le message est le même dans les deux cas');
  });
});
