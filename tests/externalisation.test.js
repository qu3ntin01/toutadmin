const test = require('node:test');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const path = require('node:path');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD, MEMBER_PASSWORD } = require('./helpers');
const { startFtpServer } = require('./helpers-ftp');

const dir = prepareEnvironment();
process.env.VAULT_DIR = path.join(dir, 'coffre');
process.env.BACKUP_DIR = path.join(dir, 'sauvegardes');

const createApp = require('../src/app');
const db = require('../src/db');
const settings = require('../src/settings');
const secrets = require('../src/secret-store');
const notifications = require('../src/notifications');
const ftp = require('../src/offsite/ftp');
const drive = require('../src/offsite/drive');
const offsite = require('../src/offsite');

let server;
let baseUrl;
let ftpServer;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
  ftpServer = await startFtpServer();
});

test.after(async () => {
  server.close();
  await ftpServer.close();
});

const newClient = () => new Client(baseUrl);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

const ftpConfig = (extra = {}) => ({
  host: '127.0.0.1',
  port: ftpServer.port,
  mode: 'ftp',
  user: 'sauvegarde',
  password: 'motdepasse',
  directory: '/sauvegardes',
  ...extra,
});

/** Réinitialise la destination distante entre deux scénarios. */
function clearRemote() {
  ftpServer.files.clear();
}

// Un vrai couple de clés : la signature du jeton Google est vérifiée pour de
// bon, pas seulement sa forme.
const keyPair = crypto.generateKeyPairSync('rsa', { modulusLength: 2048 });
const driveConfig = {
  clientEmail: 'sauvegarde@projet.iam.gserviceaccount.com',
  privateKey: keyPair.privateKey.export({ type: 'pkcs8', format: 'pem' }),
  folderId: 'dossier-distant',
};

/**
 * Un faux Google : rend les réponses attendues et garde trace des requêtes,
 * pour éprouver le client sans jamais sortir du poste.
 */
function fakeGoogle({ token = 'jeton-google', files = [], fail = null } = {}) {
  const calls = [];
  const stored = new Map(files.map((f) => [f.id, f]));

  const fetchImpl = async (url, options = {}) => {
    calls.push({ url: String(url), options });
    const reply = (status, body) => ({
      ok: status < 400,
      status,
      text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
    });

    if (String(url) === drive.TOKEN_URL) {
      if (fail === 'token') return reply(401, { error: 'invalid_grant' });
      return reply(200, { access_token: token, expires_in: 3600 });
    }
    if (fail === 'api') return reply(403, 'Insufficient permissions');

    if (String(url).startsWith(drive.UPLOAD_URL)) {
      const id = `id-${stored.size + 1}`;
      stored.set(id, { id, name: 'inconnu', size: '0' });
      return reply(200, { id, name: 'inconnu' });
    }
    if (options.method === 'DELETE') {
      stored.delete(decodeURIComponent(String(url).split('/').pop().split('?')[0]));
      return reply(200, '');
    }
    return reply(200, { files: [...stored.values()] });
  };

  return { calls, stored, deps: { fetchImpl } };
}

test('Secrets rangés en base', async (t) => {
  await t.test('un secret ne se relit qu\'après déchiffrement', () => {
    const chiffre = secrets.encrypt('mot-de-passe-ftp');
    assert.equal(chiffre.startsWith('enc.v1:'), true);
    assert.equal(chiffre.includes('mot-de-passe-ftp'), false);
    assert.equal(secrets.decrypt(chiffre), 'mot-de-passe-ftp');
  });

  await t.test('deux chiffrements de la même valeur ne se ressemblent pas', () => {
    // Sinon, comparer deux réglages révélerait qu\'ils partagent un mot de passe.
    assert.notEqual(secrets.encrypt('identique'), secrets.encrypt('identique'));
  });

  await t.test('une valeur altérée est perdue, pas devinée', () => {
    const chiffre = secrets.encrypt('clé-privée');
    const raw = Buffer.from(chiffre.slice('enc.v1:'.length), 'base64');
    raw[raw.length - 1] ^= 0xff;
    assert.equal(secrets.decrypt(`enc.v1:${raw.toString('base64')}`), '');
  });

  await t.test('le masque dit la présence du secret, jamais sa valeur', () => {
    assert.equal(secrets.mask(''), '');
    const masque = secrets.mask(secrets.encrypt('sésame'));
    assert.equal(masque.includes('sésame'), false);
    assert.match(masque, /défini/);
  });
});

test('Client FTP', async (t) => {
  await t.test('le test de connexion écrit puis efface un fichier d\'essai', async () => {
    clearRemote();
    const verdict = await ftp.test(ftpConfig());
    assert.equal(verdict.ok, true, verdict.message);
    assert.equal(ftpServer.files.size, 0, 'le fichier d\'essai ne doit pas rester');
  });

  await t.test('une archive arrive octet pour octet', async () => {
    clearRemote();
    const archive = crypto.randomBytes(5000);
    const envoi = await ftp.upload(ftpConfig(), 'sauvegarde-2026-01-02T03-04-05-abcd.tar.gz', archive);
    assert.equal(envoi.ok, true, envoi.message);

    const distant = ftpServer.files.get('sauvegarde-2026-01-02T03-04-05-abcd.tar.gz');
    assert.equal(distant.equals(archive), true);
  });

  await t.test('le dossier distant est créé s\'il manque', () => {
    assert.equal(ftpServer.directories.has('sauvegardes'), true);
  });

  await t.test('la liste ne retient que les archives', async () => {
    clearRemote();
    await ftp.upload(ftpConfig(), 'sauvegarde-a.tar.gz', Buffer.from('a'));
    await ftp.upload(ftpConfig(), 'notes-perso.txt', Buffer.from('bb'));

    const listing = await ftp.list(ftpConfig());
    assert.equal(listing.ok, true);
    assert.deepEqual(listing.files.map((f) => f.name), ['sauvegarde-a.tar.gz']);
    assert.equal(listing.files[0].bytes, 1);
  });

  await t.test('une suppression retire réellement le fichier', async () => {
    clearRemote();
    await ftp.upload(ftpConfig(), 'sauvegarde-b.tar.gz', Buffer.from('b'));
    assert.equal((await ftp.remove(ftpConfig(), 'sauvegarde-b.tar.gz')).ok, true);
    assert.equal(ftpServer.files.has('sauvegarde-b.tar.gz'), false);
  });

  await t.test('un mot de passe faux est refusé sans exception', async () => {
    const verdict = await ftp.test(ftpConfig({ password: 'faux' }));
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /530|refus/i);
  });

  await t.test('un serveur injoignable rend une erreur, pas un plantage', async () => {
    const verdict = await ftp.test(ftpConfig({ port: 1, timeoutMs: 2000 }));
    assert.equal(verdict.ok, false);
    assert.equal(typeof verdict.message, 'string');
  });
});

test('Client Google Drive', async (t) => {
  await t.test('le jeton présenté à Google est signé par la clé du compte de service', () => {
    const now = 1767225600000;
    const assertion = drive.buildAssertion(driveConfig, now);
    const [header, claims, signature] = assertion.split('.');
    assert.equal(assertion.split('.').length, 3);

    const decode = (part) => JSON.parse(Buffer.from(part, 'base64url').toString('utf8'));
    assert.deepEqual(decode(header), { alg: 'RS256', typ: 'JWT' });

    const payload = decode(claims);
    assert.equal(payload.iss, driveConfig.clientEmail);
    assert.equal(payload.aud, drive.TOKEN_URL);
    assert.equal(payload.scope, drive.SCOPE);
    assert.equal(payload.exp - payload.iat, 3600);

    const valide = crypto.createVerify('RSA-SHA256')
      .update(`${header}.${claims}`)
      .verify(keyPair.publicKey, Buffer.from(signature, 'base64url'));
    assert.equal(valide, true, 'Google rejetterait un jeton mal signé');
  });

  await t.test('une clé collée depuis le fichier JSON est acceptée', () => {
    const collee = keyPair.privateKey.export({ type: 'pkcs8', format: 'pem' }).replace(/\n/g, '\\n');
    assert.doesNotThrow(() => drive.buildAssertion({ ...driveConfig, privateKey: collee }));
  });

  await t.test('l\'archive part en une requête authentifiée', async () => {
    drive.forgetTokens();
    const google = fakeGoogle();
    const archive = Buffer.from('contenu-de-l-archive');

    const envoi = await drive.upload(driveConfig, 'sauvegarde-c.tar.gz', archive, google.deps);
    assert.equal(envoi.ok, true, envoi.message);

    const dépôt = google.calls.at(-1);
    assert.match(dépôt.url, /uploadType=multipart/);
    assert.equal(dépôt.options.headers.authorization, 'Bearer jeton-google');
    const corps = dépôt.options.body;
    assert.match(corps.toString('utf8'), /"parents":\["dossier-distant"\]/);
    assert.match(corps.toString('utf8'), /"name":"sauvegarde-c.tar.gz"/);
    assert.equal(corps.includes(archive), true, 'le contenu binaire doit voyager tel quel');
  });

  await t.test('le jeton est réutilisé au lieu d\'être redemandé', async () => {
    drive.forgetTokens();
    const google = fakeGoogle();
    await drive.upload(driveConfig, 'sauvegarde-d.tar.gz', Buffer.from('d'), google.deps);
    await drive.upload(driveConfig, 'sauvegarde-e.tar.gz', Buffer.from('e'), google.deps);

    const jetons = google.calls.filter((c) => c.url === drive.TOKEN_URL);
    assert.equal(jetons.length, 1);
  });

  await t.test('la liste ne retient que les archives et rend leur identifiant', async () => {
    drive.forgetTokens();
    const google = fakeGoogle({
      files: [
        { id: 'x1', name: 'sauvegarde-f.tar.gz', size: '42', createdTime: '2026-01-01T00:00:00Z' },
        { id: 'x2', name: 'photo.png', size: '10', createdTime: '2026-01-02T00:00:00Z' },
      ],
    });

    const listing = await drive.list(driveConfig, {}, google.deps);
    assert.equal(listing.ok, true);
    assert.deepEqual(listing.files, [{ id: 'x1', name: 'sauvegarde-f.tar.gz', bytes: 42, modifiedAt: '2026-01-01T00:00:00Z' }]);
  });

  await t.test('une authentification refusée est rendue lisible', async () => {
    drive.forgetTokens();
    const google = fakeGoogle({ fail: 'token' });
    const envoi = await drive.upload(driveConfig, 'sauvegarde-g.tar.gz', Buffer.from('g'), google.deps);
    assert.equal(envoi.ok, false);
    assert.match(envoi.message, /Google/);
  });

  await t.test('un dossier non partagé est rendu lisible', async () => {
    drive.forgetTokens();
    const google = fakeGoogle({ fail: 'api' });
    const envoi = await drive.upload(driveConfig, 'sauvegarde-h.tar.gz', Buffer.from('h'), google.deps);
    assert.equal(envoi.ok, false);
    assert.match(envoi.message, /403/);
  });
});

test('Réglage des destinations', async (t) => {
  await t.test('le secret est rangé chiffré, jamais rendu à l\'écran', () => {
    offsite.setConfig('ftp', ftpConfig());

    const brut = settings.get('offsite.ftp.config');
    assert.equal(brut.includes('motdepasse'), false, 'le mot de passe ne doit pas dormir en clair');
    assert.equal(offsite.config('ftp').password, 'motdepasse');
    assert.equal(offsite.displayConfig('ftp').password.includes('motdepasse'), false);
    assert.match(offsite.displayConfig('ftp').password, /défini/);
  });

  await t.test('un secret laissé vide conserve le précédent', () => {
    // L'écran ne montre jamais le secret : il ne peut pas le renvoyer, et une
    // simple modification du port effacerait sinon le mot de passe.
    offsite.setConfig('ftp', { ...ftpConfig(), password: '', port: 2121 });
    assert.equal(offsite.config('ftp').password, 'motdepasse');
    assert.equal(offsite.config('ftp').port, '2121');
    offsite.setConfig('ftp', ftpConfig());
  });

  await t.test('une destination incomplète ne peut pas être activée', () => {
    offsite.setConfig('drive', { clientEmail: '', privateKey: '', folderId: '' });
    assert.equal(offsite.isConfigured('drive'), false);
    assert.equal(offsite.setEnabled('drive', true), false);
    assert.equal(offsite.isEnabled('drive'), false);
  });

  await t.test('une destination renseignée s\'active', () => {
    assert.equal(offsite.isConfigured('ftp'), true);
    assert.equal(offsite.setEnabled('ftp', true), true);
    assert.deepEqual(offsite.enabled(), ['ftp']);
  });
});

test('Envoi des sauvegardes au-dehors', async (t) => {
  await t.test('l\'archive est déposée et l\'issue conservée', async () => {
    clearRemote();
    offsite.setConfig('ftp', ftpConfig());
    offsite.setEnabled('ftp', true);

    const résultats = await offsite.afterBackup('sauvegarde-2026-03-01.tar.gz', Buffer.from('archive'), { keep: 24 });
    assert.deepEqual(résultats.map((r) => r.ok), [true]);
    assert.equal(ftpServer.files.has('sauvegarde-2026-03-01.tar.gz'), true);

    const état = offsite.status('ftp');
    assert.equal(état.ok, true);
    assert.equal(état.fileName, 'sauvegarde-2026-03-01.tar.gz');
    assert.deepEqual(offsite.failing(), []);
  });

  await t.test('le distant est aligné sur le nombre d\'archives conservées', async () => {
    clearRemote();
    for (const nom of ['sauvegarde-1.tar.gz', 'sauvegarde-2.tar.gz', 'sauvegarde-3.tar.gz']) {
      await ftp.upload(ftpConfig(), nom, Buffer.from(nom));
    }
    const élagage = await offsite.prune('ftp', 2);
    assert.equal(élagage.ok, true);
    assert.equal(ftpServer.files.size, 2, 'un espace distant qui grossit sans fin finit par refuser les dépôts');
  });

  await t.test('un échec alerte les administrateurs au lieu de passer inaperçu', async () => {
    const admin = db.prepare("SELECT id FROM users WHERE role = 'admin' AND active = 1").get();
    const avant = notifications.forUser(admin.id, { limit: 200 }).length;

    offsite.setConfig('ftp', ftpConfig({ password: 'faux' }));
    const résultats = await offsite.afterBackup('sauvegarde-2026-03-02.tar.gz', Buffer.from('archive'), { keep: 24 });
    assert.deepEqual(résultats.map((r) => r.ok), [false]);

    const après = notifications.forUser(admin.id, { limit: 200 });
    assert.equal(après.length, avant + 1);
    assert.match(après[0].title, /Externalisation en échec/);
    assert.equal(offsite.failing().map((d) => d.key).join(), 'ftp');

    // Deux échecs le même jour ne noient pas la boîte sous les rappels.
    await offsite.afterBackup('sauvegarde-2026-03-03.tar.gz', Buffer.from('archive'), { keep: 24 });
    assert.equal(notifications.forUser(admin.id, { limit: 200 }).length, avant + 1);

    offsite.setConfig('ftp', ftpConfig());
  });
});

test('Écran d\'externalisation', async (t) => {
  await t.test('seule l\'administration y accède', async () => {
    const salarie = newClient();
    const temporaire = 'Mot-De-Passe-Temp-2026';
    const admin = await loginAsAdmin();
    await admin.refreshToken('/admin/membres/nouveau');
    await admin.post('/admin/membres/nouveau', {
      first_name: 'Camille', last_name: 'Extern', email: 'camille.extern@test.local',
      password: temporaire, role: 'employee',
    });
    await salarie.firstAccess('camille.extern@test.local', temporaire, MEMBER_PASSWORD);

    const refus = await salarie.get('/sauvegardes');
    assert.equal([302, 403].includes(refus.status), true);
  });

  await t.test('la page montre les destinations sans laisser fuir les secrets', async () => {
    const admin = await loginAsAdmin();
    const { res, body } = await admin.html('/sauvegardes');
    assert.equal(res.status, 200);
    assert.match(body, /Serveur FTP/);
    assert.match(body, /Google Drive/);
    assert.equal(body.includes('motdepasse'), false);
  });

  await t.test('l\'administration enregistre et éprouve une destination', async () => {
    clearRemote();
    const admin = await loginAsAdmin();
    await admin.refreshToken('/sauvegardes');

    const enregistré = await admin.post('/sauvegardes/destinations/ftp', {
      host: '127.0.0.1', port: String(ftpServer.port), mode: 'ftp',
      user: 'sauvegarde', password: 'motdepasse', directory: '/sauvegardes', enabled: '1',
    });
    assert.equal(enregistré.status, 302);
    assert.equal(offsite.isEnabled('ftp'), true);

    await admin.post('/sauvegardes/destinations/ftp/tester', {});
    const message = await admin.flash('/sauvegardes');
    assert.equal(message.type, 'success', message.message);
    assert.match(message.message, /Serveur FTP/);
  });

  await t.test('activer une destination vide est refusé', async () => {
    const admin = await loginAsAdmin();
    await admin.refreshToken('/sauvegardes');
    await admin.post('/sauvegardes/destinations/drive', {
      clientEmail: '', privateKey: '', folderId: '', enabled: '1',
    });

    const message = await admin.flash('/sauvegardes');
    assert.equal(message.type, 'error');
    assert.match(message.message, /obligatoires/);
    assert.equal(offsite.isEnabled('drive'), false);
  });

  await t.test('une destination inconnue est écartée', async () => {
    const admin = await loginAsAdmin();
    await admin.refreshToken('/sauvegardes');
    const res = await admin.post('/sauvegardes/destinations/dropbox', { enabled: '1' });
    assert.equal(res.status, 302);
    const message = await admin.flash('/sauvegardes');
    assert.equal(message.type, 'error');
    assert.match(message.message, /inconnue/);
  });
});
