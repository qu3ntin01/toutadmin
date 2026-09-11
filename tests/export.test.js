const test = require('node:test');
const assert = require('node:assert/strict');
const zlib = require('node:zlib');
const path = require('node:path');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

const dir = prepareEnvironment();
process.env.VAULT_DIR = path.join(dir, 'coffre');
process.env.SIGN_DIR = path.join(dir, 'parapheur');
process.env.BACKUP_DIR = path.join(dir, 'sauvegardes');

const createApp = require('../src/app');
const db = require('../src/db');
const tar = require('../src/tar');
const exporter = require('../src/export');
const tokens = require('../src/api-tokens');
const webhooks = require('../src/webhooks');

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

const read = (archive, name) => {
  const entry = tar.unpack(zlib.gunzipSync(archive)).find((e) => e.name === name);
  return entry ? entry.data.toString('utf8') : null;
};

test('Export intégral', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('les tables sensibles ne sont pas exportées', () => {
    const names = exporter.tables();
    for (const skipped of ['sessions', 'totp_recovery_codes', 'api_tokens']) {
      assert.equal(names.includes(skipped), false, `${skipped} ne doit pas sortir`);
    }
    assert.equal(names.includes('users'), true);
  });

  await t.test('les colonnes sensibles sont retirées ligne par ligne', () => {
    const rows = exporter.rowsOf('users');
    assert.ok(rows.length >= 1);
    assert.equal('password_hash' in rows[0], false);
    assert.equal('totp_secret' in rows[0], false);
    assert.equal(rows[0].email, ADMIN_EMAIL, 'le reste de la ligne est bien là');
  });

  await t.test('les réglages chiffrés ne sortent pas', () => {
    const settings = require('../src/settings');
    settings.set('offsite.ftp.config', '{"password":"enc.v1:secret"}');
    settings.set('company_name', 'Entreprise Témoin');

    const rows = exporter.rowsOf('settings');
    assert.equal(rows.some((r) => r.key === 'offsite.ftp.config'), false);
    assert.equal(rows.some((r) => r.key === 'company_name'), true);
  });

  await t.test('le secret d\'un webhook ne quitte pas le serveur', () => {
    webhooks.create({ label: 'Sortie', url: 'https://exemple.fr/hook', events: ['facture.creee'] });
    const rows = exporter.rowsOf('webhooks');
    assert.equal(rows.length, 1);
    assert.equal('secret' in rows[0], false);
    assert.equal(rows[0].url, 'https://exemple.fr/hook');
  });

  await t.test('l\'archive porte les données, les fichiers et son mode d\'emploi', () => {
    const archive = exporter.build();
    assert.match(archive.fileName, /^export-\d{4}-\d{2}-\d{2}T[\d-]+\.tar\.gz$/);

    const entries = tar.unpack(zlib.gunzipSync(archive.buffer)).map((e) => e.name);
    assert.equal(entries.includes('LISEZMOI.txt'), true);
    assert.equal(entries.includes('meta/manifeste.json'), true);
    assert.equal(entries.includes('donnees/users.json'), true);
    assert.equal(entries.some((name) => name.startsWith('donnees/sessions')), false);

    const users = JSON.parse(read(archive.buffer, 'donnees/users.json'));
    assert.equal(Array.isArray(users), true);
    assert.equal(users.some((u) => u.email === ADMIN_EMAIL), true);
    assert.equal(JSON.stringify(users).includes('$2a$'), false, 'aucune empreinte de mot de passe');
  });

  await t.test('le manifeste dit ce qui est dedans et ce qui manque', () => {
    const archive = exporter.build();
    const manifest = JSON.parse(read(archive.buffer, 'meta/manifeste.json'));

    assert.equal(manifest.format, 'toutadmin-export');
    assert.equal(manifest.instance, 'Entreprise Témoin');
    assert.ok(manifest.tables.length > 50);
    assert.ok(manifest.omissions.some((o) => /mots de passe/.test(o)));
    assert.ok(manifest.genere_le);
  });

  await t.test('le mode d\'emploi distingue l\'export de la sauvegarde', () => {
    assert.match(exporter.README, /la sauvegarde sert à revenir, l'export sert à partir/);
  });

  await t.test('un jeton d\'API ne se retrouve nulle part dans l\'archive', () => {
    const made = tokens.create({ label: 'Jeton export', scopes: ['annuaire'], days: 30 });
    const archive = exporter.build();
    const whole = zlib.gunzipSync(archive.buffer).toString('binary');

    assert.equal(whole.includes(made.token), false);
    assert.equal(whole.includes(tokens.hash(made.token)), false);
  });

  await t.test('l\'export se télécharge et n\'est pas écrit sur le serveur', async () => {
    const backup = require('../src/backup');
    const avant = require('node:fs').existsSync(backup.BACKUP_DIR)
      ? require('node:fs').readdirSync(backup.BACKUP_DIR).length
      : 0;

    await admin.refreshToken('/sauvegardes');
    const res = await fetch(`${baseUrl}/sauvegardes/export`, {
      method: 'POST',
      headers: {
        cookie: [...admin.cookies].map(([n, v]) => `${n}=${v}`).join('; '),
        'content-type': 'application/x-www-form-urlencoded',
      },
      body: new URLSearchParams({ _csrf: admin.csrfToken }).toString(),
      redirect: 'manual',
    });

    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-disposition'), /attachment; filename="export-/);
    const received = Buffer.from(await res.arrayBuffer());
    assert.ok(tar.unpack(zlib.gunzipSync(received)).length > 10);

    const apres = require('node:fs').existsSync(backup.BACKUP_DIR)
      ? require('node:fs').readdirSync(backup.BACKUP_DIR).length
      : 0;
    assert.equal(apres, avant, "l'export ne laisse pas une copie de l'entreprise sur le serveur");
  });

  await t.test('l\'export est tracé au journal', () => {
    const trace = db.prepare("SELECT * FROM audit_log WHERE action = 'export.integral' ORDER BY id DESC").get();
    assert.ok(trace);
    assert.match(trace.detail, /tables/);
  });

  await t.test('l\'export est réservé à l\'administration', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Nino', last_name: 'Export', grade: 'Employé', contract_type: 'CDI', email: 'nino.export@test.local',
    });
    const flash = await admin.flash('/admin');
    const temporaire = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const salarie = newClient();
    await salarie.firstAccess('nino.export@test.local', temporaire);

    await salarie.refreshToken('/mon-espace');
    assert.equal((await salarie.post('/sauvegardes/export', {})).status, 403);
  });
});
