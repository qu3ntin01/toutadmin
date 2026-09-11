#!/usr/bin/env node
/* =========================================================================
   Construction du site de documentation.

       node doc/build.js

   Assemble doc/pages/*.html dans doc/layout.html et écrit doc/site/, qui est
   le dossier à déposer sur l'hébergement. Aucune dépendance : le site doit
   pouvoir être reconstruit sur n'importe quelle machine où Node tourne, sans
   installation préalable.

   Deux jeux de données sont extraits du logiciel lui-même plutôt que recopiés
   à la main, pour que la documentation ne puisse pas mentir sur le produit :

     • le lexique — les 16 dictionnaires de src/locales/ ;
     • le décompte des clés, des statuts et des langues, injecté dans les pages
       via les jetons {{i18n.*}}.
   ========================================================================= */

const fs = require('fs');
const path = require('path');

const DOC = __dirname;
const OUT = path.join(DOC, 'site');

/* --------------------------------------------------- où trouver le produit

   La documentation vit dans sa propre branche, sans le code à côté. Elle doit
   donc pouvoir se reconstruire seule — sinon « indépendante » ne voudrait rien
   dire — tout en restant capable de relire le logiciel quand il est là, parce
   que c'est ce qui empêche ses chiffres de mentir.

   Deux modes, et le script dit toujours lequel il a pris :

     • avec le code — passez TOUTADMIN_SOURCE=/chemin/vers/le/dépôt, ou placez
       ce dossier dans le dépôt lui-même. Les chiffres et les dictionnaires
       sont relus, puis déposés dans produit.json et site/data/lexique.json ;
     • sans le code — c'est l'instantané produit.json, écrit par la dernière
       construction complète, qui sert. Rien n'est inventé : le site rend
       alors les chiffres de cette construction-là, et le dit.
   ========================================================================= */

const SOURCE = path.resolve(process.env.TOUTADMIN_SOURCE || path.join(DOC, '..'));
const HAS_SOURCE = fs.existsSync(path.join(SOURCE, 'src', 'i18n.js'));
const SNAPSHOT = path.join(DOC, 'produit.json');
const CACHED_LEXICON = path.join(OUT, 'data', 'lexique.json');

function readSnapshot() {
  if (!fs.existsSync(SNAPSHOT)) {
    throw new Error(
      `Ni code ni instantané : ${SNAPSHOT} est absent, et aucun logiciel n'a été trouvé `
      + `dans ${SOURCE}. Passez TOUTADMIN_SOURCE=/chemin/vers/la/branche/toutadmin.`
    );
  }
  return JSON.parse(fs.readFileSync(SNAPSHOT, 'utf8'));
}

/* ------------------------------------------------------------- ossature */

// L'ordre de ce tableau est celui de la barre latérale et des liens
// « précédent / suivant » en bas de chaque page.
const NAV = [
  { group: 'Découvrir', pages: ['index', 'espaces', 'fonctionnalites'] },
  { group: 'Mettre en place', pages: ['installation', 'configuration', 'modules'] },
  { group: 'Approfondir', pages: ['securite', 'traductions', 'lexique', 'comptable', 'api', 'sauvegardes'] },
  { group: 'Sous le capot', pages: ['architecture', 'tests', 'journal'] },
];

const ORDER = NAV.flatMap((section) => section.pages);

/* ------------------------------------------------- données du logiciel */

function readLocales() {
  // Sans le code, le lexique déjà construit fait foi : il porte les seize
  // dictionnaires en entier. Il est relu avant que site/ ne soit effacé.
  if (!HAS_SOURCE) {
    if (!fs.existsSync(CACHED_LEXICON)) {
      throw new Error(`Sans le code, ${CACHED_LEXICON} est nécessaire : c'est lui qui porte les dictionnaires.`);
    }
    const cached = JSON.parse(fs.readFileSync(CACHED_LEXICON, 'utf8'));
    return { ...cached, statusKeys: null };
  }

  const i18n = require(path.join(SOURCE, 'src', 'i18n.js'));
  const entries = {};
  const dictionaries = {};

  for (const locale of i18n.LOCALES) {
    dictionaries[locale.code] = require(path.join(SOURCE, 'src', 'locales', `${locale.code}.js`));
  }

  const keys = Object.keys(dictionaries.fr);
  for (const key of keys) {
    entries[key] = {};
    for (const locale of i18n.LOCALES) entries[key][locale.code] = dictionaries[locale.code][key];
  }

  // Les familles de clés (le préfixe avant le premier point) donnent un filtre
  // utile : « status », « nav », « common »…
  const tally = new Map();
  for (const key of keys) {
    const name = key.split('.')[0];
    tally.set(name, (tally.get(name) || 0) + 1);
  }
  const groups = [...tally.entries()]
    .map(([name, count]) => ({ name, count }))
    .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name));

  return {
    locales: i18n.LOCALES.map((l) => ({ code: l.code, label: l.label, flag: l.flag, dir: l.dir })),
    keys,
    entries,
    groups,
    statusKeys: i18n.STATUS_KEYS,
  };
}

function countTests() {
  // Le chiffre est relevé dans les fichiers plutôt que recopié à la main.
  // Le lanceur compte les suites de premier niveau ET leurs sous-tests :
  // 101 + 624 = 725, ce que « node --test » affiche.
  const dir = path.join(SOURCE, 'tests');
  const files = fs.readdirSync(dir).filter((f) => f.endsWith('.test.js'));
  let suites = 0;
  let subtests = 0;
  for (const file of files) {
    const src = fs.readFileSync(path.join(dir, file), 'utf8');
    suites += (src.match(/^\s*(?:await\s+)?test\(/gm) || []).length;
    subtests += (src.match(/^\s*(?:await\s+)?t\.test\(/gm) || []).length;
  }
  return { files: files.length, suites, subtests, cases: suites + subtests };
}

/* ------------------------------------------------------------ rendu */

const escapeHtml = (value) => String(value).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

const slug = (text) =>
  text
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/<[^>]+>/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '');

// Chaque fragment commence par un en-tête « clé: valeur » séparé du corps par
// une ligne vide. On évite ainsi une dépendance pour lire un peu de métadonnée.
function readPage(name) {
  const raw = fs.readFileSync(path.join(DOC, 'pages', `${name}.html`), 'utf8');
  const split = raw.indexOf('\n\n');
  const header = raw.slice(0, split);
  const body = raw.slice(split + 2);
  const meta = {};
  for (const line of header.split('\n')) {
    const at = line.indexOf(':');
    if (at > 0) meta[line.slice(0, at).trim()] = line.slice(at + 1).trim();
  }
  return { name, meta, body };
}

// Les titres reçoivent un identifiant stable et une ancre cliquable ; le
// sommaire de droite est construit à partir des mêmes titres.
function anchorHeadings(html) {
  const headings = [];
  // Un titre situé dans une carte cliquable est ignoré : l'ancre y créerait un
  // <a> imbriqué dans un <a>, que le navigateur défait en cassant la carte.
  const inCard = [];
  const shielded = html.replace(/<a class="card"[\s\S]*?<\/a>/g, (block) => {
    inCard.push(block);
    return `\u0000CARD${inCard.length - 1}\u0000`;
  });
  const out = restoreCards(shielded.replace(/<h([23])>([\s\S]*?)<\/h\1>/g, (whole, level, inner) => {
    const id = slug(inner);
    headings.push({ level: Number(level), id, text: inner.replace(/<[^>]+>/g, '') });
    return `<h${level} id="${id}">${inner}<a class="anchor" href="#${id}" aria-label="Lien vers cette section">#</a></h${level}>`;
  }), inCard);
  return { html: out, headings };
}

const restoreCards = (html, blocks) =>
  html.replace(/\u0000CARD(\d+)\u0000/g, (whole, index) => blocks[Number(index)]);

function renderSidebar(current) {
  const parts = [];
  for (const section of NAV) {
    parts.push(`    <h2>${escapeHtml(section.group)}</h2>`);
    for (const name of section.pages) {
      const page = PAGES[name];
      const active = name === current ? ' class="is-active" aria-current="page"' : '';
      parts.push(`    <a href="${name}.html"${active}>${escapeHtml(page.meta.nav || page.meta.title)}</a>`);
    }
  }
  return parts.join('\n');
}

function renderToc(headings) {
  if (headings.length < 2) return '';
  const links = headings
    .map((h) => `    <a class="lvl-${h.level}" href="#${h.id}">${escapeHtml(h.text)}</a>`)
    .join('\n');
  return `    <strong>Sur cette page</strong>\n${links}`;
}

function renderPager(name) {
  const at = ORDER.indexOf(name);
  const previous = at > 0 ? PAGES[ORDER[at - 1]] : null;
  const next = at < ORDER.length - 1 ? PAGES[ORDER[at + 1]] : null;
  const left = previous
    ? `<a href="${previous.name}.html">← ${escapeHtml(previous.meta.title)}</a>`
    : '<span></span>';
  const right = next ? `<a href="${next.name}.html">${escapeHtml(next.meta.title)} →</a>` : '<span></span>';
  return `      <nav class="pager" aria-label="Pages voisines">${left}${right}</nav>`;
}

/* ------------------------------------------------------------ exécution */

const locales = readLocales();

// Jetons remplaçables dans les pages : ces chiffres viennent du dépôt, pas
// d'une relecture humaine, donc ils restent justes après chaque livraison.
// Sans le code sous la main, ce sont ceux de la dernière construction faite
// avec lui — repris tels quels, jamais recalculés de mémoire.
const snapshot = HAS_SOURCE ? null : readSnapshot();
const tests = HAS_SOURCE ? countTests() : snapshot.tests;

const FACTS = HAS_SOURCE
  ? {
    'i18n.keys': String(locales.keys.length),
    'i18n.locales': String(locales.locales.length),
    'i18n.strings': String(locales.keys.length * locales.locales.length),
    'i18n.statuses': String(Object.keys(locales.statusKeys).length),
    'i18n.statusKeys': String(new Set(Object.values(locales.statusKeys)).size),
    'tests.cases': String(tests.cases),
    'tests.suites': String(tests.suites),
    'tests.files': String(tests.files),
    'src.modules': String(fs.readdirSync(path.join(SOURCE, 'src')).filter((f) => f.endsWith('.js')).length),
    'views.count': String(
      ['views', 'views/partials']
        .flatMap((d) => fs.readdirSync(path.join(SOURCE, d)))
        .filter((f) => f.endsWith('.ejs')).length
    ),
    'date': new Date().toISOString().slice(0, 10),
  }
  : { ...snapshot.facts, 'date': new Date().toISOString().slice(0, 10) };

const PAGES = {};
for (const name of ORDER) PAGES[name] = readPage(name);

fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(path.join(OUT, 'assets'), { recursive: true });
fs.mkdirSync(path.join(OUT, 'data'), { recursive: true });

const layout = fs.readFileSync(path.join(DOC, 'layout.html'), 'utf8');
const searchIndex = [];

for (const name of ORDER) {
  const page = PAGES[name];
  let body = page.body;
  for (const [token, value] of Object.entries(FACTS)) {
    body = body.split(`{{${token}}}`).join(value);
  }

  const { html, headings } = anchorHeadings(body);

  const rendered = layout
    .split('{{BASE}}').join('')
    .split('{{TITLE}}').join(escapeHtml(page.meta.title))
    .split('{{DESCRIPTION}}').join(escapeHtml(page.meta.description || ''))
    .split('{{SIDEBAR}}').join(renderSidebar(name))
    .split('{{TOC}}').join(renderToc(headings))
    .split('{{PAGER}}').join(renderPager(name))
    .split('{{SCRIPTS}}').join(page.meta.scripts
      ? page.meta.scripts.split(/\s+/).map((s) => `<script src="assets/${s}"></script>`).join('\n')
      : '')
    .split('{{WIDE}}').join(page.meta.wide === 'oui' ? ' is-wide' : '')
    .split('{{CONTENT}}').join(html)
    .split('{{DATE}}').join(FACTS.date);

  fs.writeFileSync(path.join(OUT, `${name}.html`), rendered);

  // L'index de recherche porte le texte visible, sans les balises, replié en
  // minuscules sans accents pour que « securite » trouve « sécurité ».
  const plain = html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&[a-z]+;/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
  const fold = (value) => value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

  searchIndex.push({
    u: `${name}.html`,
    t: page.meta.title,
    p: page.meta.description || '',
    f: fold(page.meta.title + ' ' + (page.meta.description || '')),
    b: fold(plain).slice(0, 20000),
  });
  for (const heading of headings) {
    searchIndex.push({
      u: `${name}.html#${heading.id}`,
      t: heading.text,
      p: page.meta.title,
      f: fold(heading.text),
      b: fold(heading.text + ' ' + page.meta.title),
    });
  }
}

for (const asset of fs.readdirSync(path.join(DOC, 'assets'))) {
  fs.copyFileSync(path.join(DOC, 'assets', asset), path.join(OUT, 'assets', asset));
}

fs.writeFileSync(path.join(OUT, 'data', 'recherche.json'), JSON.stringify(searchIndex));
fs.writeFileSync(path.join(OUT, 'data', 'lexique.json'), JSON.stringify({
  locales: locales.locales,
  keys: locales.keys,
  entries: locales.entries,
  groups: locales.groups,
}));

// Un hébergement statique sert index.html à la racine ; ce fichier évite qu'un
// serveur cherche à indexer les données brutes.
fs.writeFileSync(path.join(OUT, 'robots.txt'), 'User-agent: *\nDisallow: /data/\n');

// L'instantané n'est écrit que lorsqu'il a été relu du code : une construction
// hors dépôt ne doit pas pouvoir figer des chiffres qu'elle n'a pas vérifiés.
if (HAS_SOURCE) {
  const { date, ...facts } = FACTS;
  fs.writeFileSync(SNAPSHOT, `${JSON.stringify({ releve: date, source: 'toutadmin', facts, tests }, null, 2)}\n`);
}

const size = (p) => (fs.statSync(p).size / 1024).toFixed(0) + ' Ko';
console.log(`${ORDER.length} pages écrites dans ${path.relative(process.cwd(), OUT) || OUT}/`);
console.log(`  lexique   : ${locales.keys.length} clés × ${locales.locales.length} langues (${size(path.join(OUT, 'data', 'lexique.json'))})`);
console.log(`  recherche : ${searchIndex.length} entrées (${size(path.join(OUT, 'data', 'recherche.json'))})`);
console.log(`  tests     : ${tests.cases} cas (${tests.suites} suites + ${tests.subtests} sous-tests) dans ${tests.files} fichiers`);
console.log(HAS_SOURCE
  ? `  source    : le logiciel, relu dans ${SOURCE} — instantané mis à jour`
  : `  source    : l'instantané produit.json du ${snapshot.releve} (le logiciel n'est pas là)`);
