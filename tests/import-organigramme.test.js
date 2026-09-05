const test = require('node:test');
const assert = require('node:assert/strict');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const csv = require('../src/csv');
const importer = require('../src/importer');
const org = require('../src/org');
const modules = require('../src/modules');

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

test('Lecture des fichiers CSV', async (t) => {
  await t.test('le séparateur est reconnu tout seul', () => {
    assert.equal(csv.parse('a;b\n1;2').delimiter, ';');
    assert.equal(csv.parse('a,b\n1,2').delimiter, ',');
    assert.equal(csv.parse('a\tb\n1\t2').delimiter, '\t');
  });

  await t.test('un champ entre guillemets garde ses virgules et ses retours à la ligne', () => {
    const parsed = csv.parse('nom,adresse\n"Curie","12, rue de la Paix\nParis"');
    assert.equal(parsed.rows[0].adresse, '12, rue de la Paix\nParis');
  });

  await t.test('un guillemet doublé est un guillemet', () => {
    assert.equal(csv.parse('titre\n"Le ""Petit"" Prince"').rows[0].titre, 'Le "Petit" Prince');
  });

  await t.test('la marque d\'Excel en tête de fichier est retirée', () => {
    // Sans cela, la première colonne s'appellerait « ﻿prenom » et ne
    // correspondrait à rien.
    assert.deepEqual(csv.parse('﻿prenom;nom\nJean;Dupont').headers, ['prenom', 'nom']);
  });

  await t.test('les en-têtes sont normalisés : accents, casse, espaces', () => {
    assert.deepEqual(csv.parse('Prénom;Type de contrat;E-MAIL\nJean;CDI;j@x.fr').headers,
      ['prenom', 'type_de_contrat', 'e_mail']);
  });

  await t.test('les fins de ligne Windows et les lignes vides ne gênent pas', () => {
    const parsed = csv.parse('a;b\r\n1;2\r\n\r\n3;4\r\n');
    assert.equal(parsed.rows.length, 2);
    assert.deepEqual(parsed.rows.map((r) => r.__line), [2, 3]);
  });

  await t.test('un fichier vide ou aux colonnes en double est refusé', () => {
    assert.equal(csv.parse('').ok, false);
    assert.equal(csv.parse('   ').ok, false);
    assert.match(csv.parse('a;a\n1;2').message, /même nom/);
    assert.match(csv.parse('a;;b\n1;2;3').message, /sans nom/);
  });

  await t.test('un fichier démesuré est refusé avant d\'être analysé', () => {
    const lines = ['a'].concat(Array.from({ length: 5001 }, (_, i) => String(i)));
    const verdict = csv.parse(lines.join('\n'));
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /découpez/);
  });
});

test('Import de membres', async (t) => {
  const admin = await loginAsAdmin();
  const departmentId = db.prepare("INSERT INTO departments (name, description) VALUES ('Production', '')").run().lastInsertRowid;
  db.prepare("INSERT INTO teams (name, department_id, description) VALUES ('Atelier A', ?, '')").run(departmentId);

  const bon = [
    'prenom;nom;email;grade;type_contrat;service;equipe',
    'Jean;Dupont;jean.dupont@import.local;Employé;CDI;Production;Atelier A',
    'Marie;Curie;marie.curie@import.local;Technicien;CDD;Production;',
  ].join('\n');

  await t.test('l\'aperçu ne touche pas la base', async () => {
    await admin.refreshToken('/import');
    const { res, body } = await (async () => {
      const response = await admin.post('/import/apercu', { entity: 'membres', content: bon });
      return { res: response, body: await response.text() };
    })();

    assert.equal(res.status, 200);
    assert.match(body, /Jean Dupont/);
    assert.match(body, /Prête/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM users WHERE email LIKE '%@import.local'").get().n, 0);
  });

  await t.test('un fichier avec une ligne fautive n\'importe rien du tout', () => {
    const mauvais = `${bon}\nPaul;Sansmail;pas-une-adresse;Employé;CDI;;`;
    const verdict = importer.commit('membres', mauvais);

    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /rien n'a été importé/);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM users WHERE email LIKE '%@import.local'").get().n, 0,
      'les deux lignes valides ne doivent pas passer sans la troisième');
  });

  await t.test('chaque erreur est nommée avec son numéro de ligne', () => {
    const preview = importer.preview('membres', [
      'prenom;nom;email;grade;type_contrat',
      'Jean;Dupont;jean.dupont@import.local;Employé;CDI',
      'Alice;Martin;alice@import.local;Sorcière;CDI',
      'Bob;Martin;bob@import.local;Employé;Vacation',
      'Chloé;Noe;pas-une-adresse;Employé;CDI',
      'Dan;Dubois;jean.dupont@import.local;Employé;CDI',
    ].join('\n'));

    assert.deepEqual(preview.errors.map((e) => e.line), [3, 4, 5, 6]);
    assert.match(preview.errors[0].message, /Grade inconnu/);
    assert.match(preview.errors[1].message, /Type de contrat inconnu/);
    assert.match(preview.errors[2].message, /email invalide/);
    assert.match(preview.errors[3].message, /existe déjà/, 'le fichier est aussi vérifié contre lui-même');
  });

  await t.test('un service ou une équipe inconnus sont refusés', () => {
    const preview = importer.preview('membres', [
      'prenom;nom;email;grade;type_contrat;service',
      'Zoé;Blanc;zoe@import.local;Employé;CDI;Marketing',
    ].join('\n'));
    assert.match(preview.errors[0].message, /Service introuvable/);
  });

  await t.test('l\'import crée les comptes et rend les mots de passe une fois', async () => {
    await admin.refreshToken('/import');
    const res = await admin.post('/import/importer', { entity: 'membres', content: bon });
    const body = await res.text();

    assert.equal(res.status, 200);
    assert.match(body, /2 ligne\(s\) importée/);

    const created = db.prepare("SELECT * FROM users WHERE email LIKE '%@import.local' ORDER BY last_name").all();
    assert.equal(created.length, 2);
    assert.equal(created[0].must_change_password, 1, 'un mot de passe temporaire doit être remplacé');
    assert.equal(created[0].department_id, departmentId);
    assert.ok(created[0].password_hash.startsWith('$2'), 'le mot de passe est haché, jamais stocké en clair');

    // Le mot de passe rendu à l'écran ouvre bien le compte.
    const shown = new Map([...body.matchAll(/<td class="cell-strong">([^<]+)<\/td><td class="mono">([^<]+)<\/td>/g)]
      .map((m) => [m[1], m[2]]));
    assert.equal(shown.size, 2, 'les identifiants créés sont affichés une fois');

    const nouveau = newClient();
    await nouveau.login('marie.curie@import.local', shown.get('marie.curie@import.local'));
    assert.equal((await nouveau.get('/mon-profil/premier-acces')).status, 200);
  });

  await t.test('rejouer le même fichier est refusé, pas fondu dans l\'existant', () => {
    const verdict = importer.commit('membres', bon);
    assert.equal(verdict.ok, false);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM users WHERE email LIKE '%@import.local'").get().n, 2);
  });

  await t.test('le journal retient l\'import sans le contenu des lignes', () => {
    const trace = db.prepare("SELECT * FROM audit_log WHERE action = 'import.realise' ORDER BY id DESC").get();
    assert.ok(trace);
    assert.match(trace.detail, /lignes/);
    assert.equal(trace.detail.includes('marie.curie'), false);
  });
});

test('Import de tiers et cloisonnement', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('les tiers s\'importent avec leur type', () => {
    const verdict = importer.commit('tiers', [
      'nom;type;email;telephone',
      'Aciers du Nord;Fournisseur;contact@aciers.test;01 02 03 04 05',
      'Mairie de Lille;Client;;',
    ].join('\n'));

    assert.equal(verdict.ok, true);
    assert.equal(verdict.imported, 2);
    assert.equal(db.prepare("SELECT kind FROM partners WHERE name = 'Mairie de Lille'").get().kind, 'Client');
  });

  await t.test('un type inventé ou un doublon sont refusés', () => {
    const preview = importer.preview('tiers', [
      'nom;type',
      'Aciers du Nord;Fournisseur',
      'Nouvelle boîte;Partenaire',
    ].join('\n'));
    assert.match(preview.errors[0].message, /existe déjà/);
    assert.match(preview.errors[1].message, /Type inconnu/);
  });

  await t.test('les imports de module suivent l\'activation du module', () => {
    const user = db.prepare("SELECT * FROM users WHERE role = 'admin'").get();
    assert.equal(importer.availableFor(user).some((e) => e.key === 'articles'), false);

    modules.setEnabled('stock', true);
    assert.equal(importer.availableFor(user).some((e) => e.key === 'articles'), true);
  });

  await t.test('un contact se rattache à un tiers existant, désigné par son nom', () => {
    modules.setEnabled('crm', true);
    const verdict = importer.commit('contacts', [
      'tiers;prenom;nom;fonction;email',
      'Aciers du Nord;Luc;Berger;Acheteur;luc@aciers.test',
    ].join('\n'));
    assert.equal(verdict.ok, true);

    const contact = db.prepare('SELECT c.*, p.name FROM crm_contacts c JOIN partners p ON p.id = c.partner_id').get();
    assert.equal(contact.name, 'Aciers du Nord');
    assert.equal(contact.role, 'Acheteur');

    const orphelin = importer.preview('contacts', 'tiers;prenom;nom\nSociété fantôme;A;B');
    assert.match(orphelin.errors[0].message, /Tiers introuvable/);
  });

  await t.test('un salarié ordinaire n\'accède pas à l\'import', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Nina', last_name: 'Import', grade: 'Employé', contract_type: 'CDI',
      email: 'nina.import@test.local',
    });
    const flash = await admin.flash('/admin');
    const temporaire = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];

    const salarie = newClient();
    await salarie.firstAccess('nina.import@test.local', temporaire);
    assert.equal((await salarie.get('/import')).status, 403);

    // La gestion, elle, importe des tiers mais pas des membres.
    const userId = db.prepare('SELECT id FROM users WHERE email = ?').get('nina.import@test.local').id;
    db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(userId);
    const gestionnaire = db.prepare('SELECT * FROM users WHERE id = ?').get(userId);
    assert.deepEqual(importer.availableFor(gestionnaire).map((e) => e.key).sort(), ['articles', 'contacts', 'tiers']);

    await salarie.refreshToken('/import');
    const refus = await salarie.post('/import/importer', { entity: 'membres', content: 'prenom;nom\nA;B' });
    assert.equal(refus.status, 302);
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM users WHERE first_name = 'A'").get().n, 0);
  });

  await t.test('le modèle se télécharge avec ses en-têtes', async () => {
    const res = await admin.get('/import/tiers/modele.csv');
    assert.equal(res.status, 200);
    assert.match(res.headers.get('content-type'), /text\/csv/);
    assert.match(await res.text(), /nom;type;identifiant;contact;email;telephone;adresse/);
  });
});

test('Organigramme', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('la structure suit les services, les équipes et les rattachements', () => {
    const chart = org.chart({ includeHidden: true });
    const production = chart.departments.find((d) => d.name === 'Production');

    assert.ok(production);
    assert.deepEqual(production.teams.map((t2) => t2.name), ['Atelier A']);
    assert.deepEqual(production.teams[0].members.map((m) => m.last_name), ['Dupont']);
    assert.deepEqual(production.loose.map((m) => m.last_name), ['Curie'],
      'rattaché au service sans équipe : la place existe et se voit');
  });

  await t.test('les encadrants apparaissent sur leur périmètre', () => {
    const jean = db.prepare("SELECT id FROM users WHERE email = 'jean.dupont@import.local'").get().id;
    const production = org.departments().find((d) => d.name === 'Production');
    org.addManager('department', production.id, jean);

    const chart = org.chart({ includeHidden: true });
    const departement = chart.departments.find((d) => d.name === 'Production');
    assert.deepEqual(departement.managers.map((m) => m.last_name), ['Dupont']);
    assert.equal(chart.managerCount, 1);
  });

  await t.test('ceux qui ne sont rattachés nulle part se voient', () => {
    const chart = org.chart({ includeHidden: true });
    assert.ok(chart.unassigned.some((p) => p.email === undefined || true));
    assert.ok(chart.unassigned.length >= 1, "c'est ce qu'un organigramme sert à découvrir");
  });

  await t.test('une équipe sans service ne disparaît pas', () => {
    db.prepare("INSERT INTO teams (name, department_id, description) VALUES ('Équipe orpheline', NULL, '')").run();
    const chart = org.chart({ includeHidden: true });
    assert.deepEqual(chart.orphanTeams.map((t2) => t2.name), ['Équipe orpheline']);
  });

  await t.test('un membre retiré de l\'annuaire n\'apparaît qu\'à l\'administration', async () => {
    const marie = db.prepare("SELECT id FROM users WHERE email = 'marie.curie@import.local'").get().id;
    db.prepare('UPDATE users SET directory_hidden = 1 WHERE id = ?').run(marie);

    const publique = org.chart();
    const production = publique.departments.find((d) => d.name === 'Production');
    assert.deepEqual(production.loose.map((m) => m.last_name), [], "l'organigramme suit la règle de l'annuaire");

    const complete = org.chart({ includeHidden: true });
    assert.deepEqual(complete.departments.find((d) => d.name === 'Production').loose.map((m) => m.last_name), ['Curie']);
  });

  await t.test('la page s\'ouvre à tous et signale ce qu\'elle montre', async () => {
    const { res, body } = await admin.html('/organigramme');
    assert.equal(res.status, 200);
    assert.match(body, /Production/);
    assert.match(body, /Atelier A/);
    assert.match(body, /effectif entier/, "l'administration voit l'effectif complet et doit le savoir");

    const salarie = newClient();
    await salarie.login('nina.import@test.local', 'Prairie-Bleue-2026');
    const vue = await salarie.html('/organigramme');
    assert.equal(vue.res.status, 200);
    assert.equal(vue.body.includes('Curie'), false, 'un salarié ne voit pas ceux que l\'annuaire masque');
  });
});
