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
    public static function all(): array
    {
        return Db::all('SELECT * FROM tools ORDER BY name COLLATE NOCASE');
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM tools WHERE id = ?', [$id]);
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            "INSERT INTO tools (name, category, reference, description, login_url, status)
             VALUES (?, ?, ?, ?, ?, 'disponible')",
            [$fields['name'], $fields['category'], $fields['reference'], $fields['description'], $fields['login_url']]
        );
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE tools SET name = ?, category = ?, reference = ?, description = ?, login_url = ? WHERE id = ?',
            [$fields['name'], $fields['category'], $fields['reference'], $fields['description'], $fields['login_url'], $id]
        );
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM tools WHERE id = ?', [$id]);
    }

    public static function assignments(): array
    {
        return Db::all('SELECT a.id, a.employee_id, a.tool_id, a.assigned_at, a.note, a.username FROM assignments a');
    }

    public static function assign(int $employeeId, int $toolId, string $note, string $username): bool
    {
        try {
            Db::insert(
                'INSERT INTO assignments (employee_id, tool_id, note, username) VALUES (?, ?, ?, ?)',
                [$employeeId, $toolId, $note, $username]
            );
            return true;
        } catch (\PDOException) {
            // L'index unique tranche : un outil n'est affecté qu'une fois par personne.
            return false;
        }
    }

    public static function unassign(int $id): void
    {
        Db::run('DELETE FROM assignments WHERE id = ?', [$id]);
    }

    public static function forEmployee(int $employeeId): array
    {
        return Db::all(
            'SELECT a.*, t.name, t.category, t.login_url FROM assignments a
             JOIN tools t ON t.id = a.tool_id WHERE a.employee_id = ?
             ORDER BY t.name COLLATE NOCASE',
            [$employeeId]
        );
    }
}
