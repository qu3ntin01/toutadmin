<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Verrouillage de compte et plafonds de requêtes.
 *
 * Cinq échecs verrouillent le compte un quart d'heure ; l'adresse, elle, est
 * plafonnée séparément, sans quoi un attaquant essaierait mille comptes à
 * raison de quatre essais chacun sans jamais rien verrouiller.
 */
final class Security
{
    public const MAX_FAILED_ATTEMPTS = 5;
    public const LOCKOUT_MINUTES = 15;

    /**
     * Empreinte bcrypt d'une valeur aléatoire : ne correspond à aucun mot de
     * passe réel. Sert à garder un temps de réponse constant que le compte
     * existe ou non — sans quoi la durée de la réponse dirait quelles adresses
     * sont inscrites.
     */
    public const DUMMY_HASH = '$2a$12$yztgRTN3RnAyNh3.buKzkuqYPz1ESHeRuOD4TFbLjE6MdyFLKeL5e';

    public static function isLocked(array $user): bool
    {
        $until = $user['locked_until'] ?? null;
        return $until !== null && $until !== '' && strtotime((string) $until) > time();
    }

    public static function registerFailedAttempt(array $user): void
    {
        $attempts = (int) ($user['failed_attempts'] ?? 0) + 1;
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            $until = gmdate('Y-m-d\TH:i:s.000\Z', time() + self::LOCKOUT_MINUTES * 60);
            Db::run('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?', [$attempts, $until, $user['id']]);
            return;
        }
        Db::run('UPDATE users SET failed_attempts = ? WHERE id = ?', [$attempts, $user['id']]);
    }

    public static function resetFailedAttempts(int $userId): void
    {
        Db::run('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$userId]);
    }

    /**
     * Plafond par adresse et par action, compté en base : l'hébergement
     * mutualisé n'offre ni mémoire partagée ni démon à qui confier ce compte.
     */
    public static function tooManyAttempts(string $bucket, int $limit, int $windowSeconds): bool
    {
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 64);
        $now = time();
        Db::run('DELETE FROM rate_limits WHERE window_start < ?', [$now - $windowSeconds]);

        $row = Db::get('SELECT * FROM rate_limits WHERE bucket = ? AND ip = ?', [$bucket, $ip]);
        if ($row === null) {
            Db::run('INSERT INTO rate_limits (bucket, ip, hits, window_start) VALUES (?, ?, 1, ?)', [$bucket, $ip, $now]);
            return false;
        }
        if ((int) $row['window_start'] < $now - $windowSeconds) {
            Db::run('UPDATE rate_limits SET hits = 1, window_start = ? WHERE id = ?', [$now, $row['id']]);
            return false;
        }
        $hits = (int) $row['hits'] + 1;
        Db::run('UPDATE rate_limits SET hits = ? WHERE id = ?', [$hits, $row['id']]);
        return $hits > $limit;
    }

    /**
     * Les empreintes de l'édition Node commencent par $2a$ ; PHP écrit $2y$.
     * Les deux sont du bcrypt et se vérifient de part et d'autre : une base
     * passée d'une édition à l'autre garde ses mots de passe.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Brûle le même temps qu'une vérification réelle, sans compte à vérifier. */
    public static function burnTime(string $password): void
    {
        password_verify($password, self::DUMMY_HASH);
    }

    /** La requête courante voyage-t-elle chiffrée ? */
    private static function isSecure(): bool
    {
        if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }
        // Derrière un proxy de confiance seulement : sinon n'importe qui
        // annoncerait le protocole de son choix dans un en-tête.
        return Config::get('trust_proxy', false)
            && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    /** En-têtes de sécurité, posés sur chaque réponse HTML. */
    public static function headers(string $nonce): array
    {
        $headers = [
            'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-$nonce'; "
                . "style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            // Une ressource de ce site ne se charge pas depuis un autre site.
            'Cross-Origin-Resource-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            'X-DNS-Prefetch-Control' => 'off',
            'Permissions-Policy' => 'geolocation=(), camera=(), microphone=(), payment=()',
        ];

        // HSTS ne se pose que sur une connexion déjà chiffrée : annoncé depuis
        // une page en clair, il n'est pas lu, et il enfermerait un essai local
        // en https pour six mois.
        if (self::isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=15552000; includeSubDomains';
        }
        return $headers;
    }
}
