const settings = require('./settings');
const themes = require('./themes');

/**
 * Le produit s'installe sur l'écran d'accueil d'un téléphone ou d'un poste :
 * un manifeste et une icône, rien de plus.
 *
 * Les deux portent la marque de l'instance — son nom, ses initiales, la couleur
 * de sa palette — et non celle de l'éditeur : c'est l'outil de l'entreprise que
 * le salarié pose sur son écran. Ils sont publics, comme la page de connexion
 * qui les annonce déjà, et ne disent rien de plus qu'elle.
 *
 * Il n'y a pas de travailleur de service : une page de gestion lue hors ligne
 * serait une page périmée qui a l'air à jour, et un formulaire envoyé dans le
 * vide. Hors réseau, le navigateur dit qu'il n'y a pas de réseau, ce qui est
 * vrai.
 */

const xml = (s) => String(s).replace(/[<>&"']/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;', '"': '&quot;', "'": '&apos;' }[c]));

function marque() {
  const nom = settings.get('company_name') || settings.DEFAULTS.company_name;
  const palette = themes.byKey(themes.current());
  return { nom, initiales: settings.brandInitials(nom), couleur: palette.swatches.accent, fond: palette.swatches.bg };
}

function manifeste() {
  const m = marque();
  return {
    name: m.nom,
    short_name: m.nom.length > 12 ? m.initiales : m.nom,
    start_url: '/',
    scope: '/',
    display: 'standalone',
    background_color: m.fond,
    theme_color: m.couleur,
    icons: [{ src: '/icone.svg', sizes: 'any', type: 'image/svg+xml', purpose: 'any' }],
  };
}

function icone() {
  const m = marque();
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
    + `<rect width="512" height="512" rx="112" fill="${xml(m.couleur)}"/>`
    + '<text x="256" y="256" dy=".35em" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif"'
    + ` font-size="220" font-weight="700" fill="#ffffff">${xml(m.initiales)}</text></svg>`;
}

function monter(app) {
  app.get('/manifest.webmanifest', (req, res) => {
    res.set('Cache-Control', 'public, max-age=3600');
    res.type('application/manifest+json').send(JSON.stringify(manifeste()));
  });
  app.get('/icone.svg', (req, res) => {
    res.set('Cache-Control', 'public, max-age=3600');
    res.type('image/svg+xml').send(icone());
  });
}

module.exports = { manifeste, icone, monter };
