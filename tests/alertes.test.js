const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const audit = require('../src/audit');
const whistleblow = require('../src/whistleblow');
const deadlines = require('../src/deadlines');

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

// ---------------------------------------------------------------- alerte interne

test("Dispositif d'alerte interne", async (t) => {
  const admin = await loginAsAdmin();
  const lanceur = await makeMember(admin, 'lanceur@test.local', { first_name: 'Léa', last_name: 'Alerte' });
  const referent = await makeMember(admin, 'referent@test.local', { first_name: 'Rémi', last_name: 'Référent' });

  await t.test("sans référent désigné, le dispositif n'accepte rien", async () => {
    await lanceur.client.refreshToken('/alertes');
    await lanceur.client.post('/alertes/signalements', { subject: 'Essai', category: 'Fraude', body: 'x', anonymous: '1' });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM whistleblow_reports').get().n, 0);

    const flash = await lanceur.client.flash('/alertes');
    assert.match(flash.message, /Aucun référent/);
  });

  let reportId;
  let code;

  await t.test("un signalement anonyme n'enregistre pas son auteur, et rien ne le rattrape", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/alerte/nommer', { employee_id: String(referent.id) });

    const before = db.prepare('SELECT COUNT(*) AS n FROM audit_log').get().n;
    await lanceur.client.refreshToken('/alertes');
    await lanceur.client.post('/alertes/signalements', {
      subject: 'Facturation suspecte', category: 'Fraude',
      body: 'Des factures sans commande, réglées par le même circuit.', anonymous: '1',
    });

    const report = db.prepare('SELECT * FROM whistleblow_reports').get();
    reportId = report.id;
    assert.equal(report.author_id, null);
    assert.equal(report.anonymous, 1);

    // Le contenu est chiffré : une copie de la base ne livre pas le signalement.
    assert.doesNotMatch(report.subject_enc, /Facturation/);
    assert.doesNotMatch(report.body_enc, /circuit/);

    // Le journal général retient qu'un signalement est arrivé, jamais de qui :
    // la trace automatique nommerait l'auteur du dépôt.
    const added = db.prepare('SELECT * FROM audit_log ORDER BY id DESC LIMIT ?')
      .all(db.prepare('SELECT COUNT(*) AS n FROM audit_log').get().n - before);
    assert.equal(added.length, 1);
    assert.equal(added[0].action, 'alerte.deposee');
    assert.equal(added[0].actor_id, null);
    assert.equal(added[0].actor_label, 'système');
    assert.equal(added[0].entity_id, null);
  });

  await t.test("le code de suivi n'est affiché qu'une fois", async () => {
    const { body } = await lanceur.client.html('/alertes');
    code = (body.match(/<span class="cell-strong mono">([A-Z2-9]{20})<\/span>/) || [])[1];
    assert.ok(code, 'le code doit être rendu au retour du dépôt');

    const { body: encore } = await lanceur.client.html('/alertes');
    assert.doesNotMatch(encore, /<span class="cell-strong mono">[A-Z2-9]{20}<\/span>/);

    // Il n'est gardé que haché : la base ne peut pas le redonner.
    const stored = db.prepare('SELECT follow_code_hash FROM whistleblow_reports WHERE id = ?').get(reportId).follow_code_hash;
    assert.doesNotMatch(stored, new RegExp(code));
  });

  await t.test("l'administration désigne les référents mais ne lit pas les signalements", async () => {
    assert.equal((await admin.get(`/alertes/signalements/${reportId}`)).status, 403);

    const { body } = await admin.html('/alertes');
    assert.doesNotMatch(body, /Facturation suspecte/);
  });

  await t.test('le référent lit le signalement déchiffré, et sa lecture est consignée', async () => {
    const { res, body } = await referent.client.html(`/alertes/signalements/${reportId}`);
    assert.equal(res.status, 200);
    assert.match(body, /Des factures sans commande/);

    const seen = db.prepare('SELECT COUNT(*) AS n FROM whistleblow_access_log WHERE report_id = ?').get(reportId).n;
    assert.equal(seen, 1);
  });

  await t.test("l'auteur suit son signalement sans compte, avec sa référence et son code", async () => {
    await referent.client.refreshToken(`/alertes/signalements/${reportId}`);
    await referent.client.post(`/alertes/signalements/${reportId}/message`, { body: 'Nous instruisons.' });

    const reference = whistleblow.byId(reportId).reference;
    const visiteur = newClient();

    await visiteur.refreshToken('/alertes/suivi');
    const refuse = await visiteur.post('/alertes/suivi', { reference, code: 'AAAAAAAAAAAAAAAAAAAA' });
    assert.match(await refuse.text(), /Référence ou code inconnu/);

    await visiteur.refreshToken('/alertes/suivi');
    const ouvert = await visiteur.post('/alertes/suivi', { reference, code });
    const body = await ouvert.text();
    assert.equal(ouvert.status, 200);
    assert.match(body, /Nous instruisons/);
    // Le suivi ne demande jamais de se connecter : ce serait signer son signalement.
    assert.doesNotMatch(body, /name="password"/);
  });

  await t.test('les deux délais légaux sont comptés, pas promis', async () => {
    // Un signalement vieux de dix jours, sans accusé : le délai de 7 jours est passé.
    db.prepare("UPDATE whistleblow_reports SET submitted_at = datetime('now', '-10 days') WHERE id = ?").run(reportId);
    assert.equal(whistleblow.summary().lateAck, 1);

    const rows = deadlines.collect().filter((row) => row.source === 'Alerte');
    assert.ok(rows.some((row) => row.detail === 'Accusé de réception' && row.overdue));
    // Elles ne sont adressées qu'aux référents.
    assert.deepEqual(rows[0].audience, [referent.id]);

    await referent.client.refreshToken(`/alertes/signalements/${reportId}`);
    await referent.client.post(`/alertes/signalements/${reportId}/accuser`, {});
    assert.equal(whistleblow.summary().lateAck, 0);
  });
});

// ---------------------------------------------------------------- scellement

test("Scellement du journal d'audit", async (t) => {
  await t.test('une chaîne intacte se vérifie', () => {
    const verdict = audit.verifySeal();
    assert.equal(verdict.ok, true);
    assert.ok(verdict.sealed > 0);
    assert.equal(verdict.broken, null);
  });

  await t.test('une entrée modifiée en base est détectée, et nommée', () => {
    const target = db.prepare("SELECT * FROM audit_log WHERE action != '' ORDER BY id LIMIT 1 OFFSET 3").get();
    const original = target.detail;
    db.prepare('UPDATE audit_log SET detail = ? WHERE id = ?').run('{"falsifie":true}', target.id);

    const verdict = audit.verifySeal();
    assert.equal(verdict.ok, false);
    assert.equal(verdict.broken.reason, 'contenu');
    assert.equal(verdict.broken.row.id, target.id);

    db.prepare('UPDATE audit_log SET detail = ? WHERE id = ?').run(original, target.id);
    assert.equal(audit.verifySeal().ok, true);
  });

  await t.test('une entrée retirée du milieu casse la chaîne', () => {
    const target = db.prepare('SELECT * FROM audit_log ORDER BY id LIMIT 1 OFFSET 5').get();
    db.prepare('DELETE FROM audit_log WHERE id = ?').run(target.id);

    const verdict = audit.verifySeal();
    assert.equal(verdict.ok, false);
    assert.equal(verdict.broken.reason, 'chaine');

    // Remise en état pour la suite : l'entrée est réécrite à l'identique.
    db.prepare(`
      INSERT INTO audit_log (id, occurred_at, actor_id, actor_label, action, entity, entity_id, detail, ip, prev_hash, hash)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    `).run(target.id, target.occurred_at, target.actor_id, target.actor_label, target.action,
      target.entity, target.entity_id, target.detail, target.ip, target.prev_hash, target.hash);
    assert.equal(audit.verifySeal().ok, true);
  });

  await t.test('une purge laisse sa propre entrée plutôt que de se faire oublier', () => {
    db.prepare("UPDATE audit_log SET occurred_at = datetime('now', '-400 days') WHERE id = (SELECT MIN(id) FROM audit_log)").run();
    const removed = audit.purgeOlderThan(365);
    assert.equal(removed, 1);

    const trace = db.prepare("SELECT * FROM audit_log WHERE action = 'journal.purge' ORDER BY id DESC LIMIT 1").get();
    assert.ok(trace, 'la purge doit laisser une trace');
    assert.match(trace.detail, /"supprimees":1/);

    // Le début de la chaîne est parti : c'est légitime, et la vérification le tolère.
    assert.equal(audit.verifySeal().ok, true);
  });

  await t.test("la console de sécurité affiche l'état du sceau", async () => {
    const admin = await loginAsAdmin();
    const { body } = await admin.html('/securite');
    assert.match(body, /Scellement du journal/);
    assert.match(body, /aucune altération détectée/);
  });
});

// ---------------------------------------------------------------- secrets à usage unique

test('Les secrets à usage unique ne survivent pas au rechargement', async (t) => {
  const admin = await loginAsAdmin();

  await t.test("les codes de secours de la double authentification ne s'affichent qu'une fois", async () => {
    const membre = await makeMember(admin, 'deuxfacteurs@test.local', { first_name: 'Théa', last_name: 'Facteur' });

    await membre.client.refreshToken('/mon-profil');
    await membre.client.post('/mon-profil/2fa/preparer', {});
    const secret = db.prepare('SELECT totp_secret FROM users WHERE id = ?').get(membre.id).totp_secret;
    const totp = require('../src/totp');

    await membre.client.refreshToken('/mon-profil');
    await membre.client.post('/mon-profil/2fa/activer', { code: totp.currentCode(secret) });

    const { body } = await membre.client.html('/mon-profil');
    const codes = body.match(/<li>[A-F0-9]{5}-[A-F0-9]{5}<\/li>/g) || [];
    assert.equal(codes.length, 8, 'les huit codes de secours doivent être rendus une fois');

    // Rechargée, la page ne les montre plus : ils n'existent qu'en session, et
    // la session les a perdus au premier affichage.
    const { body: encore } = await membre.client.html('/mon-profil');
    assert.doesNotMatch(encore, /<li>[A-F0-9]{5}-[A-F0-9]{5}<\/li>/);
  });
});
