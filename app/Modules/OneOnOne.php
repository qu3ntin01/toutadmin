<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Points individuels entre un manager et ses collaborateurs.
 *
 * Deux comptes rendus par point, et c'est tout l'intérêt : le résumé partagé,
 * que les deux relisent avant le suivant, et les notes du manager, qui ne
 * sortent jamais de son écran. Confondre les deux, c'est soit un manager qui
 * n'écrit rien de franc, soit un collaborateur qui découvre ce qu'on pense de
 * lui dans un export.
 *
 * Le périmètre est vérifié à l'écriture, pas seulement à l'affichage : un
 * changement d'équipe ferme l'accès sans attendre un rechargement.
 */
final class OneOnOne
{
    public const STATUSES = ['Planifié', 'Tenu', 'Annulé'];

    /** Ce que le collaborateur voit de son côté : jamais private_note. */
    private const SHARED_COLUMNS = 'id, manager_id, employee_id, scheduled_on, held_on, topics, shared_note, next_on, status';

    public static function manages(int $managerId, int $employeeId): bool
    {
        return in_array($employeeId, array_map('intval', array_column(Org::membersManagedBy($managerId), 'id')), true);
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM one_on_ones WHERE id = ?', [$id]);
    }

    /** Un point n'appartient qu'au manager qui l'a inscrit, et tant qu'il encadre la personne. */
    public static function ownedBy(int $id, int $managerId): ?array
    {
        $point = self::byId($id);
        if ($point === null || (int) $point['manager_id'] !== $managerId) {
            return null;
        }
        return self::manages($managerId, (int) $point['employee_id']) ? $point : null;
    }

    public static function forManager(int $managerId, int $limit = 100): array
    {
        return Db::all(
            "SELECT o.*, u.first_name, u.last_name, u.grade
             FROM one_on_ones o JOIN users u ON u.id = o.employee_id
             WHERE o.manager_id = ?
             ORDER BY o.status = 'Tenu', o.scheduled_on DESC, o.id DESC
             LIMIT ?",
            [$managerId, $limit]
        );
    }

    /** Le fil d'un collaborateur, amputé des notes privées de son manager. */
    public static function forEmployee(int $employeeId, int $limit = 30): array
    {
        $columns = implode(', ', array_map(
            static fn (string $column): string => 'o.' . $column,
            explode(', ', self::SHARED_COLUMNS)
        ));
        return Db::all(
            "SELECT $columns, u.first_name AS manager_first_name, u.last_name AS manager_last_name
             FROM one_on_ones o LEFT JOIN users u ON u.id = o.manager_id
             WHERE o.employee_id = ? AND o.status != 'Annulé'
             ORDER BY o.scheduled_on DESC, o.id DESC LIMIT ?",
            [$employeeId, $limit]
        );
    }

    /**
     * Le dernier point tenu et le prochain prévu, par collaborateur : c'est ce
     * que le manager regarde pour savoir qui il n'a pas vu depuis trop
     * longtemps.
     */
    public static function cadence(int $managerId): array
    {
        $members = Org::membersManagedBy($managerId);
        if ($members === []) {
            return [];
        }
        $ids = array_map('intval', array_column($members, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Db::all(
            "SELECT u.id AS employee_id, u.first_name, u.last_name,
               (SELECT MAX(held_on) FROM one_on_ones o
                 WHERE o.manager_id = ? AND o.employee_id = u.id AND o.status = 'Tenu') AS last_held,
               (SELECT MIN(scheduled_on) FROM one_on_ones o
                 WHERE o.manager_id = ? AND o.employee_id = u.id AND o.status = 'Planifié') AS next_on
             FROM users u WHERE u.id IN ($placeholders)
             ORDER BY last_held IS NOT NULL, last_held, u.last_name COLLATE NOCASE",
            array_merge([$managerId, $managerId], $ids)
        );
    }

    public static function create(int $managerId, int $employeeId, string $scheduledOn, string $topics = ''): array
    {
        if (!self::manages($managerId, $employeeId)) {
            return ['ok' => false, 'reason' => 'perimetre'];
        }
        return [
            'ok' => true,
            'id' => Db::insert(
                'INSERT INTO one_on_ones (manager_id, employee_id, scheduled_on, topics) VALUES (?, ?, ?, ?)',
                [$managerId, $employeeId, $scheduledOn, mb_substr($topics, 0, 2000)]
            ),
        ];
    }

    public static function update(int $id, int $managerId, array $fields): bool
    {
        if (self::ownedBy($id, $managerId) === null) {
            return false;
        }
        Db::run(
            'UPDATE one_on_ones
             SET scheduled_on = ?, held_on = ?, topics = ?, shared_note = ?, private_note = ?, mood = ?, next_on = ?, status = ?
             WHERE id = ? AND manager_id = ?',
            [
                $fields['scheduledOn'], $fields['heldOn'], mb_substr($fields['topics'] ?? '', 0, 2000),
                mb_substr($fields['sharedNote'] ?? '', 0, 4000), mb_substr($fields['privateNote'] ?? '', 0, 4000),
                $fields['mood'], $fields['nextOn'], $fields['status'], $id, $managerId,
            ]
        );
        return true;
    }

    public static function remove(int $id, int $managerId): bool
    {
        if (self::ownedBy($id, $managerId) === null) {
            return false;
        }
        Db::run('DELETE FROM one_on_ones WHERE id = ? AND manager_id = ?', [$id, $managerId]);
        return true;
    }

    public static function summary(int $managerId): array
    {
        $rows = self::forManager($managerId, 500);
        $today = gmdate('Y-m-d');
        $count = static fn (callable $filter): int => count(array_filter($rows, $filter));
        return [
            'planned' => $count(static fn (array $r): bool => $r['status'] === 'Planifié'),
            'overdue' => $count(static fn (array $r): bool => $r['status'] === 'Planifié' && $r['scheduled_on'] < $today),
            'held' => $count(static fn (array $r): bool => $r['status'] === 'Tenu'),
            'neverMet' => count(array_filter(self::cadence($managerId), static fn (array $row): bool => $row['last_held'] === null)),
        ];
    }
}
