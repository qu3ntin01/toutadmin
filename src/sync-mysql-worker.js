/**
 * Le fil qui parle à MySQL.
 *
 * Il fait le travail asynchrone — se connecter, interroger — et rend la main au
 * fil principal par un signal atomique. Le fil principal, lui, n'a jamais rien
 * à attendre : il bloque, et repart avec la réponse.
 */
const { parentPort, workerData } = require('worker_threads');
const mysql = require('mysql2');

const { config, shared, port } = workerData;
const signal = new Int32Array(shared);

// Une seule connexion, comme better-sqlite3 n'a qu'un seul fichier : l'appelant
// est bloqué pendant la requête, il ne peut pas en lancer deux à la fois.
const connexion = mysql.createConnection({
  ...config,
  // Les dates reviennent en chaînes, comme de SQLite : le reste du produit
  // compare des « 2026-09-15 » et non des objets Date.
  dateStrings: true,
  // Les décimaux aussi reviennent en nombres : SQLite rend des REAL, et un
  // montant qui devient une chaîne casserait chaque addition du produit.
  decimalNumbers: true,
  multipleStatements: false,
});

let reglageManque = null;

// Le produit écrit du SQL SQLite traduit : « || » y concatène, et les
// guillemets doubles y désignent des identifiants. On le dit à la session une
// fois pour toutes, avant la première requête — mysql2 les met en file jusqu'à
// ce que la connexion soit établie, donc l'ordre est garanti.
connexion.query(
  "SET SESSION sql_mode = CONCAT(@@sql_mode, ',PIPES_AS_CONCAT,ANSI_QUOTES')",
  (err) => {
    if (err) reglageManque = err;
  }
);

function repondre(charge) {
  // L'ordre compte : le message d'abord, le signal ensuite. Le fil principal
  // se réveille sur le signal et lit aussitôt le port — s'il se réveillait
  // avant que le message y soit, il ne trouverait rien.
  port.postMessage(charge);
  Atomics.store(signal, 0, 1);
  Atomics.notify(signal, 0);
}

function erreurLisible(err) {
  return {
    message: String(err && err.message ? err.message : err),
    code: err && err.code ? err.code : '',
    errno: err && typeof err.errno === 'number' ? err.errno : 0,
    sqlState: err && err.sqlState ? err.sqlState : '',
  };
}

parentPort.on('message', (demande) => {
  if (reglageManque) {
    // Le mode SQL n'a pas pu être posé : toute requête qui suivrait serait
    // interprétée autrement qu'écrite. Mieux vaut le dire à la première.
    repondre({ ok: false, erreur: erreurLisible(reglageManque) });
    return;
  }
  if (demande.fin) {
    connexion.end(() => repondre({ ok: true, resultat: null }));
    return;
  }

  connexion.query(
    { sql: demande.sql, values: demande.params, rowsAsArray: false },
    (err, lignes, champs) => {
      if (err) {
        repondre({ ok: false, erreur: erreurLisible(err) });
        return;
      }
      repondre({
        ok: true,
        // Un SELECT rend des lignes, le reste un état d'écriture.
        lignes: Array.isArray(lignes) ? lignes : null,
        colonnes: champs ? champs.map((c) => c.name) : [],
        affectees: lignes && typeof lignes.affectedRows === 'number' ? lignes.affectedRows : 0,
        insertId: lignes && typeof lignes.insertId === 'number' ? lignes.insertId : 0,
      });
    }
  );
});
