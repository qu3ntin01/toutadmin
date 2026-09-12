<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Chiffrement des secrets rangés en base.
 *
 * Un mot de passe FTP ou une clé de compte de service Google, écrits en clair
 * dans la table des réglages, feraient d'une copie de la base la clé de la
 * sauvegarde externalisée — donc de tout. La clé de chiffrement dérive du
 * secret de l'instance, qui vit dans un fichier à part : voler la base seule ne
 * suffit plus.
 *
 * AES-256-GCM : le déchiffrement échoue si le message a été modifié, ce qu'un
 * simple chiffrement ne dirait pas.
 */
final class Secrets
{
    public const PREFIX = 'enc.v1:';

    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    /** Une dérivation, pas le secret brut : la clé de l'instance sert déjà ailleurs. */
    private static function key(): string
    {
        return hash_hkdf('sha256', Config::secret(), 32, 'secrets-instance');
    }

    public static function encrypt(?string $plain): string
    {
        $value = (string) $plain;
        if ($value === '') {
            return '';
        }
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $body = openssl_encrypt($value, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_BYTES);
        if ($body === false) {
            return '';
        }
        return self::PREFIX . base64_encode($iv . $tag . $body);
    }

    /** Rend la valeur en clair, ou '' si elle est illisible — jamais une exception. */
    public static function decrypt(?string $stored): string
    {
        $value = (string) $stored;
        if ($value === '') {
            return '';
        }
        if (!str_starts_with($value, self::PREFIX)) {
            return $value; // valeur écrite avant le chiffrement
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= self::IV_BYTES + self::TAG_BYTES) {
            return '';
        }
        $plain = openssl_decrypt(
            substr($raw, self::IV_BYTES + self::TAG_BYTES),
            'aes-256-gcm',
            self::key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, self::IV_BYTES),
            substr($raw, self::IV_BYTES, self::TAG_BYTES)
        );
        // Secret de l'instance changé, ou valeur altérée : le secret est perdu,
        // pas deviné. L'appelant redemandera la saisie.
        return $plain === false ? '' : $plain;
    }

    public static function isEncrypted(?string $stored): bool
    {
        return is_string($stored) && str_starts_with($stored, self::PREFIX);
    }

    /** Ce qu'on montre à l'écran : la présence d'un secret, jamais sa valeur. */
    public static function mask(?string $stored): string
    {
        return self::decrypt($stored) !== '' ? '•••••••• (défini)' : '';
    }
}
