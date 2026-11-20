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
 *
 * La palette par défaut est la première de la liste, et c'est la seule dont le
 * rail est clair. C'est elle qui a demandé que les surimpressions du rail —
 * survol, rubrique en service, filets — deviennent des jetons : elles étaient
 * écrites en blanc translucide dans les règles, ce qui interdisait par
 * construction toute palette dont la marque ne serait pas sombre.
 */
final class Themes
{
    public const DEFAULT_KEY = 'lavande';

    public const PALETTES = [
        [
            'key' => 'lavande',
            'label' => 'Indigo et lavande',
            'description' => "Rail clair posé comme une carte, fond lavande dégradé, indigo et violet pour l'action. Titres plus gros et plus gras, angles très ronds : la palette par défaut.",
            'swatches' => ['nav' => '#fbfaff', 'accent' => '#5b4ae8', 'surface' => '#ffffff', 'bg' => '#ecedfb'],
        ],
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
        [
            'key' => 'cyan',
            'label' => 'Cyan et violet',
            'description' => "La charte du template Toutadmin : rail bleu nuit, cyan pour l'action, fonds bleu nuit en sombre et gris très clair en clair, angles doux.",
            'swatches' => ['nav' => '#0b1128', 'accent' => '#00718c', 'surface' => '#ffffff', 'bg' => '#f4f6fc'],
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
