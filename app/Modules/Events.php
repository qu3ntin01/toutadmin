<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Événements d'entreprise : séminaires, formations, réunions générales.
 *
 * Toute la difficulté d'un événement tient dans la place. Une salle a une
 * capacité, les inscriptions arrivent dans le désordre, et quelqu'un se
 * désiste toujours la veille. Ce module tient la file : au-delà de la
 * capacité on n'est pas refusé, on est en liste d'attente, et un désistement
 * fait monter le premier qui attend — automatiquement, avec une notification,
 * parce qu'une place libérée que personne ne voit est une place perdue.
 */
final class Events
{
    public const KINDS = ['Séminaire', 'Formation', 'Réunion générale', 'Atelier', 'Salon', 'Convivialité'];
    public const EVENT_STATUSES = ['Brouillon', 'Ouvert', 'Complet', 'Clos', 'Annulé'];
    public const REGISTRATION_STATUSES = ['Inscrit', "Liste d'attente", 'Annulée', 'Présent', 'Absent'];

    /**
     * Les états qui occupent une place : le décompte de la capacité ne regarde
     * qu'eux. Une annulation libère, une absence constatée non — la place a été
     * réservée, et l'événement a eu lieu.
     */
    public const HOLDING = ['Inscrit', 'Présent', 'Absent'];

    private const COLUMNS = "
        e.*,
        u.first_name AS organizer_first_name, u.last_name AS organizer_last_name,
        CASE e.scope WHEN 'team' THEN (SELECT t.name FROM teams t WHERE t.id = e.scope_id)
                     WHEN 'department' THEN (SELECT d.name FROM departments d WHERE d.id = e.scope_id)
                     ELSE NULL END AS scope_name,
        (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status IN ('Inscrit','Présent','Absent')) AS taken,
        (SELECT COUNT(*) FROM event_registrations r WHERE r.event_id = e.id AND r.status = 'Liste d''attente') AS waiting
    ";

    public static function all(array $options = []): array
    {
        $includeDrafts = $options['includeDrafts'] ?? true;
        $clause = $includeDrafts ? '' : "WHERE e.status != 'Brouillon'";

        return Db::all(
            'SELECT ' . self::COLUMNS . "
             FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
             $clause
             ORDER BY e.starts_at DESC LIMIT ?",
            [(int) ($options['limit'] ?? 200)]
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get(
            'SELECT ' . self::COLUMNS . '
             FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
             WHERE e.id = ?',
            [$id]
        );
    }

    /**
     * Ce qu'une personne a le droit de voir : les événements de l'entreprise, de
     * son service et de son équipe. Un brouillon n'existe pas encore, il reste
     * invisible — y compris de ceux qu'il concernera.
     */
    public static function visibleTo(array $user, array $options = []): array
    {
        return Db::all(
            'SELECT ' . self::COLUMNS . ",
                (SELECT r.status FROM event_registrations r WHERE r.event_id = e.id AND r.user_id = ?) AS my_status
             FROM company_events e LEFT JOIN users u ON u.id = e.organizer_id
             WHERE e.status != 'Brouillon'
               AND (e.scope = 'company'
                 OR (e.scope = 'department' AND e.scope_id = ?)
                 OR (e.scope = 'team' AND e.scope_id = ?))
             ORDER BY e.starts_at DESC LIMIT ?",
            [
                (int) $user['id'],
                (int) ($user['department_id'] ?? 0) ?: -1,
                (int) ($user['team_id'] ?? 0) ?: -1,
                (int) ($options['limit'] ?? 100),
            ]
        );
    }

    public static function concerns(array $event, array $user): bool
    {
        if ($event['scope'] === 'company') {
            return true;
        }
        if ($event['scope'] === 'department') {
            return (int) $event['scope_id'] === (int) ($user['department_id'] ?? 0);
        }
        return (int) $event['scope_id'] === (int) ($user['team_id'] ?? 0);
    }

    private static function values(array $fields): array
    {
        return [
            $fields['title'], $fields['kind'], $fields['description'] ?? '', $fields['location'] ?? '',
            $fields['startsAt'], $fields['endsAt'] ?? null, $fields['scope'],
            $fields['scope'] === 'company' ? null : ($fields['scopeId'] ?? null),
            (int) ($fields['capacity'] ?? 0), $fields['registrationClosesOn'] ?? null,
            $fields['budget'] ?? null, $fields['cost'] ?? null,
            $fields['organizerId'] ?? null,
        ];
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            'INSERT INTO company_events (title, kind, description, location, starts_at, ends_at, scope, scope_id,
                                         capacity, registration_closes_on, budget, cost, organizer_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array_merge(self::values($fields), [$fields['status'] ?? 'Brouillon'])
        );
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE company_events
             SET title = ?, kind = ?, description = ?, location = ?, starts_at = ?, ends_at = ?,
                 scope = ?, scope_id = ?, capacity = ?, registration_closes_on = ?, budget = ?, cost = ?,
                 organizer_id = ?, status = ?
             WHERE id = ?',
            array_merge(self::values($fields), [$fields['status'], $id])
        );
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM company_events WHERE id = ?', [$id]);
    }

    // ---------- Inscriptions ----------

    public static function registrations(int $eventId): array
    {
        return Db::all(
            "SELECT r.*, u.first_name, u.last_name, u.email, u.grade
             FROM event_registrations r JOIN users u ON u.id = r.user_id
             WHERE r.event_id = ?
             ORDER BY CASE r.status WHEN 'Liste d''attente' THEN 1 WHEN 'Annulée' THEN 2 ELSE 0 END,
                      r.registered_at, r.id",
            [$eventId]
        );
    }

    public static function registrationOf(int $eventId, int $userId): ?array
    {
        return Db::get('SELECT * FROM event_registrations WHERE event_id = ? AND user_id = ?', [$eventId, $userId]);
    }

    public static function seatsLeft(array $event): ?int
    {
        $capacity = (int) $event['capacity'];
        return $capacity === 0 ? null : max(0, $capacity - (int) $event['taken']);
    }

    /**
     * S'inscrire. Le résultat dit ce qui s'est passé — inscrit, ou mis en liste
     * d'attente — parce que les deux sont des succès et que la personne doit savoir
     * lequel.
     */
    public static function register(int $eventId, int $userId): array
    {
        $event = self::byId($eventId);
        if ($event === null) {
            return ['ok' => false, 'reason' => 'introuvable'];
        }
        if ($event['status'] !== 'Ouvert' && $event['status'] !== 'Complet') {
            return ['ok' => false, 'reason' => 'ferme'];
        }

        $today = gmdate('Y-m-d');
        if (!empty($event['registration_closes_on']) && $event['registration_closes_on'] < $today) {
            return ['ok' => false, 'reason' => 'cloture'];
        }

        $existing = self::registrationOf($eventId, $userId);
        if ($existing !== null && $existing['status'] !== 'Annulée') {
            return ['ok' => false, 'reason' => 'deja'];
        }

        $full = (int) $event['capacity'] > 0 && (int) $event['taken'] >= (int) $event['capacity'];
        $status = $full ? "Liste d'attente" : 'Inscrit';

        if ($existing !== null) {
            Db::run(
                "UPDATE event_registrations SET status = ?, registered_at = datetime('now') WHERE id = ?",
                [$status, (int) $existing['id']]
            );
        } else {
            Db::run('INSERT INTO event_registrations (event_id, user_id, status) VALUES (?, ?, ?)', [$eventId, $userId, $status]);
        }

        self::syncFullness($eventId);
        return ['ok' => true, 'status' => $status];
    }

    /**
     * Se désister. La place libérée revient au premier de la liste d'attente, tout
     * de suite : sans cela elle reste vide alors que quelqu'un l'attend.
     */
    public static function cancel(int $eventId, int $userId): array
    {
        $registration = self::registrationOf($eventId, $userId);
        if ($registration === null || $registration['status'] === 'Annulée') {
            return ['ok' => false, 'reason' => 'introuvable'];
        }

        Db::run("UPDATE event_registrations SET status = 'Annulée' WHERE id = ?", [(int) $registration['id']]);
        $promoted = $registration['status'] === 'Inscrit' ? self::promoteFromWaitlist($eventId) : null;
        self::syncFullness($eventId);
        return ['ok' => true, 'promoted' => $promoted];
    }

    /** Fait monter le premier de la liste d'attente, s'il reste de la place. */
    public static function promoteFromWaitlist(int $eventId): ?int
    {
        $event = self::byId($eventId);
        if ($event === null || (int) $event['capacity'] === 0 || (int) $event['taken'] >= (int) $event['capacity']) {
            return null;
        }

        $next = Db::get(
            "SELECT * FROM event_registrations
             WHERE event_id = ? AND status = 'Liste d''attente'
             ORDER BY registered_at, id LIMIT 1",
            [$eventId]
        );
        if ($next === null) {
            return null;
        }

        Db::run("UPDATE event_registrations SET status = 'Inscrit' WHERE id = ?", [(int) $next['id']]);
        Notifications::push((int) $next['user_id'], 'Place libérée — ' . $event['title'], [
            'kind' => 'evenement',
            'body' => "Vous étiez en liste d'attente : une place s'est libérée, votre inscription est confirmée.",
            'link' => '/evenements/' . $eventId,
            'dedupeKey' => 'evenement:' . $eventId . ':promotion:' . (int) $next['user_id'],
        ]);
        return (int) $next['user_id'];
    }

    /**
     * Recale le statut « Complet » sur la réalité des places. L'organisateur ne
     * doit pas avoir à y penser : c'est la capacité qui décide, pas lui.
     */
    public static function syncFullness(int $eventId): void
    {
        $event = self::byId($eventId);
        if ($event === null || (int) $event['capacity'] === 0) {
            return;
        }
        if ($event['status'] === 'Ouvert' && (int) $event['taken'] >= (int) $event['capacity']) {
            Db::run("UPDATE company_events SET status = 'Complet' WHERE id = ?", [$eventId]);
        } elseif ($event['status'] === 'Complet' && (int) $event['taken'] < (int) $event['capacity']) {
            Db::run("UPDATE company_events SET status = 'Ouvert' WHERE id = ?", [$eventId]);
        }
    }

    /** L'émargement : présent ou absent, une fois l'événement passé. */
    public static function markAttendance(int $registrationId, string $status): bool
    {
        if ($status !== 'Présent' && $status !== 'Absent') {
            return false;
        }
        return Db::run('UPDATE event_registrations SET status = ? WHERE id = ?', [$status, $registrationId]) > 0;
    }

    /** Prévient les inscrits d'une annulation : personne ne doit se déplacer pour rien. */
    public static function notifyCancellation(array $event): int
    {
        $sent = 0;
        foreach (self::registrations((int) $event['id']) as $row) {
            if ($row['status'] === 'Annulée') {
                continue;
            }
            $done = Notifications::push((int) $row['user_id'], 'Annulé — ' . $event['title'], [
                'kind' => 'evenement',
                'body' => "L'événement du " . substr((string) $event['starts_at'], 0, 10) . ' est annulé.',
                'link' => '/evenements',
                'dedupeKey' => 'evenement:' . (int) $event['id'] . ':annulation:' . (int) $row['user_id'],
            ]);
            if ($done) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function summary(): array
    {
        $now = gmdate('Y-m-d H:i');
        return [
            'upcoming' => (int) Db::value(
                "SELECT COUNT(*) FROM company_events WHERE status IN ('Ouvert','Complet') AND starts_at >= ?",
                [$now]
            ),
            'drafts' => (int) Db::value("SELECT COUNT(*) FROM company_events WHERE status = 'Brouillon'"),
            'registered' => (int) Db::value("SELECT COUNT(*) FROM event_registrations WHERE status IN ('Inscrit','Présent')"),
            'waiting' => (int) Db::value("SELECT COUNT(*) FROM event_registrations WHERE status = 'Liste d''attente'"),
            'spend' => round((float) Db::value(
                "SELECT COALESCE(SUM(cost), 0) FROM company_events WHERE starts_at >= date('now', '-1 year')"
            ), 2),
        ];
    }
}
