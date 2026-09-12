<?php

declare(strict_types=1);

namespace App\Core;

/** Réglages de l'instance : posés à l'installation, modifiables ensuite. */
final class Settings
{
    public const DEFAULTS = [
        'company_name' => 'Toutadmin',
        'default_locale' => 'fr',
        'annual_leave_days' => '25',
        'installed_at' => '',
        'installed_version' => '',
        'theme_palette' => 'institutionnel',
        'require_2fa_admin' => '0',
        'require_2fa_all' => '0',
        'audit_retention_days' => '365',
        'backup_enabled' => '1',
        'backup_interval_minutes' => '60',
        'backup_keep' => '24',
        'password_max_age_days' => '0',
    ];

    public static function get(string $key): string
    {
        $value = Db::value('SELECT value FROM settings WHERE key = ?', [$key]);
        return $value === null ? (self::DEFAULTS[$key] ?? '') : (string) $value;
    }

    public static function set(string $key, string|int $value): void
    {
        Db::run(
            'INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime(\'now\'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
            [$key, (string) $value]
        );
    }

    public static function setMany(array $entries): void
    {
        Db::transaction(static function () use ($entries): void {
            foreach ($entries as $key => $value) {
                self::set((string) $key, $value);
            }
        });
    }

    public static function all(): array
    {
        $rows = Db::all('SELECT key, value FROM settings');
        $out = self::DEFAULTS;
        foreach ($rows as $row) {
            $out[$row['key']] = $row['value'];
        }
        return $out;
    }

    public static function isTrue(string $key): bool
    {
        return self::get($key) === '1';
    }
}
