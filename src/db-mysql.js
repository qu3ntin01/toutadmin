/**
 * MySQL sous les traits de better-sqlite3.
 *
 * Le produit interroge sa base par `db.prepare(sql).get()` en mille cent
 * soixante et un endroits. Ce module rend la même forme — prepare, get, all,
 * run, transaction, exec — au-dessus d'une connexion MySQL synchrone. Aucun de
 * ces mille appels ne change.
 *
 * Deux traductions ont lieu ici : le dialecte (sql-mysql.js) et les paramètres
 * nommés « @nom », que better-sqlite3 accepte et que MySQL ne connaît pas.
 * L'ordre compte — les noms deviennent des « ? » avant que le dialecte passe,
 * sans quoi « @cursor » se ferait échapper comme un mot réservé.
 */
const { ClientSynchrone } = require('./sync-mysql');
const dialecte = require('./sql-mysql');
const reglages = require('./reglages');

/** Les réglages de connexion : l'environnement, ou ce que l'assistant a écrit. */
function configuration(depuis = null) {
  const lire = depuis
    ? (cle, defaut) => (depuis[cle] !== undefined && depuis[cle] !== '' ? depuis[cle] : defaut)
    : (cle, defaut) => reglages.get(cle, defaut);

  const hote = lire('DB_HOST', '127.0.0.1');
  const base = {
    user: lire('DB_USER', 'root'),
    password: lire('DB_PASSWORD', ''),
    database: lire('DB_NAME', 'toutadmin'),
    charset: 'utf8mb4',
    // Le produit écrit du SQL SQLite traduit : les guillemets doubles y
    // désignent des identifiants, et « || » y concatène. ANSI_QUOTES et
    // PIPES_AS_CONCAT le disent à MySQL une fois pour toutes.
    //
    // Le reste du mode strict est gardé : une valeur tronquée en silence est
    // exactement ce qu'on ne veut pas d'une base de paie.
    ...(String(hote).startsWith('/')
      ? { socketPath: hote }
      : { host: hote, port: Number(lire('DB_PORT', 3306)) }),
  };
  return base;
}

/**
 * Les paramètres nommés.
 *
 * « VALUES (@kind, @label) » devient « VALUES (?, ?) », et l'appelant qui
 * passait un objet se voit rendre un tableau dans l'ordre d'apparition. Un nom
 * répété dans la requête — « SET failures = CASE WHEN @enabled = 1 » — donne
 * deux « ? » et deux fois la même valeur : c'est voulu.
 */
function extraireNoms(sql) {
  const noms = [];
  let sortie = '';
  let delimiteur = null;   // la quote ouvrante, tant qu'on est dans une chaîne

  for (let i = 0; i < sql.length; i++) {
    const c = sql[i];

    if (delimiteur) {
      sortie += c;
      // Deux quotes de suite : une quote échappée, la chaîne continue.
      if (c === delimiteur) {
        if (sql[i + 1] === delimiteur) { sortie += sql[++i]; } else { delimiteur = null; }
      } else if (c === '\\' && delimiteur !== '`' && i + 1 < sql.length) {
        sortie += sql[++i];
      }
      continue;
    }

    if (c === "'" || c === '"' || c === '`') { delimiteur = c; sortie += c; continue; }

    // Un « @ » hors chaîne, suivi d'un nom : c'est un paramètre nommé. Dedans,
    // c'est une adresse électronique — et « admin@test.local » a déjà été pris
    // pour un paramètre nommé « test ».
    if (c === '@') {
      const nom = sql.slice(i + 1).match(/^[A-Za-z_]\w*/);
      if (nom) {
        noms.push(nom[0]);
        sortie += '?';
        i += nom[0].length;
        continue;
      }
    }

    sortie += c;
  }

  return noms.length > 0 ? { sql: sortie, noms } : { sql, noms: null };
}

/**
 * Une valeur telle que MySQL l'attend.
 *
 * better-sqlite3 refuse booléens et `undefined` ; le produit n'en passe donc
 * pas. On les convertit quand même plutôt que d'échouer plus loin : un
 * `undefined` devenu NULL est lisible, un `undefined` transmis tel quel ne
 * l'est pas.
 */
function valeur(v) {
  if (v === undefined) return null;
  if (typeof v === 'boolean') return v ? 1 : 0;
  if (typeof v === 'bigint') return Number(v);
  return v;
}

function creerBase(depuis = null) {
  const client = new ClientSynchrone(configuration(depuis));

  // Les requêtes du produit sont écrites en clair dans le code : les mêmes
  // chaînes reviennent des milliers de fois. On ne les retraduit pas.
  const cache = new Map();

  function traduire(sqlBrut) {
    let prete = cache.get(sqlBrut);
    if (!prete) {
      const { sql, noms } = extraireNoms(sqlBrut);
      prete = { sql: dialecte.requete(sql), noms };
      cache.set(sqlBrut, prete);
    }
    return prete;
  }

  function arguments_(prete, args) {
    if (prete.noms) {
      const objet = args[0] || {};
      return prete.noms.map((nom) => valeur(objet[nom]));
    }
    // better-sqlite3 accepte « .get(1, 2) » comme « .get([1, 2]) ».
    const plats = args.length === 1 && Array.isArray(args[0]) ? args[0] : args;
    return Array.prototype.map.call(plats, valeur);
  }

  /**
   * « PRAGMA table_info(t) », qui sert à savoir si une colonne existe encore.
   *
   * MySQL répond par information_schema ; on lui fait rendre les colonnes sous
   * les noms que SQLite emploie, pour que l'appelant — qui lit `.name` — n'ait
   * pas à savoir sur quel moteur il tourne.
   */
  function tableInfo(table) {
    const lignes = client.interroger(
      `SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, IS_NULLABLE AS nullable,
              COLUMN_DEFAULT AS dflt_value, COLUMN_KEY AS cle, ORDINAL_POSITION AS cid
         FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
        ORDER BY ORDINAL_POSITION`,
      [table]
    ).lignes || [];
    return lignes.map((l) => ({
      cid: Number(l.cid) - 1,
      name: l.name,
      type: l.type,
      notnull: String(l.nullable).toUpperCase() === 'NO' ? 1 : 0,
      dflt_value: l.dflt_value,
      pk: String(l.cle).toUpperCase() === 'PRI' ? 1 : 0,
    }));
  }

  function prepare(sqlBrut) {
    const info = String(sqlBrut).trim().match(/^PRAGMA\s+(?:\w+\.)?table_info\(\s*"?([\w$]+)"?\s*\)$/i);
    if (info) {
      const rendre = () => tableInfo(info[1]);
      return { all: rendre, get: () => rendre()[0], run: () => ({ changes: 0, lastInsertRowid: 0 }) };
    }

    const prete = traduire(sqlBrut);
    return {
      get(...args) {
        const r = client.interroger(prete.sql, arguments_(prete, args));
        return r.lignes && r.lignes.length > 0 ? r.lignes[0] : undefined;
      },
      all(...args) {
        const r = client.interroger(prete.sql, arguments_(prete, args));
        return r.lignes || [];
      },
      run(...args) {
        const r = client.interroger(prete.sql, arguments_(prete, args));
        return { changes: r.affectees, lastInsertRowid: r.insertId };
      },
    };
  }

  // La profondeur des transactions : better-sqlite3 imbrique par points de
  // sauvegarde, et le produit s'en sert (une sauvegarde restaure dans une
  // transaction qui en contient d'autres).
  let profondeur = 0;

  function transaction(travail) {
    return function (...args) {
      const imbriquee = profondeur > 0;
      const point = `ta_${profondeur}`;
      client.interroger(imbriquee ? `SAVEPOINT ${point}` : 'START TRANSACTION');
      profondeur++;
      try {
        const resultat = travail.apply(this, args);
        client.interroger(imbriquee ? `RELEASE SAVEPOINT ${point}` : 'COMMIT');
        return resultat;
      } catch (erreur) {
        try {
          client.interroger(imbriquee ? `ROLLBACK TO SAVEPOINT ${point}` : 'ROLLBACK');
        } catch {
          /* la connexion est peut-être justement ce qui vient de tomber */
        }
        throw erreur;
      } finally {
        profondeur--;
      }
    };
  }

  function exec(sql) {
    for (const instruction of dialecte.morceaux(sql)) {
      const nue = instruction.replace(/^(?:\s*--[^\n]*\n)+/, '').trim();
      if (nue === '') continue;
      client.interroger(dialecte.requete(instruction));
    }
  }

  /**
   * Les réglages de SQLite, et ce qu'ils deviennent.
   *
   * `journal_mode` n'a pas d'équivalent — InnoDB tient son journal tout seul.
   * `foreign_keys` en a un. `integrity_check` en a un aussi, mais table par
   * table : on le fait plutôt que de rendre « ok » sans avoir regardé, parce
   * que le seul appelant est la vérification d'une restauration.
   */
  function pragma(instruction, options = {}) {
    const texte = String(instruction).trim();

    if (/^journal_mode/i.test(texte)) return options.simple ? 'innodb' : [{ journal_mode: 'innodb' }];

    const cles = texte.match(/^foreign_keys\s*=\s*(\w+)/i);
    if (cles) {
      const actif = /^(on|1|true)$/i.test(cles[1]) ? 1 : 0;
      client.interroger(`SET FOREIGN_KEY_CHECKS = ${actif}`);
      return options.simple ? actif : [{ foreign_keys: actif }];
    }

    if (/^integrity_check/i.test(texte)) {
      const tables = client.interroger('SHOW TABLES').lignes.map((l) => Object.values(l)[0]);
      const soucis = [];
      for (const table of tables) {
        const etat = client.interroger(`CHECK TABLE \`${table}\``).lignes;
        for (const ligne of etat) {
          if (String(ligne.Msg_type).toLowerCase() === 'status' && String(ligne.Msg_text).toLowerCase() === 'ok') continue;
          if (String(ligne.Msg_type).toLowerCase() === 'error') soucis.push(`${table} : ${ligne.Msg_text}`);
        }
      }
      const verdict = soucis.length === 0 ? 'ok' : soucis.join(' ; ');
      return options.simple ? verdict : [{ integrity_check: verdict }];
    }

    // Un réglage qu'on ne sait pas traduire ne doit pas passer pour appliqué.
    throw new Error('Réglage SQLite sans équivalent MySQL : ' + texte);
  }

  /**
   * Le schéma, posé.
   *
   * Les contrôles de clés étrangères sont suspendus le temps de la pose :
   * SQLite accepte qu'une table cite une table déclarée plus bas, MySQL non.
   * Réordonner le schéma pour lui plaire, c'est le rendre illisible pour qui
   * l'ouvre — et ce fichier se lit tous les jours.
   */
  /**
   * Les contraintes qu'on ne peut pas encore poser.
   *
   * Une colonne ajoutée après coup peut citer une table déclarée plus loin :
   * « ALTER TABLE invoices ADD COLUMN purchase_order_id INTEGER REFERENCES
   * purchase_orders(id) » arrive avant que purchase_orders existe. SQLite ne
   * s'en émeut pas ; MySQL refuse d'inscrire la contrainte.
   *
   * On ajoute alors la colonne seule et on met la contrainte de côté, pour la
   * poser dès que la table visée existe. Elle n'est pas perdue en chemin —
   * c'est la différence entre différer et renoncer.
   */
  const contraintesEnAttente = [];

  function poserLeSchema(sql) {
    client.interroger('SET FOREIGN_KEY_CHECKS = 0');
    try {
      poserLesInstructions(sql);
      poserLesContraintesEnAttente();
    } finally {
      client.interroger('SET FOREIGN_KEY_CHECKS = 1');
    }
  }

  function poserLesContraintesEnAttente() {
    for (let i = contraintesEnAttente.length - 1; i >= 0; i--) {
      try {
        client.interroger(contraintesEnAttente[i]);
        contraintesEnAttente.splice(i, 1);
      } catch (erreur) {
        // La table visée n'existe toujours pas : on réessaiera au bloc suivant.
        if (estUnDoublon(erreur)) contraintesEnAttente.splice(i, 1);
      }
    }
  }

  /** « ALTER TABLE t ADD COLUMN c TYPE REFERENCES u(x) … », coupé en deux. */
  function separerLaContrainte(instruction) {
    const m = instruction.match(
      /^(ALTER\s+TABLE\s+(\w+)\s+ADD\s+COLUMN\s+(\w+)\s+[\s\S]*?)\s+REFERENCES\s+(\w+)\s*\(([^)]*)\)([\s\S]*)$/i
    );
    if (!m) return null;
    return {
      colonne: m[1],
      contrainte: `ALTER TABLE ${m[2]} ADD FOREIGN KEY (${m[3]}) REFERENCES ${m[4]}(${m[5]})${m[6]}`,
    };
  }

  function poserLesInstructions(sql) {
    for (const instruction of dialecte.schema(sql)) {
      for (const morceau of instruction.split(/;\s*\n/)) {
        const nue = morceau.trim();
        if (nue === '') continue;
        try {
          client.interroger(nue);
        } catch (erreur) {
          // Le schéma est rejoué à chaque démarrage : une colonne ou un index
          // déjà là n'est pas une erreur.
          if (estUnDoublon(erreur)) continue;

          const coupee = estUneContrainteImpossible(erreur) ? separerLaContrainte(nue) : null;
          if (!coupee) throw erreur;

          try {
            client.interroger(coupee.colonne);
          } catch (second) {
            if (!estUnDoublon(second)) throw second;
          }
          contraintesEnAttente.push(coupee.contrainte);
        }
      }
    }
  }

  /** Les tables de cette base. Toute façade la porte, pas seulement celle de
   *  l'instance en cours : l'assistant interroge aussi la base qu'il vient de
   *  poser, et elle n'est pas encore la sienne. */
  function tables() {
    return client.interroger(
      `SELECT TABLE_NAME AS name FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME`
    ).lignes.map((l) => l.name);
  }

  return {
    prepare,
    transaction,
    exec,
    pragma,
    tables,
    poserLeSchema,
    interroger: (sql, params) => client.interroger(sql, params),
    close: () => client.fermer(),
    driver: 'mysql',
  };
}

/** 1050 table déjà là, 1060 colonne déjà là, 1061 index déjà là, 1022 clé en double. */
function estUnDoublon(erreur) {
  return [1050, 1060, 1061, 1022, 1826].includes(erreur && erreur.errno);
}

/** 1005/150 et 1823 : une clé étrangère que MySQL n'arrive pas à inscrire. */
function estUneContrainteImpossible(erreur) {
  return [1005, 1215, 1823, 1824].includes(erreur && erreur.errno);
}

module.exports = { creerBase, estUnDoublon };
