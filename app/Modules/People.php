<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Arrivées, départs et compétences.
 *
 * Une embauche et un départ sont des suites de gestes à faire par plusieurs
 * services, à des dates relatives à un jour pivot. Les tenir de mémoire, c'est
 * oublier de couper un accès. On part donc d'un modèle réutilisable, dont on
 * tire une liste datée par personne.
 */
final class People
{
    public const CHECKLIST_KINDS = ['Arrivée', 'Départ'];
    public const OWNER_ROLES = ['RH', 'Informatique', 'Manager', 'Gestion', 'Moyens généraux'];
    public const SKILL_LEVELS = [1, 2, 3, 4];

    // ---------- Modèles ----------

    public static function templates(): array
    {
        return array_map(
            static function (array $template): array {
                $template['items'] = self::templateItems((int) $template['id']);
                return $template;
            },
            Db::all('SELECT * FROM checklist_templates ORDER BY kind, name COLLATE NOCASE')
        );
    }

    public static function templateById(int $id): ?array
    {
        return Db::get('SELECT * FROM checklist_templates WHERE id = ?', [$id]);
    }

    public static function templateItems(int $templateId): array
    {
        return Db::all(
            'SELECT * FROM checklist_template_items WHERE template_id = ? ORDER BY sort_order, id',
            [$templateId]
        );
    }

    public static function createTemplate(string $name, string $kind): int
    {
        return Db::insert('INSERT INTO checklist_templates (name, kind) VALUES (?, ?)', [$name, $kind]);
    }

    public static function deleteTemplate(int $id): void
    {
        Db::run('DELETE FROM checklist_templates WHERE id = ?', [$id]);
    }

    public static function addTemplateItem(int $templateId, array $fields): int
    {
        $next = (int) Db::value(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM checklist_template_items WHERE template_id = ?',
            [$templateId]
        );
        return Db::insert(
            'INSERT INTO checklist_template_items (template_id, label, owner_role, offset_days, sort_order)
             VALUES (?, ?, ?, ?, ?)',
            [$templateId, $fields['label'], $fields['ownerRole'], (int) $fields['offsetDays'], $next]
        );
    }

    public static function deleteTemplateItem(int $id): ?int
    {
        $row = Db::get('SELECT template_id FROM checklist_template_items WHERE id = ?', [$id]);
        Db::run('DELETE FROM checklist_template_items WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['template_id'];
    }

    // ---------- Listes appliquées ----------

    public static function shiftDate(string $reference, int $days): string
    {
        return gmdate('Y-m-d', (int) strtotime($reference . " UTC $days days"));
    }

    /**
     * Applique un modèle à une personne. Les échéances sont calculées à partir du
     * jour pivot : « J-2 : préparer le poste » vaut deux jours avant l'arrivée.
     */
    public static function startChecklist(array $options): ?int
    {
        $template = self::templateById((int) $options['templateId']);
        if ($template === null) {
            return null;
        }

        return Db::transaction(static function () use ($template, $options): int {
            $id = Db::insert(
                'INSERT INTO checklists (template_id, user_id, kind, reference_date) VALUES (?, ?, ?, ?)',
                [(int) $template['id'], (int) $options['userId'], $template['kind'], $options['referenceDate']]
            );
            foreach (self::templateItems((int) $template['id']) as $item) {
                Db::run(
                    'INSERT INTO checklist_items (checklist_id, label, owner_role, due_date, sort_order)
                     VALUES (?, ?, ?, ?, ?)',
                    [
                        $id, $item['label'], $item['owner_role'],
                        self::shiftDate($options['referenceDate'], (int) $item['offset_days']),
                        (int) $item['sort_order'],
                    ]
                );
            }
            return $id;
        });
    }

    public static function checklists(array $filters = []): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['userId'])) {
            $clauses[] = 'c.user_id = ?';
            $params[] = (int) $filters['userId'];
        }
        if (!empty($filters['openOnly'])) {
            $clauses[] = 'c.completed_at IS NULL';
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return Db::all(
            "SELECT c.*, u.first_name, u.last_name, u.email, t.name AS template_name,
                    (SELECT COUNT(*) FROM checklist_items WHERE checklist_id = c.id) AS total,
                    (SELECT COUNT(*) FROM checklist_items WHERE checklist_id = c.id AND done_at IS NOT NULL) AS done
             FROM checklists c
             JOIN users u ON u.id = c.user_id
             LEFT JOIN checklist_templates t ON t.id = c.template_id
             $where
             ORDER BY c.completed_at IS NOT NULL, c.reference_date DESC",
            $params
        );
    }

    public static function checklistById(int $id): ?array
    {
        return Db::get('SELECT * FROM checklists WHERE id = ?', [$id]);
    }

    public static function checklistItems(int $checklistId): array
    {
        return Db::all(
            'SELECT i.*, u.first_name, u.last_name FROM checklist_items i
             LEFT JOIN users u ON u.id = i.done_by
             WHERE i.checklist_id = ? ORDER BY i.sort_order, i.id',
            [$checklistId]
        );
    }

    /** Cocher le dernier point clôt la liste ; en décocher un la rouvre. */
    public static function toggleItem(int $itemId, ?int $userId): ?int
    {
        $item = Db::get('SELECT * FROM checklist_items WHERE id = ?', [$itemId]);
        if ($item === null) {
            return null;
        }

        Db::transaction(static function () use ($item, $itemId, $userId): void {
            if ($item['done_at'] !== null) {
                Db::run('UPDATE checklist_items SET done_at = NULL, done_by = NULL WHERE id = ?', [$itemId]);
            } else {
                Db::run(
                    "UPDATE checklist_items SET done_at = datetime('now'), done_by = ? WHERE id = ?",
                    [$userId, $itemId]
                );
            }
            $remaining = (int) Db::value(
                'SELECT COUNT(*) FROM checklist_items WHERE checklist_id = ? AND done_at IS NULL',
                [(int) $item['checklist_id']]
            );
            Db::run(
                'UPDATE checklists SET completed_at = ? WHERE id = ?',
                [$remaining === 0 ? gmdate('c') : null, (int) $item['checklist_id']]
            );
        });

        return (int) $item['checklist_id'];
    }

    public static function deleteChecklist(int $id): void
    {
        Db::run('DELETE FROM checklists WHERE id = ?', [$id]);
    }

    /** Les points en retard, tous parcours confondus : c'est ce qui se pilote. */
    public static function lateItems(): array
    {
        return Db::all(
            "SELECT i.*, c.kind, c.user_id, u.first_name, u.last_name
             FROM checklist_items i
             JOIN checklists c ON c.id = i.checklist_id
             JOIN users u ON u.id = c.user_id
             WHERE i.done_at IS NULL AND i.due_date IS NOT NULL AND i.due_date < date('now')
             ORDER BY i.due_date"
        );
    }

    // ---------- Compétences et habilitations ----------

    public static function skills(): array
    {
        return Db::all(
            'SELECT s.*, (SELECT COUNT(*) FROM user_skills WHERE skill_id = s.id) AS holders
             FROM skills s ORDER BY s.category COLLATE NOCASE, s.name COLLATE NOCASE'
        );
    }

    public static function createSkill(array $fields): int
    {
        return Db::insert(
            'INSERT INTO skills (name, category, validity_months, mandatory) VALUES (?, ?, ?, ?)',
            [
                $fields['name'], $fields['category'], $fields['validityMonths'] ?? null,
                !empty($fields['mandatory']) ? 1 : 0,
            ]
        );
    }

    public static function deleteSkill(int $id): void
    {
        Db::run('DELETE FROM skills WHERE id = ?', [$id]);
    }

    public static function skillById(int $id): ?array
    {
        return Db::get('SELECT * FROM skills WHERE id = ?', [$id]);
    }

    /** L'échéance découle de la durée de validité de l'habilitation, pas d'une saisie. */
    public static function expiryFor(array $skill, ?string $obtainedOn): ?string
    {
        if (empty($skill['validity_months']) || $obtainedOn === null || $obtainedOn === '') {
            return null;
        }
        return Billing::addMonths($obtainedOn, (int) $skill['validity_months']);
    }

    public static function grantSkill(array $fields): bool
    {
        $skill = self::skillById((int) $fields['skillId']);
        if ($skill === null) {
            return false;
        }

        $obtained = $fields['obtainedOn'] ?? null;
        Db::run(
            'INSERT INTO user_skills (user_id, skill_id, level, obtained_on, expires_on, reference)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, skill_id) DO UPDATE SET
               level = excluded.level, obtained_on = excluded.obtained_on,
               expires_on = excluded.expires_on, reference = excluded.reference',
            [
                (int) $fields['userId'], (int) $fields['skillId'], (int) $fields['level'],
                $obtained ?: null, self::expiryFor($skill, $obtained), $fields['reference'] ?? '',
            ]
        );
        return true;
    }

    public static function revokeSkill(int $userId, int $skillId): void
    {
        Db::run('DELETE FROM user_skills WHERE user_id = ? AND skill_id = ?', [$userId, $skillId]);
    }

    public static function skillsOf(int $userId): array
    {
        return Db::all(
            'SELECT us.*, s.name, s.category, s.validity_months, s.mandatory
             FROM user_skills us JOIN skills s ON s.id = us.skill_id
             WHERE us.user_id = ? ORDER BY s.category COLLATE NOCASE, s.name COLLATE NOCASE',
            [$userId]
        );
    }

    /** La matrice : qui détient quoi, et ce qui périme. */
    public static function matrix(): array
    {
        $people = Db::all(
            "SELECT id, first_name, last_name FROM users
             WHERE active = 1 AND role = 'employee' ORDER BY last_name COLLATE NOCASE"
        );
        $held = Db::all('SELECT * FROM user_skills');

        return array_map(static function (array $person) use ($held): array {
            $mine = [];
            foreach ($held as $row) {
                if ((int) $row['user_id'] === (int) $person['id']) {
                    $mine[(int) $row['skill_id']] = $row;
                }
            }
            $person['held'] = $mine;
            return $person;
        }, $people);
    }

    public static function expiringSkills(int $withinDays = 90): array
    {
        return Db::all(
            "SELECT us.*, s.name, s.category, u.first_name, u.last_name
             FROM user_skills us
             JOIN skills s ON s.id = us.skill_id
             JOIN users u ON u.id = us.user_id
             WHERE us.expires_on IS NOT NULL AND us.expires_on <= date('now', ?) AND u.active = 1
             ORDER BY us.expires_on",
            ['+' . $withinDays . ' days']
        );
    }

    /** Les habilitations obligatoires que quelqu'un n'a pas, ou plus. */
    public static function missingMandatory(): array
    {
        return Db::all(
            "SELECT u.id AS user_id, u.first_name, u.last_name, s.id AS skill_id, s.name, us.expires_on
             FROM users u
             CROSS JOIN skills s
             LEFT JOIN user_skills us ON us.user_id = u.id AND us.skill_id = s.id
             WHERE s.mandatory = 1 AND u.active = 1 AND u.role = 'employee'
               AND (us.id IS NULL OR (us.expires_on IS NOT NULL AND us.expires_on < date('now')))
             ORDER BY u.last_name COLLATE NOCASE, s.name"
        );
    }
}
