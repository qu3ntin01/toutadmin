const settings = require('./settings');

/**
 * Palettes de l'instance.
 *
 * Deux axes qu'il ne faut pas confondre : la palette engage l'identité visuelle
 * de l'entreprise et se choisit une fois, par l'administration ; le mode clair
 * ou sombre est un confort de lecture et appartient à chaque personne. Chaque
 * palette existe donc dans les deux modes.
 *
 * Les teintes d'aperçu reprennent exactement les jetons de la feuille de style :
 * l'écran de choix ne doit pas mentir sur ce qu'il propose.
 *
 * La palette par défaut est la première de la liste, et c'est la seule dont le
 * rail est clair. C'est elle qui a demandé que les surimpressions du rail —
 * survol, rubrique en service, filets — deviennent des jetons : elles étaient
 * écrites en blanc translucide dans les règles, ce qui interdisait par
 * construction toute palette dont la marque ne serait pas sombre.
 */
const PALETTES = [
  {
    key: 'lavande',
    label: 'Indigo et lavande',
    description: "Rail clair posé comme une carte, fond lavande dégradé, indigo et violet pour l'action. Titres plus gros et plus gras, angles très ronds : la palette par défaut.",
    swatches: { nav: '#fbfaff', accent: '#5b4ae8', surface: '#ffffff', bg: '#ecedfb' },
  },
  {
    key: 'institutionnel',
    label: 'Bleu institutionnel',
    description: "Bleu profond, contenu dense, angles droits. Le parti pris d'origine, sobre et lisible en toutes circonstances.",
    swatches: { nav: '#0b46d1', accent: '#0a66ff', surface: '#ffffff', bg: '#eef1f6' },
  },
  {
    key: 'moderne',
    label: 'Ardoise et indigo',
    description: 'Neutres presque sans teinte, indigo violacé pour l\'action, angles nettement plus doux : le vocabulaire des interfaces contemporaines.',
    swatches: { nav: '#242640', accent: '#5546d6', surface: '#ffffff', bg: '#f5f6fa' },
  },
  {
    key: 'vif',
    label: 'Magenta et violet',
    description: 'Couleurs franches, navigation en dégradé violet-magenta, fonds légèrement teintés. Pour une marque qui assume la couleur.',
    swatches: { nav: '#7c12c8', accent: '#cc1268', surface: '#ffffff', bg: '#fbf6fc' },
  },
];

const KEYS = PALETTES.map((p) => p.key);
const DEFAULT_KEY = 'lavande';

function isValid(key) {
  return KEYS.includes(key);
}

/** La palette de l'instance, toujours une valeur connue. */
function current() {
  const stored = settings.get('theme_palette');
  return isValid(stored) ? stored : DEFAULT_KEY;
}

function set(key) {
  if (!isValid(key)) return false;
  settings.set('theme_palette', key);
  return true;
}

function list() {
  const active = current();
  return PALETTES.map((p) => ({ ...p, active: p.key === active }));
}

function byKey(key) {
  return PALETTES.find((p) => p.key === key) || null;
}

module.exports = { PALETTES, KEYS, DEFAULT_KEY, isValid, current, set, list, byKey };
