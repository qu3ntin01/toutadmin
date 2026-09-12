<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Projets, jalons, tâches et temps passé.
 *
 * Le temps est la matière première d'une entreprise de services : sans
 * imputation par projet, aucune rentabilité n'est calculable. Ce module répond
 * à une question précise — « ce projet a-t-il coûté plus que prévu ? » — et
 * vaut pour tout le monde, salariés compris.
 */
final class Projects
{
    public const STATUSES = ['Cadrage', 'En cours', 'En pause', 'Livré', 'Clôturé'];
    public const TASK_STATUSES = ['À faire', 'En cours', 'En revue', 'Terminée'];
    public const PRIORITIES = ['Basse', 'Normale', 'Haute', 'Critique'];
    public const OPEN_STATUSES = ['Cadrage', 'En cours', 'En pause'];

    public const MAX_HOURS_PER_ENTRY = 24;

    // ---------- Projets ----------

    public static function list(bool $includeArchived = false): array
    {
        $where = $includeArchived ? '' : 'WHERE p.archived = 0';
        return Db::all(
            "SELECT p.*, d.name AS department_name, t.name AS team_name, par.name AS partner_name,
                    u.first_name AS lead_first_name, u.last_name AS lead_last_name
             FROM projects p
             LEFT JOIN departments d ON d.id = p.department_id
             LEFT JOIN teams t ON t.id = p.team_id
             LEFT JOIN partners par ON par.id = p.partner_id
             LEFT JOIN users u ON u.id = p.lead_id
             $where
             ORDER BY p.archived, p.due_date IS NULL, p.due_date, p.name COLLATE NOCASE"
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM projects WHERE id = ?', [$id]);
    }

    public static function create(array $fields): int
    {
        $id = Db::insert(
            'INSERT INTO projects (code, name, partner_id, department_id, team_id, lead_id, status,
                                   start_date, due_date, budget_amount, hourly_rate, description)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['code'] ?? '', $fields['name'], $fields['partnerId'] ?? null, $fields['departmentId'] ?? null,
                $fields['teamId'] ?? null, $fields['leadId'] ?? null, $fields['status'] ?? 'Cadrage',
                $fields['startDate'] ?? null, $fields['dueDate'] ?? null,
                $fields['budgetAmount'] ?? null, $fields['hourlyRate'] ?? null, $fields['description'] ?? '',
            ]
        );
        // Le responsable est membre de fait : sans cela il ne verrait pas son projet.
        if (!empty($fields['leadId'])) {
            self::addMember($id, (int) $fields['leadId'], 'Responsable');
        }
        Audit::log('projet.cree', 'projects', $id, ['nom' => $fields['name']]);
        return $id;
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE projects SET code = ?, name = ?, partner_id = ?, department_id = ?, team_id = ?, lead_id = ?,
                    status = ?, start_date = ?, due_date = ?, budget_amount = ?, hourly_rate = ?, description = ?
             WHERE id = ?',
            [
                $fields['code'] ?? '', $fields['name'], $fields['partnerId'] ?? null, $fields['departmentId'] ?? null,
                $fields['teamId'] ?? null, $fields['leadId'] ?? null, $fields['status'],
                $fields['startDate'] ?? null, $fields['dueDate'] ?? null,
                $fields['budgetAmount'] ?? null, $fields['hourlyRate'] ?? null, $fields['description'] ?? '', $id,
            ]
        );
        Audit::log('projet.modifie', 'projects', $id);
    }

    public static function archive(int $id, bool $archived = true): void
    {
        Db::run('UPDATE projects SET archived = ? WHERE id = ?', [$archived ? 1 : 0, $id]);
        Audit::log($archived ? 'projet.archive' : 'projet.reouvert', 'projects', $id);
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM projects WHERE id = ?', [$id]);
        Audit::log('projet.supprime', 'projects', $id);
    }

    // ---------- Équipe projet ----------

    public static function members(int $projectId): array
    {
        return Db::all(
            'SELECT pm.*, u.first_name, u.last_name, u.email, u.grade
             FROM project_members pm JOIN users u ON u.id = pm.user_id
             WHERE pm.project_id = ? ORDER BY u.last_name COLLATE NOCASE',
            [$projectId]
        );
    }

    public static function addMember(int $projectId, int $userId, string $role = ''): void
    {
        Db::run('INSERT OR IGNORE INTO project_members (project_id, user_id, role) VALUES (?, ?, ?)', [$projectId, $userId, $role]);
    }

    public static function removeMember(int $projectId, int $userId): void
    {
        Db::run('DELETE FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
    }

    /** Les projets qu'une personne voit : ceux dont elle est membre ou responsable. */
    public static function forUser(int $userId): array
    {
        return Db::all(
            'SELECT DISTINCT p.* FROM projects p
             LEFT JOIN project_members pm ON pm.project_id = p.id
             WHERE p.archived = 0 AND (pm.user_id = ? OR p.lead_id = ?)
             ORDER BY p.due_date IS NULL, p.due_date',
            [$userId, $userId]
        );
    }

    // ---------- Jalons ----------

    public static function milestones(int $projectId): array
    {
        return Db::all('SELECT * FROM project_milestones WHERE project_id = ? ORDER BY due_date IS NULL, due_date, id', [$projectId]);
    }

    public static function createMilestone(int $projectId, string $title, ?string $dueDate): int
    {
        return Db::insert('INSERT INTO project_milestones (project_id, title, due_date) VALUES (?, ?, ?)', [$projectId, $title, $dueDate]);
    }

    public static function toggleMilestone(int $id): ?int
    {
        $row = Db::get('SELECT * FROM project_milestones WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        Db::run('UPDATE project_milestones SET reached_on = ? WHERE id = ?', [$row['reached_on'] ? null : gmdate('Y-m-d'), $id]);
        return (int) $row['project_id'];
    }

    public static function deleteMilestone(int $id): ?int
    {
        $row = Db::get('SELECT project_id FROM project_milestones WHERE id = ?', [$id]);
        Db::run('DELETE FROM project_milestones WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['project_id'];
    }

    // ---------- Tâches ----------

    public static function tasks(int $projectId): array
    {
        return Db::all(
            'SELECT t.*, u.first_name, u.last_name, m.title AS milestone_title,
                    (SELECT COALESCE(SUM(hours), 0) FROM project_time WHERE task_id = t.id) AS spent_hours
             FROM project_tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             LEFT JOIN project_milestones m ON m.id = t.milestone_id
             WHERE t.project_id = ?
             ORDER BY t.done_at IS NOT NULL, t.due_date IS NULL, t.due_date, t.id',
            [$projectId]
        );
    }

    /** Les tâches regroupées par colonne, dans l'ordre des statuts. */
    public static function board(int $projectId): array
    {
        $all = self::tasks($projectId);
        return array_map(
            static fn (string $status): array => [
                'status' => $status,
                'items' => array_values(array_filter($all, static fn (array $t): bool => $t['status'] === $status)),
            ],
            self::TASK_STATUSES
        );
    }

    public static function createTask(array $fields): int
    {
        return Db::insert(
            'INSERT INTO project_tasks (project_id, milestone_id, title, description, assignee_id, status,
                                        priority, estimate_hours, due_date, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['projectId'], $fields['milestoneId'] ?? null, $fields['title'], $fields['description'] ?? '',
                $fields['assigneeId'] ?? null, $fields['status'] ?? 'À faire', $fields['priority'] ?? 'Normale',
                $fields['estimateHours'] ?? null, $fields['dueDate'] ?? null, $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function taskById(int $id): ?array
    {
        return Db::get('SELECT * FROM project_tasks WHERE id = ?', [$id]);
    }

    /** Changer de statut pose (ou efface) la date de fin : elle ne se saisit pas. */
    public static function setTaskStatus(int $id, string $status): ?int
    {
        if (!in_array($status, self::TASK_STATUSES, true)) {
            return null;
        }
        $task = self::taskById($id);
        if ($task === null) {
            return null;
        }
        Db::run(
            'UPDATE project_tasks SET status = ?, done_at = ? WHERE id = ?',
            [$status, $status === 'Terminée' ? gmdate('Y-m-d') : null, $id]
        );
        return (int) $task['project_id'];
    }

    public static function assignTask(int $id, ?int $userId): ?int
    {
        $task = self::taskById($id);
        if ($task === null) {
            return null;
        }
        Db::run('UPDATE project_tasks SET assignee_id = ? WHERE id = ?', [$userId ?: null, $id]);
        return (int) $task['project_id'];
    }

    public static function deleteTask(int $id): ?int
    {
        $task = self::taskById($id);
        Db::run('DELETE FROM project_tasks WHERE id = ?', [$id]);
        return $task === null ? null : (int) $task['project_id'];
    }

    /** Les tâches ouvertes d'une personne, tous projets confondus. */
    public static function tasksOf(int $userId): array
    {
        return Db::all(
            "SELECT t.*, p.name AS project_name
             FROM project_tasks t JOIN projects p ON p.id = t.project_id
             WHERE t.assignee_id = ? AND t.status != 'Terminée' AND p.archived = 0
             ORDER BY t.due_date IS NULL, t.due_date",
            [$userId]
        );
    }

    // ---------- Temps passé ----------

    public static function logTime(array $fields): array
    {
        $hours = (float) $fields['hours'];
        if ($hours <= 0 || $hours > self::MAX_HOURS_PER_ENTRY) {
            return ['ok' => false, 'message' => 'Une saisie porte entre 0 et ' . self::MAX_HOURS_PER_ENTRY . ' heures.'];
        }
        Db::insert(
            'INSERT INTO project_time (project_id, task_id, user_id, spent_on, hours, note, billable)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['projectId'], $fields['taskId'] ?? null, $fields['userId'], $fields['spentOn'],
                $hours, $fields['note'] ?? '', ($fields['billable'] ?? true) ? 1 : 0,
            ]
        );
        return ['ok' => true];
    }

    public static function timeEntries(int $projectId, int $limit = 200): array
    {
        return Db::all(
            'SELECT pt.*, u.first_name, u.last_name, t.title AS task_title
             FROM project_time pt
             JOIN users u ON u.id = pt.user_id
             LEFT JOIN project_tasks t ON t.id = pt.task_id
             WHERE pt.project_id = ?
             ORDER BY pt.spent_on DESC, pt.id DESC LIMIT ?',
            [$projectId, $limit]
        );
    }

    public static function timeOf(int $userId, ?string $from = null, ?string $to = null): array
    {
        $clauses = ['pt.user_id = ?'];
        $params = [$userId];
        if ($from !== null) {
            $clauses[] = 'pt.spent_on >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'pt.spent_on <= ?';
            $params[] = $to;
        }
        return Db::all(
            'SELECT pt.*, p.name AS project_name, t.title AS task_title
             FROM project_time pt
             JOIN projects p ON p.id = pt.project_id
             LEFT JOIN project_tasks t ON t.id = pt.task_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY pt.spent_on DESC, pt.id DESC',
            $params
        );
    }

    /** Une saisie ne s'efface que par son auteur, sauf appel sans restriction. */
    public static function deleteTimeEntry(int $id, ?int $userId = null): ?int
    {
        $clause = $userId === null ? '' : 'AND user_id = ?';
        $params = $userId === null ? [$id] : [$id, $userId];
        $row = Db::get("SELECT project_id FROM project_time WHERE id = ? $clause", $params);
        if ($row === null) {
            return null;
        }
        Db::run('DELETE FROM project_time WHERE id = ?', [$id]);
        return (int) $row['project_id'];
    }

    /**
     * Rentabilité : heures passées, coût au taux horaire du projet, écart au
     * budget. Sans taux horaire renseigné, on rend les heures sans inventer un
     * coût.
     */
    public static function profitability(array $project): array
    {
        $totals = Db::get(
            'SELECT COALESCE(SUM(hours), 0) AS hours,
                    COALESCE(SUM(CASE WHEN billable = 1 THEN hours ELSE 0 END), 0) AS billable_hours
             FROM project_time WHERE project_id = ?',
            [$project['id']]
        );
        $rate = $project['hourly_rate'];
        $budget = $project['budget_amount'];
        $cost = $rate === null ? null : round((float) $totals['hours'] * (float) $rate, 2);
        $margin = $cost === null || $budget === null ? null : round((float) $budget - $cost, 2);

        return [
            'hours' => round((float) $totals['hours'], 2),
            'billableHours' => round((float) $totals['billable_hours'], 2),
            'cost' => $cost,
            'budget' => $budget === null ? null : (float) $budget,
            'margin' => $margin,
            // Part du budget consommée : ce qui alerte avant que le dépassement
            // soit acquis.
            'consumed' => $cost === null || empty($budget) ? null : min(999, (int) round($cost / (float) $budget * 100)),
        ];
    }

    public static function timeByMember(int $projectId): array
    {
        return Db::all(
            'SELECT u.id, u.first_name, u.last_name, SUM(pt.hours) AS hours
             FROM project_time pt JOIN users u ON u.id = pt.user_id
             WHERE pt.project_id = ?
             GROUP BY u.id ORDER BY hours DESC',
            [$projectId]
        );
    }

    public static function summary(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        return [
            'open' => (int) Db::value("SELECT COUNT(*) FROM projects WHERE archived = 0 AND status IN ($placeholders)", self::OPEN_STATUSES),
            'late' => (int) Db::value(
                "SELECT COUNT(*) FROM projects
                 WHERE archived = 0 AND due_date IS NOT NULL AND due_date < date('now') AND status NOT IN ('Livré', 'Clôturé')"
            ),
            'openTasks' => (int) Db::value("SELECT COUNT(*) FROM project_tasks WHERE status != 'Terminée'"),
            'hours' => round((float) Db::value("SELECT COALESCE(SUM(hours), 0) FROM project_time WHERE spent_on >= date('now', '-30 days')"), 1),
        ];
    }
}
