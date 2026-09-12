<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/** Une actualité vise l'entreprise entière, un service, ou une équipe. */
final class Announcements
{
    public const SCOPES = ['company', 'department', 'team'];

    private const SELECT = "
        SELECT a.*, u.first_name, u.last_name,
          CASE a.scope WHEN 'team' THEN (SELECT t.name FROM teams t WHERE t.id = a.scope_id)
                       WHEN 'department' THEN (SELECT d.name FROM departments d WHERE d.id = a.scope_id)
                       ELSE NULL END AS scope_name
        FROM announcements a
        LEFT JOIN users u ON u.id = a.author_id";

    public static function create(?int $authorId, string $scope, ?int $scopeId, string $title, string $body): int
    {
        return Db::insert(
            'INSERT INTO announcements (author_id, scope, scope_id, title, body) VALUES (?, ?, ?, ?, ?)',
            [$authorId, $scope, $scope === 'company' ? null : $scopeId, $title, $body]
        );
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM announcements WHERE id = ?', [$id]);
    }

    /** Un manager ne retire qu'une actualité publiée sur un périmètre qu'il encadre. */
    public static function removeWithinScopes(int $id, array $scopes): bool
    {
        $announcement = Db::get('SELECT * FROM announcements WHERE id = ?', [$id]);
        if ($announcement === null || $announcement['scope'] === 'company') {
            return false;
        }
        $allowed = $announcement['scope'] === 'team' ? $scopes['teams'] : $scopes['departments'];
        if (!in_array((int) $announcement['scope_id'], $allowed, true)) {
            return false;
        }
        self::remove($id);
        return true;
    }

    public static function all(int $limit = 40): array
    {
        return Db::all(self::SELECT . ' ORDER BY a.created_at DESC LIMIT ?', [$limit]);
    }

    /** Fil d'un collaborateur : l'entreprise, son service et son équipe. */
    public static function forEmployee(array $employee, int $limit = 12): array
    {
        return Db::all(
            self::SELECT . " WHERE a.scope = 'company'
                OR (a.scope = 'department' AND a.scope_id = ?)
                OR (a.scope = 'team' AND a.scope_id = ?)
             ORDER BY a.created_at DESC LIMIT ?",
            [$employee['department_id'] ?? -1, $employee['team_id'] ?? -1, $limit]
        );
    }
}
