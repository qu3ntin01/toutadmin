<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Planning d'équipe, roulements et astreintes.
 *
 * Le tableur partagé finit toujours par mentir : deux personnes sur le même
 * créneau, quelqu'un planifié pendant ses congés, une astreinte qui n'a jamais
 * été relue. Ce module tient la grille et refuse les contradictions au moment
 * où elles sont créées — plus tard, elles sont déjà devenues des absences.
 *
 * Un planning non publié reste un brouillon : personne d'autre que son auteur
 * ne doit organiser sa semaine dessus.
 */
final class Planning
{
    public const KINDS = ['Poste', 'Astreinte', 'Permanence', 'Télétravail', 'Formation'];
    public const WEEKDAYS = [
        ['value' => 1, 'label' => 'Lundi', 'short' => 'Lun'],
        ['value' => 2, 'label' => 'Mardi', 'short' => 'Mar'],
        ['value' => 3, 'label' => 'Mercredi', 'short' => 'Mer'],
        ['value' => 4, 'label' => 'Jeudi', 'short' => 'Jeu'],
        ['value' => 5, 'label' => 'Vendredi', 'short' => 'Ven'],
        ['value' => 6, 'label' => 'Samedi', 'short' => 'Sam'],
        ['value' => 7, 'label' => 'Dimanche', 'short' => 'Dim'],
    ];

    // ---------- Dates ----------

    /** Le lundi de la semaine d'une date : toute la grille s'y accroche. */
    public static function weekStart(string $iso): string
    {
        $day = (int) gmdate('N', (int) strtotime($iso . ' UTC'));
        return self::addDays($iso, -($day - 1));
    }

    public static function addDays(string $iso, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime($iso . " UTC $days days"));
    }

    public static function weekDays(string $startIso): array
    {
        $out = [];
        foreach (self::WEEKDAYS as $index => $day) {
            $day['date'] = self::addDays($startIso, $index);
            $out[] = $day;
        }
        return $out;
    }

    public static function hoursBetween(string $startsAt, string $endsAt): float
    {
        $start = strtotime($startsAt . ':00 UTC');
        $end = strtotime($endsAt . ':00 UTC');
        if ($start === false || $end === false || $end <= $start) {
            return 0.0;
        }
        return round(($end - $start) / 3600, 2);
    }

    // ---------- Créneaux ----------

    public static function between(string $from, string $to, array $filters = []): array
    {
        $clauses = ['s.starts_at < ?', 's.ends_at > ?'];
        $params = [$to . 'T23:59', $from . 'T00:00'];
        if (!empty($filters['userId'])) {
            $clauses[] = 's.user_id = ?';
            $params[] = (int) $filters['userId'];
        }
        if (!empty($filters['teamId'])) {
            $clauses[] = 's.team_id = ?';
            $params[] = (int) $filters['teamId'];
        }
        if (!empty($filters['publishedOnly'])) {
            $clauses[] = 's.published = 1';
        }

        $rows = Db::all(
            'SELECT s.*, u.first_name, u.last_name, t.name AS team_name
             FROM shifts s
             JOIN users u ON u.id = s.user_id
             LEFT JOIN teams t ON t.id = s.team_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY s.starts_at, u.last_name COLLATE NOCASE',
            $params
        );

        return array_map(static function (array $row): array {
            $row['hours'] = self::hoursBetween($row['starts_at'], $row['ends_at']);
            $row['day'] = substr($row['starts_at'], 0, 10);
            return $row;
        }, $rows);
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM shifts WHERE id = ?', [$id]);
    }

    /**
     * Ce qui empêche un créneau d'exister : un autre créneau qui le chevauche, ou
     * une absence déjà accordée. Les deux sont rendus ensemble — c'est la même
     * question pour celui qui planifie.
     */
    public static function conflicts(int $userId, string $startsAt, string $endsAt, ?int $ignoreId = null): array
    {
        $overlapping = Db::all(
            'SELECT s.*, u.first_name, u.last_name FROM shifts s JOIN users u ON u.id = s.user_id
             WHERE s.user_id = ? AND s.id != ? AND s.starts_at < ? AND s.ends_at > ?',
            [$userId, $ignoreId ?? 0, $endsAt, $startsAt]
        );

        $leave = Db::all(
            "SELECT * FROM hr_requests
             WHERE employee_id = ? AND status = 'Approuvée' AND start_date <= ? AND end_date >= ?",
            [$userId, substr($endsAt, 0, 10), substr($startsAt, 0, 10)]
        );

        return [
            'shifts' => $overlapping,
            'leave' => $leave,
            'blocked' => $overlapping !== [] || $leave !== [],
        ];
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            'INSERT INTO shifts (user_id, starts_at, ends_at, kind, label, location, team_id, published, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['userId'], $fields['startsAt'], $fields['endsAt'], $fields['kind'],
                $fields['label'] ?? '', $fields['location'] ?? '', $fields['teamId'] ?? null,
                !empty($fields['published']) ? 1 : 0, $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE shifts SET user_id = ?, starts_at = ?, ends_at = ?, kind = ?, label = ?, location = ?, team_id = ?
             WHERE id = ?',
            [
                (int) $fields['userId'], $fields['startsAt'], $fields['endsAt'], $fields['kind'],
                $fields['label'] ?? '', $fields['location'] ?? '', $fields['teamId'] ?? null, $id,
            ]
        );
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM shifts WHERE id = ?', [$id]);
    }

    /** Publier une semaine d'un coup : c'est ainsi qu'on annonce un planning. */
    public static function publishWeek(string $startIso, ?int $teamId = null): int
    {
        $end = self::addDays($startIso, 7);
        $clause = $teamId !== null ? 'AND team_id = ?' : '';
        $params = [$end . 'T00:00', $startIso . 'T00:00'];
        if ($teamId !== null) {
            $params[] = $teamId;
        }
        return Db::run(
            "UPDATE shifts SET published = 1 WHERE published = 0 AND starts_at < ? AND ends_at > ? $clause",
            $params
        );
    }

    // ---------- Roulements ----------

    private static function decorateTemplate(array $row): array
    {
        $row['days'] = array_values(array_filter(array_map('intval', explode(',', (string) $row['weekdays']))));
        return $row;
    }

    public static function templates(): array
    {
        return array_map(
            [self::class, 'decorateTemplate'],
            Db::all('SELECT * FROM shift_templates ORDER BY name COLLATE NOCASE')
        );
    }

    public static function templateById(int $id): ?array
    {
        $row = Db::get('SELECT * FROM shift_templates WHERE id = ?', [$id]);
        return $row === null ? null : self::decorateTemplate($row);
    }

    public static function createTemplate(array $fields): int
    {
        return Db::insert(
            'INSERT INTO shift_templates (name, kind, start_time, end_time, weekdays, location) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['kind'], $fields['startTime'], $fields['endTime'],
                implode(',', $fields['weekdays'] ?? []), $fields['location'] ?? '',
            ]
        );
    }

    public static function deleteTemplate(int $id): void
    {
        Db::run('DELETE FROM shift_templates WHERE id = ?', [$id]);
    }

    /**
     * Applique un roulement sur une période. Les jours en conflit sont sautés et
     * rendus à l'appelant : poser un créneau par-dessus des congés accordés serait
     * une promesse qu'on ne peut pas tenir.
     */
    public static function applyTemplate(int $templateId, array $options): array
    {
        $template = self::templateById($templateId);
        if ($template === null) {
            return ['ok' => false, 'message' => 'Roulement inconnu.'];
        }
        $from = $options['from'];
        $to = $options['to'];
        if ($to < $from) {
            return ['ok' => false, 'message' => 'La période finit avant de commencer.'];
        }

        $created = [];
        $skipped = [];
        for ($day = $from; $day <= $to; $day = self::addDays($day, 1)) {
            $weekday = (int) gmdate('N', (int) strtotime($day . ' UTC'));
            if (!in_array($weekday, $template['days'], true)) {
                continue;
            }

            $startsAt = $day . 'T' . $template['start_time'];
            // Un poste de nuit finit le lendemain : l'heure de fin plus petite le dit.
            $endsAt = $template['end_time'] > $template['start_time']
                ? $day . 'T' . $template['end_time']
                : self::addDays($day, 1) . 'T' . $template['end_time'];

            $clash = self::conflicts((int) $options['userId'], $startsAt, $endsAt);
            if ($clash['blocked']) {
                $skipped[] = [
                    'day' => $day,
                    'reason' => $clash['leave'] !== [] ? 'absence accordée' : 'créneau déjà posé',
                ];
                continue;
            }
            self::create([
                'userId' => (int) $options['userId'],
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'kind' => $template['kind'],
                'label' => $template['name'],
                'location' => $template['location'],
                'published' => !empty($options['published']),
                'createdBy' => $options['createdBy'] ?? null,
            ]);
            $created[] = $day;
        }
        return ['ok' => true, 'created' => $created, 'skipped' => $skipped];
    }

    // ---------- Lectures ----------

    /** Qui est d'astreinte, et quand : la question qu'on pose à 3 h du matin. */
    public static function onCall(string $from, string $to): array
    {
        return array_values(array_filter(
            self::between($from, $to),
            static fn (array $shift): bool => $shift['kind'] === 'Astreinte'
        ));
    }

    public static function whoIsOnCall(?string $at = null): array
    {
        $moment = $at ?? gmdate('Y-m-d\TH:i');
        return Db::all(
            "SELECT s.*, u.first_name, u.last_name, u.phone FROM shifts s JOIN users u ON u.id = s.user_id
             WHERE s.kind = 'Astreinte' AND s.starts_at <= ? AND s.ends_at > ? ORDER BY s.starts_at",
            [$moment, $moment]
        );
    }

    /** Heures planifiées par personne sur la période : le déséquilibre saute aux yeux. */
    public static function load(string $from, string $to, ?int $teamId = null): array
    {
        $byUser = [];
        foreach (self::between($from, $to, ['teamId' => $teamId]) as $shift) {
            $userId = (int) $shift['user_id'];
            $entry = $byUser[$userId] ?? [
                'userId' => $userId,
                'name' => trim($shift['first_name'] . ' ' . $shift['last_name']),
                'hours' => 0.0, 'shifts' => 0, 'onCall' => 0,
            ];
            $entry['hours'] = round($entry['hours'] + $shift['hours'], 2);
            $entry['shifts']++;
            if ($shift['kind'] === 'Astreinte') {
                $entry['onCall']++;
            }
            $byUser[$userId] = $entry;
        }

        $rows = array_values($byUser);
        usort($rows, static fn (array $a, array $b): int => $b['hours'] <=> $a['hours']);
        return $rows;
    }

    /** La grille de la semaine : une ligne par personne, une colonne par jour. */
    public static function grid(string $startIso, array $options = []): array
    {
        $days = self::weekDays($startIso);
        $shifts = self::between($startIso, self::addDays($startIso, 6), [
            'teamId' => $options['teamId'] ?? null,
            'publishedOnly' => $options['publishedOnly'] ?? false,
        ]);
        $userIds = $options['userIds'] ?? null;

        $people = [];
        foreach ($shifts as $shift) {
            $userId = (int) $shift['user_id'];
            if ($userIds !== null && !in_array($userId, $userIds, true)) {
                continue;
            }
            $entry = $people[$userId] ?? [
                'userId' => $userId,
                'name' => trim($shift['first_name'] . ' ' . $shift['last_name']),
                'days' => array_fill_keys(array_column($days, 'date'), []),
                'hours' => 0.0,
            ];
            if (isset($entry['days'][$shift['day']])) {
                $entry['days'][$shift['day']][] = $shift;
            }
            $entry['hours'] = round($entry['hours'] + $shift['hours'], 2);
            $people[$userId] = $entry;
        }

        $rows = array_values($people);
        usort($rows, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return ['start' => $startIso, 'days' => $days, 'rows' => $rows];
    }
}
