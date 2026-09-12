<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sessions en base, pas en fichiers.
 *
 * Trois raisons, les mêmes que dans l'édition Node : elles survivent à un
 * redémarrage, elles tiennent quand plusieurs processus servent le site, et
 * l'administration peut révoquer d'un coup toutes les sessions d'un compte —
 * ce qu'un dossier de fichiers de session ne sait pas faire proprement.
 *
 * Le cookie ne porte que l'identifiant de session : aucune donnée, donc rien à
 * falsifier côté visiteur.
 */
final class Session
{
    public const COOKIE = 'ta_sid';

    private static ?string $sid = null;
    private static array $data = [];
    private static bool $started = false;
    /** En test, les en-têtes ne partent nulle part : on les retient. */
    private static array $queuedCookies = [];
    private static bool $sendCookies = true;

    public static function start(?string $sid = null): void
    {
        self::$sid = $sid ?? ($_COOKIE[self::COOKIE] ?? null);
        self::$data = [];
        self::$started = true;

        if (self::$sid !== null) {
            $row = Db::get('SELECT data, expires_at FROM sessions WHERE sid = ?', [self::$sid]);
            if ($row === null || (int) $row['expires_at'] < time()) {
                if ($row !== null) {
                    Db::run('DELETE FROM sessions WHERE sid = ?', [self::$sid]);
                }
                self::$sid = null;
            } else {
                $decoded = json_decode((string) $row['data'], true);
                self::$data = is_array($decoded) ? $decoded : [];
            }
        }

        if (self::$sid === null) {
            self::$sid = self::newId();
            self::emitCookie();
        }

        self::collect();
    }

    public static function id(): string
    {
        self::ensure();
        return (string) self::$sid;
    }

    public static function get(string $key, mixed $fallback = null): mixed
    {
        self::ensure();
        return self::$data[$key] ?? $fallback;
    }

    public static function set(string $key, mixed $value): void
    {
        self::ensure();
        self::$data[$key] = $value;
        self::save();
    }

    public static function forget(string $key): void
    {
        self::ensure();
        unset(self::$data[$key]);
        self::save();
    }

    public static function all(): array
    {
        self::ensure();
        return self::$data;
    }

    /**
     * Nouvel identifiant, mêmes données.
     *
     * À faire à chaque changement de niveau de droits — ouverture de session,
     * passage du second facteur : sans cela, un identifiant volé avant la
     * connexion vaudrait encore après (fixation de session).
     */
    public static function regenerate(): void
    {
        self::ensure();
        $old = self::$sid;
        self::$sid = self::newId();
        Db::run('DELETE FROM sessions WHERE sid = ?', [$old]);
        self::emitCookie();
        self::save();
    }

    public static function destroy(): void
    {
        self::ensure();
        Db::run('DELETE FROM sessions WHERE sid = ?', [self::$sid]);
        self::$data = [];
        self::$sid = self::newId();
        self::emitCookie();
    }

    /** Ferme toutes les sessions d'un compte : mot de passe changé, départ, vol. */
    public static function destroyAllFor(int $userId): int
    {
        return Db::run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    public static function save(): void
    {
        self::ensure();
        $idle = (int) Config::get('session_idle_minutes', 60);
        $expires = time() + $idle * 60;
        $user = self::$data['user'] ?? null;
        Db::run(
            'INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(sid) DO UPDATE SET user_id = excluded.user_id, data = excluded.data, expires_at = excluded.expires_at',
            [self::$sid, is_array($user) ? ($user['id'] ?? null) : null, json_encode(self::$data, JSON_UNESCAPED_UNICODE), $expires]
        );
    }

    /** Purge des sessions expirées, à l'occasion d'une visite sur deux cents. */
    public static function collect(): void
    {
        if (random_int(1, 200) === 1) {
            Db::run('DELETE FROM sessions WHERE expires_at < ?', [time()]);
        }
    }

    /** La suite de tests pilote les cookies elle-même. */
    public static function withoutCookies(): void
    {
        self::$sendCookies = false;
    }

    public static function queuedCookies(): array
    {
        return self::$queuedCookies;
    }

    private static function emitCookie(): void
    {
        $secure = (($_SERVER['HTTPS'] ?? '') !== '') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $options = [
            'expires' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        self::$queuedCookies[self::COOKIE] = self::$sid;
        if (self::$sendCookies && !headers_sent()) {
            setcookie(self::COOKIE, (string) self::$sid, $options);
        }
        $_COOKIE[self::COOKIE] = self::$sid;
    }

    private static function newId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function ensure(): void
    {
        if (!self::$started) {
            self::start();
        }
    }
}
