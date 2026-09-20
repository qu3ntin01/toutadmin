#!/usr/bin/env node
/* =========================================================================
   Les chiffres de la page d'accueil, relevés dans le code.

   ── Pourquoi ce fichier existe ──────────────────────────────────────────

   La page d'accueil annonce six nombres : des espaces métier, des langues,
   des tests, des modules, des libellés traduits. Ils étaient écrits à la
   main, avec un commentaire honnête qui disait quand ils avaient été relevés
   et comment les refaire. Cela n'a pas suffi : le produit a grossi, la page
   est restée. Elle annonçait mille sept cent quarante-six tests quand il y
   en avait mille sept cent quatre-vingt-onze, et trois mille deux cent
   quatorze libellés quand il y en avait trois mille deux cent soixante-dix-
   neuf. Personne n'avait menti ; les chiffres avaient simplement vieilli,
   comme vieillit tout nombre recopié.

   Un chiffre invérifiable sur une page de vente est pire qu'un chiffre
   absent : il se vérifie, justement, et il ne tient pas.

   ── Comment il marche ───────────────────────────────────────────────────

       TOUTADMIN_SOURCE=/chemin/vers/la/branche/toutadmin \
       TOUTADMIN_PHP_SOURCE=/chemin/vers/la/branche/version-web \
         node chiffres.js

   Il écrit `chiffres.json`, qui est versionné avec le site. La construction
   lit ce fichier et ne touche jamais au code du produit : le site se
   construit tout seul, sur une machine qui n'a pas les deux éditions sous la
   main, et le jour où les chiffres bougent c'est une modification visible
   dans l'historique plutôt qu'un chiffre qui dérive en silence.

   Les définitions sont celles du site de documentation, qui compte les mêmes
   choses depuis sa propre branche. Si les deux divergent un jour, c'est
   qu'une des deux a tort, et la comparaison le dira.
   ========================================================================= */

const fs = require('fs');
const path = require('path');

const NODE = process.env.TOUTADMIN_SOURCE;
const PHP = process.env.TOUTADMIN_PHP_SOURCE;

if (!NODE || !PHP) {
  console.error('Il faut les deux éditions :');
  console.error('  TOUTADMIN_SOURCE=… TOUTADMIN_PHP_SOURCE=… node chiffres.js');
  process.exit(1);
}

/** Les cas de test de l'édition Node : un « test( » ou un « t.test( » par cas. */
function testsNode(racine) {
  const dossier = path.join(racine, 'tests');
  let cas = 0;
  for (const fichier of fs.readdirSync(dossier).filter((f) => f.endsWith('.js'))) {
    const source = fs.readFileSync(path.join(dossier, fichier), 'utf8');
    cas += (source.match(/^\s*(?:await\s+)?test\(/gm) || []).length;
    cas += (source.match(/^\s*(?:await\s+)?t\.test\(/gm) || []).length;
  }
  return cas;
}

/** Ceux de l'édition PHP : un « Tests::run( » par cas. */
function testsPhp(racine) {
  const dossier = path.join(racine, 'tests');
  let cas = 0;
  for (const fichier of fs.readdirSync(dossier).filter((f) => f.startsWith('test_') && f.endsWith('.php'))) {
    cas += (fs.readFileSync(path.join(dossier, fichier), 'utf8').match(/^Tests::run\(/gm) || []).length;
  }
  return cas;
}

/*
 * Le catalogue et les modules se lisent en exécutant les fichiers du produit
 * plutôt qu'en les relisant à l'expression régulière : le catalogue se
 * construit en partie par calcul, et le compter au texte donnait cinquante et
 * un là où le produit en sert quarante-quatre. Ce script-ci a les deux
 * éditions sous la main par définition ; la construction du site, elle, ne
 * lira que le JSON.
 */
const sections = require(path.join(NODE, 'src', 'sections.js'));
const modules = require(path.join(NODE, 'src', 'modules.js'));
const fr = require(path.join(NODE, 'src', 'locales', 'fr.js'));
const langues = fs.readdirSync(path.join(NODE, 'src', 'locales')).filter((f) => f.endsWith('.js'));

const chiffres = {
  // Les sections du catalogue : ce que le produit appelle lui-même un espace,
  // et ce que le rail montre. C'est le seul nombre que le visiteur peut
  // recompter à l'écran.
  espaces: (sections.CATALOGUE || sections).length,
  langues: langues.length,
  tests: testsNode(NODE) + testsPhp(PHP),
  modules: modules.KEYS.length,
  libelles: Object.keys(fr).length,
  /*
   * Zéro service extérieur. Ce n'est pas un comptage : c'est une promesse, et
   * elle se vérifie autrement — aucune file d'attente, aucun cache réparti,
   * aucun moteur de recherche à côté. Une machine, une base. Le jour où cela
   * change, ce zéro doit changer à la main, et c'est bien ainsi.
   */
  services: 0,
  releve: new Date().toISOString().slice(0, 10),
};

const sortie = path.join(__dirname, 'chiffres.json');
fs.writeFileSync(sortie, `${JSON.stringify(chiffres, null, 2)}\n`);

console.log('chiffres.json :');
for (const [nom, valeur] of Object.entries(chiffres)) {
  console.log(`  ${nom.padEnd(10)} ${valeur}`);
}
