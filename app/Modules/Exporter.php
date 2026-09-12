<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;
use App\Core\Tar;

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
 *   — les **réglages chiffrés** : illisibles ailleurs, puisque la clé reste sur
 *     le serveur.
 * L'export dit ce qu'il omet, dans son propre fichier de lecture.
 */
final class Exporter
{
    /** Tables dont le contenu ne quitte pas le serveur. */
    public const SKIPPED_TABLES = ['sessions', 'totp_recovery_codes', 'api_tokens', 'webhook_deliveries'];

    /** Colonnes retirées, table par table. */
    public const SKIPPED_COLUMNS = [
        'users' => ['password_hash', 'totp_secret'],
        'webhooks' => ['secret'],
        'vault_access_grants' => ['code_hash'],
        'signature_requests' => ['body'],
    ];

    // Réglages qui portent un secret chiffré : la clé reste ici, la valeur ne
    // servirait à rien ailleurs.
    private const SECRET_SETTING = '/^offsite\..*\.config$/';

    public const README = <<<'TXT'
        Export intégral — Toutadmin
        ================================

        Ce dossier contient toutes les données de l'instance, dans un format ouvert.

          donnees/<table>.json   une table par fichier, un tableau d'objets JSON,
                                 les noms de colonnes tels qu'ils sont en base.
          fichiers/coffre/       coffre-fort (bulletins et documents scellés)
          fichiers/parapheur/    documents mis à la signature
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
        TXT;

    public static function tables(): array
    {
        $names = array_column(
            Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"),
            'name'
        );
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => !in_array($name, self::SKIPPED_TABLES, true)
        ));
    }

    public static function rowsOf(string $name): array
    {
        $skipped = self::SKIPPED_COLUMNS[$name] ?? [];
        $out = [];
        foreach (Db::all("SELECT * FROM \"$name\"") as $row) {
            if ($name === 'settings' && preg_match(self::SECRET_SETTING, (string) $row['key']) === 1) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                if (!in_array($key, $skipped, true)) {
                    $clean[$key] = $value;
                }
            }
            $out[] = $clean;
        }
        return $out;
    }

    private static function collectFiles(string $root, string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name !== '.' && $name !== '..' && is_file("$dir/$name")) {
                $files[] = ['name' => "fichiers/$root/$name", 'bytes' => (string) file_get_contents("$dir/$name")];
            }
        }
        return $files;
    }

    /** Construit l'archive. Rendue en mémoire : elle part directement au navigateur. */
    public static function build(): array
    {
        $generatedAt = gmdate('c');
        $entries = [];
        $inventory = [];

        foreach (self::tables() as $name) {
            $rows = self::rowsOf($name);
            $body = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            $entries[] = ['name' => "donnees/$name.json", 'bytes' => $body];
            $inventory[] = ['table' => $name, 'lignes' => count($rows), 'octets' => strlen($body)];
        }

        $files = array_merge(
            self::collectFiles('coffre', Vault::directory()),
            self::collectFiles('parapheur', Signing::directory())
        );
        $entries = array_merge($entries, $files);
        $entries[] = ['name' => 'LISEZMOI.txt', 'bytes' => self::README . "\n"];

        $manifest = [
            'format' => 'toutadmin-export',
            'version' => 1,
            'genere_le' => $generatedAt,
            'instance' => Settings::get('company_name'),
            'tables' => $inventory,
            'fichiers' => array_map(static fn (array $file): array => [
                'nom' => $file['name'],
                'octets' => strlen($file['bytes']),
                'sha256' => hash('sha256', $file['bytes']),
            ], $files),
            'omissions' => [
                'empreintes de mots de passe',
                'secrets de double authentification',
                "jetons d'API et secrets de webhook",
                'sessions ouvertes',
                'réglages chiffrés',
            ],
        ];
        $entries[] = [
            'name' => 'meta/manifeste.json',
            'bytes' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
        ];

        $archive = (string) gzencode(Tar::pack($entries), 9);
        return [
            'fileName' => 'export-' . gmdate('Y-m-d\TH-i-s') . '.tar.gz',
            'buffer' => $archive,
            'tables' => count($inventory),
            'rows' => array_sum(array_column($inventory, 'lignes')),
            'files' => count($files),
            'bytes' => strlen($archive),
        ];
    }

    /** Ce que l'export contiendrait, sans le construire : c'est ce qu'affiche l'écran. */
    public static function preview(): array
    {
        $inventory = [];
        foreach (self::tables() as $name) {
            $inventory[] = ['table' => $name, 'lignes' => (int) Db::value("SELECT COUNT(*) FROM \"$name\"")];
        }
        $filled = array_values(array_filter($inventory, static fn (array $e): bool => $e['lignes'] > 0));
        usort($filled, static fn (array $a, array $b): int => $b['lignes'] <=> $a['lignes']);

        $count = static fn (string $dir): int => is_dir($dir) ? max(0, count(scandir($dir) ?: []) - 2) : 0;

        return [
            'tables' => $filled,
            'tableCount' => count($inventory),
            'rows' => array_sum(array_column($inventory, 'lignes')),
            'files' => [
                'coffre' => $count(Vault::directory()),
                'parapheur' => $count(Signing::directory()),
            ],
            'skipped' => self::SKIPPED_TABLES,
            'skippedColumns' => self::SKIPPED_COLUMNS,
        ];
    }
}
