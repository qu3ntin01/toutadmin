<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Security;
use App\Core\Validate;

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

    public const GRADES = [
        'Stagiaire', 'Employé', 'Technicien', 'Technicien confirmé',
        "Chef d'équipe", 'Responsable', 'Manager', 'Directeur',
    ];

    public const CONTRACT_TYPES = ['CDI', 'CDD', 'Intérim', 'Stage', 'Alternance', 'Freelance'];

    /** Les droits transverses, posés par l'administration et par elle seule. */
    public const ROLE_FLAGS = [
        'is_hr' => 'accès RH',
        'is_finance' => 'accès à la gestion',
        'is_it' => 'service informatique',
        'is_referent' => "référent du dispositif d'alerte",
    ];

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

    // ---------- Administration ----------

    public static function employees(): array
    {
        return \App\Core\Db::all(
            "SELECT * FROM users WHERE role = 'employee'
             ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
        );
    }

    public static function employeeById(int $id): ?array
    {
        return \App\Core\Db::get("SELECT * FROM users WHERE id = ? AND role = 'employee'", [$id]);
    }

    /**
     * Crée un salarié avec un mot de passe temporaire, qu'il devra changer à la
     * première connexion : un mot de passe transmis par un tiers n'a pas à
     * rester en vigueur.
     */
    public static function createEmployee(array $fields, int $leaveBalance): array
    {
        $password = \App\Core\Validate::generatePassword();
        $id = \App\Core\Db::insert(
            "INSERT INTO users (role, email, password_hash, first_name, last_name, grade, contract_type,
                                contract_end_date, daily_rate, leave_balance, active, must_change_password)
             VALUES ('employee', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)",
            [
                $fields['email'], Security::hashPassword($password), $fields['first_name'], $fields['last_name'],
                $fields['grade'], $fields['contract_type'], $fields['contract_end_date'], $fields['daily_rate'],
                $leaveBalance,
            ]
        );
        Audit::log('membre.cree', 'users', $id, ['email' => $fields['email']]);
        return ['id' => $id, 'password' => $password];
    }

    public static function updateEmployee(int $id, array $fields): void
    {
        \App\Core\Db::run(
            'UPDATE users SET grade = ?, contract_type = ?, contract_end_date = ?, daily_rate = ? WHERE id = ?',
            [$fields['grade'], $fields['contract_type'], $fields['contract_end_date'], $fields['daily_rate'], $id]
        );
        Audit::log('membre.modifie', 'users', $id);
    }

    public static function toggleActive(int $id, bool $wasActive): void
    {
        \App\Core\Db::run('UPDATE users SET active = ? WHERE id = ?', [$wasActive ? 0 : 1, $id]);
        Audit::log($wasActive ? 'membre.desactive' : 'membre.reactive', 'users', $id);
    }

    public static function toggleDirectory(int $id, bool $wasHidden): void
    {
        \App\Core\Db::run('UPDATE users SET directory_hidden = ? WHERE id = ?', [$wasHidden ? 0 : 1, $id]);
        Audit::log($wasHidden ? 'annuaire.affiche' : 'annuaire.masque', 'users', $id);
    }

    /**
     * Nouveau mot de passe temporaire. Toutes les sessions ouvertes tombent :
     * un mot de passe passé de la main à la main ne doit pas laisser derrière
     * lui une session encore valable.
     */
    public static function resetPassword(int $id): string
    {
        $password = \App\Core\Validate::generatePassword();
        \App\Core\Db::run(
            'UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL, must_change_password = 1 WHERE id = ?',
            [Security::hashPassword($password), $id]
        );
        \App\Core\Session::destroyAllFor($id);
        Audit::log('utilisateur.mot_de_passe_reinitialise', 'users', $id);
        return $password;
    }

    public static function deleteEmployee(int $id): void
    {
        \App\Core\Db::run("DELETE FROM users WHERE id = ? AND role = 'employee'", [$id]);
        Audit::log('membre.supprime', 'users', $id);
    }

    /**
     * Réglages de messagerie : adresse interne et serveurs. **Réservés à
     * l'administration** — c'est par cette adresse que l'entreprise joint la
     * personne, elle ne se la choisit pas.
     */
    public static function setMailbox(int $id, array $fields): void
    {
        \App\Core\Db::run(
            'UPDATE users SET mail_address = ?, mail_imap_host = ?, mail_imap_port = ?,
                              mail_smtp_host = ?, mail_smtp_port = ? WHERE id = ?',
            [
                $fields['mail_address'], $fields['mail_imap_host'], $fields['mail_imap_port'],
                $fields['mail_smtp_host'], $fields['mail_smtp_port'], $id,
            ]
        );
        Audit::log('membre.messagerie_modifiee', 'users', $id);
    }

    public static function setRoleFlag(int $id, string $flag, bool $granted): bool
    {
        if (!array_key_exists($flag, self::ROLE_FLAGS)) {
            return false;
        }
        \App\Core\Db::run("UPDATE users SET $flag = ? WHERE id = ?", [$granted ? 1 : 0, $id]);
        Audit::log($granted ? 'droit.accorde' : 'droit.retire', 'users', $id, ['droit' => $flag]);
        return true;
    }

    /** Un contrat échu ferme le compte : le lendemain, sans intervention. */
    public static function deactivateExpiredContracts(): int
    {
        return \App\Core\Db::run(
            "UPDATE users SET active = 0
             WHERE active = 1 AND contract_end_date IS NOT NULL AND contract_end_date < date('now')"
        );
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
