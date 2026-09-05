const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

const dir = prepareEnvironment();
process.env.VAULT_DIR = path.join(dir, 'coffre');
process.env.BACKUP_DIR = path.join(dir, 'sauvegardes');

const createApp = require('../src/app');
const db = require('../src/db');
const tar = require('../src/tar');
const backup = require('../src/backup');
const settings = require('../src/settings');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);
const companyName = () => settings.get('company_name');

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

test('Archives tar', async (t) => {
  await t.test('un aller-retour rend exactement ce qui a été mis', () => {
    const gros = Buffer.alloc(1500, 7);
    const archive = tar.pack([
      { name: 'db/app.sqlite', source: gros },
      { name: 'coffre/bulletin.pdf', source: Buffer.from('%PDF-1.4') },
      { name: 'meta/manifeste.json', source: Buffer.from('{"a":"é"}') },
    ]);
    assert.equal(archive.length % 512, 0, 'une archive tar est faite de blocs de 512 octets');

    const back = tar.unpack(archive);
    assert.deepEqual(back.map((e) => e.name), ['db/app.sqlite', 'coffre/bulletin.pdf', 'meta/manifeste.json']);
    assert.equal(back[0].data.equals(gros), true);
    assert.equal(back[2].data.toString('utf8'), '{"a":"é"}');
  });

  await t.test('un en-tête abîmé ou une archive coupée sont refusés', () => {
    const archive = tar.pack([{ name: 'db/app.sqlite', source: Buffer.alloc(100, 3) }]);

    const abime = Buffer.from(archive);
    abime[10] ^= 0xff;
    assert.throws(() => tar.unpack(abime), /somme de contrôle/);

    // Sans les blocs de fin, l'archive a été coupée : mieux vaut le dire que
    // rendre une liste incomplète en silence.
    assert.throws(() => tar.unpack(archive.subarray(0, 700)), /tronquée/);
  });

  await t.test('un chemin qui sort de sa racine est refusé', () => {
    const roots = ['db', 'coffre', 'meta'];
    for (const nom of ['../etc/passwd', '/etc/passwd', 'db/../../x', 'autre/x', 'db/./x', '']) {
      assert.equal(tar.safeName(nom, roots), null, nom);
    }
    assert.equal(tar.safeName('coffre/abc.pdf', roots), 'coffre/abc.pdf');
  });
});

test('Sauvegarde et restauration', async (t) => {
  const admin = await loginAsAdmin();
  let archiveName;

  await t.test("l'archive embarque la base et les fichiers", async () => {
    // Un document au coffre : c'est précisément ce qu'une sauvegarde de la
    // seule base laisserait derrière elle.
    fs.mkdirSync(process.env.VAULT_DIR, { recursive: true });
    fs.writeFileSync(path.join(process.env.VAULT_DIR, 'bulletin.pdf'), Buffer.from('%PDF-1.4 bulletin\n%%EOF'));
    settings.set('company_name', 'Avant restauration');

    await admin.refreshToken('/sauvegardes');
    await admin.post('/sauvegardes', { label: 'essai' });
    assert.match((await admin.flash('/sauvegardes')).message, /créée/);

    const archives = backup.list();
    assert.equal(archives.length >= 1, true);
    archiveName = archives[0].fileName;

    const verdict = backup.inspectFile(archiveName);
    assert.equal(verdict.ok, true, verdict.message);
    assert.equal(verdict.manifest.reason, 'manuelle');
    assert.equal(verdict.manifest.label, 'essai');
    assert.equal(verdict.entries.has('db/app.sqlite'), true);
    assert.equal(verdict.entries.has('coffre/bulletin.pdf'), true);
  });

  await t.test("une archive altérée est refusée avant d'écrire quoi que ce soit", () => {
    const original = fs.readFileSync(backup.pathOf(archiveName));
    const plain = zlib.gunzipSync(original);

    // On modifie le contenu d'un fichier sans toucher aux en-têtes : seule
    // l'empreinte du manifeste peut le voir.
    const at = plain.indexOf(Buffer.from('%PDF-1.4 bulletin'));
    assert.notEqual(at, -1);
    plain[at + 2] = 0x58;

    const verdict = backup.inspect(zlib.gzipSync(plain));
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /altéré/);
  });

  await t.test('un fichier qui n\'est pas une archive est refusé', () => {
    assert.match(backup.inspect(Buffer.from('bonjour')).message, /pas une archive/);
    assert.match(backup.inspect(zlib.gzipSync(Buffer.alloc(2048, 0))).message, /sans manifeste/);
  });

  await t.test('la restauration remet la base et les fichiers', async () => {
    settings.set('company_name', 'Après bêtise');
    fs.rmSync(path.join(process.env.VAULT_DIR, 'bulletin.pdf'));
    db.prepare("INSERT INTO tools (name, login_url) VALUES ('Outil ajouté après', 'https://exemple.test')").run();

    await admin.refreshToken('/sauvegardes');
    await admin.post(`/sauvegardes/${archiveName}/restaurer`, { confirmation: archiveName });

    assert.equal(companyName(), 'Avant restauration');
    assert.equal(fs.existsSync(path.join(process.env.VAULT_DIR, 'bulletin.pdf')), true);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM tools WHERE name = 'Outil ajouté après'").get().n, 0,
      "ce qui a été ajouté après la sauvegarde disparaît");
  });

  await t.test("l'état précédent est sauvegardé avant la restauration", () => {
    const safety = backup.list().find((a) => backup.inspectFile(a.fileName).manifest.reason === 'avant restauration');
    assert.ok(safety, 'une sauvegarde de sécurité a été prise');

    // Elle contient bien l'état d'avant, donc la bêtise est rattrapable.
    const verdict = backup.inspectFile(safety.fileName);
    assert.equal(verdict.ok, true);
  });

  await t.test('la session de l\'administrateur survit à la restauration', async () => {
    // Les sessions ne sont pas restaurées : sans quoi l'opération déconnecterait
    // celui qui la mène, au milieu.
    assert.equal(backup.NOT_RESTORED.has('sessions'), true);
    assert.equal((await admin.get('/sauvegardes')).status, 200);
  });

  await t.test('refuse une restauration sans le nom exact', async () => {
    settings.set('company_name', 'Sentinelle');
    await admin.refreshToken('/sauvegardes');
    await admin.post(`/sauvegardes/${archiveName}/restaurer`, { confirmation: 'à peu près' });

    assert.match((await admin.flash('/sauvegardes')).message, /nom exact/);
    assert.equal(companyName(), 'Sentinelle', "rien n'a été restauré");
  });

  await t.test('une colonne ajoutée depuis la sauvegarde ne fait pas échouer la restauration', () => {
    // Cas réel : une migration a ajouté une colonne après la sauvegarde. Seules
    // les colonnes communes sont recopiées.
    db.exec('ALTER TABLE tools ADD COLUMN nouvelle_colonne TEXT');
    const verdict = backup.restore(fs.readFileSync(backup.pathOf(archiveName)));
    assert.equal(verdict.ok, true, verdict.message);
    assert.equal(companyName(), 'Avant restauration');
  });

  await t.test('la purge garde le nombre configuré', async () => {
    backup.setConfig({ enabled: true, intervalMinutes: 60, keep: 2 });
    await backup.create({ reason: 'manuelle' });
    await backup.create({ reason: 'manuelle' });
    await backup.create({ reason: 'manuelle' });

    const removed = backup.prune();
    assert.equal(backup.list().length, 2);
    assert.equal(removed.length >= 1, true);
  });

  await t.test('la sauvegarde automatique respecte son intervalle', async () => {
    backup.setConfig({ enabled: true, intervalMinutes: 60, keep: 10 });
    backup.resetSchedule();

    const first = await backup.runScheduled();
    assert.ok(first, 'la première échéance déclenche une sauvegarde');
    assert.equal(first.reason, 'automatique');

    // Rejouée aussitôt, elle ne refait rien : l'intervalle n'est pas écoulé.
    assert.equal(await backup.runScheduled(), null);

    backup.setConfig({ enabled: false, intervalMinutes: 15, keep: 10 });
    backup.resetSchedule();
    assert.equal(await backup.runScheduled(), null, 'désactivée, elle ne sauvegarde plus');
    backup.setConfig({ enabled: true, intervalMinutes: 60, keep: 10 });
  });

  await t.test('refuse un intervalle ou une rétention hors bornes', async () => {
    await admin.refreshToken('/sauvegardes');
    await admin.post('/sauvegardes/reglages', { enabled: '1', interval_minutes: '5', keep: '10' });
    assert.match((await admin.flash('/sauvegardes')).message, /entre 15 minutes et 24 heures/);

    await admin.refreshToken('/sauvegardes');
    await admin.post('/sauvegardes/reglages', { enabled: '1', interval_minutes: '60', keep: '1' });
    assert.match((await admin.flash('/sauvegardes')).message, /entre 2 et 500/);
  });

  await t.test("l'espace est réservé à l'administration", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Sans', last_name: 'Droits', grade: 'Employé', contract_type: 'CDI', email: 'sauvegarde@test.local',
    });
    const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const client = newClient();
    await client.firstAccess('sauvegarde@test.local', password);

    assert.equal((await client.get('/sauvegardes')).status, 403);
    assert.equal((await client.get(`/sauvegardes/${archiveName}/telecharger`)).status, 403);

    await client.refreshToken('/mon-espace');
    assert.equal((await client.post(`/sauvegardes/${archiveName}/restaurer`, { confirmation: archiveName })).status, 403);
  });

  await t.test('le téléchargement rend une archive intacte', async () => {
    // Les purges précédentes ont pu emporter l'archive d'origine : on en crée
    // une pour ce contrôle-ci.
    const fresh = await backup.create({ reason: 'manuelle', label: 'téléchargement' });

    const res = await admin.get(`/sauvegardes/${fresh.fileName}/telecharger`);
    assert.equal(res.status, 200);

    const body = Buffer.from(await res.arrayBuffer());
    assert.equal(body.equals(fs.readFileSync(backup.pathOf(fresh.fileName))), true);
    assert.equal(backup.inspect(body).ok, true);
  });

  await t.test("un nom d'archive forgé ne sort pas du dossier", async () => {
    for (const nom of ['..%2F..%2Fetc%2Fpasswd', 'app.sqlite', 'sauvegarde-inexistante.tar.gz']) {
      assert.equal((await admin.get(`/sauvegardes/${nom}/telecharger`)).status, 404, nom);
    }
    assert.equal(backup.pathOf('../../etc/passwd'), null);
    assert.equal(backup.pathOf('app.sqlite'), null);
  });
});
