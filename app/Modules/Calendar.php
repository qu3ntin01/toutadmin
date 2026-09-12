<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Agenda.
 *
 * Un agenda d'entreprise ne se remplit pas à la main : les congés approuvés,
 * les réservations de salle, les formations et la fin de contrat s'y posent
 * d'eux-mêmes. Ce qui est saisi à la main, ce sont les rendez-vous — privés
 * par défaut, partageables avec l'équipe ou le service.
 */
final class Calendar
{
    public const EVENT_CATEGORIES = ['Personnel', 'Réunion', 'Déplacement', 'Formation', 'Télétravail', 'Autre'];
    public const VISIBILITIES = ['Privé', 'Équipe', 'Service'];

    /** Un mois valide au format AAAA-MM, sinon le mois courant. */
    public static function normalizeMonth(?string $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) ? $value : gmdate('Y-m');
    }

    public static function monthBounds(string $month): array
    {
        [$year, $m] = array_map('intval', explode('-', $month));
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $m), new \DateTimeZone('UTC'));
        $last = $first->modify('last day of this month');
        return ['first' => $first, 'last' => $last, 'firstISO' => $first->format('Y-m-d'), 'lastISO' => $last->format('Y-m-d')];
    }

    public static function shiftMonth(string $month, int $delta): string
    {
        $bounds = self::monthBounds($month);
        return $bounds['first']->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
    }

    /**
     * Grille du mois, semaines commençant le lundi : six lignes de sept jours,
     * débordant sur les mois voisins pour que la grille soit toujours pleine.
     */
    public static function buildGrid(string $month): array
    {
        ['first' => $first, 'last' => $last] = self::monthBounds($month);
        $leading = ((int) $first->format('N')) - 1;
        $cursor = $first->modify("-$leading day");
        $todayISO = gmdate('Y-m-d');

        $weeks = [];
        while (count($weeks) < 6) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $iso = $cursor->format('Y-m-d');
                $week[] = [
                    'iso' => $iso,
                    'day' => (int) $cursor->format('j'),
                    'inMonth' => $cursor >= $first && $cursor <= $last,
                    'isToday' => $iso === $todayISO,
                    'isWeekend' => in_array((int) $cursor->format('N'), [6, 7], true),
                ];
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }
        return $weeks;
    }

    // ---------- Événements personnels ----------

    public static function createEvent(array $fields): int
    {
        $visibility = in_array($fields['visibility'] ?? '', self::VISIBILITIES, true) ? $fields['visibility'] : 'Privé';
        return Db::insert(
            'INSERT INTO calendar_events (user_id, title, description, location, start_date, end_date,
                                          start_time, end_time, all_day, category, visibility)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['userId'], $fields['title'], $fields['description'] ?? '', $fields['location'] ?? '',
                $fields['startDate'], $fields['endDate'], $fields['startTime'] ?? '', $fields['endTime'] ?? '',
                !empty($fields['allDay']) ? 1 : 0, $fields['category'] ?: 'Personnel', $visibility,
            ]
        );
    }

    /** Change la portée d'un de ses propres événements. */
    public static function setVisibility(int $id, int $userId, string $visibility): bool
    {
        if (!in_array($visibility, self::VISIBILITIES, true)) {
            return false;
        }
        return Db::run('UPDATE calendar_events SET visibility = ? WHERE id = ? AND user_id = ?', [$visibility, $id, $userId]) > 0;
    }

    public static function deleteEvent(int $id, int $userId): bool
    {
        return Db::run('DELETE FROM calendar_events WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    public static function personalEvents(int $userId, string $from, string $to): array
    {
        return Db::all(
            'SELECT * FROM calendar_events
             WHERE user_id = ? AND start_date <= ? AND end_date >= ?
             ORDER BY start_date, start_time',
            [$userId, $to, $from]
        );
    }

    // ---------- Entrées dérivées du reste du site ----------

    /** Congés approuvés : ils occupent l'agenda sans avoir à être ressaisis. */
    public static function leaveEntries(int $userId, string $from, string $to): array
    {
        return Db::all(
            "SELECT type, start_date, end_date FROM hr_requests
             WHERE employee_id = ? AND status = 'Approuvée' AND start_date <= ? AND end_date >= ?
             ORDER BY start_date",
            [$userId, $to, $from]
        );
    }

    public static function roomEntries(int $userId, string $from, string $to): array
    {
        return Db::all(
            'SELECT b.title, b.booking_date, b.start_time, b.end_time, r.name AS room_name
             FROM room_bookings b JOIN rooms r ON r.id = b.room_id
             WHERE b.user_id = ? AND b.booking_date BETWEEN ? AND ?
             ORDER BY b.booking_date, b.start_time',
            [$userId, $from, $to]
        );
    }

    public static function trainingEntries(int $userId, string $from, string $to): array
    {
        return Db::all(
            "SELECT t.title, s.start_date, s.end_date, s.location
             FROM training_registrations reg
             JOIN training_sessions s ON s.id = reg.session_id
             JOIN trainings t ON t.id = s.training_id
             WHERE reg.employee_id = ? AND reg.status = 'Inscrite' AND s.status != 'Annulée'
               AND s.start_date <= ? AND COALESCE(s.end_date, s.start_date) >= ?",
            [$userId, $to, $from]
        );
    }

    public static function meetingEntries(string $from, string $to): array
    {
        return Db::all(
            'SELECT id, title, meeting_date, meeting_time, location FROM cse_meetings
             WHERE meeting_date BETWEEN ? AND ?
             ORDER BY meeting_date, meeting_time',
            [$from, $to]
        );
    }

    // ---------- Agenda partagé ----------

    /**
     * Les événements que des collègues ont ouverts au lecteur : « Équipe » dans
     * son équipe, « Service » dans son service. Rien de privé ne franchit cette
     * requête.
     */
    public static function sharedEvents(array $viewer, string $from, string $to): array
    {
        $clauses = [];
        $params = [$viewer['id'], $to, $from];
        if (!empty($viewer['team_id'])) {
            $clauses[] = "(u.team_id = ? AND e.visibility IN ('Équipe', 'Service'))";
            $params[] = $viewer['team_id'];
        }
        if (!empty($viewer['department_id'])) {
            $clauses[] = "(u.department_id = ? AND e.visibility = 'Service')";
            $params[] = $viewer['department_id'];
        }
        if ($clauses === []) {
            return [];
        }
        return Db::all(
            'SELECT e.*, u.first_name, u.last_name, u.avatar_file
             FROM calendar_events e JOIN users u ON u.id = e.user_id
             WHERE e.user_id != ? AND e.start_date <= ? AND e.end_date >= ? AND (' . implode(' OR ', $clauses) . ')
             ORDER BY e.start_date, e.start_time',
            $params
        );
    }

    /**
     * Les absences approuvées des collègues d'équipe. Le motif et le type
     * restent chez leur auteur : l'agenda partagé dit qu'un collègue est
     * absent, pas pourquoi.
     */
    public static function sharedAbsences(array $viewer, string $from, string $to): array
    {
        if (empty($viewer['team_id'])) {
            return [];
        }
        return Db::all(
            "SELECT r.start_date, r.end_date, u.first_name, u.last_name
             FROM hr_requests r JOIN users u ON u.id = r.employee_id
             WHERE u.team_id = ? AND u.id != ? AND r.status = 'Approuvée'
               AND r.start_date <= ? AND r.end_date >= ?
             ORDER BY r.start_date",
            [$viewer['team_id'], $viewer['id'], $to, $from]
        );
    }

    /** Agenda d'un mois : chaque jour reçoit ses entrées, saisies comme dérivées. */
    public static function monthAgenda(array $user, string $month, bool $attendsCse = false, bool $shared = false): array
    {
        ['firstISO' => $firstISO, 'lastISO' => $lastISO] = self::monthBounds($month);
        $byDay = [];

        $push = static function (string $iso, array $entry) use (&$byDay, $firstISO, $lastISO): void {
            if ($iso < $firstISO || $iso > $lastISO) {
                return;
            }
            $byDay[$iso][] = $entry;
        };
        // Un événement de plusieurs jours est posé sur chacun d'eux.
        $spread = static function (string $startISO, string $endISO, array $entry) use ($push): void {
            $cursor = \DateTimeImmutable::createFromFormat('!Y-m-d', $startISO, new \DateTimeZone('UTC'));
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $endISO, new \DateTimeZone('UTC'));
            if ($cursor === false || $end === false) {
                return;
            }
            while ($cursor <= $end) {
                $push($cursor->format('Y-m-d'), $entry);
                $cursor = $cursor->modify('+1 day');
            }
        };
        $range = static fn (string $a, string $b): string => implode(' – ', array_filter([$a, $b]));

        foreach (self::personalEvents((int) $user['id'], $firstISO, $lastISO) as $event) {
            $spread($event['start_date'], $event['end_date'], [
                'source' => 'personnel',
                'id' => (int) $event['id'],
                'title' => $event['title'],
                'category' => $event['category'],
                'location' => $event['location'],
                'description' => $event['description'],
                'time' => (int) $event['all_day'] === 1 ? '' : $range($event['start_time'], $event['end_time']),
                'startTime' => (int) $event['all_day'] === 1 ? '' : $event['start_time'],
                'visibility' => $event['visibility'],
                'removable' => true,
            ]);
        }

        foreach (self::leaveEntries((int) $user['id'], $firstISO, $lastISO) as $leave) {
            $spread($leave['start_date'], $leave['end_date'], [
                'source' => 'conge', 'title' => $leave['type'], 'category' => $leave['type'],
                'time' => '', 'startTime' => '', 'removable' => false,
            ]);
        }

        if ($attendsCse) {
            foreach (self::meetingEntries($firstISO, $lastISO) as $meeting) {
                $push($meeting['meeting_date'], [
                    'source' => 'cse', 'title' => $meeting['title'], 'category' => 'CSE',
                    'location' => $meeting['location'], 'time' => $meeting['meeting_time'],
                    'startTime' => $meeting['meeting_time'], 'removable' => false,
                ]);
            }
        }

        foreach (self::roomEntries((int) $user['id'], $firstISO, $lastISO) as $booking) {
            $push($booking['booking_date'], [
                'source' => 'salle', 'title' => $booking['title'], 'category' => $booking['room_name'],
                'location' => $booking['room_name'], 'time' => $range($booking['start_time'], $booking['end_time']),
                'startTime' => $booking['start_time'], 'removable' => false,
            ]);
        }

        foreach (self::trainingEntries((int) $user['id'], $firstISO, $lastISO) as $session) {
            $spread($session['start_date'], $session['end_date'] ?: $session['start_date'], [
                'source' => 'formation', 'title' => $session['title'], 'category' => 'Formation',
                'location' => $session['location'], 'time' => '', 'startTime' => '', 'removable' => false,
            ]);
        }

        if (!empty($user['contract_end_date'])) {
            $push($user['contract_end_date'], [
                'source' => 'contrat', 'title' => 'Fin de contrat', 'category' => 'Contrat',
                'time' => '', 'startTime' => '', 'removable' => false,
            ]);
        }

        if ($shared) {
            foreach (self::sharedEvents($user, $firstISO, $lastISO) as $event) {
                $spread($event['start_date'], $event['end_date'], [
                    'source' => 'partage',
                    'title' => $event['title'],
                    'owner' => trim($event['first_name'] . ' ' . $event['last_name']),
                    'visibility' => $event['visibility'],
                    'category' => $event['category'],
                    'location' => $event['location'],
                    'time' => (int) $event['all_day'] === 1 ? '' : $range($event['start_time'], $event['end_time']),
                    'startTime' => (int) $event['all_day'] === 1 ? '' : $event['start_time'],
                    'removable' => false,
                ]);
            }
            foreach (self::sharedAbsences($user, $firstISO, $lastISO) as $absence) {
                $spread($absence['start_date'], $absence['end_date'], [
                    'source' => 'absence', 'title' => 'Absent',
                    'owner' => trim($absence['first_name'] . ' ' . $absence['last_name']),
                    'category' => 'Absence', 'time' => '', 'startTime' => '', 'removable' => false,
                ]);
            }
        }

        return $byDay;
    }

    /** Les prochaines échéances, tous types confondus, pour la colonne latérale. */
    public static function upcoming(array $user, bool $attendsCse = false, int $days = 30, int $limit = 8): array
    {
        $fromISO = gmdate('Y-m-d');
        $toISO = gmdate('Y-m-d', strtotime("+$days days"));
        $entries = [];

        foreach (self::personalEvents((int) $user['id'], $fromISO, $toISO) as $event) {
            $entries[] = [
                'date' => max($event['start_date'], $fromISO),
                'title' => $event['title'],
                'category' => $event['category'],
                'time' => (int) $event['all_day'] === 1 ? '' : $event['start_time'],
                'source' => 'personnel',
            ];
        }
        foreach (self::leaveEntries((int) $user['id'], $fromISO, $toISO) as $leave) {
            $entries[] = [
                'date' => max($leave['start_date'], $fromISO),
                'title' => $leave['type'], 'category' => $leave['type'], 'time' => '', 'source' => 'conge',
            ];
        }
        if ($attendsCse) {
            foreach (self::meetingEntries($fromISO, $toISO) as $meeting) {
                $entries[] = [
                    'date' => $meeting['meeting_date'], 'title' => $meeting['title'],
                    'category' => 'CSE', 'time' => $meeting['meeting_time'], 'source' => 'cse',
                ];
            }
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['date'] . $a['time'], $b['date'] . $b['time']));
        return array_slice($entries, 0, $limit);
    }
}
