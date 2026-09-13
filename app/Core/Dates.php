<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Dates écrites dans la langue du lecteur.
 *
 * L'édition Node s'appuie sur « toLocaleDateString », que le moteur JavaScript
 * porte toujours. PHP, lui, n'a l'équivalent que si l'extension intl est
 * installée — et elle ne l'est pas partout sur un hébergement mutualisé. Les
 * deux chemins sont donc écrits :
 *
 *   — avec intl, la mise en forme est celle d'ICU, c'est-à-dire exactement
 *     celle de l'autre édition ;
 *   — sans intl, les noms de jours et de mois viennent des dictionnaires, et
 *     l'ordre des éléments suit la langue (13 septembre / September 13).
 *
 * Dans les deux cas, une date vide rend « — » plutôt qu'une fausse date.
 */
final class Dates
{
    /** Les langues où le mois précède le jour. */
    private const MONTH_FIRST = ['en', 'ja', 'ko', 'zh'];

    private static function parse(?string $value): ?int
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $time = strtotime(str_contains($raw, 'T') || str_contains($raw, ' ') ? $raw : $raw . ' 00:00:00 UTC');
        return $time === false ? null : $time;
    }

    /**
     * Mise en forme par ICU, à partir d'un « squelette » : on décrit les
     * éléments voulus (jour, mois, année) et la bibliothèque choisit l'ordre
     * et la ponctuation de la langue — « 13 septembre » mais « September 13 ».
     * C'est ce que fait « toLocaleDateString » dans l'autre édition.
     */
    private static function icu(int $time, string $skeleton): ?string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }
        $locale = self::icuLocale();
        $pattern = class_exists(\IntlDatePatternGenerator::class)
            ? (new \IntlDatePatternGenerator($locale))->getBestPattern($skeleton)
            : $skeleton;
        $formatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::NONE,
            'UTC',
            null,
            $pattern
        );
        $out = $formatter->format($time);
        return $out === false ? null : $out;
    }

    /** La date courte de la langue : « 13/09/2026 », « 9/13/2026 »… */
    private static function icuShort(int $time): ?string
    {
        if (!class_exists(\IntlDateFormatter::class)) {
            return null;
        }
        // « yMd » plutôt que le style court d'ICU : celui-ci abrège l'année
        // sur deux chiffres dans plusieurs langues, là où l'autre édition
        // l'écrit en entier.
        return self::icu($time, 'yMd');
    }

    /**
     * ICU écrit les chiffres arabes en indo-arabes par défaut ; le moteur de
     * l'autre édition, non. On demande donc explicitement les chiffres latins,
     * pour que les deux éditions affichent la même date.
     */
    private static function icuLocale(): string
    {
        return I18n::current() . '@numbers=latn';
    }

    /** « dimanche 13 septembre 2026 » — jour de la semaine compris. */
    public static function long(?string $value): string
    {
        $time = self::parse($value);
        if ($time === null) {
            return '—';
        }
        $icu = self::icu($time, 'EEEEdMMMM');
        if ($icu !== null) {
            return $icu;
        }
        // Les clés « cal.day1 » à « cal.day7 » et « cal.month1 » à
        // « cal.month12 » portent les noms longs dans les seize langues.
        $dayKey = 'cal.day' . (int) gmdate('N', $time);
        return t($dayKey) . ' ' . self::day($value);
    }

    /** « 13 septembre 2026 » — sans le jour de la semaine. */
    public static function day(?string $value): string
    {
        $time = self::parse($value);
        if ($time === null) {
            return '—';
        }
        $icu = self::icu($time, 'dMMMMy');
        if ($icu !== null) {
            return $icu;
        }
        $monthKey = 'cal.month' . (int) gmdate('n', $time);
        $month = t($monthKey);
        $number = (int) gmdate('j', $time);
        $year = gmdate('Y', $time);
        return in_array(I18n::current(), self::MONTH_FIRST, true)
            ? "$month $number, $year"
            : "$number $month $year";
    }

    /** La date en chiffres, dans l'ordre de la langue. */
    public static function short(?string $value): string
    {
        $time = self::parse($value);
        if ($time === null) {
            return '—';
        }
        $icu = self::icuShort($time);
        if ($icu !== null) {
            return $icu;
        }
        return gmdate('d/m/Y', $time);
    }

    /** Date et heure, pour un horodatage. */
    public static function moment(?string $value): string
    {
        $time = self::parse($value);
        if ($time === null) {
            return '—';
        }
        return self::short($value) . ' ' . gmdate('H:i', $time);
    }

    /** L'heure seule. */
    public static function hour(?string $value): string
    {
        $time = self::parse($value);
        return $time === null ? '—' : gmdate('H:i', $time);
    }
}
