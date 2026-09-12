<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Security;

/**
 * Comptes.
 *
 * Une règle tient tout le reste : **seul un administrateur crée un compte**.
 * Il n'y a pas d'inscription libre, pas de lien d'invitation ouvert, pas de
 * création par un manager. Un annuaire d'entreprise se peuple par décision, pas
 * par formulaire public.
 */
final class Users
{
    public const ROLES = ['admin', 'employee'];

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public static function byEmail(string $email): ?array
    {
        return Db::get('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    }

    public static function count(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM users');
    }

    /**
     * Crée un compte. Réservé à l'administration : l'appelant doit l'avoir
     * vérifié, et la route qui y mène passe par requireAdmin.
     */
    public static function create(array $fields): int
    {
        $email = strtolower(trim((string) ($fields['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Adresse électronique invalide.');
        }
        if (self::byEmail($email) !== null) {
            throw new \InvalidArgumentException('Cette adresse est déjà utilisée.');
        }
        $role = in_array($fields['role'] ?? 'employee', self::ROLES, true) ? $fields['role'] : 'employee';

        $id = Db::insert(
            'INSERT INTO users (role, email, password_hash, first_name, last_name, grade, contract_type,
                                contract_end_date, department_id, team_id, locale, must_change_password, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)',
            [
                $role,
                $email,
                Security::hashPassword((string) ($fields['password'] ?? bin2hex(random_bytes(12)))),
                (string) ($fields['first_name'] ?? ''),
                (string) ($fields['last_name'] ?? ''),
                (string) ($fields['grade'] ?? ''),
                (string) ($fields['contract_type'] ?? ''),
                $fields['contract_end_date'] ?? null,
                $fields['department_id'] ?? null,
                $fields['team_id'] ?? null,
                (string) ($fields['locale'] ?? 'fr'),
                !empty($fields['must_change_password']) ? 1 : 0,
            ]
        );
        Audit::log('membre.cree', 'users', $id, ['email' => $email, 'role' => $role]);
        return $id;
    }

    /**
     * L'adresse de courrier interne et les réglages de serveur de messagerie ne
     * figurent pas ici : ils ne sont modifiables que par l'administration. Le
     * membre change son mot de passe, sa langue, son avatar, sa biographie —
     * pas l'adresse par laquelle l'entreprise le joint.
     */
    public const SELF_EDITABLE = ['first_name', 'last_name', 'phone', 'bio', 'locale', 'avatar_file'];

    public static function updateSelf(int $userId, array $fields): void
    {
        $changes = [];
        $params = [];
        foreach (self::SELF_EDITABLE as $column) {
            if (array_key_exists($column, $fields)) {
                $changes[] = "$column = ?";
                $params[] = $fields[$column];
            }
        }
        if ($changes === []) {
            return;
        }
        $params[] = $userId;
        Db::run('UPDATE users SET ' . implode(', ', $changes) . ' WHERE id = ?', $params);
        Audit::log('profil.modifie', 'users', $userId, array_keys(array_intersect_key($fields, array_flip(self::SELF_EDITABLE))));
    }

    /** Le nom affiché, avec repli sur l'adresse : jamais une ligne vide. */
    public static function displayName(array $user): string
    {
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        return $name !== '' ? $name : (string) ($user['email'] ?? '');
    }

    public static function initials(array $user): string
    {
        $first = mb_substr((string) ($user['first_name'] ?? ''), 0, 1);
        $last = mb_substr((string) ($user['last_name'] ?? ''), 0, 1);
        $initials = mb_strtoupper($first . $last);
        return $initials !== '' ? $initials : mb_strtoupper(mb_substr((string) ($user['email'] ?? '?'), 0, 2));
    }

    /**
     * Politique de mot de passe : douze caractères au moins, trois familles sur
     * quatre. Un minimum plus court se compense mal par des règles plus
     * tordues, qui poussent surtout à écrire le mot de passe sur un papier.
     */
    public static function passwordProblem(string $password): ?string
    {
        if (mb_strlen($password) < 12) {
            return 'Le mot de passe doit faire au moins 12 caractères.';
        }
        $families = 0;
        $families += preg_match('/[a-z]/', $password) ? 1 : 0;
        $families += preg_match('/[A-Z]/', $password) ? 1 : 0;
        $families += preg_match('/[0-9]/', $password) ? 1 : 0;
        $families += preg_match('/[^a-zA-Z0-9]/', $password) ? 1 : 0;
        if ($families < 3) {
            return 'Le mot de passe doit mêler au moins trois familles de caractères : minuscules, majuscules, chiffres, symboles.';
        }
        return null;
    }

    public static function setPassword(int $userId, string $password): void
    {
        Db::run(
            "UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = datetime('now') WHERE id = ?",
            [Security::hashPassword($password), $userId]
        );
        Audit::log('mot_de_passe.change', 'users', $userId);
    }

    /** La forme que prend l'utilisateur en session : aucune empreinte, aucun secret. */
    public static function forSession(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'role' => $user['role'],
            'email' => $user['email'],
            'firstName' => $user['first_name'],
            'lastName' => $user['last_name'],
            'locale' => $user['locale'] ?: 'fr',
            'isHr' => (int) ($user['is_hr'] ?? 0) === 1,
            'isFinance' => (int) ($user['is_finance'] ?? 0) === 1,
            'isIt' => (int) ($user['is_it'] ?? 0) === 1,
            'isReferent' => (int) ($user['is_referent'] ?? 0) === 1,
            'mustChangePassword' => (int) ($user['must_change_password'] ?? 0) === 1,
        ];
    }
}
