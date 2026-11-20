const tokens = require('../api-tokens');

/**
 * Le catalogue de l'API, en un seul endroit.
 *
 * Chaque fonction se déclare une fois : sa méthode, son chemin, la portée
 * qu'elle exige, ce qu'elle fait, ce qu'elle prend, ce qu'elle rend, et le code
 * qui l'exécute. Tout le reste en découle — le routeur se construit à partir
 * d'ici, la découverte (`GET /api/v1/`) le récite, le document OpenAPI le
 * traduit, et la documentation du site engendre sa page de référence avec.
 *
 * Ce n'est pas une élégance : c'est la seule façon qu'une API rendue publique
 * ne mente pas sur elle-même. Une route ajoutée à côté du manifeste n'est pas
 * montée du tout, et un test le vérifie — mieux vaut une fonction absente
 * qu'une fonction non documentée qu'on découvre en production.
 */

const GROUPES = [
  require('./annuaire'),
  require('./rh'),
  require('./gestion'),
  require('./projets'),
  require('./pilotage'),
  require('./documents'),
  require('./exploitation'),
];

/** Une portée d'écriture se reconnaît à son suffixe, jamais à un drapeau posé à la main. */
const estEcriture = (fonction) => String(fonction.portee).endsWith(tokens.WRITE_SUFFIX);

const TOUTES = GROUPES.flatMap((groupe) => groupe.fonctions.map((fonction) => ({
  ...fonction,
  groupe: groupe.cle,
  ecrit: estEcriture(fonction),
  parametres: fonction.parametres || [],
  rend: fonction.rend || [],
})));

/** Le chemin complet, tel qu'il s'appelle : `/api/v1/collaborateurs`. */
const BASE = '/api/v1';
const cheminComplet = (fonction) => BASE + fonction.chemin;

/** La clé d'une fonction dans les journaux et les tests : « GET /collaborateurs ». */
const cle = (fonction) => `${fonction.methode} ${fonction.chemin}`;

/**
 * Le catalogue tel que `GET /api/v1/` le rend, filtré par ce que le jeton peut.
 *
 * Un jeton qui n'a que l'annuaire ne doit pas apprendre, en lisant la racine,
 * quelles routes de paie existent : la découverte se tait sur ce qu'elle
 * refuserait de servir.
 */
function pourJeton(jeton) {
  return GROUPES.map((groupe) => ({
    groupe: groupe.cle,
    titre: groupe.titre,
    resume: groupe.resume,
    fonctions: groupe.fonctions
      .filter((f) => tokens.allows(jeton, f.portee))
      .map((f) => ({
        methode: f.methode,
        chemin: cheminComplet(f),
        portee: f.portee,
        ecrit: estEcriture(f),
        resume: f.resume,
      })),
  })).filter((groupe) => groupe.fonctions.length > 0);
}

module.exports = { BASE, GROUPES, TOUTES, estEcriture, cheminComplet, cle, pourJeton };
