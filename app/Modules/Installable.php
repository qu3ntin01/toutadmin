<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Settings;

/**
 * Le produit s'installe sur l'écran d'accueil d'un téléphone ou d'un poste :
 * un manifeste et une icône, rien de plus — comme dans l'édition Node.
 *
 * Les deux portent la marque de l'instance — son nom, ses initiales, la couleur
 * de sa palette — et non celle de l'éditeur. Ils sont publics, comme la page de
 * connexion qui les annonce déjà, et ne disent rien de plus qu'elle.
 *
 * Il n'y a pas de travailleur de service : une page de gestion lue hors ligne
 * serait une page périmée qui a l'air à jour.
 */
final class Installable
{
    private static function marque(): array
    {
        $nom = (string) Settings::get('company_name');
        if ($nom === '') {
            $nom = 'Toutadmin';
        }
        $palette = Themes::byKey(Themes::current()) ?? Themes::list()[0];
        return [
            'nom' => $nom,
            // La même règle que la pastille de la barre latérale.
            'initiales' => mb_strtoupper(mb_substr($nom, 0, 2)),
            'couleur' => $palette['swatches']['accent'],
            'fond' => $palette['swatches']['bg'],
        ];
    }

    public static function manifeste(): array
    {
        $m = self::marque();
        return [
            'name' => $m['nom'],
            'short_name' => mb_strlen($m['nom']) > 12 ? $m['initiales'] : $m['nom'],
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => $m['fond'],
            'theme_color' => $m['couleur'],
            'icons' => [['src' => '/icone.svg', 'sizes' => 'any', 'type' => 'image/svg+xml', 'purpose' => 'any']],
        ];
    }

    public static function icone(): string
    {
        $m = self::marque();
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">'
            . '<rect width="512" height="512" rx="112" fill="' . $x($m['couleur']) . '"/>'
            . '<text x="256" y="256" dy=".35em" text-anchor="middle" font-family="system-ui, -apple-system, Segoe UI, Roboto, sans-serif"'
            . ' font-size="220" font-weight="700" fill="#ffffff">' . $x($m['initiales']) . '</text></svg>';
    }
}
