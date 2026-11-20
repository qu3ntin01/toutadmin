<?php

declare(strict_types=1);

namespace App\Core;

/**
 * L'heure, en temps universel.
 *
 * Les dates du produit sont écrites en UTC — c'est ce que rend « datetime('now') »
 * de SQLite, et ce que rend UTC_TIMESTAMP() de MySQL. Les calculer ici plutôt
 * que dans la requête a deux avantages : le SQL reste portable, et le décalage
 * demandé se lit en clair au lieu d'être une chaîne liée en paramètre, que
 * MySQL ne saurait de toute façon pas interpréter.
 */
final class Horloge
{
    /** Un instant, « 2026-09-14 08:30:00 », éventuellement décalé. */
    public static function instant(string $decalage = ''): string
    {
        return gmdate('Y-m-d H:i:s', self::quand($decalage));
    }

    /** Un jour, « 2026-09-14 », éventuellement décalé. */
    public static function jour(string $decalage = ''): string
    {
        return gmdate('Y-m-d', self::quand($decalage));
    }

    /** Dans n jours — ou il y a n jours, si le nombre est négatif. */
    public static function dansJours(int $jours): string
    {
        return self::jour(($jours >= 0 ? '+' : '') . $jours . ' days');
    }

    /** Dans n minutes, à la seconde près. */
    public static function dansMinutes(int $minutes): string
    {
        return self::instant(($minutes >= 0 ? '+' : '') . $minutes . ' minutes');
    }

    private static function quand(string $decalage): int
    {
        if ($decalage === '') {
            return time();
        }
        $quand = strtotime($decalage);
        if ($quand === false) {
            throw new \RuntimeException('Décalage de temps incompréhensible : ' . $decalage);
        }
        return $quand;
    }
}
