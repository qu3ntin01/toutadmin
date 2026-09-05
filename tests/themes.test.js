const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const themes = require('../src/themes');
const settings = require('../src/settings');

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

// ---------- Lecture de la feuille de style ----------

const css = fs.readFileSync(path.join(__dirname, '..', 'public', 'css', 'style.css'), 'utf8');

function tokensOf(selector) {
  const at = css.indexOf(`${selector} {`);
  assert.notEqual(at, -1, `bloc introuvable : ${selector}`);
  const body = css.slice(at, css.indexOf('}', at));
  const tokens = {};
  for (const line of body.split('\n')) {
    const match = line.match(/--([\w-]+):\s*([^;]+);/);
    if (match) tokens[match[1]] = match[2].trim();
  }
  return tokens;
}

function toRgb(value) {
  const hex = value.match(/^#([0-9a-f]{6})$/i);
  if (hex) {
    const n = parseInt(hex[1], 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
  }
  const rgba = value.match(/rgba?\(([^)]+)\)/);
  if (rgba) return rgba[1].split(',').slice(0, 3).map((v) => Number(v.trim()));
  throw new Error(`couleur illisible : ${value}`);
}

function flatten(value, backdrop) {
  const alpha = (value.match(/rgba\([^)]*?,\s*([\d.]+)\s*\)/) || [])[1];
  const front = toRgb(value);
  if (alpha === undefined) return front;
  const back = toRgb(backdrop);
  return front.map((c, i) => Math.round(c * Number(alpha) + back[i] * (1 - Number(alpha))));
}

const channel = (c) => { const v = c / 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
const luminance = ([r, g, b]) => 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);

function contrast(front, back) {
  const a = luminance(flatten(front, back));
  const b = luminance(toRgb(back));
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

// Les six combinaisons réellement servies : trois palettes, deux modes.
const COMBOS = {
  'institutionnel clair': tokensOf(':root,\n[data-palette-preview="institutionnel"]'),
  'moderne clair': tokensOf(':root[data-palette="moderne"],\n[data-palette-preview="moderne"]'),
  'vif clair': tokensOf(':root[data-palette="vif"],\n[data-palette-preview="vif"]'),
};
COMBOS['institutionnel sombre'] = { ...COMBOS['institutionnel clair'], ...tokensOf(':root[data-theme="dark"]') };
COMBOS['moderne sombre'] = { ...COMBOS['moderne clair'], ...tokensOf(':root[data-palette="moderne"][data-theme="dark"]') };
COMBOS['vif sombre'] = { ...COMBOS['vif clair'], ...tokensOf(':root[data-palette="vif"][data-theme="dark"]') };

test('Palettes : jetons et lisibilité', async (t) => {
  await t.test('chaque palette définit tous les jetons de couleur du défaut', () => {
    // Ces jetons-là n'ont pas de couleur et sont volontairement partagés : ils
    // restent posés une seule fois sur :root, qui s'applique toujours.
    const SHARED = ['sidebar-width', 'font-sans', 'font-mono'];
    const reference = Object.keys(COMBOS['institutionnel clair']).filter((token) => !SHARED.includes(token));

    for (const name of ['moderne clair', 'vif clair']) {
      const missing = reference.filter((token) => !(token in COMBOS[name]));
      assert.deepEqual(missing, [], `${name} : jetons manquants`);
    }

    // Les blocs sombres doivent redéfinir tout ce que redéfinit le sombre par
    // défaut, sinon celui-ci reprend la main à spécificité égale.
    const darkReference = Object.keys(tokensOf(':root[data-theme="dark"]'));
    for (const selector of [
      ':root[data-palette="moderne"][data-theme="dark"]',
      ':root[data-palette="vif"][data-theme="dark"]',
    ]) {
      const missing = darkReference.filter((token) => !(token in tokensOf(selector)));
      assert.deepEqual(missing, [], `${selector} : jetons sombres manquants`);
    }
  });

  await t.test('le texte reste lisible dans les six combinaisons', () => {
    // Paires telles qu'elles apparaissent à l'écran, avec le seuil applicable.
    const pairs = [
      ['texte sur fond', 'text', 'bg', 4.5],
      ['texte sur carte', 'text', 'surface', 4.5],
      ['texte secondaire', 'text-secondary', 'surface', 4.5],
      ['texte tertiaire', 'text-tertiary', 'surface', 3],
      ['lien accentué', 'accent', 'surface', 4.5],
      ['libellé de bouton', 'accent-contrast', 'accent', 4.5],
      ['encre de navigation', 'brand-ink', 'brand-deep', 4.5],
      ['navigation estompée', 'text-on-brand', 'brand-deep', 4.5],
      ['succès', 'success', 'success-bg', 4.5],
      ['alerte', 'warning', 'warning-bg', 4.5],
      ['danger', 'danger', 'danger-bg', 4.5],
    ];

    for (const [name, tokens] of Object.entries(COMBOS)) {
      for (const [label, fg, bg, min] of pairs) {
        let back = tokens[bg];
        // Un fond d'état translucide se lit par-dessus la carte.
        if (/rgba/.test(back)) {
          const flat = flatten(back, tokens.surface);
          back = `#${flat.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
        }
        const ratio = contrast(tokens[fg], back);
        assert.ok(ratio >= min, `${name} — ${label} : ${ratio.toFixed(2)} < ${min}`);
      }
    }
  });

  await t.test("l'aperçu emprunte les jetons de la palette qu'il montre", () => {
    // Aucune couleur en dur dans les règles d'aperçu : elles n'auraient aucune
    // raison de suivre la palette si on la modifiait.
    const at = css.indexOf('/* ---------- Choix de la palette');
    const block = css.slice(at);
    const hardcoded = block.match(/:\s*#[0-9a-f]{3,8}\b/gi) || [];
    assert.deepEqual(hardcoded, [], 'couleurs en dur dans les règles d\'aperçu');
  });
});

test("Choix de la palette par l'administration", async (t) => {
  const admin = await loginAsAdmin();

  await t.test("l'instance sert la palette institutionnelle par défaut", async () => {
    assert.equal(themes.current(), 'institutionnel');
    const { body } = await admin.html('/admin');
    assert.match(body, /<html[^>]+data-palette="institutionnel"/);
  });

  await t.test("appliquer une palette la sert partout, connexion comprise", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/apparence', { palette: 'vif' });
    assert.match((await admin.flash('/admin')).message, /Magenta et violet/);
    assert.equal(settings.get('theme_palette'), 'vif');

    for (const page of ['/admin', '/securite', '/rh']) {
      const { body } = await admin.html(page);
      assert.match(body, /<html[^>]+data-palette="vif"/, page);
    }

    // Y compris avant toute connexion : la marque vaut aussi pour l'écran d'accueil.
    const { body } = await newClient().html('/connexion');
    assert.match(body, /<html[^>]+data-palette="vif"/);
  });

  await t.test('refuse une palette inventée et garde la précédente', async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/apparence', { palette: 'arc-en-ciel' });
    assert.match((await admin.flash('/admin')).message, /Palette inconnue/);
    assert.equal(themes.current(), 'vif');
  });

  await t.test('une valeur aberrante en base ne casse pas le rendu', async () => {
    settings.set('theme_palette', '"><script>alert(1)</script>');
    assert.equal(themes.current(), 'institutionnel');

    const { body } = await admin.html('/admin');
    assert.match(body, /<html[^>]+data-palette="institutionnel"/);
    assert.equal(/alert\(1\)/.test(body), false);
    settings.set('theme_palette', 'moderne');
  });

  await t.test("le choix est réservé à l'administration", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Sans', last_name: 'Droits', grade: 'Employé', contract_type: 'CDI', email: 'palette@test.local',
    });
    const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const client = newClient();
    await client.firstAccess('palette@test.local', password);

    await client.refreshToken('/mon-espace');
    assert.equal((await client.post('/admin/apparence', { palette: 'vif' })).status, 403);
    assert.equal(themes.current(), 'moderne');

    // Mais le membre voit bien la palette de l'instance.
    const { body } = await client.html('/mon-espace');
    assert.match(body, /<html[^>]+data-palette="moderne"/);
  });

  await t.test('le mode clair ou sombre reste un réglage personnel', async () => {
    // Aucun jeton de mode n'est stocké côté serveur : seule la palette l'est.
    assert.equal(db.prepare("SELECT COUNT(*) AS n FROM settings WHERE key LIKE '%theme%'").get().n, 1);
    const { body } = await admin.html('/admin');
    assert.match(body, /localStorage\.getItem\('pm-theme'\)/);
  });
});
