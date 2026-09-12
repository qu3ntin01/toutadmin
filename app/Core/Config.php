<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Réglages de déploiement : chemin de la base, clé de session, URL publique.
 *
 * Ils vivent dans un fichier que l'installateur écrit, jamais dans le dépôt :
 * une clé de session versionnée est une clé publique. Le fichier d'exemple,
 * lui, est versionné et documente chaque entrée.
 */
final class Config
{
    private static array $values = [];

    public static function load(?string $file = null): void
    {
        $file ??= APP_ROOT . '/config.php';
        $defaults = [
            'db_path' => APP_ROOT . '/data/app.sqlite',
            'data_dir' => APP_ROOT . '/data',
            'session_secret' => '',
            'base_url' => '',
            'trust_proxy' => false,
            'login_rate_limit' => 10,
            'global_rate_limit' => 300,
            'session_idle_minutes' => 60,
            'session_max_hours' => 12,
        ];
        $values = is_file($file) ? (array) require $file : [];
        self::$values = array_merge($defaults, $values);

        // La base et les téléversements ne doivent jamais être servis par le
        // serveur web : ils vivent au-dessus de la racine publique.
        if (!is_dir(self::$values['data_dir'])) {
            @mkdir(self::$values['data_dir'], 0770, true);
        }
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        return self::$values[$key] ?? $fallback;
    }

    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function isInstalled(): bool
    {
        return is_file(APP_ROOT . '/config.php') && is_file((string) self::get('db_path'));
    }
}
