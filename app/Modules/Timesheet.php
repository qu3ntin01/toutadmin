<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Pointage des freelances.
 *
 * Un freelance n'a ni congés ni bulletin de paie : ce qui se compte chez lui,
 * c'est le temps passé. Le pointage est donc volontairement réduit à deux
 * gestes — commencer, terminer — et à une seule règle : un seul pointage
 * ouvert à la fois, faute de quoi les heures se compteraient deux fois.
 *
 * L'estimation n'est qu'une estimation : le taux horaire se déduit du TJM sur
 * une base de huit heures. Elle aide à suivre la mission, elle ne facture rien.
 */
final class Timesheet
{
    /** L'instant est écrit en ISO 8601 UTC, comme l'édition Node. */
    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /** La durée d'une entrée ; celle qui court se mesure jusqu'à maintenant. */
    public static function durationHours(array $entry, ?int $now = null): float
    {
        $start = strtotime((string) $entry['clock_in']);
        $end = $entry['clock_out'] !== null && $entry['clock_out'] !== ''
            ? strtotime((string) $entry['clock_out'])
            : ($now ?? time());
        if ($start === false || $end === false) {
            return 0.0;
        }
        return max(0.0, ($end - $start) / 3600);
    }

    public static function openEntry(int $employeeId): ?array
    {
        return Db::get(
            'SELECT * FROM time_entries WHERE employee_id = ? AND clock_out IS NULL ORDER BY id DESC LIMIT 1',
            [$employeeId]
        );
    }

    public static function entries(int $employeeId, int $limit = 60): array
    {
        return Db::all(
            'SELECT * FROM time_entries WHERE employee_id = ? ORDER BY clock_in DESC LIMIT ?',
            [$employeeId, $limit]
        );
    }

    /** Taux horaire dérivé du TJM (taux journalier moyen) sur une base de 8 heures. */
    public static function hourlyRate(mixed $dailyRate): float
    {
        $rate = (float) $dailyRate;
        return $rate > 0.0 ? $rate / 8 : 0.0;
    }

    public static function stats(int $employeeId, mixed $dailyRate): array
    {
        $now = time();
        $monthStart = (int) gmmktime(0, 0, 0, (int) gmdate('n', $now), 1, (int) gmdate('Y', $now));
        $entries = Db::all('SELECT * FROM time_entries WHERE employee_id = ?', [$employeeId]);

        $totalHours = 0.0;
        $monthHours = 0.0;
        foreach ($entries as $entry) {
            $hours = self::durationHours($entry, $now);
            $totalHours += $hours;
            if ((int) strtotime((string) $entry['clock_in']) >= $monthStart) {
                $monthHours += $hours;
            }
        }

        $rate = self::hourlyRate($dailyRate);
        return [
            'entryCount' => count($entries),
            'totalHours' => $totalHours,
            'monthHours' => $monthHours,
            'hourlyRate' => $rate,
            'totalEstimate' => $totalHours * $rate,
            'monthEstimate' => $monthHours * $rate,
        ];
    }

    /** Ouvre un pointage. Un second pointage ouvert est refusé, pas empilé. */
    public static function clockIn(int $employeeId): array
    {
        if (self::openEntry($employeeId) !== null) {
            return ['ok' => false, 'reason' => 'already-open'];
        }
        Db::run('INSERT INTO time_entries (employee_id, clock_in) VALUES (?, ?)', [$employeeId, self::now()]);
        return ['ok' => true];
    }

    public static function clockOut(int $employeeId): array
    {
        $open = self::openEntry($employeeId);
        if ($open === null) {
            return ['ok' => false, 'reason' => 'no-open-entry'];
        }
        Db::run('UPDATE time_entries SET clock_out = ? WHERE id = ?', [self::now(), (int) $open['id']]);
        return ['ok' => true];
    }

    /** Suppression d'une entrée : l'identifiant du membre borne la portée. */
    public static function removeEntry(int $employeeId, int $entryId): void
    {
        Db::run('DELETE FROM time_entries WHERE id = ? AND employee_id = ?', [$entryId, $employeeId]);
    }
}
