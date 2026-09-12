<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Settings;

/**
 * Palettes de l'instance.
 *
 * Deux axes à ne pas confondre : la palette engage l'identité visuelle de
 * l'entreprise et se choisit une fois, par l'administration ; le mode clair ou
 * sombre est un confort de lecture et appartient à chaque personne.
 */
final class Themes
{
    public const DEFAULT_KEY = 'institutionnel';

    public const PALETTES = [
        [
            'key' => 'institutionnel',
            'label' => 'Bleu institutionnel',
            'description' => "Bleu profond, contenu dense, angles droits. Le parti pris d'origine, sobre et lisible en toutes circonstances.",
            'swatches' => ['nav' => '#0b46d1', 'accent' => '#0a66ff', 'surface' => '#ffffff', 'bg' => '#eef1f6'],
        ],
        [
            'key' => 'moderne',
            'label' => 'Ardoise et indigo',
            'description' => "Neutres presque sans teinte, indigo violacé pour l'action, angles nettement plus doux : le vocabulaire des interfaces contemporaines.",
            'swatches' => ['nav' => '#242640', 'accent' => '#5546d6', 'surface' => '#ffffff', 'bg' => '#f5f6fa'],
        ],
        [
            'key' => 'vif',
            'label' => 'Magenta et violet',
            'description' => 'Couleurs franches, navigation en dégradé violet-magenta, fonds légèrement teintés. Pour une marque qui assume la couleur.',
            'swatches' => ['nav' => '#7c12c8', 'accent' => '#cc1268', 'surface' => '#ffffff', 'bg' => '#fbf6fc'],
        ],
    ];

    public static function keys(): array
    {
        return array_column(self::PALETTES, 'key');
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** La palette de l'instance, toujours une valeur connue. */
    public static function current(): string
    {
        $stored = Settings::get('theme_palette');
        return self::isValid($stored) ? $stored : self::DEFAULT_KEY;
    }

    public static function set(string $key): bool
    {
        if (!self::isValid($key)) {
            return false;
        }
        Settings::set('theme_palette', $key);
        return true;
    }

    public static function list(): array
    {
        $active = self::current();
        return array_map(
            static fn (array $palette): array => $palette + ['active' => $palette['key'] === $active],
            self::PALETTES
        );
    }

    public static function byKey(string $key): ?array
    {
        foreach (self::PALETTES as $palette) {
            if ($palette['key'] === $key) {
                return $palette;
            }
        }
        return null;
    }
}
