<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Contrôle du type réel d'un fichier reçu.
 *
 * Le type MIME d'un envoi multipart est déclaré par le client : il suffit de le
 * changer pour faire passer n'importe quoi pour un PDF. On regarde donc les
 * premiers octets, qui, eux, viennent du fichier.
 */
final class FileType
{
    /** Ce qui n'a pas de signature — du texte — est validé autrement. */
    private const TEXT_TYPES = ['text/plain', 'text/markdown', 'text/csv'];

    public static function matches(string $bytes, string $mimetype): bool
    {
        if ($bytes === '') {
            return false;
        }
        if (in_array($mimetype, self::TEXT_TYPES, true)) {
            return self::looksLikeText($bytes);
        }

        return match ($mimetype) {
            'image/jpeg' => strlen($bytes) > 3
                && ord($bytes[0]) === 0xff && ord($bytes[1]) === 0xd8 && ord($bytes[2]) === 0xff,
            'image/png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            // WebP : « RIFF » .... « WEBP »
            'image/webp' => strlen($bytes) > 12
                && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
            'application/pdf' => str_starts_with($bytes, '%PDF-'),
            // Un .docx est une archive ZIP : « PK\x03\x04 ».
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => strlen($bytes) > 4
                && ord($bytes[0]) === 0x50 && ord($bytes[1]) === 0x4b
                && in_array(ord($bytes[2]), [0x03, 0x05, 0x07], true),
            default => false,
        };
    }

    /**
     * Un texte encodé en UTF-8 n'a aucune raison d'être truffé d'octets de
     * contrôle : c'est ce qui trahit un binaire déguisé en .txt.
     */
    private static function looksLikeText(string $bytes): bool
    {
        $sample = substr($bytes, 0, 4096);
        if (str_contains($sample, "\0")) {
            return false;
        }
        $suspicious = 0;
        $length = strlen($sample);
        for ($i = 0; $i < $length; $i++) {
            $byte = ord($sample[$i]);
            $printable = $byte === 9 || $byte === 10 || $byte === 13 || ($byte >= 32 && $byte !== 127);
            if (!$printable) {
                $suspicious++;
            }
        }
        return $suspicious / max(1, $length) < 0.02;
    }
}
