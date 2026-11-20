<?php

declare(strict_types=1);

namespace App\Api;

use App\Modules\ApiTokens;

/**
 * Le catalogue de l'API, en un seul endroit.
 *
 * Chaque fonction se déclare une fois : sa méthode, son chemin, la portée
 * qu'elle exige, ce qu'elle fait, ce qu'elle prend, ce qu'elle rend, et le code
 * qui l'exécute. Tout le reste en découle — le routeur se construit à partir
 * d'ici, la découverte (`GET /api/v1/`) le récite, le document OpenAPI le
 * traduit, et la documentation du site engendre sa page de référence avec.
 *
 * Ce n'est pas une élégance : c'est la seule façon qu'une API rendue publique
 * ne mente pas sur elle-même. Une route ajoutée à côté du manifeste n'est pas
 * montée du tout, et un test le vérifie — mieux vaut une fonction absente
 * qu'une fonction non documentée qu'on découvre en production.
 *
 * Le manifeste de l'édition Node porte les mêmes fonctions, aux mêmes chemins,
 * sous les mêmes portées. Un outil écrit pour l'une marche sur l'autre.
 */
final class Manifeste
{
    public const BASE = '/api/v1';

    /** Les groupes, dans l'ordre où la découverte les récite. */
    public static function groupes(): array
    {
        return [
            Annuaire::groupe(),
            Rh::groupe(),
            Gestion::groupe(),
            Projets::groupe(),
            Pilotage::groupe(),
            Documents::groupe(),
            Exploitation::groupe(),
        ];
    }

    /** Toutes les fonctions, à plat, chacune sachant de quel groupe elle vient. */
    public static function toutes(): array
    {
        $toutes = [];
        foreach (self::groupes() as $groupe) {
            foreach ($groupe['fonctions'] as $fonction) {
                $fonction['groupe'] = $groupe['cle'];
                $fonction['ecrit'] = self::estEcriture($fonction);
                $fonction['parametres'] ??= [];
                $fonction['rend'] ??= [];
                $toutes[] = $fonction;
            }
        }
        return $toutes;
    }

    /** Une portée d'écriture se reconnaît à son suffixe, jamais à un drapeau posé à la main. */
    public static function estEcriture(array $fonction): bool
    {
        return str_ends_with((string) $fonction['portee'], ApiTokens::WRITE_SUFFIX);
    }

    /** Le chemin complet, tel qu'il s'appelle : `/api/v1/collaborateurs`. */
    public static function cheminComplet(array $fonction): string
    {
        return self::BASE . $fonction['chemin'];
    }

    /** La clé d'une fonction dans les journaux et les tests : « GET /collaborateurs ». */
    public static function cle(array $fonction): string
    {
        return $fonction['methode'] . ' ' . $fonction['chemin'];
    }

    /**
     * Le catalogue tel que `GET /api/v1/` le rend, filtré par ce que le jeton peut.
     *
     * Un jeton qui n'a que l'annuaire ne doit pas apprendre, en lisant la
     * racine, quelles routes de paie existent : la découverte se tait sur ce
     * qu'elle refuserait de servir.
     */
    public static function pourJeton(?array $jeton): array
    {
        $vus = [];
        foreach (self::groupes() as $groupe) {
            $fonctions = [];
            foreach ($groupe['fonctions'] as $fonction) {
                if (!ApiTokens::allows($jeton, (string) $fonction['portee'])) {
                    continue;
                }
                $fonctions[] = [
                    'methode' => $fonction['methode'],
                    'chemin' => self::cheminComplet($fonction),
                    'portee' => $fonction['portee'],
                    'ecrit' => self::estEcriture($fonction),
                    'resume' => $fonction['resume'],
                ];
            }
            if ($fonctions !== []) {
                $vus[] = [
                    'groupe' => $groupe['cle'],
                    'titre' => $groupe['titre'],
                    'resume' => $groupe['resume'],
                    'fonctions' => $fonctions,
                ];
            }
        }
        return $vus;
    }
}
