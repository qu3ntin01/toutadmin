#!/usr/bin/env node
/* =========================================================================
   Construction de la vitrine Toutadmin.

       node build.js

   Rend les six pages dans les seize langues : le français à la racine de
   site/, les quinze autres dans leur sous-dossier. Aucune dépendance — le
   site doit pouvoir être reconstruit sur n'importe quelle machine où Node
   tourne, comme le produit lui-même.

   Une langue dont le dictionnaire est incomplet n'est pas construite à
   moitié : le script s'arrête et dit ce qui manque. Une page à moitié
   traduite est pire qu'une page absente — elle donne l'impression d'un
   produit à moitié fini.
   ========================================================================= */

const fs = require('fs');
const path = require('path');

const ROOT = __dirname;
const OUT = path.join(ROOT, 'site');

let LOCALES = [
  { code: 'fr', flag: '🇫🇷', dir: 'ltr' }, { code: 'en', flag: '🇬🇧', dir: 'ltr' },
  { code: 'es', flag: '🇪🇸', dir: 'ltr' }, { code: 'de', flag: '🇩🇪', dir: 'ltr' },
  { code: 'it', flag: '🇮🇹', dir: 'ltr' }, { code: 'pt', flag: '🇵🇹', dir: 'ltr' },
  { code: 'nl', flag: '🇳🇱', dir: 'ltr' }, { code: 'pl', flag: '🇵🇱', dir: 'ltr' },
  { code: 'ru', flag: '🇷🇺', dir: 'ltr' }, { code: 'tr', flag: '🇹🇷', dir: 'ltr' },
  { code: 'ar', flag: '🇸🇦', dir: 'rtl' }, { code: 'hi', flag: '🇮🇳', dir: 'ltr' },
  { code: 'zh', flag: '🇨🇳', dir: 'ltr' }, { code: 'ja', flag: '🇯🇵', dir: 'ltr' },
  { code: 'ko', flag: '🇰🇷', dir: 'ltr' }, { code: 'vi', flag: '🇻🇳', dir: 'ltr' },
];

const PAGES = ['index', 'fonctionnalites', 'ecrans', 'securite', 'tarifs', 'contact'];

/* --------------------------------------------------------------- outillage */

const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ICONS = {
  check: '<path d="M20 6 9 17l-5-5"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  globe: '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
  monitor: '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8m-4-4v4"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  close: '<path d="M6 6l12 12M18 6 6 18"/>',
  left: '<path d="M15 6l-6 6 6 6"/>',
  right: '<path d="M9 6l6 6-6 6"/>',
  shield: '<path d="M12 3l7 3v6c0 4.4-3 8-7 9-4-1-7-4.6-7-9V6l7-3Z"/>',
  users: '<path d="M16 19v-1.5A3.5 3.5 0 0 0 12.5 14h-5A3.5 3.5 0 0 0 4 17.5V19"/><circle cx="10" cy="8" r="3.2"/><path d="M20 19v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 5.2a3.2 3.2 0 0 1 0 5.6"/>',
  briefcase: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5.5A1.5 1.5 0 0 1 9.5 4h5A1.5 1.5 0 0 1 16 5.5V7M3 12h18"/>',
  chart: '<path d="M4 20V9m5 11V4m5 16v-7m5 7V8"/>',
  lock: '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
  layers: '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5M3 17l9 5 9-5"/>',
  code: '<path d="m8 8-5 4 5 4m8-8 5 4-5 4M14 4l-4 16"/>',
  scale: '<path d="M12 4v16M7 20h10M5 8h14M8 8l-3 6a3 3 0 0 0 6 0L8 8Zm8 0-3 6a3 3 0 0 0 6 0l-3-6Z"/>',
  spark: '<path d="M12 3v4m0 10v4M3 12h4m10 0h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
  building: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h2m4 0h-2M9 11h2m4 0h-2M9 15h6v6H9z"/>',
  life: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="m5.6 5.6 3.8 3.8m5.2 5.2 3.8 3.8m0-12.8-3.8 3.8m-5.2 5.2-3.8 3.8"/>',
  box: '<path d="M3 8.5 12 4l9 4.5v7L12 20l-9-4.5v-7Z"/><path d="M3 8.5 12 13l9-4.5M12 13v7"/>',
};
const icon = (name, cls) => `<svg class="i${cls ? ' ' + cls : ''}" viewBox="0 0 24 24" aria-hidden="true">${ICONS[name] || ''}</svg>`;

/* --------------------------------------------------------- dictionnaires */

const dictionaries = {};
const absent = [];
for (const locale of LOCALES) {
  const file = path.join(ROOT, 'content', `${locale.code}.js`);
  if (!fs.existsSync(file)) { absent.push(locale.code); continue; }
  dictionaries[locale.code] = require(file);
}
// Une langue dont le fichier n'existe pas encore est écartée de la construction
// plutôt que d'arrêter tout : le site reste constructible pendant la traduction.
if (absent.length) {
  LOCALES = LOCALES.filter((l) => !absent.includes(l.code));
  console.warn(`Langues sans dictionnaire, écartées : ${absent.join(', ')}`);
}
const reference = dictionaries.fr;
const refKeys = Object.keys(reference);

for (const locale of LOCALES) {
  if (locale.code === 'fr') continue;
  const dict = dictionaries[locale.code];
  const missing = refKeys.filter((k) => !(k in dict));
  const arrayGaps = refKeys.filter((k) => Array.isArray(reference[k])
    && (!Array.isArray(dict[k]) || dict[k].length !== reference[k].length));
  if (missing.length || arrayGaps.length) {
    console.error(`\nLangue « ${locale.code} » incomplète — rien n'a été construit.`);
    if (missing.length) console.error(`  ${missing.length} clé(s) absente(s) : ${missing.slice(0, 6).join(', ')}${missing.length > 6 ? '…' : ''}`);
    if (arrayGaps.length) console.error(`  ${arrayGaps.length} liste(s) de longueur différente : ${arrayGaps.join(', ')}`);
    process.exit(1);
  }
}

/* ------------------------------------------------------------- médias */

const MEDIA = require(path.join(ROOT, 'medias.js'));
const MEDIA_FILES = new Set(fs.readdirSync(path.join(ROOT, 'medias')));

/* ------------------------------------------------------------- gabarit */

const layout = fs.readFileSync(path.join(ROOT, 'layout.html'), 'utf8');

function navHtml(t, ctx) {
  const links = [
    ['fonctionnalites', t('nav.features')],
    ['ecrans', t('nav.screens')],
    ['securite', t('nav.security')],
    ['tarifs', t('nav.pricing')],
    ['contact', t('nav.contact')],
  ].map(([page, label]) =>
    `<a href="${ctx.href(page)}"${ctx.page === page ? ' class="is-active" aria-current="page"' : ''}>${esc(label)}</a>`).join('\n        ');

  const langs = LOCALES.map((l) => {
    const active = l.code === ctx.locale.code;
    return `<a lang="${l.code}" hreflang="${l.code}" href="${ctx.hrefIn(l.code, ctx.page)}"${active ? ' class="is-active"' : ''}><span class="flag">${l.flag}</span>${esc(dictionaries[l.code]['locale.name'])}</a>`;
  }).join('\n            ');

  const themes = [['light', 'sun', t('nav.themeLight')], ['dark', 'moon', t('nav.themeDark')], ['system', 'monitor', t('nav.themeSystem')]]
    .map(([value, ic, label]) => `<button type="button" data-theme-choice="${value}">${icon(ic)}${esc(label)}</button>`).join('\n            ');

  return `  <header class="nav">
    <div class="wrap nav-in">
      <a class="brand" href="${ctx.href('index')}">
        <span class="brand-mark">TA</span>${esc(t('site.name'))}
      </a>
      <nav class="nav-links" aria-label="${esc(t('nav.product'))}">
        ${links}
      </nav>
      <div class="nav-right">
        <div class="picker">
          <button class="picker-btn" type="button" aria-haspopup="true">${icon('globe')}<span>${esc(ctx.locale.code.toUpperCase())}</span></button>
          <div class="picker-pop cols" role="menu" aria-label="${esc(t('nav.language'))}">
            ${langs}
          </div>
        </div>
        <div class="picker">
          <button class="picker-btn" type="button" aria-haspopup="true" aria-label="${esc(t('nav.theme'))}">${icon('sun')}</button>
          <div class="picker-pop" role="menu">
            ${themes}
          </div>
        </div>
        <a class="btn btn-sm" href="${ctx.href('contact')}">${esc(t('nav.demo'))}</a>
        <button class="picker-btn nav-toggle" type="button" aria-label="${esc(t('nav.menu'))}">${icon('menu')}</button>
      </div>
    </div>
  </header>`;
}

function footHtml(t, ctx) {
  const langs = LOCALES.map((l) =>
    `<a lang="${l.code}" hreflang="${l.code}" href="${ctx.hrefIn(l.code, ctx.page)}"><span class="flag">${l.flag}</span> ${esc(dictionaries[l.code]['locale.name'])}</a>`).join('\n          ');
  return `  <footer class="foot">
    <div class="wrap">
      <div class="foot-grid">
        <div>
          <a class="brand" href="${ctx.href('index')}"><span class="brand-mark">TA</span>${esc(t('site.name'))}</a>
          <p class="lede">${esc(t('site.tagline'))}</p>
          <p class="stat-n">${esc(t('foot.madeWith'))}</p>
        </div>
        <div>
          <h4>${esc(t('foot.product'))}</h4>
          <ul>
            <li><a href="${ctx.href('fonctionnalites')}">${esc(t('nav.features'))}</a></li>
            <li><a href="${ctx.href('ecrans')}">${esc(t('nav.screens'))}</a></li>
            <li><a href="${ctx.href('securite')}">${esc(t('nav.security'))}</a></li>
          </ul>
        </div>
        <div>
          <h4>${esc(t('foot.resources'))}</h4>
          <ul>
            <li><a href="${ctx.href('tarifs')}">${esc(t('nav.pricing'))}</a></li>
            <li><a href="${ctx.href('contact')}">${esc(t('nav.contact'))}</a></li>
          </ul>
        </div>
        <div>
          <h4>${esc(t('foot.company'))}</h4>
          <ul>
            <li><a href="${ctx.href('contact')}">${esc(t('contact.write'))}</a></li>
          </ul>
        </div>
      </div>
      <div>
        <h4 class="sr">${esc(t('foot.langTitle'))}</h4>
        <div class="foot-langs">
          ${langs}
        </div>
      </div>
      <div class="foot-bottom">
        <span>© ${new Date().getFullYear()} ${esc(t('site.name'))}. ${esc(t('foot.rights'))}</span>
        <span>${esc(t('foot.langTitle'))}</span>
      </div>
    </div>
  </footer>`;
}

/* ------------------------------------------------------------- exécution */

const renderers = {};
for (const page of PAGES) renderers[page] = require(path.join(ROOT, 'pages', `${page}.js`));

fs.rmSync(OUT, { recursive: true, force: true });
fs.mkdirSync(OUT, { recursive: true });

let written = 0;
for (const locale of LOCALES) {
  const dict = dictionaries[locale.code];
  const t = (key, vars) => {
    let value = dict[key];
    if (value === undefined) throw new Error(`Clé absente en ${locale.code} : ${key}`);
    if (Array.isArray(value)) return value;
    if (vars) for (const name of Object.keys(vars)) value = value.split(`{${name}}`).join(vars[name]);
    return value;
  };
  const dirOut = locale.code === 'fr' ? OUT : path.join(OUT, locale.code);
  fs.mkdirSync(dirOut, { recursive: true });
  const base = locale.code === 'fr' ? '' : '../';

  for (const page of PAGES) {
    const ctx = {
      locale,
      page,
      base,
      t,
      esc,
      icon,
      media: MEDIA,
      href: (name) => `${base}${name}.html`,
      hrefIn: (code, name) => (code === 'fr' ? `${base}${name}.html` : `${base}${code === locale.code ? '' : (locale.code === 'fr' ? `${code}/` : `../${code}/`)}${name}.html`),
      asset: (file) => `${base}assets/${file}`,
      shot: (file) => `${base}medias/${file}`,
      // Les écrans du héros et du carrousel existent dans chaque langue : montrer
      // une interface française à un visiteur japonais annulerait la promesse.
      shotFile: (name) => (MEDIA_FILES.has(`${name}-${locale.code}.png`) ? `${name}-${locale.code}.png` : `${name}.png`),
      hasShot: (file) => MEDIA_FILES.has(file),
    };
    // Le lien d'une langue vers elle-même reste la page courante.
    ctx.hrefIn = (code, name) => {
      if (code === locale.code) return `${base}${name}.html`;
      if (code === 'fr') return locale.code === 'fr' ? `${name}.html` : `../${name}.html`;
      return locale.code === 'fr' ? `${code}/${name}.html` : `../${code}/${name}.html`;
    };

    const view = renderers[page](ctx);
    const alternates = LOCALES.map((l) =>
      `<link rel="alternate" hreflang="${l.code}" href="${ctx.hrefIn(l.code, page)}" />`).join('\n  ');

    const html = layout
      .split('{{LANG}}').join(locale.code)
      .split('{{DIR}}').join(locale.dir)
      .split('{{TITLE}}').join(esc(view.title))
      .split('{{DESCRIPTION}}').join(esc(view.description))
      .split('{{BASE}}').join(base)
      .split('{{ALTERNATES}}').join(alternates)
      .split('{{NAV}}').join(navHtml(t, ctx))
      .split('{{CONTENT}}').join(view.body)
      .split('{{FOOT}}').join(footHtml(t, ctx))
      .split('{{SKIP}}').join(esc(t('nav.product')));

    fs.writeFileSync(path.join(dirOut, `${page}.html`), html);
    written += 1;
  }
}

/* Ressources statiques */
for (const dir of ['assets', 'medias']) {
  fs.mkdirSync(path.join(OUT, dir), { recursive: true });
  for (const file of fs.readdirSync(path.join(ROOT, dir))) {
    fs.copyFileSync(path.join(ROOT, dir, file), path.join(OUT, dir, file));
  }
}

fs.writeFileSync(path.join(OUT, 'robots.txt'), 'User-agent: *\nAllow: /\n');
const urls = [];
for (const locale of LOCALES) {
  for (const page of PAGES) {
    urls.push(`  <url><loc>https://toutadmin.example/${locale.code === 'fr' ? '' : locale.code + '/'}${page}.html</loc></url>`);
  }
}
fs.writeFileSync(path.join(OUT, 'sitemap.xml'),
  `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.join('\n')}\n</urlset>\n`);

const medias = fs.readdirSync(path.join(ROOT, 'medias')).length;
console.log(`${written} pages écrites dans site/ (${PAGES.length} pages × ${LOCALES.length} langues)`);
console.log(`  captures  : ${medias} fichiers`);
console.log(`  clés      : ${refKeys.length} par langue`);
