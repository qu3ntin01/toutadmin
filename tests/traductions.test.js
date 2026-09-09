const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const i18n = require('../src/i18n');

const ROOT = path.join(__dirname, '..');
const fr = require('../src/locales/fr.js');
const OTHERS = i18n.LOCALES.map((l) => l.code).filter((c) => c !== 'fr');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const placeholders = (value) => (String(value).match(/\{[a-zA-Z0-9_]+\}/g) || []).sort().join(',');

function viewFiles() {
  const files = [];
  for (const dir of ['views', 'views/partials']) {
    for (const name of fs.readdirSync(path.join(ROOT, dir))) {
      if (name.endsWith('.ejs')) files.push(path.join(dir, name));
    }
  }
  return files;
}

// ---------------------------------------------------------------- dictionnaires

test('chaque langue couvre exactement le jeu de clés français', () => {
  for (const code of OTHERS) {
    const dict = require(`../src/locales/${code}.js`);
    const missing = Object.keys(fr).filter((k) => !(k in dict));
    const extra = Object.keys(dict).filter((k) => !(k in fr));
    assert.deepEqual(missing, [], `${code} : clés manquantes`);
    assert.deepEqual(extra, [], `${code} : clés en trop`);
  }
});

test('aucune traduction vide', () => {
  for (const code of i18n.LOCALES.map((l) => l.code)) {
    const dict = require(`../src/locales/${code}.js`);
    for (const [key, value] of Object.entries(dict)) {
      assert.equal(typeof value, 'string', `${code}/${key} n'est pas une chaîne`);
      assert.notEqual(value.trim(), '', `${code}/${key} est vide`);
    }
  }
});

test('les paramètres {nom} sont identiques dans toutes les langues', () => {
  // Un paramètre perdu à la traduction affiche une phrase amputée, sans erreur.
  for (const code of OTHERS) {
    const dict = require(`../src/locales/${code}.js`);
    for (const key of Object.keys(fr)) {
      assert.equal(placeholders(dict[key]), placeholders(fr[key]), `${code}/${key} : paramètres différents du français`);
    }
  }
});

test('les langues non latines sont écrites dans leur écriture', () => {
  // Une traduction oubliée se repère à ce qu'elle reste en caractères latins.
  const SCRIPTS = { ru: /[Ѐ-ӿ]/, ar: /[؀-ۿ]/, hi: /[ऀ-ॿ]/, zh: /[一-鿿]/, ja: /[぀-ヿ一-鿿]/, ko: /[가-힯]/ };
  // La marque et les sigles techniques restent en latin, partout.
  const EXEMPT = new Set(['app.name', 'tools.reference', 'nav.vat']);
  for (const [code, script] of Object.entries(SCRIPTS)) {
    const dict = require(`../src/locales/${code}.js`);
    for (const [key, value] of Object.entries(dict)) {
      if (EXEMPT.has(key)) continue;
      // Le nom d'un paramètre est du code, pas du texte affiché : « {used} / {total} »
      // ne contient rien à traduire, et le relever ferait crier le garde-fou à tort.
      if (!/[A-Za-zÀ-ÿ]/.test(value.replace(/\{[a-zA-Z0-9_]+\}/g, ''))) continue;
      assert.ok(script.test(value), `${code}/${key} n'utilise pas son écriture : ${value}`);
    }
  }
});

test('toute clé appelée dans le code existe au dictionnaire', () => {
  const missing = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        if (entry.name !== 'node_modules' && entry.name !== 'locales') walk(full);
        continue;
      }
      if (!/\.(ejs|js)$/.test(entry.name)) continue;
      const src = fs.readFileSync(full, 'utf8');
      for (const match of src.matchAll(/\bt\(\s*'([a-z][a-zA-Z0-9.]*[a-zA-Z0-9])'\s*[,)]/g)) {
        if (!(match[1] in fr)) missing.push(`${path.relative(ROOT, full)} → ${match[1]}`);
      }
    }
  };
  walk(path.join(ROOT, 'views'));
  walk(path.join(ROOT, 'src'));
  assert.deepEqual(missing, []);
});

// ---------------------------------------------------------------- statuts

test('chaque statut de la table pointe vers une clé qui existe', () => {
  for (const [value, key] of Object.entries(i18n.STATUS_KEYS)) {
    assert.ok(key in fr, `${value} → ${key} absent du dictionnaire`);
  }
});

test('tout statut stocké par le schéma a une traduction', () => {
  // Le garde-fou : un statut ajouté à une contrainte CHECK sans entrée dans la
  // table resterait affiché en français pour un utilisateur allemand ou japonais.
  const stored = new Set();
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, entry.name);
      if (entry.isDirectory()) { walk(full); continue; }
      if (!entry.name.endsWith('.js')) continue;
      const src = fs.readFileSync(full, 'utf8');
      // Une apostrophe dans une valeur se double en SQL (« Liste d''attente ») et
      // s'échappe en JavaScript ; une constante peut aussi être écrite entre
      // guillemets pour l'éviter. Sans ces trois cas, le relevé coupe les mots
      // en deux et remonte des statuts qui n'existent pas.
      const values = (list) => {
        for (const v of list.matchAll(/'((?:[^'\\]|\\.|'')+)'|"((?:[^"\\]|\\.)+)"/g)) {
          stored.add((v[1] ?? v[2]).replace(/''/g, "'").replace(/\\(.)/g, '$1'));
        }
      };
      for (const m of src.matchAll(/CHECK\(\s*status\s+IN\s*\(([^)]*)\)/g)) values(m[1]);
      for (const m of src.matchAll(/[A-Z_]*STATUSES\s*=\s*\[([^\]]*)\]/g)) values(m[1]);
    }
  };
  walk(path.join(ROOT, 'src'));
  assert.ok(stored.size > 50, 'le relevé des statuts a échoué');
  const untranslated = [...stored].filter((v) => !(v in i18n.STATUS_KEYS));
  assert.deepEqual(untranslated, []);
});

test('un statut inconnu ressort tel quel plutôt que vide', () => {
  assert.equal(i18n.statusLabel('en', 'Statut maison'), 'Statut maison');
  assert.equal(i18n.statusLabel('en', ''), '');
  assert.equal(i18n.statusLabel('en', null), '');
});

test('un même statut se traduit dans chaque langue', () => {
  assert.equal(i18n.statusLabel('fr', 'Approuvée'), 'Approuvée');
  assert.equal(i18n.statusLabel('en', 'Approuvée'), 'Approved');
  assert.equal(i18n.statusLabel('de', 'Brouillon'), 'Entwurf');
  assert.equal(i18n.statusLabel('ja', 'Signé'), '署名済み');
  // Les deux genres du français partagent une seule traduction.
  assert.equal(i18n.statusLabel('en', 'Annulé'), i18n.statusLabel('en', 'Annulée'));
});

test('aucune vue n\'affiche un statut brut', () => {
  const offenders = [];
  for (const file of viewFiles()) {
    const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
    for (const m of src.matchAll(/<%=\s*[a-zA-Z_][a-zA-Z_0-9.]*\.status\s*%>/g)) {
      offenders.push(`${file} : ${m[0]}`);
    }
  }
  assert.deepEqual(offenders, [], 'ces affichages doivent passer par st()');
});

test('aucune vue ne masque le helper de traduction', () => {
  // Une boucle « tools.forEach((t) => … ) » masque t() : l'appel devient une
  // tentative d'invoquer l'objet de la boucle, et la page casse à l'exécution.
  const offenders = [];
  for (const file of viewFiles()) {
    const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
    for (const m of src.matchAll(/\(\s*t\s*\)\s*=>|\bfor\s*\(\s*(?:const|let)\s+t\s+of\b|\b(?:const|let)\s+t\s*=[^=]/g)) {
      offenders.push(`${file} : ${m[0].trim()}`);
    }
  }
  assert.deepEqual(offenders, [], 'renommer la variable de boucle');
});

test('toutes les vues compilent', () => {
  // Un remplacement maladroit peut imbriquer un <%= dans un autre : le moteur
  // refuse alors le gabarit, et l'écran devient une erreur 500.
  const ejs = require('ejs');
  const broken = [];
  for (const file of viewFiles()) {
    const full = path.join(ROOT, file);
    try {
      ejs.compile(fs.readFileSync(full, 'utf8'), { filename: full });
    } catch (error) {
      broken.push(`${file} : ${error.message.split('\n')[0]}`);
    }
  }
  assert.deepEqual(broken, []);
});

test('les dates suivent la langue de l\'utilisateur', () => {
  // Une date formatée en « fr-FR » en dur reste française quelle que soit la
  // langue choisie — le mois s'affiche en toutes lettres dans la mauvaise.
  const offenders = [];
  for (const file of viewFiles()) {
    const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
    for (const m of src.matchAll(/toLocale\w*\(\s*['"][a-z]{2}-[A-Z]{2}['"]/g)) {
      offenders.push(`${file} : ${m[0]}`);
    }
  }
  assert.deepEqual(offenders, [], 'passer « locale » plutôt qu\'une langue figée');
});

// ---------------------------------------------------------------- chrome partagé

test('le chrome présent sur chaque page est entièrement traduit', () => {
  // En-tête, sélecteur de thème et barres latérales suivent l'utilisateur partout :
  // un libellé oublié ici se voit sur toutes les pages du produit.
  const CHROME = [
    'views/partials/app-header.ejs',
    'views/partials/theme-toggle.ejs',
    'views/partials/sidebar.ejs',
    'views/partials/admin-sidebar.ejs',
    'views/partials/rh-sidebar.ejs',
    'views/partials/gestion-sidebar.ejs',
    'views/partials/member-sidebar.ejs',
  ];
  const offenders = [];
  for (const file of CHROME) {
    const src = fs.readFileSync(path.join(ROOT, file), 'utf8');
    // Un libellé de navigation ou un intitulé de panneau doit venir du dictionnaire.
    for (const m of src.matchAll(/(?:panelLabel|label|headerTitle|headerSubtitle|backLabel):\s*'([^']+)'/g)) {
      offenders.push(`${file} : ${m[1]}`);
    }
    // Idem pour les attributs lus par les lecteurs d'écran et les infobulles.
    for (const m of src.matchAll(/(?:title|aria-label)="([^"<]*[A-Za-zÀ-ÿ][^"<]*)"/g)) {
      offenders.push(`${file} : ${m[1]}`);
    }
  }
  assert.deepEqual(offenders, []);
});

// ---------------------------------------------------------------- mise en page arabe

test('la feuille de style ne fige aucun côté', () => {
  // L'arabe se lit de droite à gauche : une marge « left » ne se retourne pas,
  // et le liseré d'onglet actif se retrouverait du mauvais côté.
  const css = fs.readFileSync(path.join(ROOT, 'public/css/style.css'), 'utf8');
  const offenders = [];
  for (const m of css.matchAll(/(?:margin|padding|border)-(?:left|right)[a-z-]*\s*:/g)) offenders.push(m[0]);
  for (const m of css.matchAll(/text-align:\s*(?:left|right)/g)) offenders.push(m[0]);
  assert.deepEqual(offenders, [], 'utiliser les propriétés logiques (inline-start / inline-end / start / end)');
});

// ---------------------------------------------------------------- de bout en bout

test('une page rendue dans une autre langue ne parle plus français', async () => {
  const admin = new Client(baseUrl);
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);

  const french = await admin.get('/admin');
  const frenchBody = await french.text();
  assert.match(frenchBody, /Déconnexion/);
  assert.match(frenchBody, /Parapheur/);

  await admin.post('/langue', { locale: 'de' });
  const german = await admin.get('/admin');
  const body = await german.text();

  assert.match(body, /lang="de"/);
  assert.match(body, /Abmelden/);
  assert.match(body, /Unterschriftenmappe/);
  assert.match(body, /Datenimport/);
  assert.doesNotMatch(body, /Déconnexion/);
  assert.doesNotMatch(body, /Parapheur/);
});

test('l\'arabe bascule la page en lecture de droite à gauche', async () => {
  const admin = new Client(baseUrl);
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  await admin.post('/langue', { locale: 'ar' });

  const res = await admin.get('/admin');
  const body = await res.text();
  assert.match(body, /lang="ar"/);
  assert.match(body, /dir="rtl"/);
  assert.match(body, /ملف التوقيع/);
});
