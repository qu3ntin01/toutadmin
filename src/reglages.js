/**
 * Les réglages de déploiement.
 *
 * Deux sources : les variables d'environnement, et le fichier que l'assistant
 * d'installation écrit dans le dossier de données. L'environnement l'emporte —
 * c'est la convention des conteneurs, et c'est aussi ce qui permet de corriger
 * une instance sans toucher à son disque.
 *
 * Le fichier n'est pas versionné : il porte le mot de passe de la base.
 */
const fs = require('fs');
const path = require('path');

const FICHIER = 'instance.json';

function dossier() {
  return path.dirname(process.env.DB_PATH || path.join(__dirname, '..', 'data', 'app.sqlite'));
}

function chemin() {
  return path.join(dossier(), FICHIER);
}

function lus() {
  try {
    const brut = fs.readFileSync(chemin(), 'utf8');
    const objet = JSON.parse(brut);
    return objet && typeof objet === 'object' ? objet : {};
  } catch {
    // Absent, illisible, ou mal formé : on repart des variables d'environnement.
    return {};
  }
}

// Relu une fois par processus : ces réglages ne changent pas en cours de route,
// et les relire à chaque requête coûterait un accès disque par appel.
let cache = null;

function tous() {
  if (cache === null) cache = lus();
  return cache;
}

/** Une variable d'environnement, ou à défaut ce que l'assistant a écrit. */
function get(cle, defaut = undefined) {
  const env = process.env[cle];
  if (env !== undefined && env !== '') return env;
  const fichier = tous()[cle];
  return fichier !== undefined && fichier !== '' ? fichier : defaut;
}

/** Écrit les réglages de l'instance, lisibles du seul propriétaire. */
function ecrire(valeurs) {
  const cible = chemin();
  fs.mkdirSync(path.dirname(cible), { recursive: true, mode: 0o700 });
  fs.writeFileSync(cible, JSON.stringify(valeurs, null, 2) + '\n', { mode: 0o600 });
  cache = { ...valeurs };
  return cible;
}

function oublier() {
  cache = null;
}

module.exports = { get, tous, ecrire, oublier, chemin };
