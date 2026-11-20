<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Secrets;
use App\Core\SectionsReglages;
use App\Core\Settings;

/**
 * L'assistant d'installation.
 *
 * Il fait deux choses qu'on ne peut pas demander à l'hébergement : il écrit la
 * configuration du site, et il pose la base. Tant qu'il n'a pas fini, rien ne
 * tourne — et il doit donc se passer de presque tout ce que le reste du
 * produit tient pour acquis.
 *
 * D'où le brouillon dans un fichier plutôt qu'en session : les sessions vivent
 * en base, et l'assistant est précisément ce qui décide quelle base il y aura.
 * Le fichier porte aussi, le temps de l'installation, le mot de passe de la
 * base — raison pour laquelle il est en 0600 et effacé dès la fin.
 */
final class Installation
{
    /** Les étapes, dans l'ordre où on les traverse. */
    public const ETAPES = ['langue', 'prerequis', 'mises-a-jour', 'licence', 'base', 'entreprise',
        'sections', 'administrateur', 'installation'];

    private const BROUILLON = '/.installation.json';

    // ---------- Le brouillon ----------

    private static function fichier(): string
    {
        return (string) Config::get('data_dir') . self::BROUILLON;
    }

    public static function brouillon(): array
    {
        $fichier = self::fichier();
        if (!is_file($fichier)) {
            return [];
        }
        $lu = json_decode((string) file_get_contents($fichier), true);
        return is_array($lu) ? $lu : [];
    }

    public static function noter(array $valeurs): void
    {
        $fichier = self::fichier();
        $dossier = dirname($fichier);
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0770, true);
        }
        file_put_contents($fichier, json_encode(array_merge(self::brouillon(), $valeurs), JSON_UNESCAPED_UNICODE));
        @chmod($fichier, 0600);
    }

    public static function oublier(): void
    {
        $fichier = self::fichier();
        if (is_file($fichier)) {
            // Le mot de passe de la base y figure : on écrase avant d'effacer,
            // plutôt que de laisser au système le soin de le faire un jour.
            file_put_contents($fichier, str_repeat('0', (int) filesize($fichier)));
            @unlink($fichier);
        }
    }

    /**
     * Jusqu'où l'on a le droit d'aller.
     *
     * On ne saute pas d'étape : l'écran du compte suppose une base joignable,
     * et l'installation suppose tout le reste. Une adresse forgée à la main
     * ramène donc à la première étape encore ouverte.
     */
    public static function etapePermise(string $demandee): string
    {
        $brouillon = self::brouillon();
        $atteinte = 'langue';
        foreach (self::ETAPES as $etape) {
            if (!self::etapeFaite($etape, $brouillon)) {
                break;
            }
            $atteinte = self::ETAPES[min(array_search($etape, self::ETAPES, true) + 1, count(self::ETAPES) - 1)];
        }

        $rang = array_search($demandee, self::ETAPES, true);
        $limite = array_search($atteinte, self::ETAPES, true);
        return $rang === false || $rang > $limite ? $atteinte : $demandee;
    }

    /**
     * Poser la licence de cette instance, au sortir de l'installation.
     *
     * Rend une phrase courte pour l'écran final — « activée », « posée », ou le
     * motif du refus — et ne jette jamais : une installation ne doit pas échouer
     * parce qu'un service de licences était injoignable. L'instance s'installe, et
     * son écran « Licence » dira quoi faire.
     */
    /**
     * Éprouver la licence saisie, sans rien engager.
     *
     * C'est ce que fait le bouton « Vérifier la licence » de l'installeur, et
     * ce que le serveur refait quand on valide l'étape. Rien n'est lié ici —
     * ni côté panneau, dont la route d'essai n'active aucune licence, ni côté
     * instance, qui n'existe pas encore. On peut donc presser le bouton autant
     * de fois qu'on veut ; une installation abandonnée ne consomme la licence
     * de personne.
     *
     * @return array{ok:bool, raison?:string, explication?:string, mode?:string,
     *                offre?:string, membres?:int, echeance?:string, liee?:bool}
     */
    public static function eprouverLaLicence(string $cle, string $jeton, string $url): array
    {
        // Le jeton hors ligne se vérifie ici même : sa signature est celle de
        // l'éditeur, et la clé publique est dans le code. Aucun réseau
        // nécessaire — c'est tout l'intérêt du mode hors ligne.
        if ($jeton !== '') {
            $charge = Licence::relire($jeton);
            if ($charge === null) {
                return ['ok' => false, 'raison' => 'signature'];
            }
            if (($charge['edition'] ?? '') !== Licence::EDITION) {
                return ['ok' => false, 'raison' => 'autre-edition'];
            }
            if ((int) strtotime((string) ($charge['abonnement'] ?? '')) <= time()) {
                return ['ok' => false, 'raison' => 'echue'];
            }
            return [
                'ok' => true, 'mode' => 'hors-ligne',
                'offre' => (string) ($charge['offre'] ?? ''),
                'membres' => (int) ($charge['membres'] ?? 0),
                'echeance' => (string) ($charge['abonnement'] ?? ''),
            ];
        }

        if ($cle === '') {
            return ['ok' => false, 'raison' => 'vide'];
        }
        if ($url === '') {
            return ['ok' => false, 'raison' => 'sans-panneau'];
        }

        $contexte = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Cle-Instance: $cle\r\n",
            'content' => (string) json_encode(['edition' => Licence::EDITION]),
            'timeout' => 15,
            // Sans cela, un 403 lèverait un avertissement et rendrait false :
            // on veut lire le corps de la réponse, qui nomme le refus.
            'ignore_errors' => true,
        ]]);

        $reponse = @file_get_contents("$url/api/v1/licence/verifier", false, $contexte);
        if ($reponse === false) {
            return ['ok' => false, 'injoignable' => true, 'raison' => 'reseau'];
        }

        $statut = 0;
        $type = '';
        foreach ($http_response_header ?? [] as $ligne) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $trouve) === 1) {
                $statut = (int) $trouve[1];
            }
            if (preg_match('#^Content-Type:\s*([^;\r\n]+)#i', $ligne, $trouve) === 1) {
                $type = trim($trouve[1]);
            }
        }

        /*
         * Une réponse qui n'est pas du JSON n'est pas un refus de licence :
         * c'est quelque chose qui s'est mis en travers. Un pare-feu applicatif
         * qui sert un défi JavaScript, un proxy d'entreprise, une page de
         * maintenance, un hébergeur qui redirige — tous rendent du HTML avec un
         * code quelconque.
         *
         * Les confondre est le pire service qu'on puisse rendre : le client lit
         * « licence refusée », appelle son éditeur, et cherche du mauvais côté
         * pendant une heure. On dit donc ce qu'on a reçu — le code, le type —
         * pour que la cause se voie du premier coup d'œil.
         */
        $lu = json_decode($reponse, true);
        if (!is_array($lu)) {
            return [
                'ok' => false,
                'injoignable' => true,
                'raison' => 'reponse-illisible',
                'statut' => $statut,
                'type' => $type,
                'detail' => mb_substr($reponse, 0, 200),
            ];
        }
        if ($statut < 200 || $statut >= 300) {
            return [
                'ok' => false,
                'raison' => (string) ($lu['erreur'] ?? $statut),
                // Le panneau nomme son refus en clair : on le montre tel quel
                // plutôt que de laisser deviner pourquoi une clé qu'on vient de
                // payer est refusée.
                'explication' => (string) ($lu['explication'] ?? ''),
            ];
        }

        $dit = is_array($lu['licence'] ?? null) ? $lu['licence'] : [];
        return [
            'ok' => true,
            'mode' => (string) ($dit['mode'] ?? 'en-ligne'),
            'offre' => (string) ($dit['offre'] ?? ''),
            'membres' => (int) ($dit['membres'] ?? 0),
            'echeance' => (string) ($dit['echeance'] ?? ''),
            'liee' => (bool) ($dit['liee'] ?? false),
        ];
    }

    public static function poserLaLicence(array $brouillon): array
    {
        $cle = (string) ($brouillon['licence_cle'] ?? '');
        $jeton = (string) ($brouillon['licence_jeton'] ?? '');
        if ($cle === '' && $jeton === '') {
            return ['fait' => false, 'raison' => 'aucune'];
        }

        $valeurs = [];
        if (($brouillon['licence_adresse'] ?? '') !== '') {
            $valeurs['licence_adresse'] = (string) $brouillon['licence_adresse'];
        }
        // La clé ouvre la licence et les mises à jour : une seule chez le client.
        if ($cle !== '') {
            $valeurs['update_instance_key'] = Secrets::encrypt($cle);
        }
        if ($valeurs !== []) {
            Settings::setMany($valeurs);
        }

        try {
            if ($jeton !== '') {
                $pose = Licence::poser($jeton);
                return ['fait' => (bool) $pose['ok'], 'raison' => $pose['ok'] ? 'posee' : (string) $pose['raison']];
            }
            $bail = Licence::renouveler();
            return ['fait' => (bool) $bail['ok'], 'raison' => $bail['ok'] ? 'activee' : (string) $bail['raison']];
        } catch (\Throwable $erreur) {
            return ['fait' => false, 'raison' => 'reseau'];
        }
    }

    private static function etapeFaite(string $etape, array $brouillon): bool
    {
        return match ($etape) {
            'langue' => isset($brouillon['locale']),
            'prerequis' => ($brouillon['prerequis'] ?? false) === true,
            /*
             * L'étape des mises à jour se franchit de deux façons : l'instance
             * est déjà à jour, ou l'éditeur n'a pas pu être joint. Le second
             * cas passe volontairement — voir « versionDisponible » : une panne
             * de réseau ne doit pas rendre le produit ininstallable.
             */
            'mises-a-jour' => ($brouillon['maj_vue'] ?? false) === true,
            // « licence_vue » et non « licence_cle » : continuer sans licence
            // est un choix qu'on peut faire, et il ne doit pas renvoyer
            // indéfiniment sur cette étape.
            'licence' => ($brouillon['licence_vue'] ?? false) === true,
            'base' => ($brouillon['base'] ?? false) === true,
            'entreprise' => isset($brouillon['company_name']),
            // « sections_vues » et non « sections » : ne retenir que
            // « Réglages » est un choix parfaitement légitime, et la liste est
            // alors vide. Se fier à sa longueur renverrait indéfiniment sur
            // cette étape celui qui a tout décoché.
            'sections' => ($brouillon['sections_vues'] ?? false) === true,
            'administrateur' => isset($brouillon['email']),
            default => false,
        };
    }

    // ---------- Ce que l'hébergement doit offrir ----------

    /**
     * Vérifié plutôt que supposé. Chaque ligne dit aussi si le manque est
     * bloquant : sans SQLite rien ne tourne, alors qu'openssl ne manque qu'aux
     * secrets chiffrés.
     */
    /**
     * Les contrôles de l'étape « prérequis ».
     *
     * LA MÊME LISTE QUE L'ÉDITION NODE, dans le même ordre et sous les mêmes
     * clés. Les deux éditions posent les mêmes questions au serveur ; seules
     * les réponses diffèrent, puisque les moteurs diffèrent. Deux listes qui
     * divergent laissent croire qu'une édition exige moins que l'autre, et l'on
     * choisit alors pour de mauvaises raisons. Un test de chaque côté épingle
     * l'ordre des clés.
     */
    public const CONTROLES = ['runtime', 'engine', 'data', 'hash', 'text', 'uploads', 'config',
        'crypto', 'network', 'proxy', 'production'];

    /** Les moteurs de base que cet hébergement saurait mener, ici et maintenant. */
    public static function moteursDisponibles(): array
    {
        return [
            'sqlite' => extension_loaded('pdo_sqlite'),
            'mysql' => extension_loaded('pdo_mysql'),
        ];
    }

    public static function verifications(): array
    {
        $dossier = (string) Config::get('data_dir');
        $televersements = $dossier . '/uploads';

        // Un moteur, pas SQLite. Exiger pdo_sqlite quand on installe sur MySQL
        // était un contresens : l'hébergement peut très bien n'avoir que l'un
        // des deux, et l'installation se fait alors sur celui qu'il a.
        $moteurs = self::moteursDisponibles();
        $unMoteur = $moteurs['sqlite'] || $moteurs['mysql'];
        $ditMoteur = $moteurs['sqlite'] && $moteurs['mysql'] ? 'install.checks.engineBoth'
            : ($moteurs['sqlite'] ? 'install.checks.engineSqlite'
            : ($moteurs['mysql'] ? 'install.checks.engineMysql' : 'install.checks.engineNone'));

        return [
            [
                'key' => 'runtime',
                'labelKey' => 'install.checks.runtime',
                'detailKey' => 'install.checks.runtimeDetail',
                'detailParams' => ['actuel' => 'PHP ' . PHP_VERSION, 'min' => 'PHP 8.1'],
                'ok' => PHP_VERSION_ID >= 80100,
                'blocking' => true,
            ],
            [
                'key' => 'engine',
                'labelKey' => 'install.checks.engine',
                'detailKey' => $ditMoteur,
                'ok' => $unMoteur,
                'blocking' => true,
            ],
            [
                'key' => 'data',
                'labelKey' => 'install.checks.data',
                'detailText' => $dossier,
                'ok' => is_dir($dossier) && is_writable($dossier),
                'blocking' => true,
            ],
            [
                'key' => 'hash',
                'labelKey' => 'install.checks.bcrypt',
                'detailText' => 'password_hash()',
                'ok' => defined('PASSWORD_BCRYPT'),
                'blocking' => true,
            ],
            [
                'key' => 'text',
                'labelKey' => 'install.checks.text',
                'detailKey' => extension_loaded('mbstring') ? 'install.checks.present' : 'install.checks.absent',
                'detailParams' => ['name' => 'mbstring'],
                'ok' => extension_loaded('mbstring'),
                'blocking' => true,
            ],
            [
                'key' => 'uploads',
                'labelKey' => 'install.checks.uploads',
                'detailText' => $televersements,
                // Le dossier n'existe pas encore avant l'installation : ce qui
                // compte est qu'on puisse le créer, donc que le parent s'écrive.
                'ok' => is_dir($televersements) ? is_writable($televersements) : (is_dir($dossier) && is_writable($dossier)),
                'blocking' => false,
            ],
            [
                'key' => 'config',
                'labelKey' => 'install.checks.config',
                'detailKey' => 'install.checks.configFile',
                'detailParams' => ['path' => self::fichierConfig()],
                'ok' => self::configInscriptible(),
                'blocking' => false,
            ],
            [
                'key' => 'crypto',
                'labelKey' => 'install.checks.crypto',
                'detailKey' => extension_loaded('openssl') ? 'install.checks.present' : 'install.checks.absentSecrets',
                'detailParams' => ['name' => 'openssl'],
                'ok' => extension_loaded('openssl'),
                'blocking' => false,
            ],
            [
                'key' => 'network',
                'labelKey' => 'install.checks.network',
                'detailKey' => extension_loaded('curl') ? 'install.checks.present' : 'install.checks.absentRemote',
                'detailParams' => ['name' => 'curl'],
                'ok' => extension_loaded('curl'),
                'blocking' => false,
            ],
            [
                'key' => 'proxy',
                'labelKey' => 'install.checks.proxy',
                'detailKey' => self::derriereUnProxy() ? 'install.checks.proxyOn' : 'install.checks.proxyOff',
                'detailParams' => ['value' => (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')],
                'ok' => true,
                'blocking' => false,
            ],
            [
                'key' => 'production',
                'labelKey' => 'install.checks.production',
                'detailKey' => self::enProduction() ? 'install.checks.productionOn' : 'install.checks.productionOff',
                'ok' => true,
                'blocking' => false,
            ],
        ];
    }

    /** L'application reçoit-elle ses requêtes par un proxy qui s'annonce ? */
    private static function derriereUnProxy(): bool
    {
        return ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '') !== '' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== '';
    }

    /** Servie en HTTPS, donc prête à poser des cookies sûrs. */
    private static function enProduction(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') === 'on'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || (bool) Config::get('production');
    }

    /** Une ligne de contrôle, dite dans la langue de la requête. */
    public static function dire(array $controle): array
    {
        return $controle + [
            'label' => t($controle['labelKey'], $controle['labelParams'] ?? []),
            'detail' => isset($controle['detailKey'])
                ? t($controle['detailKey'], $controle['detailParams'] ?? [])
                : (string) ($controle['detailText'] ?? ''),
        ];
    }

    /** Les prérequis bloquants non satisfaits, dits dans la langue de la requête. */
    public static function manques(): array
    {
        return array_values(array_map(
            static fn (array $c): string => self::dire($c)['label'],
            array_filter(self::verifications(), static fn (array $c): bool => $c['blocking'] && !$c['ok'])
        ));
    }

    /**
     * Le fichier de configuration que l'assistant doit écrire.
     *
     * Le même que celui que Config::load() relira : la racine du dossier, ou
     * l'endroit désigné par TOUTADMIN_CONFIG pour les hébergements qui tiennent
     * leur configuration ailleurs. Écrire à un endroit et relire à un autre
     * donnerait une installation qui se termine bien et un site qui ne démarre
     * pas.
     */
    public static function fichierConfig(): string
    {
        $configure = (string) Config::get('config_file', '');
        if ($configure !== '') {
            return $configure;
        }
        return ((string) (getenv('TOUTADMIN_CONFIG') ?: '')) ?: APP_ROOT . '/config.php';
    }

    public static function configInscriptible(): bool
    {
        $fichier = self::fichierConfig();
        return is_file($fichier) ? is_writable($fichier) : is_writable(dirname($fichier));
    }

    // ---------- La base ----------

    /**
     * Essaie la connexion avant de s'y engager.
     *
     * Une adresse qui ne répond pas doit se dire ici, à l'écran où on la saisit,
     * et non trois étapes plus loin sous la forme d'une page blanche.
     */
    public static function essayerMysql(array $champs): array
    {
        if (!extension_loaded('pdo_mysql')) {
            return ['ok' => false, 'message' => t('install.db.noDriver')];
        }

        $hote = trim((string) ($champs['host'] ?? ''));
        $base = trim((string) ($champs['name'] ?? ''));
        if ($hote === '' || $base === '') {
            return ['ok' => false, 'message' => t('install.db.missing')];
        }

        $ou = str_starts_with($hote, '/')
            ? 'unix_socket=' . $hote
            : 'host=' . $hote . ';port=' . ((int) ($champs['port'] ?? 3306) ?: 3306);

        try {
            $pdo = new \PDO(
                'mysql:' . $ou . ';dbname=' . $base . ';charset=utf8mb4',
                (string) ($champs['user'] ?? ''),
                (string) ($champs['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 5]
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        } catch (\Throwable $erreur) {
            return ['ok' => false, 'message' => $erreur->getMessage()];
        }

        return ['ok' => true, 'message' => t('install.db.reached', ['version' => $version])];
    }

    // ---------- L'installation elle-même ----------

    /**
     * Le contenu de config.php.
     *
     * Écrit plutôt que recopié à la main : c'est le seul fichier que l'assistant
     * ait à poser. Les chemins repris sont ceux qui servent déjà — réécrire
     * db_path en dur déplacerait la base que l'on vient de poser.
     */
    public static function configuration(array $brouillon): string
    {
        $valeurs = [
            'db_path' => (string) Config::get('db_path'),
            'data_dir' => (string) Config::get('data_dir'),
            // La clé déjà engendrée pour cette instance, et non une neuve :
            // ce qui a été scellé pendant l'installation doit rester lisible.
            'session_secret' => Config::secret(),
            'install_token' => (string) Config::get('install_token', ''),
            'base_url' => (string) ($brouillon['base_url'] ?? Config::get('base_url', '')),
            'trust_proxy' => (bool) Config::get('trust_proxy', false),
            'login_rate_limit' => (int) Config::get('login_rate_limit', 10),
            'global_rate_limit' => (int) Config::get('global_rate_limit', 300),
            'session_idle_minutes' => (int) Config::get('session_idle_minutes', 60),
            'session_max_hours' => (int) Config::get('session_max_hours', 12),
        ];

        if (($brouillon['db_driver'] ?? 'sqlite') === 'mysql') {
            $valeurs = array_merge($valeurs, [
                'db_driver' => 'mysql',
                'db_host' => (string) $brouillon['db_host'],
                'db_port' => (int) $brouillon['db_port'],
                'db_name' => (string) $brouillon['db_name'],
                'db_user' => (string) $brouillon['db_user'],
                'db_password' => (string) $brouillon['db_password'],
            ]);
        }

        $lignes = [
            '<?php',
            '',
            "/* Écrit par l'assistant d'installation, le " . gmdate('Y-m-d H:i') . " UTC.",
            " * La clé de session appartient à cette instance : la remplacer",
            " * invaliderait les sceaux du journal d'audit et du parapheur. */",
            '',
            'return [',
        ];
        foreach ($valeurs as $cle => $valeur) {
            $lignes[] = sprintf('    %s => %s,', var_export($cle, true), var_export($valeur, true));
        }
        $lignes[] = '];';

        return implode("\n", $lignes) . "\n";
    }

    /** Pose la configuration sur le disque. */
    public static function ecrireConfiguration(array $brouillon): bool
    {
        $fichier = self::fichierConfig();
        if (file_put_contents($fichier, self::configuration($brouillon)) === false) {
            return false;
        }
        // Lisible par le serveur web, par personne d'autre : le fichier porte
        // le mot de passe de la base et la clé de l'instance.
        @chmod($fichier, 0640);
        return true;
    }

    /**
     * Le temps d'un travail, la base visée par l'assistant.
     *
     * Et seulement ce temps-là : la session de l'assistant vit dans la base
     * d'amorçage, et si on basculait pour de bon dès le premier palier, on
     * écrirait la suite de la session dans une base qui n'a pas encore de
     * tables. D'où la restitution dans le « finally » — y compris quand le
     * palier échoue, ce qui est précisément le moment où l'on tient à pouvoir
     * réafficher l'assistant.
     */
    public static function avec(array $brouillon, callable $travail): mixed
    {
        if (($brouillon['db_driver'] ?? 'sqlite') !== 'mysql') {
            return $travail();
        }

        $avant = [];
        foreach (['db_driver', 'db_host', 'db_port', 'db_name', 'db_user', 'db_password'] as $cle) {
            $avant[$cle] = Config::get($cle);
        }

        Db::reset();
        Config::set('db_driver', 'mysql');
        Config::set('db_host', (string) $brouillon['db_host']);
        Config::set('db_port', (int) $brouillon['db_port']);
        Config::set('db_name', (string) $brouillon['db_name']);
        Config::set('db_user', (string) $brouillon['db_user']);
        Config::set('db_password', (string) $brouillon['db_password']);

        try {
            return $travail();
        } finally {
            Db::reset();
            foreach ($avant as $cle => $valeur) {
                Config::set($cle, $valeur);
            }
        }
    }

    /**
     * Le palier qui ne fabrique rien.
     *
     * Il vérifie ce que les paliers suivants supposent : les prérequis, la base
     * joignable, config.php inscriptible. Rien ne coûte plus cher qu'une
     * installation qui pose cent quarante-sept tables et crée un compte avant
     * de découvrir qu'elle ne peut pas écrire sa configuration.
     */
    public static function controler(array $brouillon): void
    {
        $manques = self::manques();
        if ($manques !== []) {
            throw new \RuntimeException(t('install.error.requirements', ['list' => implode(', ', $manques)]));
        }
        if (!self::configInscriptible()) {
            throw new \RuntimeException(t('install.error.configUnwritable'));
        }
        if (($brouillon['db_driver'] ?? 'sqlite') === 'mysql') {
            $essai = self::essayerMysql([
                'host' => $brouillon['db_host'] ?? '', 'port' => $brouillon['db_port'] ?? 3306,
                'name' => $brouillon['db_name'] ?? '', 'user' => $brouillon['db_user'] ?? '',
                'password' => $brouillon['db_password'] ?? '',
            ]);
            if (!$essai['ok']) {
                throw new \RuntimeException(t('install.db.failed', ['message' => $essai['message']]));
            }
        }
    }

    /** Pose le schéma sur la base visée, et dit combien de tables il a faites. */
    public static function poserLeSchema(array $brouillon): int
    {
        return (int) self::avec($brouillon, static function (): int {
            Db::migrate();
            return count(Db::tables());
        });
    }

    /** Crée l'entreprise et son administrateur, d'un bloc. */
    public static function ouvrirLInstance(array $brouillon): void
    {
        $locale = I18n::isSupported($brouillon['locale'] ?? null) ? (string) $brouillon['locale'] : 'fr';

        self::avec($brouillon, static function () use ($brouillon, $locale): void {
            if (Users::count() > 0) {
                throw new \RuntimeException(t('install.error.adminExists'));
            }
            Db::transaction(static function () use ($brouillon, $locale): void {
                /*
                 * Les sections retenues. On écrit « 0 » pour les refusées
                 * plutôt que « 1 » pour les gardées : l'absence de réglage vaut
                 * « installée », ce qui laisse les instances posées avant cette
                 * version avec tout ce qu'elles avaient.
                 */
                $voulues = $brouillon['sections'] ?? SectionsReglages::cles();
                $sections = [];
                foreach (SectionsReglages::cles() as $cle) {
                    $retenue = in_array($cle, $voulues, true)
                        || in_array($cle, SectionsReglages::OBLIGATOIRES, true);
                    $sections['section.' . $cle . '.active'] = $retenue ? '1' : '0';
                }
                Settings::setMany($sections + [
                    /*
                     * Le témoin qui dit « née avec les sections » : ses
                     * assistants doivent s'ouvrir à la première ouverture de
                     * chaque métier. Sans lui, le démarrage suivant prendrait
                     * l'instance pour une ancienne et les fermerait tous.
                     */
                    'sections.rattrapees' => '1',
                ]);
                Settings::setMany([
                    'company_name' => (string) $brouillon['company_name'],
                    'default_locale' => $locale,
                    'annual_leave_days' => (string) (int) ($brouillon['annual_leave_days'] ?? 25),
                    'installed_at' => gmdate('Y-m-d H:i:s'),
                    // Une version sémantique nue : l'édition se dit ailleurs, et
                    // le service de licences refuse un numéro qu'il ne saurait ordonner.
                    'installed_version' => trim((string) @file_get_contents(APP_ROOT . '/VERSION')) ?: '1.0.0',
                ]);
                Users::create([
                    'role' => 'admin',
                    'email' => (string) $brouillon['email'],
                    'password' => (string) $brouillon['password'],
                    'first_name' => trim((string) ($brouillon['first_name'] ?? '')) ?: 'Administrateur',
                    'last_name' => trim((string) ($brouillon['last_name'] ?? '')) ?: 'Général',
                    'locale' => $locale,
                ]);

                /*
                 * La première société, posée en même temps que le compte
                 * d'administration : c'est le périmètre de tout ce qui sera
                 * saisi ensuite, et une instance sans société n'afficherait
                 * rien.
                 */
                \App\Core\Db::garantirPremiereSociete((string) ($brouillon['company_name'] ?? ''));
            });
        });
    }

    /**
     * Le dernier palier : la configuration posée sur le disque.
     *
     * En dernier, et non en premier : tant qu'elle n'est pas écrite, une
     * installation interrompue n'a rien cassé — le site repart sur sa base
     * d'amorçage et l'assistant se réaffiche. Une fois écrite, l'instance
     * existe.
     */
    public static function verrouiller(array $brouillon): void
    {
        self::avec($brouillon, static function () use ($brouillon): void {
            Audit::log('installation.terminee', 'settings', null, [
                'entreprise' => (string) $brouillon['company_name'],
                'moteur' => ($brouillon['db_driver'] ?? 'sqlite') === 'mysql' ? 'mysql' : 'sqlite',
            ]);
        });

        if (!self::ecrireConfiguration($brouillon)) {
            throw new \RuntimeException(t('install.error.configUnwritable'));
        }
        self::oublier();
        self::retirerLaBaseDAmorcage($brouillon);
    }

    /**
     * La base SQLite qui a porté l'assistant, quand ce n'est pas elle qu'on garde.
     *
     * Elle existe parce que l'assistant a besoin d'une session avant de savoir
     * quelle base il y aura — mais sur une instance MySQL, la laisser derrière
     * soi, c'est laisser un fichier d'un mégaoctet dont personne ne saura dire
     * à quoi il sert. On ne l'efface qu'après s'être assuré qu'elle ne porte
     * aucun compte : si elle en portait, ce ne serait pas la bonne.
     *
     * Et à l'extinction du script, non tout de suite : la session de
     * l'assistant vit encore dans ce fichier le temps de la réponse, et
     * l'effacer avant la dernière écriture ne ferait que le faire renaître,
     * vide.
     */
    private static function retirerLaBaseDAmorcage(array $brouillon): void
    {
        if (($brouillon['db_driver'] ?? 'sqlite') !== 'mysql') {
            return;
        }

        $fichier = (string) Config::get('db_path');
        if ($fichier === '' || !is_file($fichier)) {
            return;
        }

        try {
            $pdo = new \PDO('sqlite:' . $fichier, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) {
                return;
            }
        } catch (\Throwable) {
            // Illisible, donc pas sûrement vide : on la laisse où elle est.
            return;
        }

        $pdo = null;
        register_shutdown_function(static function () use ($fichier): void {
            \App\Core\Db::reset();
            foreach ([$fichier, $fichier . '-wal', $fichier . '-shm'] as $trace) {
                if (is_file($trace)) {
                    @unlink($trace);
                }
            }
        });
    }

    // ---------- Les mises à jour ----------

    /*
     * L'étape « Mises à jour » de l'assistant.
     *
     * Installer une version dépassée est une mauvaise façon de commencer : on
     * pose un CMS dont on sait déjà qu'il lui manque des correctifs, et la
     * première chose qu'on demandera au client sera de le mettre à jour.
     * Autant le faire avant qu'il n'y ait des données dedans.
     *
     * ── Pourquoi cette étape vient avant la licence ─────────────────────
     *
     * Parce que c'est là qu'elle sert. Mais cela pose une difficulté réelle :
     * télécharger une archive demande une clé, et la clé se saisit à l'étape
     * d'après. On coupe donc en deux ce qui n'a pas à être fait d'un bloc :
     *
     *   - **savoir** s'il existe plus récent ne demande aucune clé. Le numéro
     *     de la dernière version est écrit sur la page de téléchargement du
     *     site ; le refuser à un installeur serait absurde ;
     *   - **télécharger** en demande une, et l'écran la réclame seulement à ce
     *     moment-là, une fois qu'on sait qu'elle va servir. Elle est ensuite
     *     reportée à l'étape « Licence », qui n'a donc pas à la redemander.
     *
     * ── Ce qui bloque, et ce qui ne bloque pas ──────────────────────────
     *
     * Une version en retard bloque : c'est la demande, et elle est
     * raisonnable. Un éditeur injoignable **ne bloque pas**. Un client derrière
     * un pare-feu d'entreprise, sur un réseau sans sortie, ou pendant une panne
     * de notre côté, doit pouvoir installer son CMS. Transformer une panne de
     * réseau en produit inutilisable serait une faute bien plus grave que de
     * laisser poser une version d'un mois.
     */

    /** Comparer deux numéros de version, composant par composant. */
    public static function comparerVersions(string $a, string $b): int
    {
        $gauche = array_map('intval', explode('.', $a !== '' ? $a : '0'));
        $droite = array_map('intval', explode('.', $b !== '' ? $b : '0'));
        $taille = max(count($gauche), count($droite));
        for ($i = 0; $i < $taille; $i++) {
            $x = $gauche[$i] ?? 0;
            $y = $droite[$i] ?? 0;
            if ($x !== $y) {
                return $x > $y ? 1 : -1;
            }
        }
        return 0;
    }

    /**
     * Y a-t-il plus récent ? Sans clé — voir plus haut pourquoi.
     *
     * Ne lève jamais : un éditeur injoignable est un cas ordinaire, pas une
     * panne, et il se dit à l'écran.
     */
    public static function versionDisponible(string $url, string $canal = 'stable'): array
    {
        $courante = Updates::currentVersion();
        $cible = rtrim(trim($url), '/');
        if ($cible === '') {
            return ['ok' => false, 'raison' => 'sans-editeur', 'courante' => $courante];
        }

        $contexte = stream_context_create(['http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $adresse = $cible . '/api/v1/derniere?edition=' . rawurlencode(Updates::EDITION)
            . '&canal=' . rawurlencode($canal);
        $reponse = @file_get_contents($adresse, false, $contexte);
        if ($reponse === false) {
            return ['ok' => false, 'raison' => 'injoignable', 'courante' => $courante];
        }

        $statut = 0;
        $type = '';
        foreach ($http_response_header ?? [] as $ligne) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $trouve) === 1) {
                $statut = (int) $trouve[1];
            }
            if (preg_match('#^Content-Type:\s*([^;\r\n]+)#i', $ligne, $trouve) === 1) {
                $type = trim($trouve[1]);
            }
        }

        // Une réponse qui n'est pas du JSON n'est pas une version : c'est un
        // pare-feu applicatif, un proxy, une page de maintenance. On le dit
        // comme tel plutôt que de laisser croire à un problème de version.
        $lu = json_decode($reponse, true);
        if (!is_array($lu) || $statut < 200 || $statut >= 300) {
            return [
                'ok' => false,
                'raison' => 'illisible',
                'courante' => $courante,
                'statut' => $statut,
                'type' => $type,
            ];
        }

        $derniere = (string) ($lu['numero'] ?? '');
        if ($derniere === '') {
            return ['ok' => true, 'aJour' => true, 'courante' => $courante, 'derniere' => $courante];
        }
        return [
            'ok' => true,
            'aJour' => self::comparerVersions($derniere, $courante) <= 0,
            'courante' => $courante,
            'derniere' => $derniere,
            'publieeLe' => (string) ($lu['publiee_le'] ?? ''),
        ];
    }

    /**
     * Poser la dernière version, pendant l'installation.
     *
     * Ce n'est pas « Updates::apply » et cela ne peut pas l'être : celui-là
     * écrit dans la base — l'historique des mises à jour, le journal, la
     * sauvegarde d'avant — et la base n'existe pas encore. Il n'y a d'ailleurs
     * rien à sauvegarder : aucune donnée n'a été posée, et les fichiers qu'on
     * remplace sont ceux qui viennent d'être déballés.
     *
     * Ce qui est gardé de l'autre chemin, en revanche, c'est tout ce qui
     * compte : la taille, l'empreinte, la signature de l'éditeur sur la phrase
     * qui nomme cette version précise, le contrôle du contenu de l'archive, et
     * la remise en place de ce qui a été mis de côté si la pose échoue à
     * mi-chemin.
     *
     * La clé publique est celle du code — la même paire signe les licences et
     * les versions — et non un réglage : à l'installation il n'y a pas encore
     * de réglages, et une clé de vérification qu'on pourrait poser soi-même ne
     * vérifierait rien.
     */
    public static function poserLaMiseAJour(string $url, string $cle, string $canal = 'stable'): array
    {
        $cible = rtrim(trim($url), '/');
        $secret = trim($cle);
        if ($cible === '') {
            return ['ok' => false, 'raison' => 'sans-editeur'];
        }
        if ($secret === '') {
            return ['ok' => false, 'raison' => 'sans-cle'];
        }

        $pem = LicenceCle::PEM;
        if (trim($pem) === '') {
            // Une instance dont le code ne porte pas de clé publique ne peut
            // rien vérifier, donc ne doit rien poser. C'est le cas d'un dépôt
            // de développement, et il n'a pas à se mettre à jour tout seul.
            return ['ok' => false, 'raison' => 'sans-cle-publique'];
        }

        // 1. L'annonce complète — empreinte, taille, signature, adresse de
        //    l'archive. Celle-là demande la clé, et c'est normal : elle mène au
        //    téléchargement.
        $contexte = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "X-Cle-Instance: $secret\r\n",
            'timeout' => 20,
            'ignore_errors' => true,
        ]]);
        $adresse = $cible . '/api/v1/version?edition=' . rawurlencode(Updates::EDITION)
            . '&canal=' . rawurlencode($canal)
            . '&courante=' . rawurlencode(Updates::currentVersion());
        $reponse = @file_get_contents($adresse, false, $contexte);
        if ($reponse === false) {
            return ['ok' => false, 'raison' => 'injoignable'];
        }
        $statut = 0;
        foreach ($http_response_header ?? [] as $ligne) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $ligne, $trouve) === 1) {
                $statut = (int) $trouve[1];
            }
        }
        if ($statut === 401) {
            return ['ok' => false, 'raison' => 'cle-refusee'];
        }
        $lu = json_decode($reponse, true);
        if (!is_array($lu) || $statut < 200 || $statut >= 300) {
            return ['ok' => false, 'raison' => 'http-' . $statut];
        }
        if (empty($lu['disponible']) || !is_array($lu['version'] ?? null)) {
            return ['ok' => false, 'raison' => 'deja-a-jour'];
        }
        $annonce = $lu['version'];

        // 2. Télécharger.
        $tirage = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "X-Cle-Instance: $secret\r\n",
            'timeout' => 180,
            'ignore_errors' => true,
        ]]);
        $contenu = @file_get_contents((string) ($annonce['archive'] ?? ''), false, $tirage);
        if ($contenu === false || $contenu === '') {
            return ['ok' => false, 'raison' => 'telechargement'];
        }

        // 3. Vérifier avant de croire.
        $verdict = Updates::verifyArchive($contenu, $annonce, $pem);
        if (empty($verdict['ok'])) {
            return ['ok' => false, 'raison' => 'verification', 'detail' => (string) ($verdict['reason'] ?? '')];
        }

        try {
            $fichiers = Updates::readArchive($contenu);
        } catch (\Throwable $souci) {
            return ['ok' => false, 'raison' => 'archive', 'detail' => $souci->getMessage()];
        }
        $dedans = Updates::inspectContents($fichiers, (string) ($annonce['numero'] ?? ''));
        if (empty($dedans['ok'])) {
            return ['ok' => false, 'raison' => 'contenu', 'detail' => (string) ($dedans['reason'] ?? '')];
        }

        // 4. Mettre de côté, poser, et remettre si la pose échoue à mi-chemin.
        $depuis = Updates::currentVersion();
        $deCote = rtrim((string) Config::get('data_dir'), '/') . '/installation-precedente-' . $depuis;
        $gardes = [];
        try {
            $gardes = Updates::setAside($fichiers, $deCote);
            Updates::writeFiles($fichiers);
        } catch (\Throwable $souci) {
            Updates::restoreFiles($deCote, $gardes);
            return ['ok' => false, 'raison' => 'ecriture', 'detail' => $souci->getMessage()];
        }

        return [
            'ok' => true,
            'version' => (string) ($annonce['numero'] ?? ''),
            'depuis' => $depuis,
            'redemarrer' => true,
        ];
    }
}
