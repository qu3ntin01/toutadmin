<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Accès à la base.
 *
 * SQLite par PDO : le même schéma que l'édition Node, un fichier unique à
 * sauvegarder, et rien à installer sur l'hébergement. L'accès passe toujours
 * par des requêtes préparées — aucune valeur n'est concaténée dans du SQL.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $path = (string) Config::get('db_path');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // WAL : un lecteur ne bloque pas l'écrivain, ce qui compte sur un
        // hébergement où plusieurs processus PHP servent la même base.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::$pdo = $pdo;
        return $pdo;
    }

    /** Repart de zéro : utilisé par la suite de tests, jamais en production. */
    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::pdo()->prepare($sql);
        $statement->execute(self::normalise($params));
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
        foreach (self::statements($sql) as $statement) {
            // CREATE TABLE devient CREATE TABLE IF NOT EXISTS : le schéma est
            // rejoué à chaque démarrage, comme dans l'édition Node.
            $statement = preg_replace('/^CREATE TABLE (?!IF NOT EXISTS)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
            $statement = preg_replace('/^CREATE (UNIQUE )?INDEX (?!IF NOT EXISTS)/i', 'CREATE $1INDEX IF NOT EXISTS ', (string) $statement);
            $pdo->exec((string) $statement);
        }
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
