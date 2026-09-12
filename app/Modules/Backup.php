<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\Db;
use App\Core\Settings;
use App\Core\Tar;

/**
 * Sauvegarde et restauration de l'instance.
 *
 * Une sauvegarde de la seule base serait un piège : les bulletins du
 * coffre-fort et les documents du parapheur vivent hors de la base. Restaurer
 * une base sans eux rendrait une instance qui prétend détenir des documents
 * disparus. L'archive embarque donc la base *et* les dossiers de fichiers.
 *
 * La base est copiée par « VACUUM INTO », qui produit une copie cohérente d'une
 * base en cours d'écriture — copier le fichier à la main ne le serait pas, le
 * journal WAL vivant à côté.
 */
final class Backup
{
    public const FORMAT_VERSION = 1;

    private const PREFIX = 'sauvegarde-';
    private const SUFFIX = '.tar.gz';

    // Une archive téléversée pour restauration : au-delà, l'opérateur dépose le
    // fichier directement dans le dossier des sauvegardes.
    public const MAX_UPLOAD_BYTES = 64 * 1024 * 1024;

    public const ARCHIVE_ROOTS = ['db', 'coffre', 'parapheur', 'meta'];

    private const DEFAULT_INTERVAL = 60;
    private const DEFAULT_KEEP = 24;

    /**
     * La table des sessions n'est pas restaurée : remplacer les sessions
     * ouvertes par celles d'hier déconnecterait l'administrateur au milieu de
     * l'opération, sans rien apporter.
     */
    public const NOT_RESTORED = ['sessions'];

    public static function directory(): string
    {
        return (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/sauvegardes';
    }

    /** Les dossiers de fichiers embarqués, sous le nom qu'ils portent dans l'archive. */
    public static function fileRoots(): array
    {
        return [
            ['root' => 'coffre', 'dir' => Vault::directory()],
            // Les documents mis à la signature font preuve : une sauvegarde qui
            // les oublierait rendrait une instance dont les attestations
            // pointent vers rien.
            ['root' => 'parapheur', 'dir' => Signing::directory()],
        ];
    }

    private static function ensureDir(): void
    {
        $dir = self::directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
    }

    public static function config(): array
    {
        $interval = (int) Settings::get('backup_interval_minutes') ?: self::DEFAULT_INTERVAL;
        $keep = (int) Settings::get('backup_keep') ?: self::DEFAULT_KEEP;
        return [
            'enabled' => Settings::get('backup_enabled') !== '0',
            'intervalMinutes' => min(1440, max(15, $interval)),
            'keep' => min(500, max(2, $keep)),
        ];
    }

    public static function setConfig(bool $enabled, int $intervalMinutes, int $keep): void
    {
        Settings::setMany([
            'backup_enabled' => $enabled ? '1' : '0',
            'backup_interval_minutes' => (string) min(1440, max(15, $intervalMinutes ?: self::DEFAULT_INTERVAL)),
            'backup_keep' => (string) min(500, max(2, $keep ?: self::DEFAULT_KEEP)),
        ]);
    }

    /** Tous les fichiers d'un dossier, à plat : nos dossiers n'ont pas de sous-niveaux. */
    private static function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $names = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file("$dir/$entry")) {
                $names[] = $entry;
            }
        }
        sort($names);
        return $names;
    }

    /**
     * Écrit une sauvegarde complète et rend sa description.
     * Le motif dit ce qui l'a déclenchée : automatique, manuelle, avant restauration.
     */
    public static function create(string $reason = 'manuelle', string $label = ''): array
    {
        self::ensureDir();

        // Copie cohérente de la base, dans un fichier temporaire hors du dossier
        // servi par le serveur web.
        $staging = sys_get_temp_dir() . '/ta-backup-' . bin2hex(random_bytes(8)) . '.sqlite';
        Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($staging));

        try {
            $entries = [];
            $manifest = [
                'format' => self::FORMAT_VERSION,
                'createdAt' => gmdate('c'),
                'reason' => $reason,
                'label' => mb_substr($label, 0, 120),
                'files' => [],
            ];

            $push = static function (string $name, string $path) use (&$entries, &$manifest): void {
                $data = (string) file_get_contents($path);
                $entries[] = ['name' => $name, 'bytes' => $data];
                $manifest['files'][] = ['name' => $name, 'size' => strlen($data), 'sha256' => hash('sha256', $data)];
            };

            $push('db/app.sqlite', $staging);
            foreach (self::fileRoots() as $entry) {
                foreach (self::filesIn($entry['dir']) as $name) {
                    $push($entry['root'] . '/' . $name, $entry['dir'] . '/' . $name);
                }
            }

            // Le manifeste est ajouté en dernier : il décrit tout ce qui
            // précède, et c'est lui qui permet de vérifier chaque fichier à la
            // restauration.
            $entries[] = [
                'name' => 'meta/manifeste.json',
                'bytes' => (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ];

            $archive = (string) gzencode(Tar::pack($entries), 6);
            // L'horodatage s'arrête à la seconde ; deux sauvegardes rapprochées
            // — celle de sécurité prise juste avant une restauration, par
            // exemple — porteraient le même nom et la première serait écrasée.
            // Un suffixe aléatoire l'évite.
            $stamp = gmdate('Y-m-d\TH-i-s');
            $fileName = self::PREFIX . $stamp . '-' . bin2hex(random_bytes(2)) . self::SUFFIX;
            $target = self::directory() . '/' . $fileName;
            file_put_contents($target, $archive);
            chmod($target, 0600);

            return [
                'fileName' => $fileName, 'bytes' => strlen($archive), 'files' => count($manifest['files']),
                'reason' => $reason, 'createdAt' => $manifest['createdAt'],
            ];
        } finally {
            @unlink($staging);
        }
    }

    public static function isBackupName(string $name): bool
    {
        return str_starts_with($name, self::PREFIX) && str_ends_with($name, self::SUFFIX)
            && !str_contains($name, '/') && !str_contains($name, '..');
    }

    public static function list(): array
    {
        self::ensureDir();
        $rows = [];
        foreach (scandir(self::directory()) ?: [] as $name) {
            if (!self::isBackupName($name)) {
                continue;
            }
            $path = self::directory() . '/' . $name;
            $rows[] = [
                'fileName' => $name,
                'bytes' => (int) filesize($path),
                'createdAt' => gmdate('c', (int) filemtime($path)),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => strcmp($b['createdAt'], $a['createdAt']) ?: strcmp($b['fileName'], $a['fileName']));
        return $rows;
    }

    public static function pathOf(string $fileName): ?string
    {
        if (!self::isBackupName($fileName)) {
            return null;
        }
        $target = self::directory() . '/' . basename($fileName);
        return is_file($target) ? $target : null;
    }

    /** Supprime les sauvegardes au-delà du nombre conservé. Rend les noms retirés. */
    public static function prune(): array
    {
        $keep = self::config()['keep'];
        $removed = [];
        foreach (array_slice(self::list(), $keep) as $entry) {
            @unlink(self::directory() . '/' . $entry['fileName']);
            $removed[] = $entry['fileName'];
        }
        return $removed;
    }

    public static function remove(string $fileName): bool
    {
        $target = self::pathOf($fileName);
        if ($target === null) {
            return false;
        }
        unlink($target);
        return true;
    }

    /**
     * Ouvre une archive et en vérifie l'intégrité, sans rien écrire.
     */
    public static function inspect(string $buffer): array
    {
        $plain = @gzdecode($buffer);
        if ($plain === false) {
            return ['ok' => false, 'message' => "Ce fichier n'est pas une archive de sauvegarde lisible."];
        }

        try {
            $raw = Tar::unpack($plain);
        } catch (\Throwable $error) {
            return ['ok' => false, 'message' => $error->getMessage()];
        }

        $entries = [];
        foreach ($raw as $entry) {
            if (Tar::safeName($entry['name'], self::ARCHIVE_ROOTS) === null) {
                return ['ok' => false, 'message' => "Entrée refusée dans l'archive : " . $entry['name']];
            }
            $entries[$entry['name']] = $entry['data'];
        }

        if (!isset($entries['meta/manifeste.json'])) {
            return ['ok' => false, 'message' => 'Archive sans manifeste : origine inconnue.'];
        }
        $manifest = json_decode($entries['meta/manifeste.json'], true);
        if (!is_array($manifest)) {
            return ['ok' => false, 'message' => 'Manifeste illisible.'];
        }
        if (($manifest['format'] ?? null) !== self::FORMAT_VERSION) {
            return ['ok' => false, 'message' => 'Format de sauvegarde ' . ($manifest['format'] ?? '?')
                . ' non pris en charge par cette version.'];
        }
        if (!isset($entries['db/app.sqlite'])) {
            return ['ok' => false, 'message' => 'Archive sans base de données.'];
        }

        // Chaque fichier est vérifié contre l'empreinte inscrite au manifeste :
        // le contrôle de tar ne couvre que les en-têtes, pas le contenu.
        foreach ($manifest['files'] as $file) {
            $data = $entries[$file['name']] ?? null;
            if ($data === null) {
                return ['ok' => false, 'message' => "Fichier manquant dans l'archive : " . $file['name']];
            }
            if (strlen($data) !== (int) $file['size'] || hash('sha256', $data) !== $file['sha256']) {
                return ['ok' => false, 'message' => "Fichier altéré dans l'archive : " . $file['name']];
            }
        }

        return ['ok' => true, 'manifest' => $manifest, 'entries' => $entries];
    }

    public static function inspectFile(string $fileName): array
    {
        $target = self::pathOf($fileName);
        if ($target === null) {
            return ['ok' => false, 'message' => 'Sauvegarde introuvable.'];
        }
        return self::inspect((string) file_get_contents($target));
    }

    // ---------- Restauration ----------

    private static function tablesOf(string $schema): array
    {
        return array_column(
            Db::all("SELECT name FROM $schema.sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            'name'
        );
    }

    private static function columnsOf(string $schema, string $table): array
    {
        return array_column(Db::all("PRAGMA $schema.table_info(\"$table\")"), 'name');
    }

    /**
     * Restaure l'archive dans l'instance en cours.
     *
     * La base n'est pas remplacée sur le disque — la connexion ouverte ne le
     * supporterait pas — mais recopiée table par table depuis l'archive
     * attachée, dans une seule transaction. L'application reste debout, et un
     * échec en cours de route ne laisse pas une base à moitié écrite.
     */
    public static function restore(string $buffer, ?int $by = null): array
    {
        $opened = self::inspect($buffer);
        if (!$opened['ok']) {
            return $opened;
        }

        $staging = sys_get_temp_dir() . '/ta-restore-' . bin2hex(random_bytes(8)) . '.sqlite';
        file_put_contents($staging, $opened['entries']['db/app.sqlite']);
        chmod($staging, 0600);

        $report = ['tables' => 0, 'rows' => 0, 'files' => 0, 'skipped' => []];
        $pdo = Db::pdo();

        try {
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->exec('ATTACH DATABASE ' . $pdo->quote($staging) . ' AS restauration');

            try {
                $sourceTables = self::tablesOf('restauration');
                $pdo->beginTransaction();
                foreach (self::tablesOf('main') as $table) {
                    if (in_array($table, self::NOT_RESTORED, true)) {
                        continue;
                    }
                    // Une table absente de l'archive n'existait pas alors : la
                    // vider est la seule façon de rendre l'instance conforme à
                    // la sauvegarde.
                    $pdo->exec("DELETE FROM main.\"$table\"");
                    if (!in_array($table, $sourceTables, true)) {
                        $report['skipped'][] = $table;
                        continue;
                    }

                    // On ne recopie que les colonnes communes : une sauvegarde
                    // antérieure à une migration n'a pas les colonnes ajoutées
                    // depuis.
                    $target = self::columnsOf('main', $table);
                    $shared = array_values(array_filter(
                        self::columnsOf('restauration', $table),
                        static fn (string $column): bool => in_array($column, $target, true)
                    ));
                    if ($shared === []) {
                        continue;
                    }
                    $quoted = implode(', ', array_map(static fn (string $c): string => "\"$c\"", $shared));
                    $report['rows'] += (int) $pdo->exec(
                        "INSERT INTO main.\"$table\" ($quoted) SELECT $quoted FROM restauration.\"$table\""
                    );
                    $report['tables']++;
                }
                $pdo->commit();
            } catch (\Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $error;
            } finally {
                $pdo->exec('DETACH DATABASE restauration');
                $pdo->exec('PRAGMA foreign_keys = ON');
            }

            $integrity = (string) Db::value('PRAGMA integrity_check');
            if ($integrity !== 'ok') {
                return ['ok' => false, 'message' => "Base incohérente après restauration : $integrity"];
            }

            // Les dossiers de fichiers sont remis à l'état de l'archive : ce qui
            // n'y figure pas n'existait pas au moment de la sauvegarde.
            foreach (self::fileRoots() as $entry) {
                if (!is_dir($entry['dir'])) {
                    mkdir($entry['dir'], 0700, true);
                }
                foreach (self::filesIn($entry['dir']) as $name) {
                    @unlink($entry['dir'] . '/' . $name);
                }
                foreach ($opened['entries'] as $name => $data) {
                    if (!str_starts_with($name, $entry['root'] . '/')) {
                        continue;
                    }
                    $file = $entry['dir'] . '/' . basename($name);
                    file_put_contents($file, $data);
                    chmod($file, 0600);
                    $report['files']++;
                }
            }

            return ['ok' => true, 'manifest' => $opened['manifest'], 'report' => $report, 'by' => $by];
        } finally {
            @unlink($staging);
        }
    }

    // ---------- Balayage ----------

    /**
     * Vrai si l'intervalle configuré est écoulé depuis la dernière archive.
     *
     * Un site PHP ne tourne qu'au moment d'une requête : l'échéance se lit donc
     * sur les archives présentes, et c'est une tâche planifiée de l'hébergeur
     * qui appelle ce balayage (voir tools/cron.php).
     */
    public static function isDue(?int $now = null): bool
    {
        $config = self::config();
        if (!$config['enabled']) {
            return false;
        }
        $now ??= time();
        $latest = self::list()[0] ?? null;
        $lastRun = $latest === null ? 0 : strtotime($latest['createdAt']);
        return $now - $lastRun >= $config['intervalMinutes'] * 60;
    }

    public static function runScheduled(): ?array
    {
        if (!self::isDue()) {
            return null;
        }
        $created = self::create('automatique');
        $removed = self::prune();

        // Une sauvegarde qui reste sur le serveur qu'elle protège ne protège de
        // rien : elle part aux destinations actives dans la foulée.
        $sent = Offsite::enabled() === []
            ? []
            : Offsite::afterBackup(
                $created['fileName'],
                (string) file_get_contents((string) self::pathOf($created['fileName'])),
                self::config()['keep']
            );

        return $created + ['removed' => $removed, 'offsite' => $sent];
    }

    public static function summary(): array
    {
        $archives = self::list();
        return self::config() + [
            'count' => count($archives),
            'bytes' => array_sum(array_column($archives, 'bytes')),
            'latest' => $archives[0] ?? null,
        ];
    }
}
