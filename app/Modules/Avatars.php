<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\FileType;

/**
 * Photos de profil.
 *
 * Elles vivent hors du dépôt, à côté de la base, et ne sont jamais servies
 * depuis le dossier public : le fichier passe par une route qui vérifie la
 * session. Un nom de fichier venu du client n'est jamais réutilisé — ni pour
 * écrire, ni pour lire.
 */
final class Avatars
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const ALLOWED = [
        'image/jpeg' => '.jpg',
        'image/png' => '.png',
        'image/webp' => '.webp',
    ];

    public static function directory(): string
    {
        $dir = (string) Config::get('upload_dir', '');
        if ($dir === '') {
            $dir = (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/uploads';
        }
        return $dir;
    }

    /**
     * Écrit la photo reçue et rend son nom de fichier, ou null si le contenu ne
     * correspond pas au type annoncé : le type d'un envoi vient du client.
     */
    public static function save(array $file): ?string
    {
        $mime = (string) ($file['mime'] ?? '');
        $bytes = (string) ($file['bytes'] ?? '');
        if (!isset(self::ALLOWED[$mime]) || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        if (!FileType::matches($bytes, $mime)) {
            return null;
        }

        $dir = self::directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        // Nom aléatoire : le nom d'origine, fourni par le client, n'est jamais réutilisé.
        $name = bin2hex(random_bytes(16)) . self::ALLOWED[$mime];
        file_put_contents($dir . '/' . $name, $bytes);
        @chmod($dir . '/' . $name, 0600);
        return $name;
    }

    /** Le nom est généré par nos soins ; on refuse malgré tout ce qui sort du dossier. */
    public static function isOurName(?string $fileName): bool
    {
        return $fileName !== null && (bool) preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $fileName);
    }

    public static function pathOf(string $fileName): ?string
    {
        return self::isOurName($fileName) ? self::directory() . '/' . $fileName : null;
    }

    public static function mimeOf(string $fileName): string
    {
        return match (pathinfo($fileName, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    public static function remove(?string $fileName): void
    {
        $path = $fileName === null ? null : self::pathOf($fileName);
        if ($path !== null && is_file($path)) {
            unlink($path);
        }
    }
}
