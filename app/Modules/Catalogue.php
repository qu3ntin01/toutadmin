<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Settings;

/**
 * Modules optionnels. Chacun est éteint par défaut : une instance n'embarque
 * que ce dont elle a besoin, et l'administration débloque le reste au fur et
 * à mesure.
 *
 * Le nom, ce que fait le module et sa réserve vivent dans le dictionnaire, sous
 * « mod.<clé>.label », « .what » et « .caveat ». Ils étaient écrits ici, en
 * français : un administrateur japonais lisait donc sa liste de modules en
 * français, au milieu d'un écran traduit. La liste ne garde que ce qui ne se
 * traduit pas — la clé et l'adresse.
 *
 * La réserve dit ce que le module ne garantit pas. Elle est affichée à
 * l'activation plutôt que cachée dans une documentation : on la lit au moment
 * où l'on décide, pas le jour où l'on découvre.
 */
final class Catalogue
{
    public const MODULES = [
        [
            'key' => 'comptabilite',
            'href' => '/comptabilite',
        ],
        [
            'key' => 'paie',
            'href' => '/paie',
        ],
        [
            'key' => 'facturation-electronique',
            'href' => '/facturation-electronique',
        ],
        [
            'key' => 'stock',
            'href' => '/stock',
        ],
        [
            'key' => 'tresorerie',
            'href' => '/tresorerie',
        ],
        [
            'key' => 'immobilisations',
            'href' => '/immobilisations',
        ],
        [
            'key' => 'crm',
            'href' => '/crm',
        ],
    ];

    public static function keys(): array
    {
        return array_column(self::MODULES, 'key');
    }

    public static function isEnabled(string $key): bool
    {
        return Settings::get('module.' . $key) === '1';
    }

    public static function setEnabled(string $key, bool $enabled): bool
    {
        if (!in_array($key, self::keys(), true)) {
            return false;
        }
        Settings::set('module.' . $key, $enabled ? '1' : '0');
        return true;
    }

    /** La liste complète, chacun avec son état : c'est l'écran d'activation. */
    public static function list(): array
    {
        return array_map(
            static fn (array $module): array => $module + ['enabled' => self::isEnabled($module['key'])],
            self::MODULES
        );
    }

    public static function enabled(): array
    {
        return array_values(array_filter(self::list(), static fn (array $m): bool => $m['enabled']));
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::MODULES as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }
        return null;
    }
}
