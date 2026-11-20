<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Accès à la base.
 *
 * Deux moteurs, un seul code. SQLite reste le défaut : un fichier à
 * sauvegarder, rien à installer sur l'hébergement. MySQL — MariaDB comprise —
 * s'impose dès qu'une instance dépasse le seul serveur, ou simplement parce
 * que l'hébergeur en fournit une et sait la sauvegarder.
 *
 * Le schéma et les requêtes restent écrits en SQLite ; Sql les traduit au
 * passage. L'accès passe toujours par des requêtes préparées — aucune valeur
 * n'est concaténée dans du SQL.
 */
final class Db
{
    private static ?PDO $pdo = null;

    /** Le moteur configuré : « sqlite » (défaut) ou « mysql ». */
    public static function pilote(): string
    {
        return Config::get('db_driver') === 'mysql' ? 'mysql' : 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        self::$pdo = self::pilote() === 'mysql' ? self::ouvrirMysql() : self::ouvrirSqlite();
        return self::$pdo;
    }

    private static function ouvrirSqlite(): PDO
    {
        $path = (string) Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, self::OPTIONS);
        // WAL : un lecteur ne bloque pas l'écrivain, ce qui compte sur un
        // hébergement où plusieurs processus PHP servent la même base.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        return $pdo;
    }

    private static function ouvrirMysql(): PDO
    {
        $pdo = new PDO(self::dsnMysql(), (string) Config::get('db_user'), (string) Config::get('db_password'), self::OPTIONS);

        // Deux réglages rapprochent MySQL de la norme, et donc de SQLite.
        //
        // PIPES_AS_CONCAT rend leur sens aux trente-cinq « a || b » du code :
        // sans lui, MySQL les lit comme un OU logique et rend 0 ou 1, ce qui ne
        // lève aucune erreur et remplace des libellés par des chiffres.
        //
        // ANSI_QUOTES fait de « "table" » un identifiant et non une chaîne : le
        // code cite ainsi ses tables, et MySQL comparait jusque-là un nom de
        // table à du texte.
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',PIPES_AS_CONCAT,ANSI_QUOTES')");

        return $pdo;
    }

    /** L'adresse de la base, telle que la configuration la décrit. */
    public static function dsnMysql(): string
    {
        $hote = (string) (Config::get('db_host') ?: '127.0.0.1');
        $port = (int) (Config::get('db_port') ?: 3306);
        $base = (string) Config::get('db_name');

        // Une chaussette Unix se reconnaît à ce qu'elle est un chemin.
        $ou = str_starts_with($hote, '/') ? 'unix_socket=' . $hote : 'host=' . $hote . ';port=' . $port;

        return 'mysql:' . $ou . ';dbname=' . $base . ';charset=utf8mb4';
    }

    private const OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    /**
     * Le schéma, posé sur MySQL.
     *
     * Une différence à traiter à la main : « CREATE INDEX IF NOT EXISTS »
     * existe sur MariaDB et pas sur MySQL. Plutôt que de parier sur l'un ou
     * l'autre, on demande au catalogue si l'index est déjà là.
     */
    private static function migrerMysql(PDO $pdo, string $sql): void
    {
        // Les tables se déclarent dans l'ordre où on les raconte, pas dans
        // l'ordre de leurs dépendances : SQLite s'en moque, MySQL exige que la
        // table visée par une clé étrangère existe déjà. On suspend le
        // contrôle le temps de poser le schéma, il reprend juste après.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            self::poserSchemaMysql($pdo, $sql);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function poserSchemaMysql(PDO $pdo, string $sql): void
    {
        foreach (Sql::morceaux(Sql::schema($sql)) as $instruction) {
            if (preg_match('/^CREATE\s+(?:UNIQUE\s+)?INDEX\s+(\w+)\s+ON\s+(\w+)/i', $instruction, $m)) {
                if (self::indexExiste($pdo, $m[2], $m[1])) {
                    continue;
                }
            }
            try {
                $pdo->exec($instruction);
            } catch (\PDOException $erreur) {
                // Le schéma est rejoué à chaque démarrage : une colonne ou un
                // index déjà posé n'est pas une erreur. Tout le reste en est
                // une, et remonte.
                $deja = ['42S21', '42000'];
                $code = (int) ($erreur->errorInfo[1] ?? 0);
                if (!in_array($code, [1060, 1061], true)) {
                    throw $erreur;
                }
            }
        }
    }

    private static function indexExiste(PDO $pdo, string $table, string $index): bool
    {
        $requete = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
        );
        $requete->execute([$table, $index]);
        return (int) $requete->fetchColumn() > 0;
    }

    /**
     * L'erreur dit-elle qu'une contrainte d'unicité a parlé ?
     *
     * Les deux moteurs le disent autrement — « UNIQUE constraint failed » d'un
     * côté, « Duplicate entry » de l'autre — mais tous deux emploient l'état
     * 23000 de la norme SQL. Lire l'état plutôt que la phrase, c'est aussi ne
     * pas dépendre de la langue dans laquelle le serveur répond.
     */
    public static function estUnDoublon(\Throwable $erreur): bool
    {
        if ($erreur instanceof \PDOException) {
            return ($erreur->getCode() === '23000')
                || in_array((int) ($erreur->errorInfo[1] ?? 0), [19, 1062], true);
        }
        return false;
    }

    /**
     * Les tables de la base, quel que soit le moteur.
     *
     * Chacun tient son catalogue à un endroit différent — sqlite_master d'un
     * côté, information_schema de l'autre — et aucune traduction de requête ne
     * peut rattraper cela : ce n'est pas du dialecte, c'est un autre endroit.
     */
    public static function tables(): array
    {
        if (self::pilote() === 'mysql') {
            return array_column(self::all(
                'SELECT table_name AS name FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'
                 ORDER BY table_name'
            ), 'name');
        }

        return array_column(self::all(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
        ), 'name');
    }

    /** Repart de zéro : utilisé par la suite de tests, jamais en production. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    /**
     * Les paramètres sont liés un par un, avec leur type.
     *
     * PDO envoie tout en texte par défaut, et SQLite range le texte après les
     * nombres : « max(colonne, ?) » rendait alors la chaîne plutôt que le plus
     * grand des deux, et une comparaison « > ? » sur un entier devenait une
     * comparaison de chaînes. Le type est donc posé explicitement.
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        /*
         * Le garde du cloisonnement, posé sur la seule porte qui existe.
         *
         * « query » est l'unique passage de toutes les lectures et de toutes les
         * écritures du produit. Vérifier ici une fois vaut mieux que d'espérer
         * que chaque appelant pense au périmètre — et c'est la seule façon de
         * couvrir aussi ce qui sera écrit demain. Avant la traduction MySQL, pour
         * que le garde voie la requête telle qu'elle est écrite dans le code.
         *
         * Le garde ne réécrit rien : il refuse. La raison est dans
         * app/Core/Cloison.php.
         */
        Cloison::verifier($sql);

        if (self::pilote() === 'mysql') {
            $sql = Sql::requete($sql);
        }
        $statement = self::pdo()->prepare($sql);
        $position = 0;
        foreach (self::normalise($params) as $key => $value) {
            $name = is_int($key) ? ++$position : $key;
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($name, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    /** Une ligne, ou null. */
    public static function get(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** Toutes les lignes. */
    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** Une seule colonne de la première ligne. */
    public static function value(string $sql, array $params = []): mixed
    {
        $row = self::query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** Écriture : renvoie le nombre de lignes touchées. */
    public static function run(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** Insertion : renvoie l'identifiant engendré. */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Une transaction, ou rien. Toute exception annule l'ensemble : une écriture
     * en deux temps laissée à moitié faite est pire qu'une écriture refusée.
     */
    public static function transaction(callable $work): mixed
    {
        $pdo = self::pdo();
        $nested = $pdo->inTransaction();
        if (!$nested) {
            $pdo->beginTransaction();
        }
        try {
            $result = $work();
            if (!$nested) {
                $pdo->commit();
            }
            return $result;
        } catch (\Throwable $error) {
            if (!$nested && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** Installe le schéma s'il manque. Idempotent. */
    public static function migrate(): void
    {
        $files = [APP_DIR . '/schema.sql', APP_DIR . '/schema-extra.sql'];
        $sql = '';
        foreach ($files as $file) {
            $part = file_get_contents($file);
            if ($part === false) {
                throw new \RuntimeException('Schéma introuvable : ' . basename($file));
            }
            $sql .= "\n" . $part;
        }
        $pdo = self::pdo();

        if (self::pilote() === 'mysql') {
            self::migrerMysql($pdo, $sql);
        } else {
            foreach (self::statements($sql) as $statement) {
                // CREATE TABLE devient CREATE TABLE IF NOT EXISTS : le schéma est
                // rejoué à chaque démarrage, comme dans l'édition Node.
                $statement = preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
                $statement = preg_replace('/^CREATE (UNIQUE )?INDEX (?!IF NOT EXISTS)/i', 'CREATE $1INDEX IF NOT EXISTS ', (string) $statement);
                $pdo->exec((string) $statement);
            }
        }

        /*
         * Ce qui suit vaut pour les deux moteurs, et le moteur MySQL en sortait
         * avant ce lot : une instance MySQL n'aurait eu ni les colonnes ajoutées
         * après coup ni sa première société — donc aucun périmètre, et un produit
         * vide au premier écran.
         */
        self::colonnesAjoutees();
        foreach (self::A_RECONSTRUIRE as $table) {
            self::reconstruirePourCloisonner($table);
        }
        self::garantirPremiereSociete();
    }

    /**
     * La première société, pour une instance qui tournait déjà.
     *
     * Sans elle, une base existante aurait des lignes métier sans périmètre, que
     * plus aucune requête ne verrait : la mise à niveau se lirait comme une perte
     * de données. Elle reprend le nom de l'entreprise déjà réglé, rattache tout
     * le monde, et pose le périmètre sur les lignes qui n'en avaient pas.
     *
     * Idempotente : elle ne crée une société que s'il n'y en a aucune, et ne
     * rattache que les comptes qui n'ont aucun rattachement — ce qui couvre aussi
     * les comptes créés avant que le cloisonnement existe.
     *
     * Hors périmètre, et c'est le seul cas légitime : elle écrit le périmètre
     * là où il manque, elle ne peut donc pas le nommer pour filtrer.
     */
    public static function garantirPremiereSociete(string $nom = ''): ?int
    {
        return Cloison::sansPerimetre(static function () use ($nom): ?int {
            if (!in_array('companies', self::tables(), true)) {
                return null;
            }
            $premiere = self::value('SELECT id FROM companies ORDER BY id LIMIT 1');
            if ($premiere === null) {
                if ((int) self::value('SELECT COUNT(*) FROM users') === 0) {
                    return null;
                }
                $voulu = trim($nom) !== '' ? trim($nom) : '';
                if ($voulu === '') {
                    $regle = self::value("SELECT value FROM settings WHERE key = 'company_name'");
                    $voulu = trim((string) ($regle ?? ''));
                }
                $premiere = self::insert('INSERT INTO companies (name) VALUES (?)',
                    [$voulu !== '' ? $voulu : 'Mon entreprise']);
            }
            $premiere = (int) $premiere;

            self::run(
                'INSERT INTO company_members (company_id, user_id)
                 SELECT ?, u.id FROM users u
                 WHERE NOT EXISTS (SELECT 1 FROM company_members m WHERE m.user_id = u.id)',
                [$premiere],
            );
            foreach (Cloison::SOUS_GARDE as $table) {
                self::run("UPDATE $table SET company_id = ? WHERE company_id IS NULL", [$premiere]);
            }
            return $premiere;
        });
    }

    /**
     * Les colonnes ajoutées après coup.
     *
     * Le schéma se rejoue à chaque démarrage, mais « CREATE TABLE IF NOT
     * EXISTS » ne touche pas une table qui existe déjà : une colonne ajoutée
     * au fichier n'arrivait donc jamais chez ceux qui tournent. Tant qu'on n'a
     * ajouté que des tables entières, cela ne s'est pas vu ; la première
     * colonne ajoutée aurait fait tomber la mise à jour chez tout le monde,
     * sur un « no such column » au premier appel.
     *
     * Chaque ligne est écrite une fois et reste : la liste est le journal des
     * colonnes venues après la première version.
     *
     * Le type s'écrit dans la forme que les deux moteurs acceptent telle
     * quelle — « INTEGER NOT NULL DEFAULT 0 », « REAL », « TEXT » sans valeur
     * par défaut. Une colonne qui demanderait plus que cela ne passe pas par
     * ici : elle vaut une table à part, que « CREATE TABLE IF NOT EXISTS »
     * pose déjà chez tout le monde.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    /**
     * Les tables dont une unicité globale devient une unicité par société.
     *
     * Nommée, parce qu'un test la relit : chacune doit déclarer ce qui porte cette
     * unicité — un tuple « UNIQUE » ou une clé primaire nommant la société —
     * sinon la reconstruire ne fait rien.
     */
    public const A_RECONSTRUIRE = ['departments', 'teams', 'accounts', 'journals',
        'exchange_rates', 'cse_mandates', 'general_meetings', 'rooms'];

    private const COLONNES_AJOUTEES = [
        // Le dernier pas TOTP servi : un code déjà accepté ne se rejoue pas.
        ['users', 'totp_last_step', 'INTEGER NOT NULL DEFAULT 0'],
        // D'où et depuis quand une session est ouverte : voir « Appareils ».
        ['sessions', 'created_at', 'INTEGER'],
        ['sessions', 'last_seen_at', 'INTEGER'],
        ['sessions', 'ip', 'TEXT'],
        ['sessions', 'user_agent', 'TEXT'],
        /*
         * Le groupe qui a la charge d'un ticket.
         *
         * Les bases neuves l'ont dans leur définition de table ; celle-ci sert
         * aux bases déjà en service, qui ne perdent rien : nul veut dire
         * « aucun groupe », et le ticket reste visible par les rôles.
         */
        ['tickets', 'group_id', 'INTEGER'],
        // Le cloisonnement par société, table par table. Voir app/Core/Cloison.php.
        ['announcements', 'company_id', 'INTEGER'],
        // Lot 2 : l'organisation.
        ['departments', 'company_id', 'INTEGER'],
        ['teams', 'company_id', 'INTEGER'],
        ['org_managers', 'company_id', 'INTEGER'],
        // Lot 3 : la gestion.
        ['partners', 'company_id', 'INTEGER'],
        ['invoices', 'company_id', 'INTEGER'],
        ['accounts', 'company_id', 'INTEGER'],
        ['journals', 'company_id', 'INTEGER'],
        ['entries', 'company_id', 'INTEGER'],
        // Lot 4 : les projets et le support.
        ['projects', 'company_id', 'INTEGER'],
        ['tickets', 'company_id', 'INTEGER'],
        ['support_groups', 'company_id', 'INTEGER'],
        // Lot 5 : les ressources humaines.
        ['hr_requests', 'company_id', 'INTEGER'],
        ['payslips', 'company_id', 'INTEGER'],
        ['payroll_rates', 'company_id', 'INTEGER'],
        ['leave_adjustments', 'company_id', 'INTEGER'],
        ['time_entries', 'company_id', 'INTEGER'],
        ['medical_visits', 'company_id', 'INTEGER'],
        ['trainings', 'company_id', 'INTEGER'],
        ['job_openings', 'company_id', 'INTEGER'],
        ['reviews', 'company_id', 'INTEGER'],
        ['one_on_ones', 'company_id', 'INTEGER'],
        ['checklists', 'company_id', 'INTEGER'],
        ['checklist_templates', 'company_id', 'INTEGER'],
        ['expense_claims', 'company_id', 'INTEGER'],
        // Lot 6 : le stock, les achats et la trésorerie.
        ['items', 'company_id', 'INTEGER'],
        ['assets', 'company_id', 'INTEGER'],
        ['tools', 'company_id', 'INTEGER'],
        ['assignments', 'company_id', 'INTEGER'],
        ['purchase_orders', 'company_id', 'INTEGER'],
        ['purchase_requests', 'company_id', 'INTEGER'],
        ['subscriptions', 'company_id', 'INTEGER'],
        ['quotes', 'company_id', 'INTEGER'],
        ['opportunities', 'company_id', 'INTEGER'],
        ['budgets', 'company_id', 'INTEGER'],
        ['bank_accounts', 'company_id', 'INTEGER'],
        ['cash_forecasts', 'company_id', 'INTEGER'],
        ['fixed_assets', 'company_id', 'INTEGER'],
        ['vat_returns', 'company_id', 'INTEGER'],
        ['exchange_rates', 'company_id', 'INTEGER'],
        // Lot 7 : le comité social, la vie sociale et le dispositif d'alerte.
        ['cse_mandates', 'company_id', 'INTEGER'],
        ['cse_elections', 'company_id', 'INTEGER'],
        ['cse_meetings', 'company_id', 'INTEGER'],
        ['cse_benefits', 'company_id', 'INTEGER'],
        ['meetings', 'company_id', 'INTEGER'],
        ['whistleblow_reports', 'company_id', 'INTEGER'],
        ['shareholders', 'company_id', 'INTEGER'],
        ['corporate_mandates', 'company_id', 'INTEGER'],
        ['general_meetings', 'company_id', 'INTEGER'],
        ['interest_declarations', 'company_id', 'INTEGER'],
        ['gift_records', 'company_id', 'INTEGER'],
        ['power_delegations', 'company_id', 'INTEGER'],
        ['decisions', 'company_id', 'INTEGER'],
        ['meeting_actions', 'company_id', 'INTEGER'],
        // Lot 8 : la qualité, la sécurité au travail et le registre des traitements.
        ['nonconformities', 'company_id', 'INTEGER'],
        ['quality_actions', 'company_id', 'INTEGER'],
        ['internal_audits', 'company_id', 'INTEGER'],
        ['risk_assessments', 'company_id', 'INTEGER'],
        ['enterprise_risks', 'company_id', 'INTEGER'],
        ['workplace_incidents', 'company_id', 'INTEGER'],
        ['ppe_items', 'company_id', 'INTEGER'],
        ['processing_records', 'company_id', 'INTEGER'],
        // Lot 9 : l'informatique — parc logiciel, services applicatifs,
        // connaissances, webhooks et parapheur.
        ['app_services', 'company_id', 'INTEGER'],
        ['it_incidents', 'company_id', 'INTEGER'],
        ['software_licences', 'company_id', 'INTEGER'],
        ['kb_articles', 'company_id', 'INTEGER'],
        ['webhooks', 'company_id', 'INTEGER'],
        ['signature_requests', 'company_id', 'INTEGER'],
        // Lot 10 : les moyens généraux — salles, flotte, accueil, documents et
        // événements d'entreprise, pièces reçues.
        ['rooms', 'company_id', 'INTEGER'],
        ['vehicles', 'company_id', 'INTEGER'],
        ['visitors', 'company_id', 'INTEGER'],
        ['mail_items', 'company_id', 'INTEGER'],
        ['company_documents', 'company_id', 'INTEGER'],
        ['company_events', 'company_id', 'INTEGER'],
        ['incoming_documents', 'company_id', 'INTEGER'],
        // Lot 11 : le reste — objectifs, enquêtes, plannings, formulaires de
        // demande, corbeille et champs personnalisés.
        ['objectives', 'company_id', 'INTEGER'],
        ['surveys', 'company_id', 'INTEGER'],
        ['shifts', 'company_id', 'INTEGER'],
        ['shift_templates', 'company_id', 'INTEGER'],
        ['request_forms', 'company_id', 'INTEGER'],
        ['trash', 'company_id', 'INTEGER'],
        ['custom_fields', 'company_id', 'INTEGER'],
        // Le jeton d'API porte son propre périmètre.
        ['api_tokens', 'company_id', 'INTEGER'],
        // La validation des écritures et le verrouillage de la période (FEC).
        ['entries', 'number', 'INTEGER'],
        ['entries', 'validated_at', 'TEXT'],
        ['entries', 'reverses_id', 'INTEGER'],
        ['companies', 'locked_until', 'TEXT'],
        // L'import des relevés bancaires.
        ['bank_transactions', 'import_ref', 'TEXT'],
        // Les paiements SEPA : les coordonnées bancaires de la société et des tiers.
        ['companies', 'iban', "TEXT NOT NULL DEFAULT ''"],
        ['companies', 'bic', "TEXT NOT NULL DEFAULT ''"],
        ['companies', 'sepa_ics', "TEXT NOT NULL DEFAULT ''"],
        ['partners', 'iban', "TEXT NOT NULL DEFAULT ''"],
        ['partners', 'bic', "TEXT NOT NULL DEFAULT ''"],
        // Le lettrage : la date à laquelle une ligne a reçu son code (DateLet du FEC).
        ['entry_lines', 'lettered_at', 'TEXT'],
        // La raison d'une affaire perdue : une clé d'une liste fermée.
        ['opportunities', 'lost_reason', "TEXT NOT NULL DEFAULT ''"],
        // Les indemnités kilométriques : la distance, la puissance fiscale, l'énergie.
        ['expense_claims', 'distance_km', 'REAL'],
        ['expense_claims', 'fiscal_hp', 'INTEGER'],
        ['expense_claims', 'electric', 'INTEGER NOT NULL DEFAULT 0'],
        // La commande d'où sort une facture.
        ['invoices', 'sales_order_id', 'INTEGER'],
        // L'identifiant opaque qu'une clé WebAuthn associe à la personne.
        ['users', 'webauthn_handle', 'TEXT'],
    ];

    private static function colonnesAjoutees(): void
    {
        foreach (self::COLONNES_AJOUTEES as [$table, $colonne, $type]) {
            if (self::aLaColonne($table, $colonne)) {
                continue;
            }
            self::pdo()->exec("ALTER TABLE $table ADD COLUMN $colonne $type");
        }
    }

    public static function aLaColonne(string $table, string $colonne): bool
    {
        if (self::pilote() === 'mysql') {
            return self::value(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $colonne],
            ) !== null;
        }
        foreach (self::all('PRAGMA table_info("' . $table . '")') as $existante) {
            if ((string) $existante['name'] === $colonne) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconstruire une table pour que son unicité devienne une unicité par société.
     *
     * Le cloisonnement demande que les contraintes d'unicité globales deviennent
     * des contraintes par société : deux entités d'un groupe ont le droit d'avoir
     * chacune un service « Production », et une unicité sur le seul nom le leur
     * interdit.
     *
     * Sur une base neuve, il suffit de corriger le schéma. Sur une base en
     * service, non : le schéma ne retouche pas une table qui existe, et SQLite ne
     * sait pas retirer l'index implicite qu'une contrainte « UNIQUE » de colonne a
     * créé — il ne se supprime pas. La table doit donc être reconstruite.
     *
     * Ce que cette fonction ne fait pas : deviner la nouvelle définition. Elle la
     * reprend telle quelle dans app/schema.sql, qui reste la seule source de
     * vérité — recopier la définition dans la migration, c'est accepter qu'un jour
     * les deux ne disent plus la même chose. Et elle ne reconstruit que si la
     * définition en base diffère de celle voulue : rejouée, elle ne fait rien.
     */
    public static function reconstruirePourCloisonner(string $table): bool
    {
        $voulue = self::definitionVoulue($table);
        if ($voulue === null) {
            throw new \RuntimeException('Définition introuvable dans le schéma : ' . $table);
        }

        return Cloison::sansPerimetre(static function () use ($table, $voulue): bool {
            if (!in_array($table, self::tables(), true)) {
                return false;
            }

            if (self::pilote() === 'mysql') {
                /*
                 * MySQL sait retirer un index, lui. Le nom de l'index créé par une
                 * contrainte « UNIQUE » de colonne est celui de la colonne ; les
                 * deux instructions sont donc tentées et leurs échecs ignorés —
                 * « l'index n'existe pas » et « la clé existe déjà » veulent tous
                 * deux dire que le travail est déjà fait.
                 *
                 * Ce qui décide — quelles colonnes, quels tuples, quelle clé — est
                 * lu par « unicitesDeDefinition », à part et sous test ; seules les
                 * instructions restent à MySQL.
                 */
                $unicites = self::unicitesDeDefinition($voulue);

                /*
                 * La clé primaire d'abord, et sans filet : c'est elle qui
                 * cloisonne les taux de change, où le périmètre entre dans la clé
                 * plutôt que dans un index. Une base MySQL en service garderait
                 * sinon « PRIMARY KEY (code) » et refuserait à la seconde société
                 * le taux d'une devise que la première a figé. Un échec doit faire
                 * échouer la migration, là où une unicité manquante peut être
                 * ignorée : un index nommé du schéma la repose.
                 */
                if ($unicites['cle'] !== null) {
                    $enPlace = array_column(array_filter(
                        self::all('PRAGMA table_info("' . $table . '")'),
                        static fn (array $colonne): bool => (int) ($colonne['pk'] ?? 0) === 1,
                    ), 'name');
                    if (!self::cleIdentique($enPlace, $unicites['cle'])) {
                        self::pdo()->exec(
                            "ALTER TABLE $table DROP PRIMARY KEY, ADD PRIMARY KEY ({$unicites['cle']})"
                        );
                    }
                }

                foreach ($unicites['colonnes'] as $colonne) {
                    try {
                        self::pdo()->exec("ALTER TABLE $table DROP INDEX $colonne");
                    } catch (\PDOException) {
                        // L'index n'était pas là : rien à retirer.
                    }
                }
                foreach ($unicites['tuples'] as $groupe) {
                    try {
                        self::pdo()->exec("ALTER TABLE $table ADD UNIQUE ($groupe)");
                    } catch (\PDOException) {
                        // La contrainte y était déjà.
                    }
                }
                return false;
            }

            $enBase = (string) self::value(
                "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?",
                [$table],
            );
            if ($enBase === '' || self::sansBlanc($enBase) === self::sansBlanc($voulue)) {
                return false;
            }

            $existantes = array_column(self::all('PRAGMA table_info("' . $table . '")'), 'name');
            preg_match_all('/^\s{2}(\w+)\s+(?:INTEGER|TEXT|REAL|BLOB|NUMERIC)/im', $voulue, $neuves);
            $communes = array_values(array_intersect($neuves[1], $existantes));

            /*
             * Les clés étrangères sont suspendues le temps de la bascule : pendant
             * les quelques instructions où l'ancienne table disparaît, les lignes
             * qui la citent pointeraient dans le vide. Elles sont revérifiées juste
             * après, et une incohérence fait échouer la migration plutôt que de
             * passer inaperçue.
             */
            $pdo = self::pdo();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            try {
                $pdo->exec('BEGIN');
                $pdo->exec(str_replace("CREATE TABLE $table (", "CREATE TABLE {$table}__neuve (", $voulue));
                $colonnes = implode(', ', $communes);
                $pdo->exec("INSERT INTO {$table}__neuve ($colonnes) SELECT $colonnes FROM $table");
                $pdo->exec("DROP TABLE $table");
                $pdo->exec("ALTER TABLE {$table}__neuve RENAME TO $table");
                $pdo->exec('COMMIT');
            } catch (\Throwable $erreur) {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (\Throwable) {
                    // Rien à annuler.
                }
                $pdo->exec('PRAGMA foreign_keys = ON');
                throw $erreur;
            }

            // Les index nommés du schéma repartent : « DROP TABLE » les a emportés.
            foreach (self::statements(self::schemaTexte()) as $instruction) {
                if (preg_match('/^CREATE (?:UNIQUE )?INDEX \S+ ON ' . $table . '\s*\(/i', $instruction) === 1) {
                    $pdo->exec($instruction);
                }
            }

            $casse = self::all('PRAGMA foreign_key_check');
            $pdo->exec('PRAGMA foreign_keys = ON');
            if ($casse !== []) {
                throw new \RuntimeException(
                    'Reconstruction de ' . $table . ' : ' . count($casse) . ' référence(s) cassée(s).'
                );
            }
            return true;
        });
    }

    /**
     * Les unicités déclarées par une définition de table.
     *
     * Trois formes, et il faut les trois : « name TEXT NOT NULL UNIQUE » pose une
     * unicité sur une colonne, « UNIQUE(company_id, name) » sur un tuple, et une
     * contrainte « PRIMARY KEY (company_id, code) » la porte dans la clé — c'est
     * le cas des taux de change. La bascule vers une unicité par société retire
     * les premières, pose les secondes et corrige la troisième.
     *
     * Cette lecture est à part pour une raison de preuve. Les instructions qui
     * s'en servent sont du MySQL, et le harnais de tests tourne sur SQLite : elles
     * ne s'exécutent pas ici. Ce qui décide, en revanche, est du texte vers du
     * texte, et se vérifie sur le schéma réel.
     *
     * @return array{colonnes: list<string>, tuples: list<string>, cle: ?string}
     */
    public static function unicitesDeDefinition(string $definition): array
    {
        preg_match_all('/^\s*(\w+)[^,\n]*\bUNIQUE\b/im', $definition, $colonnes);
        preg_match_all('/UNIQUE\s*\(([^)]+)\)/i', $definition, $tuples);
        $cle = null;
        if (preg_match('/^\s*PRIMARY KEY\s*\(([^)]+)\)/im', $definition, $trouve) === 1) {
            $cle = trim($trouve[1]);
        }
        return [
            'colonnes' => array_values($colonnes[1]),
            'tuples' => array_map('trim', array_values($tuples[1])),
            'cle' => $cle,
        ];
    }

    /**
     * La clé primaire en base dit-elle déjà ce que la définition veut ?
     *
     * MySQL rend les colonnes d'une clé composée dans l'ordre de la table et non
     * dans celui de la clé : comparer les listes telles quelles rejouerait la
     * migration à chaque démarrage.
     *
     * @param list<string> $colonnesEnBase
     */
    public static function cleIdentique(array $colonnesEnBase, string $cleVoulue): bool
    {
        $rangee = static function (array $liste): string {
            $propre = array_map(static fn (string $c): string => trim($c), $liste);
            sort($propre);
            return implode(',', $propre);
        };
        return $rangee($colonnesEnBase) === $rangee(explode(',', $cleVoulue));
    }

    /** La définition voulue d'une table, reprise du schéma et jamais recopiée. */
    public static function definitionVoulue(string $table): ?string
    {
        if (preg_match('/CREATE TABLE ' . $table . ' \(([\s\S]*?)\n\);/', self::schemaTexte(), $trouve) !== 1) {
            return null;
        }
        return "CREATE TABLE $table (" . $trouve[1] . "\n)";
    }

    /** Le texte du schéma, les deux fichiers mis bout à bout. */
    private static function schemaTexte(): string
    {
        $texte = '';
        foreach ([APP_DIR . '/schema.sql', APP_DIR . '/schema-extra.sql'] as $fichier) {
            $part = file_get_contents($fichier);
            if ($part !== false) {
                $texte .= "\n" . $part;
            }
        }
        return $texte;
    }

    /** Deux définitions se comparent sur leur texte, à la mise en page près. */
    private static function sansBlanc(string $sql): string
    {
        $sans = (string) preg_replace('/--[^\n]*/', ' ', $sql);
        $sans = (string) preg_replace('/\s+/', ' ', $sans);
        /*
         * Les guillemets tombent, et c'est ce qui rend la comparaison juste :
         * « ALTER TABLE … RENAME TO » laisse en base « CREATE TABLE "departments" »,
         * là où le schéma écrit la table sans guillemets. Sans cela, la définition
         * paraîtrait toujours différente et la table serait reconstruite à chaque
         * démarrage — une reconstruction par requête servie, sur une table en service.
         */
        $sans = str_replace(['"', '`'], '', $sans);
        $sans = (string) preg_replace('/CREATE TABLE (?:IF NOT EXISTS )?/i', 'CREATE TABLE ', $sans);
        return trim($sans);
    }

    /**
     * Découpe un fichier SQL en instructions.
     *
     * Le schéma porte des commentaires en français, donc des apostrophes : un
     * découpage naïf les prendrait pour des chaînes et avalerait les
     * point-virgules suivants. On reconnaît donc aussi les commentaires.
     */
    public static function statements(string $sql): array
    {
        $out = [];
        $current = '';
        $length = strlen($sql);
        $inString = false;
        $inLineComment = false;
        $inBlockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                $current .= $char;
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }
            if ($inBlockComment) {
                $current .= $char;
                if ($char === '*' && $next === '/') {
                    $current .= $next;
                    $i++;
                    $inBlockComment = false;
                }
                continue;
            }
            if ($inString) {
                $current .= $char;
                if ($char === "'") {
                    // Deux apostrophes de suite : une apostrophe échappée.
                    if ($next === "'") {
                        $current .= $next;
                        $i++;
                    } else {
                        $inString = false;
                    }
                }
                continue;
            }

            if ($char === '-' && $next === '-') {
                $inLineComment = true;
                $current .= $char;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $inBlockComment = true;
                $current .= $char;
                continue;
            }
            if ($char === "'") {
                $inString = true;
                $current .= $char;
                continue;
            }
            if ($char === ';') {
                $statement = self::clean($current);
                if ($statement !== '') {
                    $out[] = $statement;
                }
                $current = '';
                continue;
            }
            $current .= $char;
        }

        $statement = self::clean($current);
        if ($statement !== '') {
            $out[] = $statement;
        }
        return $out;
    }

    /**
     * Retire les lignes de commentaire en tête d'une instruction : sans cela
     * « CREATE TABLE » ne serait plus le début du texte, et la réécriture en
     * « IF NOT EXISTS » ne s'appliquerait pas.
     */
    private static function clean(string $statement): string
    {
        $lines = [];
        foreach (explode("\n", $statement) as $line) {
            if ($lines === [] && (trim($line) === '' || str_starts_with(trim($line), '--'))) {
                continue;
            }
            $lines[] = $line;
        }
        return trim(implode("\n", $lines));
    }

    /** PDO n'accepte ni booléen ni objet : on les ramène à des scalaires. */
    private static function normalise(array $params): array
    {
        return array_map(static function ($value) {
            if (is_bool($value)) {
                return $value ? 1 : 0;
            }
            if ($value instanceof \DateTimeInterface) {
                return $value->format('Y-m-d H:i:s');
            }
            return $value;
        }, $params);
    }
}
