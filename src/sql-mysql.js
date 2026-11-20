/**
 * Traduction du dialecte.
 *
 * Le schéma et les requêtes de ce produit sont écrits en SQLite — c'est la base
 * par défaut, celle qui ne demande rien à l'hébergement. Servir aussi MySQL
 * n'exige pas de réécrire mille requêtes : elles ne s'écartent de la norme
 * qu'en une poignée d'endroits, tous recensés ci-dessous.
 *
 * La traduction se fait ici, à un seul endroit, plutôt qu'aux mille points
 * d'appel. Ce qui suit dit donc aussi, noir sur blanc, ce que le produit
 * emploie de non portable — et quiconque en ajoutera devra passer par ici.
 *
 * Ce fichier est le jumeau de app/Core/Sql.php dans l'édition PHP : mêmes
 * règles, mêmes cas particuliers, afin qu'une base posée par l'une des deux
 * éditions soit lisible par l'autre. Une règle corrigée d'un côté est à
 * reporter de l'autre.
 */

/**
 * Les colonnes de texte servant de clé ou d'index ne peuvent rester TEXT :
 * MySQL refuse d'indexer un TEXT sans longueur de préfixe. 191 caractères tient
 * sous la limite de 767 octets des anciennes versions en utf8mb4.
 */
const LONGUEUR_INDEX = 191;

/**
 * Les noms de colonne que MySQL se réserve.
 *
 * La liste n'est pas devinée : les noms de colonne du schéma ont été soumis au
 * serveur un par un, et ceux-là sont les seuls qu'il refuse. Un nom ajouté plus
 * tard qui collerait à un mot réservé se signalerait par une erreur de syntaxe
 * au démarrage — il suffira alors de l'inscrire ici.
 */
const MOTS_RESERVES = ['key', 'cursor'];

/**
 * Les index partiels.
 *
 * SQLite sait n'indexer qu'une partie des lignes ; MySQL non. Les cas du schéma
 * ne se valent pas, et aucune règle générale ne les couvre :
 *
 *  • « WHERE colonne IS NOT NULL » sur une colonne de l'index n'a pas
 *    d'équivalent à écrire : MySQL laisse déjà les NULL se répéter dans un
 *    index unique. Retirer la clause rend exactement le même sens.
 *
 *  • Un filtre qui porte sur autre chose ne peut pas disparaître sans perdre la
 *    contrainte. On passe alors par une colonne calculée qui vaut NULL hors du
 *    domaine voulu — et les NULL, eux, ne se heurtent pas.
 */
const INDEX_PARTIELS = {
  idx_notifications_dedupe: {
    colonne: 'dedupe_unique',
    calcul: "NULLIF(dedupe_key, '')",
    type: 'VARCHAR(191)',
    table: 'notifications',
    index: 'user_id, dedupe_unique',
  },
  idx_software_accesses_open: {
    colonne: 'user_ouvert',
    calcul: 'IF(revoked_on IS NULL, user_id, NULL)',
    type: 'BIGINT',
    table: 'software_accesses',
    index: 'licence_id, user_ouvert',
  },
};

/**
 * « COLLATE NOCASE » demande à SQLite d'ignorer la casse. La collation des
 * tables — utf8mb4_unicode_ci — le fait déjà, et MySQL ne connaît pas ce nom.
 * La clause apparaît dans le schéma comme dans les requêtes : d'où une seule
 * fonction, appelée des deux côtés.
 */
function sansNocase(sql) {
  return sql.replace(/\s+COLLATE\s+NOCASE\b/gi, '');
}

/**
 * Les dates.
 *
 * L'heure « maintenant » de SQLite est en temps universel ; NOW() est à l'heure
 * de la session. Prendre NOW() ferait dériver les dates de plusieurs heures
 * selon le fuseau du serveur, silencieusement — d'où UTC_TIMESTAMP() et
 * UTC_DATE().
 *
 * Les modificateurs littéraux se traduisent en intervalles. Ceux passés en
 * paramètre ne le peuvent pas : une unité de temps n'est pas une valeur qu'on
 * lie. S'il en reste un, mieux vaut le dire que le laisser passer.
 */
function dates(sql) {
  if (/\b(?:date|datetime)\('now',\s*\?/i.test(sql)) {
    throw new Error(
      'Modificateur de date passé en paramètre : intraduisible en MySQL. '
      + 'Calculez la date en JavaScript et comparez-la à une valeur.'
    );
  }

  // Un décalage calculé à partir d'une colonne : « date(fin, '-' || préavis ||
  // ' days') ». Le modificateur n'est ni littéral ni lié, il est construit par
  // la requête — d'où une règle à lui.
  sql = sql.replace(
    /\bdate\(\s*(\w+)\s*,\s*'([+-])'\s*\|\|\s*(.+?)\s*\|\|\s*' days'\s*\)/gi,
    (_, colonne, signe, expression) =>
      `${signe === '-' ? 'DATE_SUB' : 'DATE_ADD'}(${colonne}, INTERVAL ${expression} DAY)`
  );

  return sql.replace(/\b(date|datetime)\('now'(?:,\s*'([^']*)')?\)/gi, (_, verbe, modificateur) => {
    const base = verbe.toLowerCase() === 'date' ? 'UTC_DATE()' : 'UTC_TIMESTAMP()';
    const m = (modificateur || '').trim();
    if (m === '') return base;
    if (m.toLowerCase() === 'start of month') return `DATE_FORMAT(${base}, '%Y-%m-01')`;

    const decalage = m.match(/^([+-])\s*(\d+)\s+(day|days|month|months|year|years|hour|hours|minute|minutes)$/i);
    if (decalage) {
      const sens = decalage[1] === '-' ? 'DATE_SUB' : 'DATE_ADD';
      const unite = decalage[3].toLowerCase().replace(/s$/, '').toUpperCase();
      return `${sens}(${base}, INTERVAL ${decalage[2]} ${unite})`;
    }
    throw new Error('Modificateur de date inconnu : ' + m);
  });
}

/**
 * L'insertion qui se rattrape.
 *
 * SQLite écrit « ON CONFLICT(colonnes) DO UPDATE SET x = excluded.x » ; MySQL,
 * « ON DUPLICATE KEY UPDATE x = VALUES(x) ». La liste de colonnes disparaît au
 * passage : MySQL retient n'importe quelle clé unique, ce qui revient au même
 * ici, chaque table n'en ayant qu'une qui puisse entrer en conflit.
 *
 * « DO NOTHING » devient « INSERT IGNORE », qui se pose sur le verbe et non à
 * la fin — d'où la reprise du début de la requête.
 */
function upsert(sql) {
  sql = sql.replace(
    /\bON\s+CONFLICT\s*\([^)]*\)(?:\s+WHERE\s+[\s\S]*?)?\s+DO\s+UPDATE\s+SET\s+/gi,
    'ON DUPLICATE KEY UPDATE '
  );

  // « excluded.x » désigne la ligne qu'on tentait d'insérer ; MySQL la nomme
  // VALUES(x).
  sql = sql.replace(/\bexcluded\.(\w+)/gi, 'VALUES($1)');

  sql = sql.replace(
    /\bINSERT\b([\s\S]*?)\bON\s+CONFLICT\s*\([^)]*\)(?:\s+WHERE\s+[\s\S]*?)?\s+DO\s+NOTHING/gi,
    (_, milieu) => 'INSERT IGNORE' + milieu.replace(/\s+$/, '')
  );

  return sql;
}

/**
 * Applique une transformation au SQL, mais jamais au contenu des chaînes.
 *
 * Un nom de colonne se réécrit ; le texte d'une valeur, non. « WHERE action =
 * 'key' » ne doit pas devenir « WHERE action = '`key`' », ce qui changerait la
 * valeur cherchée sans rien dire à personne.
 */
function horsDesChaines(sql, transformer) {
  let sortie = '';
  let hors = '';
  let delimiteur = null;

  const vider = () => { sortie += transformer(hors); hors = ''; };

  for (let i = 0; i < sql.length; i++) {
    const c = sql[i];

    if (delimiteur) {
      sortie += c;
      if (c === delimiteur) {
        if (sql[i + 1] === delimiteur) { sortie += sql[++i]; } else { delimiteur = null; }
      } else if (c === '\\' && delimiteur !== '`' && i + 1 < sql.length) {
        sortie += sql[++i];
      }
      continue;
    }

    if (c === "'" || c === '"' || c === '`') { vider(); delimiteur = c; sortie += c; continue; }
    hors += c;
  }

  vider();
  return sortie;
}

/**
 * On entoure d'accents graves les mots réservés — sans toucher aux
 * « PRIMARY KEY », « FOREIGN KEY », « UNIQUE KEY » ni « ON DUPLICATE KEY », qui
 * sont de la syntaxe et non des noms de colonne, ni au contenu des chaînes.
 */
function echapperMotsReserves(sql) {
  return horsDesChaines(sql, (morceau) => {
    for (const mot of MOTS_RESERVES) {
      morceau = morceau.replace(
        new RegExp(`(?<!\`)(?<!PRIMARY )(?<!FOREIGN )(?<!UNIQUE )(?<!DUPLICATE )(?<!\\.)\\b${mot}\\b(?!\`)(?!\\s*\\()`, 'gi'),
        '`' + mot + '`'
      );
    }
    return morceau;
  });
}

/** Une requête ordinaire, dite en MySQL. */
function requete(sql) {
  sql = dates(sql);
  sql = sansNocase(sql);

  // « max(a, b) » est en SQLite le plus grand de deux valeurs ; en MySQL, MAX
  // est un agrégat et n'en prend qu'une. Le nom diffère, pas l'idée.
  sql = sql.replace(/\bMAX\s*\(([^(),]+),\s*([^(),]+)\)/gi, 'GREATEST($1, $2)');
  sql = sql.replace(/\bMIN\s*\(([^(),]+),\s*([^(),]+)\)/gi, 'LEAST($1, $2)');

  sql = upsert(sql);

  // « INSERT OR IGNORE » n'existe pas : MySQL écrit « INSERT IGNORE ».
  sql = sql.replace(/\bINSERT\s+OR\s+IGNORE\b/gi, 'INSERT IGNORE');
  sql = sql.replace(/\bINSERT\s+OR\s+REPLACE\b/gi, 'REPLACE');

  return echapperMotsReserves(sql);
}

/**
 * Découpe un script en instructions.
 *
 * Le schéma est commenté en français, et les apostrophes de « l'édition »
 * passeraient pour des guillemets ouvrants si l'on ne sautait pas les
 * commentaires. D'où un découpage caractère par caractère plutôt qu'un
 * `split(';')`.
 */
function morceaux(sql) {
  const sorties = [];
  let courante = '';
  let dansChaine = false;
  let dansCommentaireLigne = false;
  let dansCommentaireBloc = false;

  for (let i = 0; i < sql.length; i++) {
    const c = sql[i];
    const suivant = i + 1 < sql.length ? sql[i + 1] : '';

    if (dansCommentaireLigne) {
      courante += c;
      if (c === '\n') dansCommentaireLigne = false;
      continue;
    }
    if (dansCommentaireBloc) {
      courante += c;
      if (c === '*' && suivant === '/') { courante += suivant; i++; dansCommentaireBloc = false; }
      continue;
    }
    if (dansChaine) {
      courante += c;
      if (c === "'") {
        // Deux apostrophes de suite : une apostrophe échappée.
        if (suivant === "'") { courante += suivant; i++; } else { dansChaine = false; }
      }
      continue;
    }

    if (c === '-' && suivant === '-') { dansCommentaireLigne = true; courante += c; continue; }
    if (c === '/' && suivant === '*') { dansCommentaireBloc = true; courante += c; continue; }
    if (c === "'") { dansChaine = true; courante += c; continue; }
    if (c === ';') {
      if (courante.trim() !== '') sorties.push(courante.trim());
      courante = '';
      continue;
    }
    courante += c;
  }

  if (courante.trim() !== '') sorties.push(courante.trim());
  return sorties;
}

/** Les colonnes que le schéma indexe, par table. */
function colonnesIndexees(sql) {
  const parTable = {};
  const ajouter = (table, colonne) => {
    const t = table.toLowerCase();
    if (!parTable[t]) parTable[t] = new Set();
    parTable[t].add(colonne.toLowerCase());
  };

  // Les index déclarés à part.
  const index = /CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:IF\s+NOT\s+EXISTS\s+)?[^\s(]+\s+ON\s+(\w+)\s*\(([^)]*)\)/gi;
  for (const m of sql.matchAll(index)) {
    for (let colonne of m[2].split(',')) {
      // « ON t(a, b DESC) » : le sens du tri n'est pas un nom de colonne, et le
      // garder ferait manquer la colonne — qui resterait alors en TEXT, que
      // MySQL refuse d'indexer.
      colonne = colonne.trim().replace(/\s+(ASC|DESC)\s*$/i, '');
      ajouter(m[1], colonne.replace(/^[\s`]+|[\s`]+$/g, ''));
    }
  }

  // Les contraintes posées dans la table même.
  const tables = /CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)\s*\(([\s\S]*?)\n\)/gi;
  for (const m of sql.matchAll(tables)) {
    for (const ligne of m[2].split('\n')) {
      const nue = ligne.trim();
      const texte = nue.match(/^(\w+)\s+TEXT\b.*\b(?:PRIMARY\s+KEY|UNIQUE)\b/i);
      if (texte) ajouter(m[1], texte[1]);
      const contrainte = nue.match(/^(?:UNIQUE|PRIMARY\s+KEY)\s*\(([^)]*)\)/i);
      if (contrainte) {
        for (const colonne of contrainte[1].split(',')) {
          ajouter(m[1], colonne.replace(/^[\s`]+|[\s`]+$/g, ''));
        }
      }
    }
  }

  return parTable;
}

function sansCommentaireDeTete(instruction) {
  return instruction.replace(/^(?:\s*--[^\n]*\n)+/, '').trim();
}

function traduireIndex(instruction) {
  // MySQL ne connaît pas « CREATE INDEX IF NOT EXISTS » ; MariaDB si. On retire
  // la clause, l'appelant rattrapant le doublon.
  instruction = instruction.replace(/\bIF\s+NOT\s+EXISTS\s+/gi, '');
  if (!/\bWHERE\b/i.test(instruction)) return instruction;

  const nom = (instruction.match(/INDEX\s+(\w+)\s+ON/i) || [])[1] || '';
  const cas = INDEX_PARTIELS[nom];

  if (!cas) {
    // Le filtre ne porte que sur des colonnes de l'index : la clause tombe
    // d'elle-même. Si elle portait sur autre chose, il faudrait l'inscrire
    // ci-dessus plutôt que de perdre la contrainte.
    return instruction.replace(/\s+WHERE\b[\s\S]*$/i, '');
  }

  return `ALTER TABLE ${cas.table} ADD COLUMN ${cas.colonne} ${cas.type} AS (${cas.calcul}) VIRTUAL;\n`
    + `CREATE UNIQUE INDEX ${nom} ON ${cas.table}(${cas.index})`;
}

function ligneDeTable(ligne, indexees) {
  // Un commentaire n'est pas du code : le traduire changerait le texte qui
  // explique la table, parfois jusqu'à le rendre faux.
  if (/^\s*--/.test(ligne)) return ligne;

  // L'auto-incrément : en MySQL il fait partie de la définition de clé.
  //
  // Et INTEGER devient BIGINT, non INT : l'INTEGER de SQLite tient sur 64 bits,
  // celui de MySQL sur 32. Le produit y range des instants en millisecondes —
  // 1 789 443 820 986 pour l'expiration d'une session — qui déborderaient d'un
  // INT sans que personne ne le voie, sinon par des sessions jamais retrouvées.
  ligne = ligne.replace(/\bINTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT\b/gi, 'BIGINT AUTO_INCREMENT PRIMARY KEY');
  ligne = ligne.replace(/\bINTEGER\b/gi, 'BIGINT');

  // Une valeur par défaut calculée : même traitement que dans les requêtes,
  // temps universel compris.
  ligne = sansNocase(dates(ligne));

  // Une colonne de texte indexée doit annoncer sa longueur.
  const colonne = ligne.match(/^\s*(\w+)\s+TEXT\b/i);
  if (colonne && indexees.has(colonne[1].toLowerCase())) {
    ligne = ligne.replace(/\bTEXT\b/i, `VARCHAR(${LONGUEUR_INDEX})`);
  }

  return echapperMotsReserves(ligne);
}

function instructionSchema(instruction, indexees) {
  instruction = instruction.trim();
  if (instruction === '') return null;

  // Le schéma est commenté : chaque table est précédée de ce qu'elle sert. Les
  // commentaires font partie de l'instruction et masqueraient le mot-clé si
  // l'on regardait seulement son premier mot.
  const corps = sansCommentaireDeTete(instruction);
  if (corps === '' || /^PRAGMA\b/i.test(corps)) return null;

  if (/^CREATE\s+(UNIQUE\s+)?INDEX/i.test(corps)) return traduireIndex(corps);

  // Les colonnes ajoutées après coup passent par les mêmes règles de type que
  // celles du schéma : « ADD COLUMN x INTEGER » doit devenir « INT » ici aussi.
  if (/^ALTER\s+TABLE/i.test(corps)) return ligneDeTable(corps, new Set());

  // « DROP INDEX i » suffit à SQLite ; MySQL veut savoir sur quelle table.
  // Personne ne le lui dit, donc on rend l'instruction telle quelle et
  // l'appelant, qui l'entoure déjà d'un try, laissera l'index en place.
  if (!/^CREATE\s+TABLE/i.test(corps)) return requete(instruction);

  const nom = (corps.match(/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(\w+)/i) || [])[1] || '';
  const colonnes = indexees[nom.toLowerCase()] || new Set();

  let sortie = instruction.split('\n').map((ligne) => ligneDeTable(ligne, colonnes)).join('\n');

  // Le schéma est rejoué à chaque démarrage : une table déjà là n'est pas une
  // erreur. C'est ici que la clause se pose, et non chez l'appelant, qui aurait
  // à chercher le mot-clé sous le commentaire de la table.
  sortie = sortie.replace(/\bCREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS)/i, 'CREATE TABLE IF NOT EXISTS ');

  // InnoDB et utf8mb4 : les clés étrangères et les seize langues.
  return sortie.replace(/\s+$/, '') + ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
}

/**
 * Le schéma, dit en MySQL.
 *
 * Deux difficultés : l'auto-incrément ne s'écrit pas pareil, et une colonne de
 * texte indexée doit annoncer sa longueur. On relève donc d'abord quelles
 * colonnes sont indexées — clé primaire, contrainte d'unicité, index déclaré —
 * avant de choisir leur type.
 */
function schema(sql) {
  const indexees = colonnesIndexees(sql);
  const sorties = [];
  for (const instruction of morceaux(sql)) {
    const traduite = instructionSchema(instruction, indexees);
    if (traduite !== null) sorties.push(traduite);
  }
  return sorties;
}

module.exports = { requete, schema, morceaux, LONGUEUR_INDEX };
