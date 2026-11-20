const fs = require('fs');
const path = require('path');

const PUBLIC_DIR = path.join(__dirname, '..', 'public');
const stamps = new Map();

/**
 * L'adresse d'un fichier statique, suivie de son empreinte.
 *
 * Une mise à jour remplace la feuille de style ; le navigateur, lui, garde
 * l'ancienne — les fichiers de public/ sont servis avec « maxAge: 1d » en
 * production, et un proxy en rajoute souvent. Le HTML tout neuf se retrouve
 * alors peint avec la CSS d'avant : l'écran est cassé sans que rien ne soit en
 * panne, et personne ne pense à vider un cache.
 *
 * Le nom du fichier ne change pas ; son adresse, si. La date de dernière
 * écriture suffit : elle change à chaque pose de fichier et à aucun autre
 * moment, ce qui rend le cache long légitime au lieu de dangereux.
 *
 * Les empreintes sont relevées une fois par démarrage : une mise à jour pose
 * les fichiers puis relance le service, et c'est au redémarrage qu'elles sont
 * relues.
 */
function asset(url) {
  if (!stamps.has(url)) {
    let stamp = '';
    try {
      stamp = String(Math.floor(fs.statSync(path.join(PUBLIC_DIR, url)).mtimeMs));
    } catch {
      // Fichier absent : on rend l'adresse telle quelle plutôt que d'échouer
      // au rendu d'une page pour une ressource manquante.
    }
    stamps.set(url, stamp);
  }
  const stamp = stamps.get(url);
  return stamp ? `${url}?v=${stamp}` : url;
}

/** À appeler après une mise à jour posée sans redémarrage. */
function forget() {
  stamps.clear();
}

module.exports = { asset, forget };
