#!/usr/bin/env node
/* =========================================================================
   L'espace insécable française, posée et vérifiée.

   ── Pourquoi ce fichier existe ──────────────────────────────────────────

   Le français met une espace devant «  ; : ? ! » et à l'intérieur des
   guillemets. Une espace ordinaire à cet endroit est un endroit où la ligne
   peut se couper — et elle se coupe. Sur la carte des accès, la phrase

       C'est la réponse à « si cette personne part demain, qui lit ces
       demandes ? ».

   rendait un « ». » solitaire en début de troisième ligne. Ce n'est pas une
   faute d'orthographe, c'est une faute de composition, et elle se voit
   d'autant plus qu'elle tombe sur la capture que le site montre pour vendre
   l'écran.

   Aucun des seize fichiers de langue n'avait jusqu'ici la moindre espace
   insécable : zéro U+00A0, zéro U+202F. Ce script en pose, et le test
   « typographie » veille à ce qu'on n'en reperde pas.

   ── Quelle espace, et pourquoi celle-là ─────────────────────────────────

   U+00A0, l'espace insécable ordinaire, et non U+202F, l'espace fine que
   l'Imprimerie nationale prescrit devant «  ; ? ! ».

   La fine est plus juste à l'œil. Elle est aussi absente de beaucoup de
   polices, où elle se rend en rectangle vide ou disparaît ; et ces chaînes
   ne vont pas qu'à l'écran — elles entrent dans des PDF générés par le
   produit (parapheur, Factur-X), dans des courriels, dans des exports. Une
   espace présente partout et un peu large vaut mieux qu'une espace juste
   qui manque une fois sur dix. U+00A0 est d'ailleurs la bonne espace devant
   les deux-points, qui est le cas le plus fréquent ici.

   ── Ce qu'il ne touche pas ──────────────────────────────────────────────

   Les commentaires, le code, et tout ce qui n'est pas une chaîne de
   caractères. Un « a ? b : c » n'est pas de la typographie française, et
   une espace insécable dans un ternaire serait au mieux invisible, au pire
   une erreur de syntaxe. Le fichier est donc lu comme du JavaScript, pas
   comme du texte : on ne transforme que l'intérieur des chaînes.

   Les autres langues ne sont pas touchées non plus. L'allemand, l'anglais,
   l'arabe et le japonais collent leur ponctuation ; leur poser une espace,
   fût-elle insécable, serait y introduire la faute qu'on corrige ici.

   ── Usage ───────────────────────────────────────────────────────────────

       node typographie.js              # dit ce qui manque
       node typographie.js --corriger   # le pose

   « node build.js » l'appelle avant d'écrire quoi que ce soit : un site
   qu'on publie avec une espace sécable devant un point d'interrogation n'a
   pas à être reconstruit une seconde fois pour s'en apercevoir.
   ========================================================================= */

const fs = require('fs');
const path = require('path');

/** L'espace insécable. Voir plus haut pourquoi celle-ci et pas la fine. */
const INSECABLE = ' ';

/**
 * Les signes qui veulent une espace insécable avant eux.
 *
 * Le tiret cadratin n'y est pas : il s'emploie sans espace insécable, et une
 * ligne qui casse devant un tiret d'incise ne choque personne.
 */
const AVANT = [';', '?', '!', '»', '%', '€'];

/** Et celui qui la veut après lui. */
const APRES = '«';

/**
 * Le fichier de langue française, et lui seul.
 *
 * Les quinze autres suivent chacune ses propres règles, et la plupart
 * collent leur ponctuation. Le chemin est relatif à la racine du dépôt.
 */
const FICHIER = 'content/fr.js';

/**
 * Poser les espaces insécables dans un texte déjà isolé de son code.
 *
 * Idempotent : une espace déjà insécable est laissée telle quelle, et deux
 * passages donnent le même résultat qu'un seul.
 */
function poser(texte) {
  /*
   * Un garde-fou, pour le jour où l'on pointera l'outil ailleurs.
   *
   * Les deux règles ci-dessous écartent « = ? » et « (:client) ». Elles
   * n'écartent pas « LIMIT ? » ni « LIKE ? », dont le point d'interrogation
   * est précédé d'un mot, exactement comme une question française — aucune
   * règle de ponctuation ne saurait les distinguer. La seule défense est de
   * ne jamais présenter à cet outil un texte qui contient une requête.
   *
   * « <select name=… » n'en est pas une : le motif exige un FROM après le
   * SELECT plutôt que le seul mot.
   */
  if (/\b(?:INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+[A-Za-z_]+\s+SET|SELECT\b[\s\S]{0,300}?\bFROM)\b/i.test(texte)) {
    throw new Error(`Texte contenant une requête SQL — l’outil refuse d’y toucher : ${texte.trim().slice(0, 80)}`);
  }

  /*
   * Deux précautions, et elles ne sont pas décoratives.
   *
   * 1. Ce qui précède l'espace doit être un mot, un chiffre, ou ce qui ferme
   *    un mot — une parenthèse, un guillemet, un point. En français c'est
   *    toujours le cas. En SQL, « VALUES (?, ?) » et « WHERE id = ? » ont un
   *    point d'interrogation précédé d'une virgule, d'une parenthèse ou d'un
   *    signe égal : ce sont des paramètres, pas des questions, et une espace
   *    insécable y donne une requête que la base refuse.
   *
   * 2. Le deux-points ne doit pas être suivi d'une lettre. « = :client » est
   *    un paramètre nommé ; en français, le deux-points est suivi d'une
   *    espace, ou termine le texte.
   *
   * Sans ces deux règles, l'outil casse silencieusement toute requête du
   * dépôt où il passe — rien dans la syntaxe du langage ne s'en plaint, et
   * cela ne se voit qu'à l'exécution.
   */
  const AVANT_OK = '[\\p{L}\\p{N})}»’\'".…!?%€]';
  let sortie = texte;
  for (const signe of AVANT) {
    const echappe = signe.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    sortie = sortie.replace(new RegExp(`(?<=${AVANT_OK}) ${echappe}`, 'gu'), `${INSECABLE}${signe}`);
  }
  sortie = sortie.replace(new RegExp(`(?<=${AVANT_OK}) :(?![A-Za-z0-9_])`, 'gu'), `${INSECABLE}:`);
  return sortie.split(`${APRES} `).join(`${APRES}${INSECABLE}`);
}

/**
 * Relire un fichier JavaScript et n'appliquer « poser » qu'aux chaînes.
 *
 * Un vrai analyseur serait plus court à écrire qu'à relire ; celui-ci tient
 * en trente lignes parce qu'il n'a qu'un travail : savoir si l'on est dans
 * une chaîne, dans un commentaire, ou ailleurs. Il connaît les trois
 * guillemets de JavaScript et l'échappement par barre oblique inverse.
 */
function transformerJs(source) {
  let sortie = '';
  let i = 0;
  const n = source.length;

  while (i < n) {
    const c = source[i];
    const suivant = source[i + 1];

    // Un commentaire de fin de ligne : recopié tel quel.
    if (c === '/' && suivant === '/') {
      const fin = source.indexOf('\n', i);
      const borne = fin === -1 ? n : fin;
      sortie += source.slice(i, borne);
      i = borne;
      continue;
    }

    // Un commentaire de bloc : recopié tel quel, lui aussi.
    if (c === '/' && suivant === '*') {
      const fin = source.indexOf('*/', i + 2);
      const borne = fin === -1 ? n : fin + 2;
      sortie += source.slice(i, borne);
      i = borne;
      continue;
    }

    // Une chaîne : c'est le seul endroit qu'on transforme.
    if (c === "'" || c === '"' || c === '`') {
      const ouvrant = c;
      let j = i + 1;
      let contenu = '';
      while (j < n) {
        if (source[j] === '\\') {
          contenu += source[j] + (source[j + 1] || '');
          j += 2;
          continue;
        }
        if (source[j] === ouvrant) break;
        contenu += source[j];
        j += 1;
      }
      sortie += ouvrant + poser(contenu) + ouvrant;
      i = j + 1;
      continue;
    }

    sortie += c;
    i += 1;
  }

  return sortie;
}

/**
 * Ce qui reste à corriger : une ligne par endroit, avec son numéro.
 *
 * Le témoin est la liste, pas un booléen : un test qui dit « la typographie
 * n'est pas bonne » sans dire où ne sert à rien.
 */
function verifier(racine = __dirname) {
  const chemin = path.join(racine, FICHIER);
  const source = fs.readFileSync(chemin, 'utf8');
  const corrige = transformerJs(source);
  if (corrige === source) return [];

  const avant = source.split('\n');
  const apres = corrige.split('\n');
  const manques = [];
  for (let l = 0; l < avant.length; l += 1) {
    if (avant[l] !== apres[l]) manques.push({ ligne: l + 1, texte: avant[l].trim() });
  }
  return manques;
}

function corriger(racine = __dirname) {
  const chemin = path.join(racine, FICHIER);
  const source = fs.readFileSync(chemin, 'utf8');
  const corrige = transformerJs(source);
  if (corrige !== source) fs.writeFileSync(chemin, corrige);
  return source.split('').filter((c) => c === INSECABLE).length !== corrige.split('').filter((c) => c === INSECABLE).length;
}

module.exports = { poser, transformerJs, verifier, corriger, INSECABLE, FICHIER };

if (require.main === module) {
  if (process.argv.includes('--corriger')) {
    corriger();
    const reste = verifier();
    console.log(reste.length === 0
      ? `${FICHIER} : espaces insécables posées.`
      : `${FICHIER} : ${reste.length} ligne(s) résistent encore.`);
  } else {
    const manques = verifier();
    if (manques.length === 0) {
      console.log(`${FICHIER} : typographie française conforme.`);
    } else {
      console.error(`${FICHIER} : ${manques.length} ligne(s) sans espace insécable.`);
      for (const m of manques.slice(0, 20)) console.error(`  ${m.ligne}  ${m.texte.slice(0, 110)}`);
      if (manques.length > 20) console.error(`  … et ${manques.length - 20} autre(s).`);
      console.error('Posez-les : node typographie.js --corriger');
      process.exit(1);
    }
  }
}
