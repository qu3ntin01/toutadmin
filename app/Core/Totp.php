<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Double authentification par code temporaire (TOTP, RFC 6238).
 *
 * Écrite ici plutôt qu'empruntée : l'algorithme tient en quelques lignes, et
 * une dépendance de moins sur le chemin de l'authentification est une surface
 * d'attaque de moins. Mêmes paramètres que l'édition Node — un secret enrôlé
 * d'un côté fonctionne de l'autre.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    public const PERIOD = 30;
    public const DIGITS = 6;
    /** Une période de part et d'autre : les horloges dérivent, et l'on tape. */
    private const TOLERANCE = 1;

    public static function base32Encode(string $bytes): string
    {
        $bits = 0;
        $value = 0;
        $output = '';
        foreach (str_split($bytes) as $char) {
            $value = ($value << 8) | ord($char);
            $bits += 8;
            while ($bits >= 5) {
                $output .= self::ALPHABET[($value >> ($bits - 5)) & 31];
                $bits -= 5;
            }
        }
        if ($bits > 0) {
            $output .= self::ALPHABET[($value << (5 - $bits)) & 31];
        }
        return $output;
    }

    public static function base32Decode(string $input): string
    {
        $clean = preg_replace('/[^A-Z2-7]/', '', strtoupper($input)) ?? '';
        $bits = 0;
        $value = 0;
        $bytes = '';
        foreach (str_split($clean) as $char) {
            $value = ($value << 5) | strpos(self::ALPHABET, $char);
            $bits += 5;
            if ($bits >= 8) {
                $bytes .= chr(($value >> ($bits - 8)) & 0xff);
                $bits -= 8;
            }
        }
        return $bytes;
    }

    /** 20 octets aléatoires, la taille recommandée pour HMAC-SHA1. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function codeFor(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $digest = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0f;
        $binary = ((ord($digest[$offset]) & 0x7f) << 24)
            | (ord($digest[$offset + 1]) << 16)
            | (ord($digest[$offset + 2]) << 8)
            | ord($digest[$offset + 3]);
        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function currentCode(string $secret, ?int $at = null): string
    {
        return self::codeFor($secret, intdiv($at ?? time(), self::PERIOD));
    }

    /** Comparaison à temps constant, sur une fenêtre de tolérance. */
    public static function verify(string $secret, ?string $submitted, ?int $at = null): bool
    {
        $code = preg_replace('/\s/', '', (string) $submitted) ?? '';
        if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $counter = intdiv($at ?? time(), self::PERIOD);
        for ($drift = -self::TOLERANCE; $drift <= self::TOLERANCE; $drift++) {
            if (hash_equals(self::codeFor($secret, $counter + $drift), $code)) {
                return true;
            }
        }
        return false;
    }

    /** L'URI que lisent les applications d'authentification. */
    public static function otpauthUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => (string) self::DIGITS,
            'period' => (string) self::PERIOD,
        ]);
        return "otpauth://totp/$label?$params";
    }

    /** Codes de secours : usage unique, stockés hachés, pour un téléphone perdu. */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }
        return $codes;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', preg_replace('/[^A-Z0-9]/', '', strtoupper($code)) ?? '');
    }
}
