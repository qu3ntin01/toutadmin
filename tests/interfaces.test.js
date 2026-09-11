const test = require('node:test');
const assert = require('node:assert/strict');
const http = require('node:http');
const crypto = require('node:crypto');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const tokens = require('../src/api-tokens');
const webhooks = require('../src/webhooks');
const finance = require('../src/finance');

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

const api = (pathname, token) => fetch(baseUrl + pathname, {
  headers: token ? { authorization: `Bearer ${token}` } : {},
});

test('Jetons d\'API', async (t) => {
  const admin = await loginAsAdmin();
  let token;

  await t.test('un jeton sans portée ou sans nom est refusé', () => {
    assert.match(tokens.create({ label: '', scopes: ['rh'] }).message, /intitulé/);
    assert.match(tokens.create({ label: 'Sans portée', scopes: [] }).message, /portée/);
    assert.match(tokens.create({ label: 'Portée inventée', scopes: ['tout'] }).message, /portée/);
    assert.match(tokens.create({ label: 'Éternel', scopes: ['rh'], days: 99999 }).message, /durée de vie/);
  });

  await t.test('la valeur en clair n\'est rendue qu\'une fois, jamais stockée', async () => {
    await admin.refreshToken('/integrations');
    const res = await admin.post('/integrations/jetons', {
      label: 'Outil de paie', scopes: ['annuaire', 'rh'], days: '90',
    });
    const body = await res.text();

    const shown = body.match(/class="mono cred-box">(sm_[A-Za-z0-9_-]+)</);
    assert.ok(shown, 'le jeton s\'affiche une fois');
    token = shown[1];

    const row = db.prepare('SELECT * FROM api_tokens WHERE label = ?').get('Outil de paie');
    assert.equal(row.token_hash, tokens.hash(token));
    assert.equal(row.token_hash.includes(token), false, 'seule l\'empreinte est conservée');
    assert.equal(row.prefix, token.slice(0, 11));

    // Le journal d'audit garde la trace de la création, pas la valeur.
    const trace = db.prepare("SELECT * FROM audit_log WHERE action = 'api.jeton_cree'").get();
    assert.equal(trace.detail.includes(token.slice(4)), false);
  });

  await t.test('sans jeton, l\'API répond 401 et rien d\'autre', async () => {
    const res = await api('/api/v1/collaborateurs');
    assert.equal(res.status, 401);
    assert.match(res.headers.get('www-authenticate'), /Bearer/);
    assert.deepEqual(Object.keys(await res.json()), ['error']);
  });

  await t.test('un jeton inventé ou mal formé est refusé de la même façon', async () => {
    for (const essai of ['sm_inconnu', 'sans-prefixe', `${tokens.PREFIX}${'a'.repeat(43)}`]) {
      assert.equal((await api('/api/v1/collaborateurs', essai)).status, 401);
    }
  });

  await t.test('la portée est respectée route par route', async () => {
    const refus = await api('/api/v1/factures', token);
    assert.equal(refus.status, 403);
    const body = await refus.json();
    assert.match(body.error, /gestion/);
    assert.deepEqual(body.scopes, ['annuaire', 'rh']);

    assert.equal((await api('/api/v1/collaborateurs', token)).status, 200);
    assert.equal((await api('/api/v1/absences', token)).status, 200);
  });

  await t.test('l\'API ne rend que ce qu\'elle doit', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Sonia', last_name: 'Api', grade: 'Employé', contract_type: 'CDI', email: 'sonia.api@test.local',
    });
    const masque = db.prepare('SELECT id FROM users WHERE email = ?').get('sonia.api@test.local').id;

    const visible = await (await api('/api/v1/collaborateurs', token)).json();
    assert.ok(visible.data.some((p) => p.nom === 'Api'));
    assert.equal(Object.keys(visible.data[0]).includes('password_hash'), false);

    // Retiré de l'annuaire par l'administration : il ne ressort pas par l'API.
    db.prepare('UPDATE users SET directory_hidden = 1 WHERE id = ?').run(masque);
    const apres = await (await api('/api/v1/collaborateurs', token)).json();
    assert.equal(apres.data.some((p) => p.nom === 'Api'), false);
  });

  await t.test('le motif d\'une absence ne sort pas', async () => {
    const employee = db.prepare("SELECT id FROM users WHERE role = 'employee' LIMIT 1").get().id;
    db.prepare(`
      INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, reason, status)
      VALUES (?, 'Congés payés', '2026-07-01', '2026-07-15', 10, 'Voyage de noces', 'Approuvée')
    `).run(employee);

    const absences = await (await api('/api/v1/absences', token)).json();
    assert.equal(absences.data.length, 1);
    assert.equal(absences.data[0].jours, 10);
    assert.equal(JSON.stringify(absences.data).includes('noces'), false, "le motif reste dans l'entreprise");
  });

  await t.test('la pagination est bornée', async () => {
    const res = await (await api('/api/v1/collaborateurs?limite=9999', token)).json();
    assert.equal(res.limit, 200);
    assert.equal((await (await api('/api/v1/collaborateurs?limite=1&depuis=0', token)).json()).data.length <= 1, true);
  });

  await t.test('les appels sont comptés et datés', () => {
    const row = db.prepare('SELECT * FROM api_tokens WHERE label = ?').get('Outil de paie');
    assert.ok(row.calls > 0);
    assert.ok(row.last_used_at);
    assert.ok(row.last_ip);
  });

  await t.test('une réponse d\'API n\'est pas mise en cache', async () => {
    const res = await api('/api/v1', token);
    assert.equal(res.headers.get('cache-control'), 'no-store');
    const body = await res.json();
    assert.equal(body.readOnly, true);
    assert.deepEqual(body.token.scopes, ['annuaire', 'rh']);
  });

  await t.test('une route inconnue rend du JSON, pas une page', async () => {
    const res = await api('/api/v1/nimporte-quoi', token);
    assert.equal(res.status, 404);
    assert.match(res.headers.get('content-type'), /application\/json/);
  });

  await t.test('la révocation prend effet immédiatement', async () => {
    const row = db.prepare('SELECT id FROM api_tokens WHERE label = ?').get('Outil de paie');
    await admin.refreshToken('/integrations');
    await admin.post(`/integrations/jetons/${row.id}/revoquer`, {});

    assert.equal((await api('/api/v1/collaborateurs', token)).status, 401);
    assert.equal(tokens.resolve(token), null);
  });

  await t.test('un jeton expiré ne répond plus', () => {
    const made = tokens.create({ label: 'Expiré', scopes: ['annuaire'], days: 1 });
    db.prepare("UPDATE api_tokens SET expires_at = date('now', '-1 day') WHERE id = ?").run(made.id);
    assert.equal(tokens.resolve(made.token), null);
  });

  await t.test('l\'écran des interfaces est réservé à l\'administration', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Yann', last_name: 'Curieux', grade: 'Employé', contract_type: 'CDI', email: 'yann.curieux@test.local',
    });
    const flash = await admin.flash('/admin');
    const temporaire = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const salarie = newClient();
    await salarie.firstAccess('yann.curieux@test.local', temporaire);

    assert.equal((await salarie.get('/integrations')).status, 403);
    await salarie.refreshToken('/mon-espace');
    assert.equal((await salarie.post('/integrations/jetons', { label: 'X', scopes: ['rh'] })).status, 403);
  });
});

test('Webhooks', async (t) => {
  const admin = await loginAsAdmin();
  const received = [];
  let hookServer;
  let hookUrl;

  test.before(async () => {});

  await t.test('un serveur d\'essai reçoit les envois', async () => {
    hookServer = http.createServer((req, res) => {
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        received.push({ url: req.url, headers: req.headers, body });
        res.writeHead(req.url === '/refuse' ? 500 : 200).end('ok');
      });
    });
    await new Promise((resolve) => hookServer.listen(0, '127.0.0.1', resolve));
    hookUrl = `http://127.0.0.1:${hookServer.address().port}/hook`;
    assert.ok(hookUrl);
  });

  await t.test('une adresse interne ou en clair est refusée par défaut', () => {
    assert.match(webhooks.create({ label: 'Local', url: hookUrl, events: ['facture.creee'] }).message, /https/);
    assert.match(webhooks.create({ label: 'Interne', url: 'https://192.168.0.4/hook', events: ['facture.creee'] }).message, /réseau interne/);
    assert.match(webhooks.create({ label: 'Sans événement', url: 'https://exemple.fr/h', events: [] }).message, /événement/);
    assert.match(webhooks.create({ label: 'URL cassée', url: 'pas-une-url', events: ['facture.creee'], allowPrivate: true }).message, /URL invalide/);
  });

  let hookId;
  let secret;

  await t.test('le secret est rendu une fois et chiffré en base', async () => {
    await admin.refreshToken('/integrations');
    const res = await admin.post('/integrations/webhooks', {
      label: 'Essai local', url: hookUrl, events: ['facture.creee', 'absence.approuvee'], allow_private: '1',
    });
    const body = await res.text();
    secret = body.match(/class="mono cred-box">([A-Za-z0-9_-]{20,})</)[1];

    const row = db.prepare('SELECT * FROM webhooks WHERE label = ?').get('Essai local');
    hookId = row.id;
    assert.equal(row.secret.startsWith('enc.v1:'), true);
    assert.equal(row.secret.includes(secret), false);
    assert.equal(require('../src/secret-store').decrypt(row.secret), secret);
  });

  await t.test('un événement est mis en file puis livré, signé', async () => {
    finance.createInvoice({
      direction: 'Client', label: 'Facture webhook', issueDate: '2026-05-05',
      amountHt: 100, vatRate: 20, createdBy: 1,
    });

    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE status = 'En attente'").get().n, 1);

    const result = await webhooks.flush();
    assert.deepEqual([result.delivered, result.failed], [1, 0]);

    const call = received.at(-1);
    assert.equal(call.headers['x-toutadmin-event'], 'facture.creee');
    assert.equal(call.headers['x-toutadmin-signature'], webhooks.signature(secret, call.body));

    const payload = JSON.parse(call.body);
    assert.equal(payload.event, 'facture.creee');
    assert.equal(payload.data.libelle, 'Facture webhook');
    assert.ok(payload.at);
  });

  await t.test('la signature ne vaut que pour ce corps exact', () => {
    const call = received.at(-1);
    assert.notEqual(webhooks.signature(secret, `${call.body} `), call.headers['x-toutadmin-signature']);
    assert.notEqual(webhooks.signature('autre-secret', call.body), call.headers['x-toutadmin-signature']);
  });

  await t.test('seuls les événements écoutés partent', () => {
    const avant = db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries').get().n;
    webhooks.emit('ticket.ouvert', { id: 1 });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries').get().n, avant);

    webhooks.emit('absence.approuvee', { id: 1 });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries').get().n, avant + 1);

    // Un événement inventé n'est pas mis en file.
    assert.equal(webhooks.emit('licorne.apparue', {}), 0);
  });

  await t.test('un échec est réessayé, puis abandonné', async () => {
    await webhooks.flush();
    db.prepare('UPDATE webhooks SET url = ? WHERE id = ?').run(hookUrl.replace('/hook', '/refuse'), hookId);
    webhooks.emit('facture.creee', { essai: true });

    for (let attempt = 0; attempt < webhooks.MAX_ATTEMPTS; attempt += 1) {
      db.prepare("UPDATE webhook_deliveries SET next_try_at = datetime('now', '-1 hour') WHERE status = 'En attente'").run();
      await webhooks.flush();
    }

    const delivery = db.prepare('SELECT * FROM webhook_deliveries ORDER BY id DESC LIMIT 1').get();
    assert.equal(delivery.status, 'Abandonné');
    assert.equal(delivery.attempts, webhooks.MAX_ATTEMPTS);
    assert.match(delivery.last_error, /500/);
  });

  await t.test('un webhook qui échoue en série s\'éteint tout seul', async () => {
    // L'URL pointe toujours sur /refuse : l'envoi va échouer une fois de plus.
    db.prepare('UPDATE webhooks SET failures = ? WHERE id = ?').run(webhooks.FAILURE_LIMIT - 1, hookId);
    webhooks.emit('facture.creee', { essai: true });
    await webhooks.flush();

    assert.equal(db.prepare('SELECT active FROM webhooks WHERE id = ?').get(hookId).active, 0,
      'mieux vaut un tuyau éteint, qui se voit, qu\'un tuyau muet');
  });

  await t.test('un webhook suspendu ne reçoit plus rien', () => {
    const avant = db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries').get().n;
    webhooks.emit('facture.creee', { essai: true });
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries').get().n, avant);
  });

  await t.test('la réactivation remet le compteur d\'échecs à zéro', async () => {
    db.prepare('UPDATE webhooks SET url = ? WHERE id = ?').run(hookUrl, hookId);
    await admin.refreshToken('/integrations');
    await admin.post(`/integrations/webhooks/${hookId}/etat`, { active: '1' });

    const row = db.prepare('SELECT * FROM webhooks WHERE id = ?').get(hookId);
    assert.equal(row.active, 1);
    assert.equal(row.failures, 0);
  });

  await t.test('la purge nettoie le journal sans toucher à l\'attente', () => {
    db.prepare("UPDATE webhook_deliveries SET created_at = datetime('now', '-60 days') WHERE status != 'En attente'").run();
    const enAttente = db.prepare("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE status = 'En attente'").get().n;

    const purged = webhooks.purge({ days: 30 });
    assert.ok(purged > 0);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM webhook_deliveries WHERE status = 'En attente'").get().n, enAttente);
  });

  await t.test('supprimer le webhook emporte son journal', async () => {
    await admin.refreshToken('/integrations');
    await admin.post(`/integrations/webhooks/${hookId}/supprimer`, {});
    assert.equal(webhooks.byId(hookId), null);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM webhook_deliveries WHERE webhook_id = ?').get(hookId).n, 0);
  });

  test.after(() => hookServer && hookServer.close());
});
