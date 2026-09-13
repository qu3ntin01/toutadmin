<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;
use App\Core\Totp;

/**
 * Double authentification : mise en service, vérification, codes de secours.
 *
 * Le secret n'est marqué actif qu'après qu'un premier code a été validé : sans
 * cette étape, une application mal configurée enfermerait la personne dehors.
 */
final class TwoFactor
{
    public static function stateOf(array $user): array
    {
        return [
            'enabled' => (int) ($user['totp_enabled'] ?? 0) === 1,
            'pending' => !empty($user['totp_secret']) && (int) ($user['totp_enabled'] ?? 0) !== 1,
            'remainingCodes' => (int) Db::value(
                'SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ? AND used_at IS NULL',
                [(int) $user['id']]
            ),
        ];
    }

    /** Prépare un secret, sans encore l'activer. */
    public static function beginEnrolment(int $userId): string
    {
        $secret = Totp::generateSecret();
        Db::run('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?', [$secret, $userId]);
        return $secret;
    }

    public static function uri(array $user, string $issuer = ''): string
    {
        return Totp::otpauthUri(
            (string) $user['totp_secret'],
            (string) $user['email'],
            $issuer !== '' ? $issuer : 'Toutadmin'
        );
    }

    /** Active la double authentification si le code saisi correspond au secret en attente. */
    public static function confirmEnrolment(int $userId, ?string $code): array
    {
        $user = Db::get('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || empty($user['totp_secret'])) {
            return ['ok' => false, 'message' => "Aucune mise en service n'est en cours."];
        }
        if (!Totp::verify((string) $user['totp_secret'], $code)) {
            return ['ok' => false, 'message' => "Code incorrect. Vérifiez l'heure de votre téléphone."];
        }

        Db::run('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$userId]);
        return ['ok' => true, 'recoveryCodes' => self::regenerateRecoveryCodes($userId)];
    }

    /** Remplace les codes de secours ; les anciens cessent aussitôt d'être valables. */
    public static function regenerateRecoveryCodes(int $userId): array
    {
        $codes = Totp::generateRecoveryCodes();
        Db::transaction(static function () use ($userId, $codes): void {
            Db::run('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
            foreach ($codes as $code) {
                Db::run(
                    'INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)',
                    [$userId, Totp::hashRecoveryCode($code)]
                );
            }
        });
        return $codes;
    }

    /**
     * Vérifie un code de connexion : d'abord le code temporaire, puis, à défaut,
     * un code de secours — qui est alors consommé.
     */
    public static function verifyLogin(array $user, ?string $submitted): array
    {
        if ((int) ($user['totp_enabled'] ?? 0) !== 1) {
            return ['ok' => true, 'usedRecovery' => false];
        }
        if (Totp::verify((string) $user['totp_secret'], $submitted)) {
            return ['ok' => true, 'usedRecovery' => false];
        }

        $match = Db::get(
            'SELECT id FROM totp_recovery_codes WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [(int) $user['id'], Totp::hashRecoveryCode((string) $submitted)]
        );
        if ($match === null) {
            return ['ok' => false, 'usedRecovery' => false];
        }

        Db::run("UPDATE totp_recovery_codes SET used_at = datetime('now') WHERE id = ?", [(int) $match['id']]);
        return ['ok' => true, 'usedRecovery' => true];
    }

    public static function disable(int $userId): void
    {
        Db::transaction(static function () use ($userId): void {
            Db::run('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$userId]);
            Db::run('DELETE FROM totp_recovery_codes WHERE user_id = ?', [$userId]);
        });
    }

    /**
     * L'instance peut exiger la double authentification des administrateurs :
     * ce sont eux qui peuvent tout, leur compte est la cible qui vaut la peine.
     */
    public static function requiredFor(array $user): bool
    {
        if (Settings::get('require_2fa_all') === '1') {
            return true;
        }
        return $user['role'] === 'admin' && Settings::get('require_2fa_admin') === '1';
    }
}
