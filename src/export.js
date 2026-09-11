const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const crypto = require('crypto');

const db = require('./db');
const tar = require('./tar');
const settings = require('./settings');
const { UPLOAD_DIR } = require('./uploads');
const cv = require('./cv');
const vault = require('./vault');
const signing = require('./signing');
const intake = require('./intake');

/**
 * Export intégral de l'instance, dans un format que d'autres outils savent lire.
 *
 * C'est la réversibilité : la garantie qu'on peut partir. Une sauvegarde sert à
 * revenir dans ce logiciel-ci ; un export sert à s'en aller. Il est donc écrit
 * en JSON, une table par fichier, sans rien qui suppose SQLite ou ce CMS.
 *
 * Ce qui n'y figure pas, et pourquoi :
 *   — les **empreintes de mots de passe** et les **secrets de double
 *     authentification** : ils n'ont aucune valeur ailleurs, et les recopier
 *     dans un fichier qui circule serait un risque pur ;
 *   — les **jetons d'API** et les **secrets de webhook**, pour la même raison ;
 *   — les **sessions ouvertes**, qui ne veulent rien dire hors d'ici ;
 *   — les **réglages chiffrés** (mots de passe d'externalisation) : illisibles
 *     ailleurs, puisque la clé reste sur le serveur.
 * L'export dit ce qu'il omet, dans son propre fichier de lecture.
 */

// Tables dont le contenu ne quitte pas le serveur.
const SKIPPED_TABLES = new Set(['sessions', 'totp_recovery_codes', 'api_tokens', 'webhook_deliveries']);

// Colonnes retirées, table par table.
const SKIPPED_COLUMNS = {
  users: ['password_hash', 'totp_secret'],
  webhooks: ['secret'],
  vault_access_grants: ['code_hash'],
  signature_requests: ['body'],
};

// Réglages qui portent un secret chiffré : la clé reste ici, la valeur ne
// servirait à rien ailleurs.
const SECRET_SETTING = /^offsite\..*\.config$/;

function tables() {
  return db.prepare(`
    SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
    ORDER BY name
  `).all().map((row) => row.name).filter((name) => !SKIPPED_TABLES.has(name));
}

function rowsOf(name) {
  const skipped = SKIPPED_COLUMNS[name] || [];
  const rows = db.prepare(`SELECT * FROM ${name}`).all();

  return rows
    .filter((row) => !(name === 'settings' && SECRET_SETTING.test(row.key)))
    .map((row) => {
      const clean = {};
      for (const [key, value] of Object.entries(row)) {
        if (!skipped.includes(key)) clean[key] = value;
      }
      return clean;
    });
}

function collectFiles(root, dir) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir)
    .filter((name) => fs.statSync(path.join(dir, name)).isFile())
    .map((name) => ({ name: `fichiers/${root}/${name}`, source: fs.readFileSync(path.join(dir, name)) }));
}

const README = `Export intégral — Toutadmin
================================

Ce dossier contient toutes les données de l'instance, dans un format ouvert.

  donnees/<table>.json   une table par fichier, un tableau d'objets JSON,
                         les noms de colonnes tels qu'ils sont en base.
  fichiers/uploads/      photos de profil
  fichiers/cv/           CV reçus
  fichiers/coffre/       coffre-fort (bulletins et documents scellés)
  fichiers/parapheur/    documents mis à la signature
  fichiers/pieces/       factures reçues (dépôts et captures de messagerie)
  meta/manifeste.json    inventaire, empreintes SHA-256, date de l'export

Ce que l'export ne contient pas, volontairement :

  — les empreintes de mots de passe et les secrets de double authentification ;
  — les jetons d'API et les secrets de signature des webhooks ;
  — les sessions ouvertes ;
  — les réglages chiffrés (identifiants d'externalisation des sauvegardes).

Ces éléments n'ont aucune valeur hors de cette instance et les recopier dans un
fichier destiné à circuler serait un risque sans contrepartie.

Pour revenir dans ce logiciel, utilisez une sauvegarde (menu Sauvegardes), pas
cet export : la sauvegarde sert à revenir, l'export sert à partir.
`;

/** Construit l'archive. Rendue en mémoire : elle part directement au navigateur. */
function build() {
  const generatedAt = new Date().toISOString();
  const entries = [];
  const inventory = [];

  for (const name of tables()) {
    const rows = rowsOf(name);
    const body = Buffer.from(`${JSON.stringify(rows, null, 1)}\n`, 'utf8');
    entries.push({ name: `donnees/${name}.json`, source: body });
    inventory.push({ table: name, lignes: rows.length, octets: body.length });
  }

  const files = [
    ...collectFiles('uploads', UPLOAD_DIR),
    ...collectFiles('cv', cv.CV_DIR),
    ...collectFiles('coffre', vault.VAULT_DIR),
    ...collectFiles('parapheur', signing.SIGN_DIR),
    ...collectFiles('pieces', intake.DOCS_DIR),
  ];
  entries.push(...files);

  entries.push({ name: 'LISEZMOI.txt', source: Buffer.from(README, 'utf8') });

  const manifest = {
    format: 'toutadmin-export',
    version: 1,
    genere_le: generatedAt,
    instance: settings.get('company_name') || '',
    tables: inventory,
    fichiers: files.map((file) => ({
      nom: file.name,
      octets: file.source.length,
      sha256: crypto.createHash('sha256').update(file.source).digest('hex'),
    })),
    omissions: [
      'empreintes de mots de passe',
      'secrets de double authentification',
      "jetons d'API et secrets de webhook",
      'sessions ouvertes',
      'réglages chiffrés',
    ],
  };
  entries.push({ name: 'meta/manifeste.json', source: Buffer.from(`${JSON.stringify(manifest, null, 2)}\n`, 'utf8') });

  const archive = zlib.gzipSync(tar.pack(entries), { level: 9 });
  const stamp = generatedAt.replace(/[:.]/g, '-').slice(0, 19);

  return {
    fileName: `export-${stamp}.tar.gz`,
    buffer: archive,
    tables: inventory.length,
    rows: inventory.reduce((total, entry) => total + entry.lignes, 0),
    files: files.length,
    bytes: archive.length,
  };
}

/** Ce que l'export contiendrait, sans le construire : c'est ce qu'affiche l'écran. */
function preview() {
  const inventory = tables().map((name) => ({
    table: name,
    lignes: db.prepare(`SELECT COUNT(*) AS n FROM ${name}`).get().n,
  }));

  const count = (dir) => (fs.existsSync(dir) ? fs.readdirSync(dir).length : 0);

  return {
    tables: inventory.filter((entry) => entry.lignes > 0).sort((a, b) => b.lignes - a.lignes),
    tableCount: inventory.length,
    rows: inventory.reduce((total, entry) => total + entry.lignes, 0),
    files: {
      uploads: count(UPLOAD_DIR),
      cv: count(cv.CV_DIR),
      coffre: count(vault.VAULT_DIR),
      parapheur: count(signing.SIGN_DIR),
      pieces: count(intake.DOCS_DIR),
    },
    skipped: [...SKIPPED_TABLES],
    skippedColumns: SKIPPED_COLUMNS,
  };
}

module.exports = { SKIPPED_TABLES, SKIPPED_COLUMNS, tables, rowsOf, build, preview, README };
