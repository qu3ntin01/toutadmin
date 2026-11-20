/**
 * La clé publique de l'éditeur, pour les licences.
 *
 * Elle est ici, dans le code, et non dans les réglages — c'est tout le point.
 * Une clé publique rangée en base serait modifiable depuis un écran : il
 * suffirait d'y coller la sienne pour se signer une licence perpétuelle. Celle
 * des mises à jour est un réglage parce qu'une instance peut légitimement
 * suivre un autre éditeur ; une licence, non.
 *
 * Elle se remplace à la publication d'une version, par « php tools/cles.php
 * --cms » sur la machine qui héberge le service de licences, qui écrit ce fichier
 * et son équivalent PHP. Tant qu'elle vaut la valeur d'usine ci-dessous,
 * l'instance se sait non distribuée et ne restreint rien : c'est ce qui permet
 * de développer et de faire tourner les tests sans licence.
 */

// Remplacée à la publication. Voir « php tools/cles.php --cms ».
const PEM = '';

/** Sans clé posée, rien n'est vérifiable : l'instance n'est pas une instance vendue. */
function distribue() {
  return PEM.trim() !== '';
}

module.exports = { PEM, distribue };
