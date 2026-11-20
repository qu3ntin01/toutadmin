<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

/**
 * Une horloge qui ne recule pas.
 *
 * Tout ce que la licence décide — le sursis de quarante-huit heures, la grâce,
 * l'échéance — se mesure contre une date. Or la date d'une machine appartient à
 * qui l'administre : reculer l'horloge d'un serveur prend dix secondes et
 * prolonge indéfiniment un sursis. C'est le moyen le plus simple, le plus
 * silencieux et le plus reproductible de neutraliser une licence, et aucun
 * chiffrement du code n'y change quoi que ce soit : le code chiffré lit la même
 * horloge menteuse.
 *
 * On ne cherche donc pas à savoir « quelle heure est-il » — question à laquelle
 * l'hôte répond ce qu'il veut — mais « quel est le plus tard dont cette
 * instance ait la preuve ». Trois sources, et l'on prend la plus avancée :
 *
 *   1. le jeton de licence en place. Sa date d'émission est **signée par
 *      l'éditeur** : on ne peut pas la déplacer vers l'avant sans la clé
 *      privée, et la déplacer vers l'arrière ne sert à rien ;
 *   2. la dernière ligne du journal d'audit, qui est **scellée par chaînage**.
 *      Reculer cet horodatage casse la chaîne, et la vérification du sceau le
 *      dit à l'endroit exact ;
 *   3. la marque haute déjà retenue, et l'heure système si elle va plus loin.
 *
 * La marque haute vit dans les réglages, où un administrateur peut l'écraser.
 * C'est pour cela qu'elle n'est jamais seule : les deux premières sources la
 * corrigent, et elles ne se falsifient pas sans laisser de trace.
 *
 * ── Ce que cela ne fait pas ─────────────────────────────────────────────
 *
 * Cela n'empêche pas d'avancer l'horloge — mais avancer l'horloge fait expirer
 * la licence plus tôt, pas plus tard : personne n'y a intérêt. Cela n'empêche
 * pas non plus quelqu'un de modifier le code qu'il héberge. Rien ne l'empêche,
 * sur un logiciel qu'on installe chez soi. Ce qui est visé est plus modeste et
 * plus utile : qu'on ne puisse pas neutraliser une licence **par accident, ni
 * en trois clics, ni sans laisser de trace**.
 */
final class LicenceHorloge
{
    public const CLE = 'licence_horloge_haute';

    /** Tolérance : une machine mal synchronisée dérive de quelques minutes. */
    public const DERIVE_TOLEREE = 600;

    /** Le plus tard dont le journal scellé porte la preuve. */
    public static function plancherScelle(): int
    {
        try {
            $vu = Db::value(
                "SELECT occurred_at FROM audit_log
                  WHERE hash IS NOT NULL AND hash <> '' ORDER BY id DESC LIMIT 1"
            );
        } catch (\Throwable) {
            // Pas encore de table : une instance qui s'installe. Rien à corroborer.
            return 0;
        }
        // Le journal range ses dates en UTC, « YYYY-MM-DD HH:MM:SS ».
        return $vu === null ? 0 : (int) strtotime((string) $vu . ' UTC');
    }

    /** La date d'émission du jeton en place, qui est signée. */
    public static function plancherDuJeton(?array $charge): int
    {
        return $charge === null ? 0 : (int) strtotime((string) ($charge['emise'] ?? ''));
    }

    /**
     * L'instant de référence : le plus tard des quatre, jamais moins que la
     * marque haute. Sans effet de bord — c'est « avancer » qui écrit.
     */
    public static function maintenant(?array $charge = null, ?int $horloge = null): int
    {
        $horloge ??= time();
        return max(
            $horloge,
            (int) strtotime((string) Settings::get(self::CLE)),
            self::plancherScelle(),
            self::plancherDuJeton($charge),
        );
    }

    /**
     * L'horloge système est-elle en retard sur ce qu'on sait ? Au-delà de la
     * dérive tolérée, ce n'est plus une machine mal réglée.
     */
    public static function recul(?array $charge = null, ?int $horloge = null): int
    {
        $horloge ??= time();
        $sur = self::maintenant($charge, 0);
        return $sur > $horloge + self::DERIVE_TOLEREE ? $sur - $horloge : 0;
    }

    /**
     * Faire avancer la marque haute. Appelée après chaque vérification : c'est
     * le seul endroit qui écrit, et il n'écrit jamais vers le passé.
     */
    public static function avancer(?array $charge = null, ?int $horloge = null): int
    {
        $vers = self::maintenant($charge, $horloge);
        $actuelle = (int) strtotime((string) Settings::get(self::CLE));
        if ($vers > $actuelle) {
            Settings::set(self::CLE, gmdate('c', $vers));
        }
        return $vers;
    }
}
