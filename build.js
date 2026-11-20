#!/usr/bin/env node
/* =========================================================================
   Construction de la vitrine Toutadmin.

       node build.js

   Rend les cinq pages dans les seize langues : le français à la racine de
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
const crypto = require('crypto');

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

const PAGES = ['index', 'fonctionnalites', 'securite', 'installation', 'tarifs', 'contact'];

/*
 * Les mentions légales, et pourquoi elles ne sont pas une page comme les autres.
 *
 * La loi française les impose à tout site professionnel (LCEN, article 6 III) :
 * qui édite, où il siège, qui dirige la publication, qui héberge. Ces
 * informations ne peuvent pas être inventées ici, et une page qui annoncerait un
 * SIREN approximatif serait pire que pas de page du tout — l'absence est une
 * omission, une adresse fausse est une fausse déclaration.
 *
 * La page est donc construite depuis « editeur.json », et seulement s'il est
 * rempli. Sinon elle est absente, le lien du pied de page avec elle, et la
 * construction dit à chaque passage quels champs manquent. C'est le seul rappel
 * qui tienne : un commentaire dans un fichier se lit une fois.
 */
const EDITEUR = JSON.parse(fs.readFileSync(path.join(ROOT, 'editeur.json'), 'utf8'));
const MENTIONS_MANQUANTES = EDITEUR._obligatoires
  .filter((champ) => !String(EDITEUR[champ] || '').trim());
const MENTIONS = MENTIONS_MANQUANTES.length === 0;
if (MENTIONS) PAGES.push('mentions');

// Les écrans ont rejoint la page Fonctionnalités : décrire une fonction et
// montrer l'écran qui la porte, c'est la même chose. L'ancienne adresse reste
// servie, en redirection — des liens et des index pointent encore dessus.
const MERGED = { ecrans: 'fonctionnalites.html#ecrans' };

// Le site de documentation vit ailleurs — autre branche, autre hébergement.
// Son adresse est écrite ici et nulle part ailleurs : le jour où elle change,
// c'est cette ligne qu'on modifie, pas seize fichiers de traduction.
const DOC_URL = 'https://docs.toutadmin.com/';

// L'espace client, où l'on suit ses abonnements, ses factures et ses licences.
// Même règle que ci-dessus : une seule ligne à changer le jour où l'adresse
// change — et elle vient de changer. L'espace a quitté le panneau éditeur pour
// son propre hébergement, où il sert à la racine.
const CLIENT_URL = 'https://manager.toutadmin.com/';

// La vitrine du catalogue : ce que le produit sait faire, rayon par rayon, et
// à quel prix. Site voisin, même règle que les deux au-dessus — une seule
// ligne à changer le jour où l'adresse bouge.
const STORE_URL = 'https://appstore.toutadmin.com/';

// L'adresse de ce site-ci, sans barre finale : elle sert à fabriquer des
// adresses absolues — canonique, Open Graph, plan du site — que les robots et
// les réseaux sociaux ne devinent pas depuis une adresse relative. Même les
// autres sous-domaines de l'éditeur (dl., docs., manager., appstore.) vivent
// sous celui-ci : c'est lui qui est enregistré ailleurs comme site de
// référence, donc lui que porte l'adresse canonique.
const SITE_URL = 'https://toutadmin.com';

// La forme que chaque langue prend dans « og:locale » : Facebook et consorts
// attendent du BCP 47 à leur façon (xx_XX), pas le code à deux lettres que le
// reste du site utilise.
const OG_LOCALE = {
  fr: 'fr_FR', en: 'en_US', es: 'es_ES', de: 'de_DE', it: 'it_IT', pt: 'pt_PT',
  nl: 'nl_NL', pl: 'pl_PL', ru: 'ru_RU', tr: 'tr_TR', ar: 'ar_AR', hi: 'hi_IN',
  zh: 'zh_CN', ja: 'ja_JP', ko: 'ko_KR', vi: 'vi_VN',
};

/*
 * La politique de contenu, en un seul endroit.
 *
 * Elle sert trois fois : dans la balise de chaque page (ce qu'un hébergement
 * qui ne pose pas d'en-tête sert quand même), dans `_headers` (Netlify,
 * Cloudflare Pages) et dans `.htaccess` (Apache — OVH, Infomaniak, la plupart
 * des hébergements mutualisés). Une seule chaîne de base, pour que les trois
 * ne divergent jamais : la balise ne peut pas porter « frame-ancestors » — les
 * navigateurs l'ignorent hors en-tête —, donc les fichiers d'en-têtes
 * l'ajoutent, avec le reste de ce qu'une balise ne peut pas poser.
 */
const CSP_BASE = "default-src 'none'; script-src 'self'; style-src 'self'; "
  + "img-src 'self' data:; font-src 'self'; connect-src 'self'; base-uri 'none'; "
  + "form-action 'none'; object-src 'none'; upgrade-insecure-requests";
const CSP_META = CSP_BASE;
const CSP_HEADER = `${CSP_BASE}; frame-ancestors 'none'`;

// Fermées pour la même raison que dans le produit : une page de vitrine n'a
// besoin ni de la caméra, ni du micro, ni de la position, ni de rien de tout
// cela — et une régie publicitaire chargée plus tard par erreur ne les aurait
// pas non plus.
const PERMISSIONS_POLICY = 'accelerometer=(), autoplay=(), camera=(), display-capture=(), '
  + 'encrypted-media=(), fullscreen=(), geolocation=(), gyroscope=(), magnetometer=(), '
  + 'microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(), '
  + 'screen-wake-lock=(), sync-xhr=(), usb=(), xr-spatial-tracking=()';

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
  chevron: '<path d="m6 9 6 6 6-6"/>',
  close: '<path d="M6 6l12 12M18 6 6 18"/>',
  left: '<path d="M15 6l-6 6 6 6"/>',
  right: '<path d="M9 6l6 6-6 6"/>',
  shield: '<path d="M12 3l7 3v6c0 4.4-3 8-7 9-4-1-7-4.6-7-9V6l7-3Z"/>',
  users: '<path d="M16 19v-1.5A3.5 3.5 0 0 0 12.5 14h-5A3.5 3.5 0 0 0 4 17.5V19"/><circle cx="10" cy="8" r="3.2"/><path d="M20 19v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 5.2a3.2 3.2 0 0 1 0 5.6"/>',
  briefcase: '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5.5A1.5 1.5 0 0 1 9.5 4h5A1.5 1.5 0 0 1 16 5.5V7M3 12h18"/>',
  chart: '<path d="M4 20V9m5 11V4m5 16v-7m5 7V8"/>',
  lock: '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
  // La grille : la vitrine du catalogue, où les applications se rangent en
  // cases. Dessinée ici parce qu'aucune du jeu ne disait cela.
  grid: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  layers: '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5M3 17l9 5 9-5"/>',
  code: '<path d="m8 8-5 4 5 4m8-8 5 4-5 4M14 4l-4 16"/>',
  scale: '<path d="M12 4v16M7 20h10M5 8h14M8 8l-3 6a3 3 0 0 0 6 0L8 8Zm8 0-3 6a3 3 0 0 0 6 0l-3-6Z"/>',
  spark: '<path d="M12 3v4m0 10v4M3 12h4m10 0h4M6 6l2.5 2.5M15.5 15.5 18 18M18 6l-2.5 2.5M8.5 15.5 6 18"/>',
  building: '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 7h2m4 0h-2M9 11h2m4 0h-2M9 15h6v6H9z"/>',
  life: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3.6"/><path d="m5.6 5.6 3.8 3.8m5.2 5.2 3.8 3.8m0-12.8-3.8 3.8m-5.2 5.2-3.8 3.8"/>',
  box: '<path d="M3 8.5 12 4l9 4.5v7L12 20l-9-4.5v-7Z"/><path d="M3 8.5 12 13l9-4.5M12 13v7"/>',
  // Les icônes des menus : une par domaine, une par entrée. Dessinées au même
  // trait que le reste du jeu (24 points, contour, bouts ronds).
  user: '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
  heart: '<path d="M12 20s-7.5-4.6-7.5-10.4A4.1 4.1 0 0 1 12 7.4a4.1 4.1 0 0 1 7.5 2.2C19.5 15.4 12 20 12 20Z"/><path d="M8.5 12.5h2l1.2-2.2 1.6 4 1.1-1.8h1.6"/>',
  wallet: '<rect x="3" y="6" width="18" height="14" rx="2.5"/><path d="M3 10.5h18M16 15h2"/><path d="M6 6V5a2 2 0 0 1 2-2h9"/>',
  calc: '<rect x="5" y="3" width="14" height="18" rx="2.2"/><path d="M8.5 7h7M8.5 11.5h1.5m2.5 0H14m-5.5 3.5h1.5m2.5 0H14m-5.5 3h1.5m2.5 0H14"/>',
  cart: '<path d="M3 4h2.2l2.3 10.8h10.3L20 8H6.4"/><circle cx="9" cy="19" r="1.5"/><circle cx="17" cy="19" r="1.5"/>',
  kanban: '<rect x="3" y="4" width="18" height="16" rx="2.2"/><path d="M9 4v16M15 4v16M5.7 8.5h1.2M11.4 8.5h1.2M17.1 8.5h1.2M11.4 12.5h1.2M5.7 12.5h1.2"/>',
  headset: '<path d="M4 14v-2a8 8 0 0 1 16 0v2"/><rect x="3" y="14" width="4.2" height="6" rx="1.6"/><rect x="16.8" y="14" width="4.2" height="6" rx="1.6"/><path d="M19 20a4 4 0 0 1-4 2h-2"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2.2"/><path d="M3 10h18M8 3v4M16 3v4M7.5 14h2m2.5 0h2m-6.5 3.5h2"/>',
  compass: '<circle cx="12" cy="12" r="9"/><path d="m15.6 8.4-2.1 5.1-5.1 2.1 2.1-5.1 5.1-2.1Z"/>',
  cpu: '<rect x="6" y="6" width="12" height="12" rx="2.2"/><rect x="9.6" y="9.6" width="4.8" height="4.8" rx="1"/><path d="M9.5 3v3m5-3v3M9.5 18v3m5-3v3M3 9.5h3m-3 5h3m12-5h3m-3 5h3"/>',
  sliders: '<path d="M4 7h9m4 0h3M4 12h3m4 0h9M4 17h11m4 0h1"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="17" r="2"/>',
  download: '<path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 20h14"/>',
  book: '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15H6.5A1.5 1.5 0 0 0 5 19.5v-15Z"/><path d="M5 19.5A1.5 1.5 0 0 0 6.5 21H19M9 7h6"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2.2"/><path d="m4 7 8 6 8-6"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9m-3.5 3.5 2.5 2.5m-5-.5 2 2"/>',
  tag: '<path d="M3 12V4h8l10 10-8 8-10-10Z"/><circle cx="7.5" cy="8" r="1.4"/>',
  rocket: '<path d="M9 15 7 13c1-4.5 5-9.5 13-10 0 8-5 12-9.5 13Z"/><path d="M7 13c-2 .3-3.5 2-4 4 2-.3 3.2-.2 4 .5M11 17c-.3 2-2 3.5-4 4 .3-2 .2-3.2-.5-4"/><circle cx="15" cy="9" r="1.6"/>',
  terminal: '<rect x="3" y="4" width="18" height="16" rx="2.2"/><path d="m7 9.5 3 2.5-3 2.5M12.5 15H17"/>',
  server: '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01M11 7.5h6M11 16.5h6"/>',
  image: '<rect x="3" y="4" width="18" height="16" rx="2.2"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
  account: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.6 18.4a6.2 6.2 0 0 1 10.8 0"/>',
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

/*
 * La typographie française, avant d'écrire quoi que ce soit.
 *
 * Le français met une espace devant «  ; : ? ! » et à l'intérieur des
 * guillemets, et une espace ordinaire à cet endroit est un endroit où la ligne
 * peut se couper. Elle se coupe : un « ». » se retrouve seul en début de
 * ligne, et la faute est d'autant plus visible qu'elle tombe sur la page qui
 * vend le produit. La vérification est ici, et non dans un test séparé, parce
 * qu'un site publié avec cette faute n'a pas à attendre qu'on le reconstruise
 * pour qu'on s'en aperçoive.
 *
 * Les quinze autres langues ne sont pas concernées : l'allemand, l'anglais,
 * l'arabe et le japonais collent leur ponctuation.
 */
{
  const manques = require(path.join(ROOT, 'typographie.js')).verifier(ROOT);
  if (manques.length) {
    console.error(`\nTypographie française : ${manques.length} ligne(s) sans espace insécable — rien n'a été construit.`);
    for (const m of manques.slice(0, 6)) console.error(`  ${m.ligne}  ${m.texte.slice(0, 100)}`);
    if (manques.length > 6) console.error(`  … et ${manques.length - 6} autre(s).`);
    console.error('Posez-les : node typographie.js --corriger');
    process.exit(1);
  }
}

/* ------------------------------------------------------------- médias */

const MEDIA = require(path.join(ROOT, 'medias.js'));
/*
 * Les six nombres de la page d'accueil, comptés une fois par `chiffres.js` et
 * versionnés à côté. La construction ne va pas les chercher dans le produit :
 * le site doit se bâtir sur une machine qui n'a pas les deux éditions sous la
 * main.
 */
const CHIFFRES = require(path.join(ROOT, 'chiffres.json'));
const MEDIA_FILES = new Set(fs.readdirSync(path.join(ROOT, 'medias')));

/*
 * L'empreinte de chaque capture, collée à son adresse — comme pour le script
 * et la feuille de style plus bas.
 *
 * Les captures sont servies avec un mois de cache. Tant que leur adresse ne
 * bouge pas, refaire une capture ne suffit pas : le visiteur déjà venu, et
 * tout cache posé devant le site, gardent l'ancienne image jusqu'à trente
 * jours — le héros de la page d'accueil compris. C'est exactement ce qui
 * vient d'arriver : cent quarante-six captures sur cent cinquante étaient à
 * jour en ligne, et les quatre restantes — le héros, l'écran de pilotage et
 * leurs jumelles sombres — dataient de huit jours plus tôt.
 *
 * Une adresse qui change avec le contenu supprime la classe entière du
 * problème, et le fait sans toucher à la durée du cache : une image dont
 * l'adresse ne change jamais peut, elle, être gardée un mois sans risque.
 */
/*
 * Les dimensions de chaque capture, lues dans le fichier.
 *
 * Toutes les balises déclaraient « width="1440" height="900" ». Aucune des
 * cent cinquante captures n'a ce rapport : elles vont de 0,77 à 1,60, parce
 * qu'elles suivent la hauteur de la page photographiée. Le navigateur réserve
 * donc une boîte en seize-dixièmes, pose l'image, découvre qu'elle est plus
 * haute, et pousse tout ce qui suit — la page saute sous le curseur pendant
 * le chargement. Deux attributs justes suppriment le saut, et il n'y a rien
 * à choisir : le fichier connaît sa taille.
 *
 * L'en-tête PNG la donne à poste fixe : huit octets de signature, puis le
 * bloc IHDR dont la largeur et la hauteur occupent les octets 16 à 23.
 */
const DIMENSIONS_MEDIA = new Map();
const tailleDuPng = (chemin) => {
  const tete = Buffer.alloc(24);
  const fd = fs.openSync(chemin, 'r');
  try {
    fs.readSync(fd, tete, 0, 24, 0);
  } finally {
    fs.closeSync(fd);
  }
  if (tete.toString('binary', 1, 4) !== 'PNG') return null;
  return { l: tete.readUInt32BE(16), h: tete.readUInt32BE(20) };
};
for (const fichier of MEDIA_FILES) {
  if (!fichier.endsWith('.png')) continue;
  const t = tailleDuPng(path.join(ROOT, 'medias', fichier));
  if (t) DIMENSIONS_MEDIA.set(fichier, t);
}

const EMPREINTES_MEDIA = new Map();
for (const fichier of MEDIA_FILES) {
  EMPREINTES_MEDIA.set(fichier, crypto.createHash('sha1')
    .update(fs.readFileSync(path.join(ROOT, 'medias', fichier)))
    .digest('hex').slice(0, 10));
}
const adresseMedia = (base, fichier) => {
  const v = EMPREINTES_MEDIA.get(fichier);
  return `${base}medias/${fichier}${v ? `?v=${v}` : ''}`;
};

/*
 * Une capture trop légère n'est pas une capture.
 *
 * Le CMS plafonne les requêtes par adresse et répond au-delà « Too many
 * requests, please try again later. » — une ligne de texte noir sur blanc. Le
 * navigateur l'affiche, la photographie, et le fichier part en ligne : cinq
 * écrans coréens ont ainsi été publiés sous la forme d'un refus en anglais.
 * Rien ne les distinguait, sinon leur poids : neuf kilo-octets là où une
 * interface pleine en pèse cent.
 *
 * Le script de capture sait maintenant reconnaître le refus à son code de
 * réponse. Ce contrôle-ci est la seconde barrière, à la dernière porte avant
 * la publication : il ne sait pas ce qu'il y a sur l'image, mais il sait
 * qu'une page d'application ne tient pas dans si peu.
 */
const PLANCHER = 15 * 1024;
const maigres = [];
for (const fichier of MEDIA_FILES) {
  if (!fichier.endsWith('.png')) continue;
  const taille = fs.statSync(path.join(ROOT, 'medias', fichier)).size;
  if (taille < PLANCHER) maigres.push(`${fichier} (${Math.round(taille / 1024)} Ko)`);
}
if (maigres.length) {
  throw new Error(
    `Capture(s) trop légère(s), probablement une page d'erreur photographiée :\n  ${maigres.join('\n  ')}\n`
    + 'Refaites-les : node captures.js <nom sans .png>'
  );
}

/* ------------------------------------------------------------- gabarit */

const layout = fs.readFileSync(path.join(ROOT, 'layout.html'), 'utf8');

/* L'empreinte de chaque ressource, collée à son adresse.
   Le site se déploie par « git pull » et les assets sont servis avec douze
   heures de cache : sans cela, un visiteur déjà venu garde l'ancien script
   pendant une demi-journée alors que le HTML, lui, est neuf — et un bouton
   tout neuf ne répond pas. Une adresse qui change à chaque modification
   supprime la classe entière de ce problème. */
const stamp = (file) => crypto.createHash('sha1')
  .update(fs.readFileSync(path.join(ROOT, 'assets', file)))
  .digest('hex').slice(0, 10);
const VERSIONS = { css: stamp('style.css'), boot: stamp('theme-boot.js'), app: stamp('app.js') };
const { DOMAINS } = require(path.join(ROOT, 'pages', '_shared.js'));

/*
 * La barre de navigation.
 *
 * Une barre de verre posée au-dessus de la page, et quatre menus qui se
 * déroulent dessous — comme sur le site de ToutWAF, pour que les deux produits
 * se reconnaissent l'un l'autre. Le premier est un plan : les quatorze domaines
 * de la page Fonctionnalités, rangés en quatre colonnes, chacun avec son icône
 * et ce qu'il contient. Les trois autres mènent aux sections des pages, pas
 * seulement aux pages : on y cherche « l'édition PHP » ou « la licence », pas
 * « Installation » ou « Tarifs ».
 *
 * Tout tient sans script : un menu s'ouvre au survol, et le bouton qui le porte
 * est un vrai bouton, que le script fait ouvrir au clic et au clavier. Sur un
 * écran étroit, les menus cèdent la place au tiroir, qui reste le plan complet.
 */
function navHtml(t, ctx) {
  const { DOM_ICONS, MEGA_COLS } = require(path.join(ROOT, 'pages', '_shared.js'));
  const { lienTelechargement } = require(path.join(ROOT, 'pages', 'installation.js'));
  const NB_CAPTURES = MEDIA.groups.reduce((n, groupe) => n + groupe.shots.length, 0);
  const cible = (page) => {
    if (page.startsWith('http')) return page;
    const hash = page.indexOf('#');
    return hash === -1 ? ctx.href(page) : ctx.href(page.slice(0, hash)) + page.slice(hash);
  };
  const ext = (page) => (page.startsWith('http') ? ' rel="noopener"' : '');
  const item = (page, ic, titre, desc) => `<a class="mm-item" href="${cible(page)}"${ext(page)}>
                <span class="mm-ico">${icon(ic)}</span>
                <span class="mm-txt"><strong>${esc(titre)}</strong><small>${esc(desc)}</small></span>
              </a>`;
  const menu = (id, label, corps, large, actif) => `<div class="mm${large ? ' mm-large' : ''}" data-mm>
          <button class="mm-btn${actif ? ' is-active' : ''}" type="button" aria-expanded="false" aria-controls="mm-${id}">${esc(label)}${icon('chevron', 'mm-chev')}</button>
          <div class="mm-panel" id="mm-${id}">
            <div class="mm-card">
              ${corps}
            </div>
          </div>
        </div>`;

  const colonnes = MEGA_COLS.map(([col, domaines]) => `<div class="mm-col">
              <h4>${esc(t(`mega.col.${col}`))}</h4>
              ${domaines.map((d) => item(`fonctionnalites#${d}`, DOM_ICONS[d], t(`dom.${d}.title`), t(`mega.d.${d}`))).join('\n              ')}
            </div>`).join('\n            ');
  const fonctions = menu('fonctions', t('nav.features'), `<div class="mm-cols">
            ${colonnes}
            </div>
            <div class="mm-foot">
              <a class="mm-foot-main" href="${ctx.href('fonctionnalites')}">${icon('arrow')}${esc(t('features.seeAll'))}</a>
              <a href="${ctx.href('fonctionnalites')}#ecrans">${esc(t('nav.screens'))}</a>
              <a href="${ctx.href('tarifs')}#compris">${esc(t('mega.compare'))}</a>
              <a href="${DOC_URL}" rel="noopener">${esc(t('nav.docs'))}</a>
              <a class="mm-cta" href="${ctx.href('tarifs')}">${icon('check')}${esc(t('hero.ctaPrimary'))}</a>
            </div>`, true, ctx.page === 'fonctionnalites');

  const liste = (entrees) => `<div class="mm-list">
              ${entrees.map((e) => item(...e)).join('\n              ')}
            </div>`;
  const installation = menu('installation', t('nav.install'), liste([
    ['installation#voies', 'terminal', t('install.waysTitle'), t('mega.inst.ways')],
    ['installation#node', 'server', t('install.node.name'), t('mega.inst.node')],
    ['installation#php', 'code', t('install.php.name'), t('mega.inst.php')],
    ['installation#assistant', 'spark', t('install.wizardTitle'), t('mega.inst.wizard')],
    [lienTelechargement(ctx), 'download', t('install.dlCta'), t('mega.inst.dl')],
  ]), false, ctx.page === 'installation');
  const tarifs = menu('tarifs', t('nav.pricing'), liste([
    ['tarifs#formules', 'tag', t('mega.price.plans'), t('mega.price.plans.d')],
    ['tarifs#exemples', 'users', t('pricing.examplesTitle'), t('mega.price.examples.d')],
    ['tarifs#assistance', 'headset', t('support.title'), t('mega.price.support.d')],
    ['tarifs#licence', 'key', t('licence.title'), t('mega.price.licence.d')],
    [STORE_URL, 'grid', t('nav.store'), t('mega.store.d')],
  ]), false, ctx.page === 'tarifs');
  const ressources = menu('ressources', t('nav.resources'), liste([
    [DOC_URL, 'book', t('nav.docs'), t('mega.res.docs')],
    ['securite', 'shield', t('nav.security'), t('mega.res.security')],
    ['fonctionnalites#ecrans', 'image', t('nav.screens'), t('mega.res.screens', { count: NB_CAPTURES })],
    ['contact', 'mail', t('nav.contact'), t('mega.res.contact')],
    [CLIENT_URL, 'account', t('nav.client'), t('mega.res.client')],
  ]), false, ['securite', 'contact'].includes(ctx.page));

  const langs = LOCALES.map((l) => {
    const active = l.code === ctx.locale.code;
    return `<a lang="${l.code}" hreflang="${l.code}" href="${ctx.hrefIn(l.code, ctx.page)}"${active ? ' class="is-active" aria-current="true"' : ''}><span class="flag">${l.flag}</span>${esc(dictionaries[l.code]['locale.name'])}</a>`;
  }).join('\n              ');
  const faces = [['light', 'sun'], ['dark', 'moon'], ['system', 'monitor']]
    .map(([value, ic]) => `<span class="face" data-face="${value}">${icon(ic)}</span>`).join('');
  const themes = [['light', 'sun', t('nav.themeLight')], ['dark', 'moon', t('nav.themeDark')], ['system', 'monitor', t('nav.themeSystem')]]
    .map(([value, ic, label]) => `<button class="prefs-theme" type="button" data-theme-choice="${value}">${icon(ic)}<span>${esc(label)}</span></button>`)
    .join('\n              ');

  // Le nom porte deux tons : la marque se reconnaît à cela autant qu'au
  // sigle. Si une langue écrit le nom autrement, il sort d'un seul ton
  // plutôt que coupé au mauvais endroit.
  const name = t('site.name');
  const wordmark = name.startsWith('Tout')
    ? `Tout<span class="brand-accent">${esc(name.slice(4))}</span>`
    : esc(name);

  return `  <header class="nav">
    <div class="nav-shell">
    <div class="nav-in">
      <button class="icon-btn nav-toggle" type="button" aria-label="${esc(t('nav.menu'))}" aria-controls="plan-du-site" aria-expanded="false">${icon('menu')}</button>
      <a class="brand" href="${ctx.href('index')}">
        <span class="brand-mark">TA</span><span class="brand-name">${wordmark}</span>
      </a>
      <nav class="nav-links" aria-label="${esc(t('nav.product'))}">
        ${fonctions}
        <a class="nav-link${ctx.page === 'securite' ? ' is-active' : ''}" href="${ctx.href('securite')}"${ctx.page === 'securite' ? ' aria-current="page"' : ''}>${esc(t('nav.security'))}</a>
        ${installation}
        ${tarifs}
        ${ressources}
      </nav>
      <div class="nav-right">
        <div class="picker prefs">
          <button class="picker-btn prefs-btn" type="button" aria-haspopup="true" aria-label="${esc(t('nav.settings'))}">${icon('sliders')}<span class="prefs-sum"><span>${ctx.locale.code.toUpperCase()}</span><span class="prefs-sep" aria-hidden="true">·</span><span class="prefs-face" aria-hidden="true">${faces}</span></span></button>
          <div class="picker-pop prefs-pop" role="group" aria-label="${esc(t('nav.settings'))}">
            <h4>${esc(t('nav.language'))}</h4>
            <div class="prefs-langs">
              ${langs}
            </div>
            <h4>${esc(t('nav.theme'))}</h4>
            <div class="prefs-themes">
              ${themes}
            </div>
          </div>
        </div>
        <a class="btn btn-sm btn-glass nav-client" href="${CLIENT_URL}" rel="noopener">${icon('user')}${esc(t('nav.client'))}</a>
        <a class="btn btn-sm nav-try" href="${ctx.href('tarifs')}">${esc(t('nav.try'))}</a>
      </div>
    </div>
    </div>
  </header>`;
}

function themeButtons(t) {
  return [['light', 'sun', t('nav.themeLight')], ['dark', 'moon', t('nav.themeDark')], ['system', 'monitor', t('nav.themeSystem')]]
    .map(([value, ic, label]) =>
      `<button class="seg-btn" type="button" data-theme-choice="${value}" aria-label="${esc(label)}" title="${esc(label)}">${icon(ic)}</button>`)
    .join('\n              ');
}

/* Dans le tiroir, les trois touches restent posées côte à côte : c'est un
   menu, pas une barre dont il faut économiser la largeur. */
function segHtml(t) {
  return `<div class="seg" role="group" aria-label="${esc(t('nav.theme'))}">
        ${themeButtons(t)}
      </div>`;
}

/* Le bouton à trois traits ouvre ce tiroir, à toutes les largeurs. Il ne se
   contente pas de répéter la barre : chaque entrée dit ce qu'on trouve
   derrière, et les quatorze domaines de la page Fonctionnalités sont listés —
   c'est le plan du site, pas un menu de repli pour petits écrans. */
function drawerHtml(t, ctx) {
  // Une entrée vise une page, une ancre dans une page, ou un site voisin.
  const target = (page) => {
    if (page.startsWith('http')) return page;
    const hash = page.indexOf('#');
    return hash === -1 ? ctx.href(page) : ctx.href(page.slice(0, hash)) + page.slice(hash);
  };
  const item = ([page, label, note, ic]) => `<a class="drawer-item${ctx.page === page ? ' is-active' : ''}" href="${target(page)}"${page.startsWith('http') ? ' rel="noopener"' : ''}>
            <span class="ico">${icon(ic)}</span>
            <span><strong>${esc(t(label))}</strong><span class="drawer-note">${esc(t(note, { count: NB_CAPTURES }))}</span></span>
          </a>`;

  /*
   * Le nombre de captures se compte, il ne se recopie pas.
   *
   * Le tiroir annonçait « quarante captures » dans les seize langues. Elles
   * étaient cinquante-quatre, et personne n'avait menti : le chiffre avait
   * été juste, une fois, puis la galerie avait grossi sans lui. Un nombre
   * écrit à la main dans seize fichiers vieillit toujours ainsi.
   */
  const NB_CAPTURES = MEDIA.groups.reduce((n, groupe) => n + groupe.shots.length, 0);

  const domains = DOMAINS.map((key) =>
    `<a href="${ctx.href('fonctionnalites')}#${key}">${esc(t(`dom.${key}.title`))}</a>`).join('\n            ');

  // Trois sections : ce qu'est le produit, ce qu'il faut savoir pour décider,
  // et par où entrer en contact. Les fonctionnalités et les écrans vont
  // ensemble — ce sont deux façons de regarder la même chose.
  const groups = [
    ['menu.group.product', [
      ['index', 'menu.home', 'menu.home.d', 'layers'],
      ['fonctionnalites', 'nav.features', 'menu.features.d', 'briefcase'],
      ['fonctionnalites#ecrans', 'nav.screens', 'menu.screens.d', 'monitor'],
    ], true],
    ['menu.group.decide', [
      ['securite', 'nav.security', 'menu.security.d', 'shield'],
      ['installation', 'nav.install', 'menu.install.d', 'box'],
      ['tarifs', 'nav.pricing', 'menu.pricing.d', 'chart'],
      [STORE_URL, 'nav.store', 'menu.store.d', 'grid'],
    ], false],
    ['menu.group.more', [
      ['contact', 'nav.contact', 'menu.contact.d', 'users'],
      [DOC_URL, 'nav.docs', 'menu.docs.d', 'code'],
    ], false],
  ].map(([title, entries, withDomains]) => `<h4>${esc(t(title))}</h4>
      <nav class="drawer-list" aria-label="${esc(t(title))}">
          ${entries.map(item).join('\n          ')}
      </nav>${withDomains ? `
      <div class="drawer-chips">
            ${domains}
      </div>` : ''}`).join('\n      ');

  return `  <div class="drawer-backdrop" hidden></div>
  <aside class="drawer" id="plan-du-site" aria-label="${esc(t('menu.title'))}" hidden>
    <div class="drawer-head">
      <span class="brand"><span class="brand-mark">TA</span>${esc(t('menu.title'))}</span>
      <button class="icon-btn drawer-close" type="button" aria-label="${esc(t('menu.close'))}">${icon('close')}</button>
    </div>
    <div class="drawer-body">
      <div class="drawer-theme">${segHtml(t)}</div>
      ${groups}
      <div class="drawer-ctas">
        <a class="btn drawer-cta" href="${ctx.href('contact')}">${esc(t('nav.demo'))}${icon('arrow')}</a>
        <a class="btn btn-ghost drawer-cta" href="${CLIENT_URL}" rel="noopener">${esc(t('nav.client'))}${icon('arrow')}</a>
      </div>
    </div>
  </aside>`;
}

/*
 * Le pied de page.
 *
 * Il avait vieilli sans qu'on le relise : « Installation » n'y était pas du
 * tout — seule page du site à manquer —, « Contact » y était deux fois,
 * l'espace client nulle part alors que c'est là qu'un client déjà chez nous
 * va chercher ses factures, et le bas répétait en étiquette nue le titre de
 * la liste des langues qui le surplombe.
 *
 * Quatre colonnes, et chaque page exactement une fois : ce que c'est, où
 * l'apprendre, et par où l'on entre quand on est déjà client.
 */
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
            <li><a href="${ctx.href('fonctionnalites')}#ecrans">${esc(t('nav.screens'))}</a></li>
            <li><a href="${ctx.href('securite')}">${esc(t('nav.security'))}</a></li>
            <li><a href="${ctx.href('tarifs')}">${esc(t('nav.pricing'))}</a></li>
          </ul>
        </div>
        <div>
          <h4>${esc(t('foot.resources'))}</h4>
          <ul>
            <li><a href="${ctx.href('installation')}">${esc(t('nav.install'))}</a></li>
            <li><a href="${DOC_URL}" rel="noopener">${esc(t('nav.docs'))}</a></li>
            <li><a href="${STORE_URL}" rel="noopener">${esc(t('nav.store'))}</a></li>
          </ul>
        </div>
        <div>
          <h4>${esc(t('foot.yourSpace'))}</h4>
          <ul>
            <li><a href="${CLIENT_URL}" rel="noopener">${esc(t('nav.client'))}</a></li>
            <li><a href="${ctx.href('contact')}">${esc(t('contact.write'))}</a></li>
            ${MENTIONS ? `<li><a href="${ctx.href('mentions')}">${esc(t('nav.legal'))}</a></li>` : ''}
          </ul>
        </div>
      </div>
      <section class="foot-family" aria-labelledby="foot-family-t">
        <h4 id="foot-family-t">${esc(t('foot.family'))}</h4>
        <ul class="foot-family-list">
          <li><a href="https://toutwaf.com" rel="noopener"><strong>ToutWAF</strong><small>${esc(t('foot.fam.waf'))}</small></a></li>
          <li><a href="https://toutpanel.com" rel="noopener"><strong>ToutPanel</strong><small>${esc(t('foot.fam.panel'))}</small></a></li>
          <li><a href="https://toutpvr.com" rel="noopener"><strong>ToutPVR</strong><small>${esc(t('foot.fam.pvr'))}</small></a></li>
          <li><a href="https://toutheberg.com" rel="noopener"><strong>ToutHeberg</strong><small>${esc(t('foot.fam.host'))}</small></a></li>
        </ul>
      </section>
      <div>
        <h4 class="sr">${esc(t('foot.langTitle'))}</h4>
        <div class="foot-langs">
          ${langs}
        </div>
      </div>
      <div class="foot-bottom">
        <span>© ${new Date().getFullYear()} ${esc(t('site.name'))}. ${esc(t('foot.rights'))}</span>
        <span class="foot-credit">${esc(t('foot.credit', { a: '\u0001A\u0001', b: '\u0001B\u0001', c: '\u0001C\u0001' }))
    .replace('\u0001A\u0001', '<a href="https://qu3ntin.com" rel="noopener">qu3ntin.com</a>')
    .replace('\u0001B\u0001', '<a href="https://quentin-creations.fr" rel="noopener">quentin-creations.fr</a>')
    .replace('\u0001C\u0001', '<a href="https://toutheberg.com" rel="noopener">toutheberg.com</a>')}</span>
      </div>
    </div>
  </footer>`;
}

/*
 * Les données structurées (schema.org), en JSON-LD.
 *
 * Toujours l'organisation et le site — ce qui alimente un bandeau de
 * connaissance et le nom affiché dans les résultats — et, sur l'accueil
 * seulement, le logiciel lui-même avec son prix réel : celui que la page
 * Tarifs annonce, pas un chiffre arrondi pour l'occasion. Rien n'y figure
 * qu'on ne puisse déjà lire ailleurs sur le site : pas d'avis ni de note,
 * qui se fabriqueraient plutôt qu'elles ne se liraient.
 */
function ldJson(ctx) {
  const { t, locale, page } = ctx;
  const plans = require(path.join(ROOT, 'pages', '_plans.js'));
  // Une adresse absolue depuis la racine du site, pas relative à la page
  // courante : « ctx.hrefIn » rend des chemins relatifs (« ../tarifs.html »),
  // qui n'ont pas de sens une fois sortis de la page et collés à SITE_URL.
  const url = (name) => `${SITE_URL}/${locale.code === 'fr' ? '' : `${locale.code}/`}${name}.html`;
  const org = {
    '@type': 'Organization',
    name: t('site.name'),
    url: `${SITE_URL}/`,
    logo: `${SITE_URL}/assets/logo.svg`,
  };
  const site = {
    '@type': 'WebSite',
    name: t('site.name'),
    url: `${SITE_URL}/`,
    inLanguage: locale.code,
  };
  const graph = [org, site];
  if (page === 'index') {
    graph.push({
      '@type': 'SoftwareApplication',
      name: t('site.name'),
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Linux, Windows',
      url: `${SITE_URL}/`,
      description: t('site.tagline'),
      offers: {
        '@type': 'Offer',
        price: String(plans.PRICE_PER_EMPLOYEE),
        priceCurrency: 'EUR',
        url: url('tarifs'),
        description: t('hero.note'),
      },
    });
  }
  // « </ » coupé en deux : une traduction qui contiendrait un jour cette suite
  // de caractères ne doit pas pouvoir refermer la balise plus tôt que prévu.
  const json = JSON.stringify({ '@context': 'https://schema.org', '@graph': graph }).replace(/<\//g, '<\\/');
  return `<script type="application/ld+json">${json}</script>`;
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
      chiffres: CHIFFRES,
      editeur: EDITEUR,
      href: (name) => `${base}${name}.html`,
      // La commande vit dans l'espace client, sur son domaine : le site n'a pas
      // de formulaire — il est statique, et une commande ouvre un compte. Le
      // lien porte la langue, pour que le formulaire s'ouvre dans celle-ci.
      commander: (suite = '') => `${CLIENT_URL}commander?langue=${locale.code}${suite ? `&${suite}` : ''}`,
      hrefIn: (code, name) => (code === 'fr' ? `${base}${name}.html` : `${base}${code === locale.code ? '' : (locale.code === 'fr' ? `${code}/` : `../${code}/`)}${name}.html`),
      asset: (file) => `${base}assets/${file}`,
      shot: (file) => adresseMedia(base, file),
      taille: (file) => DIMENSIONS_MEDIA.get(file) || null,
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
      `<link rel="alternate" hreflang="${l.code}" href="${ctx.hrefIn(l.code, page)}" />`).join('\n  ')
      // Le français fait référence : une recherche sans langue détectable reçoit
      // celle-là plutôt qu'un choix arbitraire parmi quinze.
      + `\n  <link rel="alternate" hreflang="x-default" href="${ctx.hrefIn('fr', page)}" />`;
    const canonical = `${SITE_URL}/${locale.code === 'fr' ? '' : `${locale.code}/`}${page}.html`;
    // Une capture au format proche du paysage qu'un réseau social recadre
    // (« accueil.png » est un portrait tout en hauteur, bien plus mauvais une
    // fois réduit en vignette) ; celle du pilotage, qui parle d'elle-même et
    // existe traduite dans chaque langue.
    const ogImageFile = ctx.shotFile('pilotage');
    const ogImageDim = DIMENSIONS_MEDIA.get(ogImageFile) || DIMENSIONS_MEDIA.get('pilotage.png');

    const html = layout
      .split('{{LANG}}').join(locale.code)
      .split('{{DIR}}').join(locale.dir)
      .split('{{TITLE}}').join(esc(view.title))
      .split('{{DESCRIPTION}}').join(esc(view.description))
      .split('{{BASE}}').join(base)
      .split('{{V_CSS}}').join(VERSIONS.css)
      .split('{{V_BOOT}}').join(VERSIONS.boot)
      .split('{{V_APP}}').join(VERSIONS.app)
      .split('{{ALTERNATES}}').join(alternates)
      .split('{{CANONICAL}}').join(esc(canonical))
      .split('{{SITE_NAME}}').join(esc(t('site.name')))
      .split('{{OG_LOCALE}}').join(OG_LOCALE[locale.code])
      .split('{{OG_IMAGE_W}}').join(ogImageDim ? String(ogImageDim.l) : '1200')
      .split('{{OG_IMAGE_H}}').join(ogImageDim ? String(ogImageDim.h) : '630')
      .split('{{OG_IMAGE}}').join(esc(`${SITE_URL}/medias/${ogImageFile}`))
      .split('{{CSP}}').join(CSP_META)
      .split('{{JSONLD}}').join(ldJson(ctx))
      .split('{{NAV}}').join(navHtml(t, ctx) + '\n' + drawerHtml(t, ctx))
      .split('{{CONTENT}}').join(view.body)
      .split('{{FOOT}}').join(footHtml(t, ctx))
      .split('{{SKIP}}').join(esc(t('nav.product')));

    fs.writeFileSync(path.join(dirOut, `${page}.html`), html);
    written += 1;
  }
}

/* Les adresses disparues restent servies : une page qui a existé ne doit pas
   devenir une erreur 404 parce qu'on a réorganisé le site. Redirection immédiate,
   et un lien visible pour le visiteur dont le navigateur ne la suit pas. */
for (const locale of LOCALES) {
  const dirOut = locale.code === 'fr' ? OUT : path.join(OUT, locale.code);
  const t = (key) => dictionaries[locale.code][key];
  for (const [from, to] of Object.entries(MERGED)) {
    // Une adresse absolue : un canonique relatif se romprait au premier robot
    // qui le lit hors contexte, et « noindex » n'empêche pas qu'on le lise.
    const absolu = `${SITE_URL}/${locale.code === 'fr' ? '' : `${locale.code}/`}${to}`;
    fs.writeFileSync(path.join(dirOut, `${from}.html`),
      `<!doctype html>\n<html lang="${locale.code}" dir="${locale.dir}">\n<head>\n`
      + '<meta charset="utf-8" />\n'
      + `<meta http-equiv="refresh" content="0; url=${to}" />\n`
      + `<link rel="canonical" href="${absolu}" />\n`
      + '<meta name="robots" content="noindex, follow" />\n'
      + `<title>${esc(t('nav.screens'))} — ${esc(t('site.name'))}</title>\n`
      + `</head>\n<body>\n<p><a href="${to}">${esc(t('nav.features'))}</a></p>\n</body>\n</html>\n`);
  }
}

/* Ressources statiques */
for (const dir of ['assets', 'medias']) {
  fs.mkdirSync(path.join(OUT, dir), { recursive: true });
  for (const file of fs.readdirSync(path.join(ROOT, dir))) {
    fs.copyFileSync(path.join(ROOT, dir, file), path.join(OUT, dir, file));
  }
}

fs.writeFileSync(path.join(OUT, 'robots.txt'),
  `User-agent: *\nAllow: /\n\nSitemap: ${SITE_URL}/sitemap.xml\n`);

/*
 * Les en-têtes de sécurité, pour l'hébergement qui les lit.
 *
 * Un site statique n'a pas de serveur applicatif pour les poser lui-même : ces
 * deux fichiers le font à sa place, chacun dans le format d'une famille
 * d'hébergeurs — `_headers` pour Netlify et Cloudflare Pages, `.htaccess` pour
 * Apache (OVH, Infomaniak, la plupart des hébergements mutualisés et des VPS
 * qui en installent un). Un hébergement qui ne lit ni l'un ni l'autre laisse
 * le site tel qu'avant : la balise CSP de chaque page continue de s'appliquer,
 * ce qui reste la majorité de la protection.
 */
const HEADERS_COMMUNS = [
  // HTTPS pour un an, sous-domaines compris, et candidat à la liste de
  // préchargement des navigateurs : la première visite elle-même ne peut
  // plus se faire en clair, pas seulement les suivantes.
  'Strict-Transport-Security: max-age=63072000; includeSubDomains; preload',
  'X-Content-Type-Options: nosniff',
  // Une ceinture par-dessus « frame-ancestors » : certains agrégateurs ne
  // lisent que l'ancien en-tête.
  'X-Frame-Options: DENY',
  'Referrer-Policy: strict-origin-when-cross-origin',
  `Permissions-Policy: ${PERMISSIONS_POLICY}`,
  'Cross-Origin-Opener-Policy: same-origin',
  'Cross-Origin-Resource-Policy: same-origin',
  `Content-Security-Policy: ${CSP_HEADER}`,
];
fs.writeFileSync(path.join(OUT, '_headers'),
  `/*\n${HEADERS_COMMUNS.map((l) => `  ${l}`).join('\n')}\n\n`
  + `/*.html\n  Cache-Control: no-cache\n\n`
  + `/assets/*\n  Cache-Control: public, max-age=31536000, immutable\n\n`
  + `/medias/*\n  Cache-Control: public, max-age=31536000, immutable\n`);
fs.writeFileSync(path.join(OUT, '.htaccess'),
  '<IfModule mod_headers.c>\n'
  + HEADERS_COMMUNS.map((l) => {
    const i = l.indexOf(': ');
    return `  Header always set "${l.slice(0, i)}" "${l.slice(i + 2)}"`;
  }).join('\n')
  + '\n</IfModule>\n\n'
  + '# HTTPS exigé, même sur la première visite.\n'
  + '<IfModule mod_rewrite.c>\n'
  + '  RewriteEngine On\n'
  + '  RewriteCond %{HTTPS} off\n'
  + '  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]\n'
  + '</IfModule>\n\n'
  + '# Pas de liste de répertoire, pas de fichier caché servi.\n'
  + 'Options -Indexes\n'
  + '<FilesMatch "^\\.">\n'
  + '  Require all denied\n'
  + '</FilesMatch>\n\n'
  + '<IfModule mod_expires.c>\n'
  + '  ExpiresActive On\n'
  + '  ExpiresByType text/html "access plus 0 seconds"\n'
  + '  ExpiresByType text/css "access plus 1 year"\n'
  + '  ExpiresByType application/javascript "access plus 1 year"\n'
  + '  ExpiresByType image/png "access plus 1 year"\n'
  + '  ExpiresByType image/svg+xml "access plus 1 year"\n'
  + '</IfModule>\n');

/*
 * L'avis de sécurité (RFC 9116), à l'adresse que les chercheurs regardent en
 * premier : /.well-known/security.txt. Il ne peut porter qu'une adresse
 * réellement surveillée — jamais une adresse inventée pour la forme — donc il
 * suit la même règle que la page des mentions légales : construit depuis
 * « editeur.json », et seulement s'il est rempli.
 */
if (MENTIONS) {
  const expire = new Date();
  expire.setFullYear(expire.getFullYear() + 1);
  fs.mkdirSync(path.join(OUT, '.well-known'), { recursive: true });
  fs.writeFileSync(path.join(OUT, '.well-known', 'security.txt'),
    `Contact: mailto:${EDITEUR.courriel}\n`
    + `Expires: ${expire.toISOString().replace(/\.\d+Z$/, 'Z')}\n`
    + `Preferred-Languages: ${LOCALES.map((l) => l.code).join(', ')}\n`
    + `Canonical: ${SITE_URL}/.well-known/security.txt\n`);
}

/*
 * Le pied de page mène à chaque page du site, une fois et une seule.
 *
 * Il a dérivé sans bruit : « Installation » n'y a jamais figuré, « Contact »
 * y figurait deux fois, et rien ne l'a signalé pendant des mois parce que
 * rien ne le regardait. Le pied de page est la seule liste du site qui se
 * veut exhaustive — la barre du haut, elle, choisit délibérément quatre
 * liens sur six. Une liste qui se veut exhaustive doit être vérifiée, sinon
 * elle ne l'est que le jour où on l'écrit.
 *
 * L'accueil est la seule exception : on y va par la marque, en tête du pied.
 */
{
  const pied = fs.readFileSync(path.join(OUT, 'index.html'), 'utf8');
  const bas = pied.slice(pied.indexOf('<footer class="foot">'), pied.indexOf('</footer>'));
  const manquantes = [];
  const doublees = [];
  for (const page of PAGES) {
    if (page === 'index') continue;
    // « fonctionnalites.html » et « fonctionnalites.html#ecrans » sont le même
    // lien de navigation : l'ancre ne compte pas pour une seconde entrée.
    const fois = (bas.match(new RegExp(`href="${page}\\.html(?:#[a-z-]+)?"`, 'g')) || []).length;
    if (fois === 0) manquantes.push(page);
    if (fois > 2) doublees.push(`${page} (${fois} fois)`);
  }
  if (manquantes.length || doublees.length) {
    throw new Error('Pied de page :'
      + (manquantes.length ? `\n  jamais liée(s) : ${manquantes.join(', ')}` : '')
      + (doublees.length ? `\n  liée(s) en double : ${doublees.join(', ')}` : ''));
  }
}

/*
 * La fenêtre du héros montre bien tous les écrans annoncés.
 *
 * Trois choses peuvent se défaire sans bruit et ne se voient qu'à l'œil, une
 * fois en ligne : un écran de `heroTour` qui n'a pas de capture et disparaît
 * de la pile ; le premier écran passé en chargement différé, qui est la plus
 * grande image de la page et donc celle que le navigateur mesure ; et des
 * pastilles qui ne correspondent plus aux écrans, donc qui mènent ailleurs
 * que là où elles disent.
 */
{
  const page = fs.readFileSync(path.join(OUT, 'index.html'), 'utf8');
  // « hero-screens » est le conteneur : sans la frontière, il compte pour un écran.
  const ecrans = (page.match(/class="hero-screen[" ]/g) || []).length;
  const pastilles = (page.match(/class="hero-dot[" ]/g) || []).length;
  const attendus = MEDIA.heroTour.length;
  const premier = page.slice(page.indexOf('class="hero-screen is-on"'));
  const differe = premier.slice(0, premier.indexOf('</div>')).includes('loading="lazy"');
  const soucis = [];
  if (ecrans !== attendus) soucis.push(`${ecrans} écran(s) dans la fenêtre pour ${attendus} annoncé(s) dans heroTour`);
  if (pastilles !== attendus) soucis.push(`${pastilles} pastille(s) pour ${attendus} écran(s)`);
  if (differe) soucis.push('le premier écran est en chargement différé — c\'est la plus grande image de la page');
  if (soucis.length) throw new Error(`Fenêtre du héros :\n  ${soucis.join('\n  ')}`);
}

/* Un relevé du barème, en texte brut, à la racine du site.
   Le site se déploie par « git pull » : quand une page semble périmée, la
   question est toujours la même — le serveur a-t-il tiré la dernière version ?
   Ce fichier y répond sans ouvrir une page ni vider un cache. Il ne porte pas
   de date : il ne change que lorsque les prix changent, ce qui est exactement
   ce qu'on cherche à vérifier. */
const plans = require(path.join(ROOT, 'pages', '_plans.js'));
const barème = [
  `gratuit jusqu'à ${plans.FREE_SEATS} salariés`,
  `abonnement : ${plans.PRICE_PER_EMPLOYEE} € par salarié et par mois au-delà de ${plans.FREE_SEATS} `
    + `(12 salariés : ${plans.monthly(12)} €/mois)`,
].join('\n  ');
fs.writeFileSync(path.join(OUT, 'version.txt'),
  `Toutadmin — vitrine\n\nBarème\n  ${barème}\n\n`
  + `Pages     : ${written} (${PAGES.length} × ${LOCALES.length} langues)\n`
  + `Clés      : ${refKeys.length} par langue\n`
  + `Captures  : ${fs.readdirSync(path.join(ROOT, 'medias')).length} fichiers\n`
  // Ce que la page d'accueil annonce au visiteur, relevé le jour où
  // `chiffres.js` a compté : c'est la première chose qu'on veut vérifier
  // quand on soupçonne le site d'avoir une version de retard.
  + `Annoncé   : ${CHIFFRES.espaces} espaces · ${CHIFFRES.langues} langues · `
  + `${CHIFFRES.tests} tests · ${CHIFFRES.modules} modules · ${CHIFFRES.libelles} libellés`
  + ` (relevé ${CHIFFRES.releve})\n`
  + `Ressources: style ${VERSIONS.css} · thème ${VERSIONS.boot} · script ${VERSIONS.app}\n`);
// La date de construction, pas une par page : rien ici ne se date à la ligne
// près, et un « lastmod » inventé lourdement faux vaudrait pire qu'absent.
const AUJOURD_HUI = new Date().toISOString().slice(0, 10);
const chemin = (code, page) => `${SITE_URL}/${code === 'fr' ? '' : `${code}/`}${page}.html`;
const urls = [];
for (const locale of LOCALES) {
  for (const page of PAGES) {
    // Les variantes de langue d'une même page, redites ici en plus de la
    // balise « hreflang » de chaque page : Google les lit indépendamment l'une
    // de l'autre, et les deux concordantes valent mieux qu'une seule.
    const alternates = LOCALES.map((l) =>
      `      <xhtml:link rel="alternate" hreflang="${l.code}" href="${chemin(l.code, page)}" />`).join('\n')
      + `\n      <xhtml:link rel="alternate" hreflang="x-default" href="${chemin('fr', page)}" />`;
    urls.push(`  <url>\n    <loc>${chemin(locale.code, page)}</loc>\n    <lastmod>${AUJOURD_HUI}</lastmod>\n${alternates}\n  </url>`);
  }
}
fs.writeFileSync(path.join(OUT, 'sitemap.xml'),
  '<?xml version="1.0" encoding="UTF-8"?>\n'
  + '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">\n'
  + `${urls.join('\n')}\n</urlset>\n`);

const medias = fs.readdirSync(path.join(ROOT, 'medias')).length;
console.log(`${written} pages écrites dans site/ (${PAGES.length} pages × ${LOCALES.length} langues)`);
console.log(`  captures  : ${medias} fichiers`);
console.log(`  clés      : ${refKeys.length} par langue`);

/*
 * Le rappel, à chaque construction et jusqu'à ce qu'il n'ait plus lieu d'être.
 * La loi n'attend pas qu'on s'en souvienne.
 */
if (MENTIONS) {
  console.log('  mentions  : page construite depuis editeur.json');
  console.log('  security.txt : construit depuis editeur.json (courriel)');
} else {
  console.log(`  mentions  : ABSENTES — la loi les impose (LCEN art. 6 III).`);
  console.log(`              Remplissez editeur.json : ${MENTIONS_MANQUANTES.join(', ')}`);
  console.log('  security.txt : ABSENT — même raison, même fichier à remplir.');
}
console.log(`  en-têtes  : _headers, .htaccess, sitemap.xml et robots.txt écrits pour ${SITE_URL}`);
