<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Les actualités, cloisonnées par société.
 *
 * Première table du produit à passer sous le garde du cloisonnement. Le périmètre
 * ne figure dans aucune signature : il vient du contexte de la requête (voir
 * Societes::courant()), parce qu'un argument qu'on peut oublier finit par être
 * oublié, et que l'oubli ne se verrait qu'une fois l'instance passée à deux
 * sociétés.
 *
 * Le périmètre est exigé pour lire comme pour écrire : une actualité publiée dans
 * une entité d'un groupe n'a pas à s'afficher dans l'autre, et le fil du salarié
 * est le premier endroit où cela se verrait.
 */
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

    private static function perimetre(): int
    {
        $societeId = Societes::courant();
        if ($societeId === null || $societeId <= 0) {
            throw new \RuntimeException('cloison.err.perimetre');
        }
        return $societeId;
    }

    public static function create(?int $authorId, string $scope, ?int $scopeId, string $title, string $body): int
    {
        return Db::insert(
            'INSERT INTO announcements (company_id, author_id, scope, scope_id, title, body) VALUES (?, ?, ?, ?, ?, ?)',
            [self::perimetre(), $authorId, $scope, $scope === 'company' ? null : $scopeId, $title, $body]
        );
    }

    public static function remove(int $id): bool
    {
        return Db::run('DELETE FROM announcements WHERE id = ? AND company_id = ?',
            [$id, self::perimetre()]) > 0;
    }

    /** Un manager ne retire qu'une actualité publiée sur un périmètre qu'il encadre. */
    public static function removeWithinScopes(int $id, array $scopes): bool
    {
        $announcement = Db::get('SELECT * FROM announcements WHERE id = ? AND company_id = ?',
            [$id, self::perimetre()]);
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

    /** Le fil d'un manager : ce qu'il a publié sur ses périmètres. */
    public static function forScopes(array $scopes, int $limit = 30): array
    {
        if ($scopes['teams'] === [] && $scopes['departments'] === []) {
            return [];
        }
        $clauses = [];
        $params = [self::perimetre()];
        if ($scopes['teams'] !== []) {
            $clauses[] = "(a.scope = 'team' AND a.scope_id IN (" . implode(',', array_fill(0, count($scopes['teams']), '?')) . '))';
            $params = array_merge($params, $scopes['teams']);
        }
        if ($scopes['departments'] !== []) {
            $clauses[] = "(a.scope = 'department' AND a.scope_id IN (" . implode(',', array_fill(0, count($scopes['departments']), '?')) . '))';
            $params = array_merge($params, $scopes['departments']);
        }
        $params[] = $limit;
        return Db::all(
            self::SELECT . ' WHERE a.company_id = ? AND (' . implode(' OR ', $clauses) . ') ORDER BY a.created_at DESC LIMIT ?',
            $params
        );
    }

    public static function all(int $limit = 40): array
    {
        return Db::all(self::SELECT . ' WHERE a.company_id = ? ORDER BY a.created_at DESC LIMIT ?',
            [self::perimetre(), $limit]);
    }

    /** Fil d'un collaborateur : l'entreprise, son service et son équipe. */
    public static function forEmployee(array $employee, int $limit = 12): array
    {
        return Db::all(
            self::SELECT . " WHERE a.company_id = ?
               AND (a.scope = 'company'
                OR (a.scope = 'department' AND a.scope_id = ?)
                OR (a.scope = 'team' AND a.scope_id = ?))
             ORDER BY a.created_at DESC LIMIT ?",
            [self::perimetre(), $employee['department_id'] ?? -1, $employee['team_id'] ?? -1, $limit]
        );
    }
}
