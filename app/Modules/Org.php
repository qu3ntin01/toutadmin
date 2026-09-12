<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Organisation : services, équipes, encadrement.
 *
 * Un service regroupe des équipes ; une équipe appartient à un service.
 * L'encadrement est une relation, pas une colonne : plusieurs managers par
 * service comme par équipe.
 */
final class Org
{
    public const SCOPES = ['department', 'team'];

    // ---------- Services ----------

    public static function departments(): array
    {
        return Db::all(
            'SELECT d.*,
                (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS member_count,
                (SELECT COUNT(*) FROM teams t WHERE t.department_id = d.id) AS team_count
             FROM departments d
             ORDER BY d.name COLLATE NOCASE'
        );
    }

    public static function departmentById(?int $id): ?array
    {
        return $id === null ? null : Db::get('SELECT * FROM departments WHERE id = ?', [$id]);
    }

    public static function createDepartment(string $name, string $description = ''): int
    {
        return Db::insert('INSERT INTO departments (name, description) VALUES (?, ?)', [$name, $description]);
    }

    public static function updateDepartment(int $id, string $name, string $description = ''): void
    {
        Db::run('UPDATE departments SET name = ?, description = ? WHERE id = ?', [$name, $description, $id]);
    }

    /** Supprimer un service détache ses membres et ses équipes plutôt que de les effacer. */
    public static function deleteDepartment(int $id): void
    {
        Db::transaction(static function () use ($id): void {
            Db::run("DELETE FROM org_managers WHERE scope = 'department' AND scope_id = ?", [$id]);
            Db::run('DELETE FROM departments WHERE id = ?', [$id]);
        });
    }

    // ---------- Équipes ----------

    public static function teams(): array
    {
        return Db::all(
            'SELECT t.*, d.name AS department_name,
                (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id) AS member_count
             FROM teams t
             LEFT JOIN departments d ON d.id = t.department_id
             ORDER BY d.name COLLATE NOCASE, t.name COLLATE NOCASE'
        );
    }

    public static function teamById(?int $id): ?array
    {
        return $id === null ? null : Db::get(
            'SELECT t.*, d.name AS department_name
             FROM teams t LEFT JOIN departments d ON d.id = t.department_id
             WHERE t.id = ?',
            [$id]
        );
    }

    public static function createTeam(string $name, ?int $departmentId, string $description = ''): int
    {
        return Db::insert(
            'INSERT INTO teams (name, department_id, description) VALUES (?, ?, ?)',
            [$name, $departmentId, $description]
        );
    }

    public static function updateTeam(int $id, string $name, ?int $departmentId, string $description = ''): void
    {
        Db::run(
            'UPDATE teams SET name = ?, department_id = ?, description = ? WHERE id = ?',
            [$name, $departmentId, $description, $id]
        );
    }

    public static function deleteTeam(int $id): void
    {
        Db::transaction(static function () use ($id): void {
            Db::run("DELETE FROM org_managers WHERE scope = 'team' AND scope_id = ?", [$id]);
            Db::run('DELETE FROM teams WHERE id = ?', [$id]);
        });
    }

    // ---------- Encadrement ----------

    public static function managersOf(string $scope, int $scopeId): array
    {
        return Db::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.grade, u.avatar_file
             FROM org_managers m JOIN users u ON u.id = m.user_id
             WHERE m.scope = ? AND m.scope_id = ?
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE',
            [$scope, $scopeId]
        );
    }

    public static function addManager(string $scope, int $scopeId, int $userId): array
    {
        if (!in_array($scope, self::SCOPES, true)) {
            return ['ok' => false, 'reason' => 'bad-scope'];
        }
        $target = $scope === 'team' ? self::teamById($scopeId) : self::departmentById($scopeId);
        if ($target === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        $user = Db::get("SELECT * FROM users WHERE id = ? AND role = 'employee'", [$userId]);
        if ($user === null) {
            return ['ok' => false, 'reason' => 'no-user'];
        }
        Db::run('INSERT OR IGNORE INTO org_managers (scope, scope_id, user_id) VALUES (?, ?, ?)', [$scope, $scopeId, $userId]);
        return ['ok' => true, 'user' => $user];
    }

    public static function removeManager(string $scope, int $scopeId, int $userId): void
    {
        Db::run('DELETE FROM org_managers WHERE scope = ? AND scope_id = ? AND user_id = ?', [$scope, $scopeId, $userId]);
    }

    /** Les périmètres qu'un salarié encadre, services et équipes confondus. */
    public static function scopesManagedBy(int $userId): array
    {
        $rows = Db::all('SELECT scope, scope_id FROM org_managers WHERE user_id = ?', [$userId]);
        $departments = [];
        $teams = [];
        foreach ($rows as $row) {
            if ($row['scope'] === 'department') {
                $departments[] = (int) $row['scope_id'];
            } else {
                $teams[] = (int) $row['scope_id'];
            }
        }
        return ['departments' => $departments, 'teams' => $teams];
    }

    public static function isManager(int $userId): bool
    {
        return Db::get('SELECT 1 AS ok FROM org_managers WHERE user_id = ? LIMIT 1', [$userId]) !== null;
    }

    /**
     * Les collaborateurs encadrés : membres des équipes dirigées, plus membres
     * des services dirigés. Un manager de service encadre donc aussi les
     * équipes qu'il contient.
     */
    public static function membersManagedBy(int $userId): array
    {
        ['departments' => $deps, 'teams' => $teams] = self::scopesManagedBy($userId);
        if ($deps === [] && $teams === []) {
            return [];
        }
        $clauses = [];
        $params = [$userId];
        if ($teams !== []) {
            $clauses[] = 'u.team_id IN (' . implode(',', array_fill(0, count($teams), '?')) . ')';
            $params = array_merge($params, $teams);
        }
        if ($deps !== []) {
            $clauses[] = 'u.department_id IN (' . implode(',', array_fill(0, count($deps), '?')) . ')';
            $params = array_merge($params, $deps);
        }
        return Db::all(
            'SELECT u.*, t.name AS team_name, d.name AS department_name
             FROM users u
             LEFT JOIN teams t ON t.id = u.team_id
             LEFT JOIN departments d ON d.id = u.department_id
             WHERE u.role = \'employee\' AND u.id != ? AND (' . implode(' OR ', $clauses) . ')
             ORDER BY d.name COLLATE NOCASE, t.name COLLATE NOCASE, u.last_name COLLATE NOCASE',
            $params
        );
    }

    /** Les managers d'un salarié : ceux de son équipe et ceux de son service. */
    public static function managersFor(array $user): array
    {
        $rows = [];
        if (!empty($user['team_id'])) {
            foreach (self::managersOf('team', (int) $user['team_id']) as $manager) {
                $rows[] = $manager + ['scope' => 'team'];
            }
        }
        if (!empty($user['department_id'])) {
            foreach (self::managersOf('department', (int) $user['department_id']) as $manager) {
                $rows[] = $manager + ['scope' => 'department'];
            }
        }
        // Un même manager peut encadrer l'équipe et le service : une seule fois.
        $seen = [];
        $out = [];
        foreach ($rows as $manager) {
            $id = (int) $manager['id'];
            if ($id === (int) $user['id'] || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $manager;
        }
        return $out;
    }

    public static function teammates(array $user): array
    {
        if (empty($user['team_id'])) {
            return [];
        }
        return Db::all(
            "SELECT u.* FROM users u
             WHERE u.team_id = ? AND u.id != ? AND u.role = 'employee'
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE",
            [$user['team_id'], $user['id']]
        );
    }

    public static function membersOfDepartment(int $departmentId): array
    {
        return Db::all(
            "SELECT u.* FROM users u WHERE u.department_id = ? AND u.role = 'employee'
             ORDER BY u.last_name COLLATE NOCASE",
            [$departmentId]
        );
    }

    /** Rattache un salarié, en refusant les identifiants inconnus. */
    public static function assignMembership(int $userId, ?int $departmentId, ?int $teamId): array
    {
        $team = $teamId === null ? null : self::teamById($teamId);
        if ($teamId !== null && $team === null) {
            return ['ok' => false, 'reason' => 'no-team'];
        }
        if ($departmentId !== null && self::departmentById($departmentId) === null) {
            return ['ok' => false, 'reason' => 'no-department'];
        }
        // Une équipe porte son service : le rattachement suit, plutôt que de diverger.
        $resolved = $team !== null && $team['department_id'] !== null ? (int) $team['department_id'] : $departmentId;
        Db::run('UPDATE users SET department_id = ?, team_id = ? WHERE id = ?', [$resolved, $teamId, $userId]);
        return ['ok' => true];
    }

    /**
     * L'organigramme : services, équipes, personnes.
     *
     * Trois choses qu'il doit dire et que les listes séparées ne disent pas :
     * qui encadre quoi, où se trouve chacun, et qui n'est rattaché nulle part —
     * c'est ce dernier point qu'on découvre en le dessinant.
     */
    public static function chart(bool $includeHidden = false): array
    {
        $visible = $includeHidden ? '' : 'AND u.directory_hidden = 0';
        $people = Db::all(
            "SELECT u.id, u.first_name, u.last_name, u.grade, u.role, u.contract_type,
                    u.department_id, u.team_id, u.avatar_file, u.directory_hidden
             FROM users u WHERE u.active = 1 $visible
             ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE"
        );
        $managerRows = Db::all(
            'SELECT m.scope, m.scope_id, u.id, u.first_name, u.last_name, u.grade
             FROM org_managers m JOIN users u ON u.id = m.user_id WHERE u.active = 1'
        );
        $managersOfScope = static fn (string $scope, int $id): array => array_values(array_filter(
            $managerRows,
            static fn (array $m): bool => $m['scope'] === $scope && (int) $m['scope_id'] === $id
        ));
        $inTeam = static fn (array $people, ?int $teamId): array => array_values(array_filter(
            $people,
            static fn (array $p): bool => $p['team_id'] !== null && (int) $p['team_id'] === $teamId
        ));

        $allTeams = self::teams();
        $structure = [];
        foreach (self::departments() as $department) {
            $departmentId = (int) $department['id'];
            $teams = [];
            foreach ($allTeams as $team) {
                if ($team['department_id'] === null || (int) $team['department_id'] !== $departmentId) {
                    continue;
                }
                $teams[] = $team + [
                    'managers' => $managersOfScope('team', (int) $team['id']),
                    'members' => $inTeam($people, (int) $team['id']),
                ];
            }
            $structure[] = $department + [
                'managers' => $managersOfScope('department', $departmentId),
                'teams' => $teams,
                // Rattaché au service sans équipe : la place existe, elle se voit.
                'loose' => array_values(array_filter($people, static fn (array $p): bool =>
                    $p['department_id'] !== null && (int) $p['department_id'] === $departmentId && empty($p['team_id']))),
            ];
        }

        // Les équipes sans service sont le signe d'un rattachement oublié :
        // les cacher reviendrait à cacher le problème.
        $orphanTeams = [];
        foreach ($allTeams as $team) {
            if ($team['department_id'] !== null) {
                continue;
            }
            $orphanTeams[] = $team + [
                'managers' => $managersOfScope('team', (int) $team['id']),
                'members' => $inTeam($people, (int) $team['id']),
            ];
        }

        $employees = array_values(array_filter($people, static fn (array $p): bool => $p['role'] === 'employee'));
        return [
            'departments' => $structure,
            'orphanTeams' => $orphanTeams,
            'unassigned' => array_values(array_filter($employees, static fn (array $p): bool =>
                empty($p['department_id']) && empty($p['team_id']))),
            'headcount' => count($employees),
            'managerCount' => count(array_unique(array_column($managerRows, 'id'))),
        ];
    }

    /** Décrit le rattachement en une ligne, pour les listes et l'annuaire. */
    public static function membershipLabel(array $user): string
    {
        return implode(' · ', array_filter([$user['team_name'] ?? null, $user['department_name'] ?? null]));
    }
}
