/**
 * Ce qui ne sort pas, même quand l'assistance est activée.
 *
 * Activer l'assistance, c'est accepter qu'un texte de l'entreprise parte chez un
 * service tiers — ou vers un modèle posé sur le réseau interne, ce que l'écran
 * propose aussi. Dans les deux cas, certaines suites de caractères n'ont aucune
 * raison de faire le voyage : elles ne servent pas à résumer un fil de support
 * ni à relever les décisions d'une réunion, et leur fuite ne se répare pas.
 *
 * Ce module est le seul endroit qui les retire. Un appel d'assistance passe par
 * ici ou ne passe pas : le socle (« ai.js ») ne sait pas envoyer autrement.
 *
 * Le filtre est une liste d'autorisation inversée : tout est retiré, et une
 * fonction déclare ce dont elle a besoin. La lecture d'une facture, par exemple,
 * a besoin de l'IBAN et du SIRET — c'est précisément ce qu'elle extrait, et
 * l'administrateur l'a activée en sachant qu'un document part. Le résumé d'un
 * ticket, lui, n'a besoin d'aucun des deux, et ne les verra donc jamais.
 *
 * Ce que ce module NE fait pas, et qu'il ne faut pas lui demander : décider
 * qu'un champ ne doit pas sortir. Le motif d'une absence, par exemple, ne se
 * reconnaît à aucun motif de caractères — c'est une colonne. La règle est donc
 * tenue là où le texte est composé, et un test la vérifie à cet endroit.
 */

/** Le chiffre de contrôle de Luhn, pour ne pas prendre une référence de facture pour une carte. */
function luhn(chiffres) {
  let somme = 0;
  let doubler = false;
  for (let i = chiffres.length - 1; i >= 0; i -= 1) {
    let n = Number(chiffres[i]);
    if (doubler) {
      n *= 2;
      if (n > 9) n -= 9;
    }
    somme += n;
    doubler = !doubler;
  }
  return somme % 10 === 0;
}

/**
 * Les motifs, et ce qu'on met à la place.
 *
 * Le remplacement dit ce qui a été retiré plutôt que de laisser un blanc : un
 * modèle qui lit « [IBAN retiré] » comprend qu'il y avait là une coordonnée
 * bancaire, et ne se met pas à chercher ailleurs ce qui lui manque.
 */
const MOTIFS = [
  {
    cle: 'iban',
    quoi: 'Coordonnées bancaires',
    // Deux lettres de pays, deux chiffres de contrôle, puis de dix à trente
    // caractères, avec ou sans les espaces de présentation.
    motif: /\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){10,30}\b/g,
    remplacer: () => '[IBAN retiré]',
  },
  {
    cle: 'carte',
    quoi: 'Numéro de carte bancaire',
    motif: /\b(?:\d[ -]?){12,18}\d\b/g,
    // Sans Luhn, ce motif mangerait les numéros de commande et les références.
    garder: (trouve) => {
      const chiffres = trouve.replace(/\D/g, '');
      return chiffres.length < 13 || chiffres.length > 19 || !luhn(chiffres);
    },
    remplacer: () => '[numéro de carte retiré]',
  },
  {
    cle: 'nir',
    quoi: 'Numéro de sécurité sociale',
    // Un NIR commence par 1 ou 2, puis treize chiffres, parfois suivis de la
    // clé à deux chiffres. C'est la donnée la plus sensible qu'un document du
    // personnel puisse porter, et aucune fonction d'assistance n'en a besoin.
    motif: /\b[12][ ]?\d{2}[ ]?\d{2}[ ]?\d{2}[ ]?\d{3}[ ]?\d{3}(?:[ ]?\d{2})?\b/g,
    remplacer: () => '[numéro de sécurité sociale retiré]',
  },
  {
    cle: 'autorisation',
    quoi: 'En-tête d’autorisation',
    motif: /\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{12,}/gi,
    remplacer: () => '[autorisation retirée]',
  },
  {
    cle: 'cle_api',
    quoi: 'Clés et jetons',
    // Les préfixes qu'on connaît : celui d'Anthropic, celui des services
    // compatibles OpenAI, et « sm_ », celui des jetons d'API du produit.
    motif: /\b(?:sk-ant-|sk-|sm_)[A-Za-z0-9_-]{16,}/g,
    remplacer: () => '[clé retirée]',
  },
  {
    cle: 'jeton_licence',
    quoi: 'Jeton de licence',
    // Le jeton de licence, comme un JWT, est fait de segments base64url
    // séparés par un point. Il ne quitte jamais l'instance, nulle part.
    motif: /\b[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{10,})?\b/g,
    /*
     * « sous-domaine-tres-long.exemple-tres-long-aussi » a la même forme qu'un
     * jeton, et n'en est pas un. On laisse donc passer ce qui est tout en
     * minuscules et sans souligné : un nom d'hôte, un fichier, un chemin. Une
     * signature base64url de vingt caractères sans une seule majuscule ni un
     * seul souligné est possible en théorie ; en pratique, jamais.
     */
    garder: (trouve) => !/[A-Z_]/.test(trouve),
    remplacer: () => '[jeton retiré]',
  },
];

/** Ce qu'une fonction peut demander à garder, pour l'écran qui l'explique. */
const CLES = MOTIFS.map((m) => m.cle);

/**
 * Retire de « texte » tout ce qui ne doit pas sortir, sauf ce que la fonction
 * déclare avoir besoin de garder.
 *
 * Rend le texte nettoyé et le compte de ce qui a été retiré, par motif : le
 * journal l'écrit, et l'écran peut le dire à la personne — « trois coordonnées
 * bancaires retirées avant l'envoi » vaut mieux qu'un silence.
 */
function nettoyer(texte, { garder = [] } = {}) {
  let sortie = String(texte || '');
  const retires = {};

  for (const motif of MOTIFS) {
    if (garder.includes(motif.cle)) continue;

    let compte = 0;
    sortie = sortie.replace(motif.motif, (trouve) => {
      if (motif.garder && motif.garder(trouve)) return trouve;
      compte += 1;
      return motif.remplacer(trouve);
    });
    if (compte) retires[motif.cle] = compte;
  }

  return { texte: sortie, retires };
}

/** Le compte total, pour une phrase courte. */
const combien = (retires) => Object.values(retires).reduce((total, n) => total + n, 0);

module.exports = { MOTIFS, CLES, nettoyer, combien, luhn };
