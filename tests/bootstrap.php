<?php

/**
 * Harnais de test.
 *
 * Pas de dépendance non plus ici : quelques fonctions d'assertion et un
 * simulateur de requête qui traverse le noyau sans serveur web. Les tests
 * parlent donc au vrai code, pas à une version arrangée pour eux.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Db;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class Tests
{
    public static int $passed = 0;
    public static array $failures = [];
    public static string $current = '';

    public static function run(string $name, callable $body): void
    {
        self::$current = $name;
        self::fresh();
        try {
            $body();
            self::$passed++;
            echo "  ✓ $name\n";
        } catch (\Throwable $error) {
            self::$failures[] = $name . ' — ' . $error->getMessage();
            echo "  ✗ $name\n    " . $error->getMessage() . "\n";
        }
    }

    /** Une base neuve par test : aucun test n'hérite de l'état d'un autre. */
    public static function fresh(): void
    {
        $dir = sys_get_temp_dir() . '/toutadmin-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $path = $dir . '/' . bin2hex(random_bytes(6)) . '.sqlite';
        Db::reset();
        Config::set('db_path', $path);
        Config::set('data_dir', $dir);
        Db::migrate();
        $_COOKIE = [];
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        Session::withoutCookies();
        Session::start(null);
    }

    public static function summary(): int
    {
        echo "\n" . self::$passed . " test(s) réussi(s)";
        if (self::$failures !== []) {
            echo ', ' . count(self::$failures) . " en échec :\n";
            foreach (self::$failures as $failure) {
                echo "  - $failure\n";
            }
            return 1;
        }
        echo ".\n";
        return 0;
    }
}

function assertTrue(bool $condition, string $message = 'condition fausse'): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(($message !== '' ? $message . ' — ' : '')
            . 'attendu ' . var_export($expected, true) . ', obtenu ' . var_export($actual, true));
    }
}

function assertContains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException(($message !== '' ? $message . ' — ' : '') . "« $needle » absent de la réponse");
    }
}

/** Joue une requête à travers le noyau, comme le ferait le serveur web. */
function visit(string $method, string $path, array $body = [], array $query = []): Response
{
    $kernel = new Kernel();
    if ($method === 'POST' && !isset($body['_csrf'])) {
        $body['_csrf'] = \App\Core\Csrf::token();
    }
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $path;
    return $kernel->handle(new Request($method, '/' . trim(parse_url($path, PHP_URL_PATH) ?: '/', '/'), $query, $body));
}

/** Installe une instance : entreprise, compte d'administration, un salarié. */
function seed(string $adminPassword = 'Administration-2026!'): array
{
    \App\Core\Settings::setMany(['company_name' => 'Vertane Industries', 'default_locale' => 'fr']);
    $adminId = \App\Modules\Users::create([
        'role' => 'admin', 'email' => 'admin@demo.test', 'password' => $adminPassword,
        'first_name' => 'Administrateur', 'last_name' => 'Général',
    ]);
    $memberId = \App\Modules\Users::create([
        'role' => 'employee', 'email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Claire', 'last_name' => 'Moreau', 'grade' => 'Responsable qualité',
    ]);
    return ['admin' => $adminId, 'member' => $memberId];
}
