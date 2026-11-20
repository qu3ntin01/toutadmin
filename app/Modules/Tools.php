<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Catalogue d'outils et affectations.
 *
 * Savoir qui a accès à quoi n'est pas un confort : c'est ce qui permet de
 * fermer tous les accès d'une personne le jour de son départ, sans en oublier.
 */
final class Tools
{
    /** Le périmètre courant : un outil et son affectation sont d'une société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    public static function all(): array
    {
        return Db::all(
            'SELECT * FROM tools WHERE company_id = ? ORDER BY name COLLATE NOCASE',
            [self::ici()]
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM tools WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            "INSERT INTO tools (company_id, name, category, reference, description, login_url, status)
             VALUES (?, ?, ?, ?, ?, ?, 'disponible')",
            [
                self::ici(), $fields['name'], $fields['category'], $fields['reference'],
                $fields['description'], $fields['login_url'],
            ]
        );
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE tools SET name = ?, category = ?, reference = ?, description = ?, login_url = ?
             WHERE id = ? AND company_id = ?',
            [
                $fields['name'], $fields['category'], $fields['reference'], $fields['description'],
                $fields['login_url'], $id, self::ici(),
            ]
        );
    }

    public static function remove(int $id): bool
    {
        if (Db::run('DELETE FROM tools WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    public static function assignments(): array
    {
        return Db::all(
            'SELECT a.id, a.employee_id, a.tool_id, a.assigned_at, a.note, a.username
             FROM assignments a WHERE a.company_id = ?',
            [self::ici()]
        );
    }

    public static function assign(int $employeeId, int $toolId, string $note, string $username): bool
    {
        try {
            Db::insert(
                'INSERT INTO assignments (company_id, employee_id, tool_id, note, username)
                 VALUES (?, ?, ?, ?, ?)',
                [self::ici(), $employeeId, $toolId, $note, $username]
            );
            return true;
        } catch (\PDOException) {
            // L'index unique tranche : un outil n'est affecté qu'une fois par personne.
            return false;
        }
    }

    public static function unassign(int $id): bool
    {
        if (Db::run('DELETE FROM assignments WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    public static function forEmployee(int $employeeId): array
    {
        return Db::all(
            'SELECT a.*, t.name, t.category, t.reference, t.login_url FROM assignments a
             JOIN tools t ON t.id = a.tool_id WHERE a.employee_id = ? AND a.company_id = ?
             ORDER BY t.name COLLATE NOCASE',
            [$employeeId, self::ici()]
        );
    }
}
