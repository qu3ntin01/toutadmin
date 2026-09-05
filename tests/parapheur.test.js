const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD, MEMBER_PASSWORD } = require('./helpers');

const dir = prepareEnvironment();
process.env.SIGN_DIR = path.join(dir, 'parapheur');

const createApp = require('../src/app');
const db = require('../src/db');
const signing = require('../src/signing');
const notifications = require('../src/notifications');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);

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
  return { client, id: db.prepare('SELECT id FROM users WHERE email = ?').get(email).id };
}

/** Un PDF minimal, mais un vrai : la signature du fichier est contrôlée. */
const pdf = () => Buffer.concat([Buffer.from('%PDF-1.4\n'), Buffer.alloc(400, 0x20), Buffer.from('\n%%EOF\n')]);

async function postFile(client, pathname, fields, file) {
  const boundary = `----test${Date.now()}`;
  const parts = [];
  for (const [name, value] of Object.entries(fields)) {
    for (const item of [].concat(value)) {
      parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${name}"\r\n\r\n${item}\r\n`));
    }
  }
  if (file) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="document"; filename="${file.name}"\r\nContent-Type: ${file.type}\r\n\r\n`));
    parts.push(file.buffer);
    parts.push(Buffer.from('\r\n'));
  }
  parts.push(Buffer.from(`--${boundary}--\r\n`));

  const res = await fetch(baseUrl + pathname, {
    method: 'POST',
    headers: {
      cookie: [...client.cookies].map(([n, v]) => `${n}=${v}`).join('; '),
      'content-type': `multipart/form-data; boundary=${boundary}`,
    },
    body: Buffer.concat(parts),
    redirect: 'manual',
  });
  return res;
}

test('Parapheur : circuit de signature', async (t) => {
  const admin = await loginAsAdmin();
  const salarie = await makeMember(admin, 'lea.signature@test.local', { first_name: 'Léa', last_name: 'Marchand' });
  const employeur = await makeMember(admin, 'paul.employeur@test.local', { first_name: 'Paul', last_name: 'Roussel' });
  let requestId;

  await t.test('un document sans signataire ou sans contenu est refusé', () => {
    assert.match(signing.create({ title: 'Vide', kind: 'Avenant', createdBy: 1, signers: [{ userId: salarie.id }] }).message, /fichier ou saisissez/);
    assert.match(signing.create({ title: 'Sans signataire', kind: 'Avenant', body: 'Texte', createdBy: 1, signers: [] }).message, /signataire/);
    assert.match(signing.create({ title: 'Doublon', kind: 'Avenant', body: 'Texte', createdBy: 1, signers: [{ userId: salarie.id }, { userId: salarie.id }] }).message, /deux fois/);
    assert.match(signing.create({ title: 'Inconnu', kind: 'Avenant', body: 'Texte', createdBy: 1, signers: [{ userId: 9999 }] }).message, /inconnu/);
  });

  await t.test('le document est figé par son empreinte dès l\'ouverture', async () => {
    await admin.refreshToken('/parapheur');
    const res = await postFile(admin, '/parapheur', {
      _csrf: admin.csrfToken,
      title: 'Avenant au contrat de Léa Marchand',
      kind: 'Avenant',
      body: "L'horaire hebdomadaire passe de 35 à 32 heures à compter du 1er novembre.",
      deadline: '2026-12-31',
      signer_ids: [String(salarie.id), '', String(employeur.id)],
      signer_roles: ['Salariée', 'ignoré', 'Employeur'],
    }, null);

    assert.equal(res.status, 302);
    requestId = Number(res.headers.get('location').split('/').pop());

    const request = signing.decorate(signing.byId(requestId));
    assert.equal(request.status, 'En cours');
    assert.equal(request.sha256.length, 64);
    // Un rang laissé libre ne décale pas les qualités des suivants.
    assert.deepEqual(request.signers.map((s) => [s.position, s.first_name, s.role_label]),
      [[1, 'Léa', 'Salariée'], [2, 'Paul', 'Employeur']]);
    assert.equal(signing.verify(request).ok, true);
  });

  await t.test('le premier signataire est prévenu, pas les suivants', () => {
    assert.equal(notifications.forUser(salarie.id, { limit: 20 }).some((n) => n.title.includes('Avenant')), true);
    assert.equal(notifications.forUser(employeur.id, { limit: 20 }).some((n) => n.title.includes('Avenant')), false);
  });

  await t.test('le parapheur circule : le second ne signe pas avant le premier', async () => {
    const verdict = signing.canSign(signing.byId(requestId), employeur.id);
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /au tour de Léa/);

    await employeur.client.refreshToken(`/parapheur/${requestId}`);
    await employeur.client.post(`/parapheur/${requestId}/signer`, { password: MEMBER_PASSWORD, consent: '1' });
    assert.equal(signing.signersOf(requestId)[1].status, 'En attente');
  });

  await t.test('un mot de passe erroné ou un consentement absent n\'apposent rien', async () => {
    await salarie.client.refreshToken(`/parapheur/${requestId}`);
    await salarie.client.post(`/parapheur/${requestId}/signer`, { password: 'mauvais-mot-de-passe', consent: '1' });
    let message = await salarie.client.flash(`/parapheur/${requestId}`);
    assert.match(message.message, /Mot de passe incorrect/);

    await salarie.client.post(`/parapheur/${requestId}/signer`, { password: MEMBER_PASSWORD });
    message = await salarie.client.flash(`/parapheur/${requestId}`);
    assert.match(message.message, /consentement/);

    assert.equal(signing.signersOf(requestId)[0].status, 'En attente');
  });

  await t.test('la signature porte qui, quand, d\'où, et un sceau vérifiable', async () => {
    await salarie.client.refreshToken(`/parapheur/${requestId}`);
    const res = await salarie.client.post(`/parapheur/${requestId}/signer`, { password: MEMBER_PASSWORD, consent: '1' });
    assert.equal(res.status, 302);

    const signer = signing.signersOf(requestId)[0];
    assert.equal(signer.status, 'Signé');
    assert.ok(signer.signed_at);
    assert.ok(signer.ip);
    assert.equal(signer.seal.length, 64);

    const request = signing.byId(requestId);
    assert.equal(signer.seal, signing.computeSeal({ documentHash: request.sha256, userId: salarie.id, signedAt: signer.signed_at }));
    assert.equal(signing.verify(request).ok, true);
    assert.equal(request.status, 'En cours', 'il reste un signataire');
  });

  await t.test('le suivant est prévenu à son tour', () => {
    assert.equal(notifications.forUser(employeur.id, { limit: 20 }).some((n) => n.title.includes('Avenant')), true);
  });

  await t.test('un sceau réécrit en base ne passe plus la vérification', () => {
    const original = signing.signersOf(requestId)[0].seal;
    db.prepare("UPDATE signature_signers SET seal = ? WHERE request_id = ? AND position = 1").run('0'.repeat(64), requestId);

    const check = signing.verify(signing.byId(requestId));
    assert.equal(check.ok, false);
    assert.deepEqual(check.broken, ['Léa Marchand']);

    db.prepare('UPDATE signature_signers SET seal = ? WHERE request_id = ? AND position = 1').run(original, requestId);
    assert.equal(signing.verify(signing.byId(requestId)).ok, true);
  });

  await t.test('un texte modifié après coup interdit toute nouvelle signature', () => {
    const original = signing.byId(requestId).body;
    db.prepare('UPDATE signature_requests SET body = ? WHERE id = ?').run(`${original} — et 30 heures en réalité.`, requestId);

    const verdict = signing.sign(requestId, employeur.id, { password: MEMBER_PASSWORD, consent: true });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /altéré/);

    db.prepare('UPDATE signature_requests SET body = ? WHERE id = ?').run(original, requestId);
  });

  await t.test('la dernière signature clôt le circuit', async () => {
    await employeur.client.refreshToken(`/parapheur/${requestId}`);
    await employeur.client.post(`/parapheur/${requestId}/signer`, { password: MEMBER_PASSWORD, consent: '1' });

    const request = signing.byId(requestId);
    assert.equal(request.status, 'Signé');
    assert.ok(request.completed_at);
    assert.equal(signing.pendingCountFor(employeur.id), 0);
  });

  await t.test('un document signé ne se supprime pas', async () => {
    const verdict = signing.remove(requestId);
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /fait preuve/);

    await admin.refreshToken('/parapheur');
    await admin.post(`/parapheur/${requestId}/supprimer`, {});
    assert.ok(signing.byId(requestId));
  });

  await t.test('l\'attestation rend l\'empreinte, les sceaux et leur vérification', async () => {
    const { res, body } = await admin.html(`/parapheur/${requestId}/attestation`);
    assert.equal(res.status, 200);
    assert.match(body, new RegExp(signing.byId(requestId).sha256));
    assert.match(body, /sceau vérifié/);
    assert.match(body, /eIDAS/, "la portée de l'attestation doit être dite, pas suggérée");
  });

  await t.test('un tiers ne voit pas le document', async () => {
    const tiers = await makeMember(admin, 'curieux@test.local', { first_name: 'Curieux', last_name: 'Tiers' });
    assert.equal((await tiers.client.get(`/parapheur/${requestId}`)).status, 403);
    assert.equal((await tiers.client.get(`/parapheur/${requestId}/attestation`)).status, 403);

    // Il peut ouvrir le parapheur, mais n'y met rien à la signature.
    assert.equal((await tiers.client.get('/parapheur')).status, 200);
    await tiers.client.refreshToken('/parapheur');
    assert.equal((await tiers.client.post('/parapheur', { title: 'Tentative', kind: 'Avenant', body: 'x' })).status, 403);
  });
});

test('Parapheur : fichier, refus et annulation', async (t) => {
  const admin = await loginAsAdmin();
  const signataire = await makeMember(admin, 'nadia.parapheur@test.local', { first_name: 'Nadia', last_name: 'Sellam' });

  await t.test('un fichier déguisé est refusé', () => {
    const verdict = signing.create({
      title: 'Faux PDF', kind: 'Contrat de travail', createdBy: 1,
      file: { buffer: Buffer.from('MZ exécutable déguisé'), mimetype: 'application/pdf', originalname: 'contrat.pdf' },
      signers: [{ userId: signataire.id }],
    });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /type annoncé/);
  });

  await t.test('un PDF est scellé par son empreinte et servi seulement s\'il correspond', async () => {
    await admin.refreshToken('/parapheur');
    const contenu = pdf();
    const res = await postFile(admin, '/parapheur', {
      _csrf: admin.csrfToken,
      title: 'Contrat de travail — Nadia Sellam',
      kind: 'Contrat de travail',
      signer_ids: String(signataire.id),
      signer_roles: 'Salariée',
    }, { name: 'contrat.pdf', type: 'application/pdf', buffer: contenu });

    const id = Number(res.headers.get('location').split('/').pop());
    const request = signing.byId(id);
    assert.equal(request.sha256, signing.fingerprint(contenu));
    assert.equal(request.byte_size, contenu.length);

    const download = await admin.get(`/parapheur/${id}/document`);
    assert.equal(download.status, 200);
    assert.equal(Buffer.from(await download.arrayBuffer()).equals(contenu), true);

    // Le fichier modifié sur le disque n'est plus servi : c'est tout l'intérêt.
    fs.writeFileSync(signing.pathOf(request), Buffer.concat([contenu, Buffer.from('altération')]));
    assert.equal((await admin.get(`/parapheur/${id}/document`)).status, 409);
    assert.equal(signing.verifyDocument(request).ok, false);

    fs.writeFileSync(signing.pathOf(request), contenu);
    assert.equal(signing.verifyDocument(request).ok, true);
  });

  await t.test('un refus se motive et interrompt le circuit', async () => {
    const verdict = signing.create({
      title: 'Accord à refuser', kind: 'Accord interne', body: 'Texte proposé.',
      createdBy: 1, signers: [{ userId: signataire.id }],
    });

    const sansMotif = signing.refuse(verdict.id, signataire.id, { reason: '  ' });
    assert.equal(sansMotif.ok, false);
    assert.match(sansMotif.message, /se motive/);

    await signataire.client.refreshToken(`/parapheur/${verdict.id}`);
    await signataire.client.post(`/parapheur/${verdict.id}/refuser`, { reason: 'La clause 4 est inacceptable.' });

    const request = signing.decorate(signing.byId(verdict.id));
    assert.equal(request.status, 'Refusé');
    assert.equal(request.signers[0].status, 'Refusé');
    assert.match(request.closing_reason, /clause 4/);
    assert.equal(notifications.forUser(1, { limit: 30 }).some((n) => n.title.includes('Signature refusée')), true);
  });

  await t.test('un document annulé sans signature se supprime, avec son fichier', async () => {
    const contenu = pdf();
    const verdict = signing.create({
      title: 'Contrat annulé', kind: 'Contrat de travail', createdBy: 1,
      file: { buffer: contenu, mimetype: 'application/pdf', originalname: 'a-annuler.pdf' },
      signers: [{ userId: signataire.id }],
    });
    const target = signing.pathOf(signing.byId(verdict.id));
    assert.equal(fs.existsSync(target), true);

    assert.match(signing.remove(verdict.id).message, /Annulez le document/);

    await admin.refreshToken('/parapheur');
    await admin.post(`/parapheur/${verdict.id}/annuler`, { reason: 'Erreur de destinataire' });
    assert.equal(signing.byId(verdict.id).status, 'Annulé');

    await admin.post(`/parapheur/${verdict.id}/supprimer`, {});
    assert.equal(signing.byId(verdict.id), null);
    assert.equal(fs.existsSync(target), false, 'le fichier suit la ligne qui le décrivait');
  });

  await t.test('signer un document clos est refusé', () => {
    const verdict = signing.create({
      title: 'Déjà annulé', kind: 'Document', body: 'x', createdBy: 1,
      signers: [{ userId: signataire.id }],
    });
    signing.cancel(verdict.id, 'Sans objet');

    const refus = signing.sign(verdict.id, signataire.id, { password: MEMBER_PASSWORD, consent: true });
    assert.equal(refus.ok, false);
    assert.match(refus.message, /n'est plus à la signature/);
  });

  await t.test('le fichier du parapheur n\'est pas lisible par le monde', () => {
    const request = signing.list().find((r) => r.file_name);
    const mode = fs.statSync(signing.pathOf(request)).mode & 0o777;
    assert.equal(mode, 0o600);
    assert.equal(fs.statSync(signing.SIGN_DIR).mode & 0o777, 0o700);
  });
});
