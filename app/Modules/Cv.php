<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Config;
use App\Core\FileType;
use App\Core\Pdf;

/**
 * Les CV reçus.
 *
 * Ce sont des données personnelles : ils vivent hors de la racine web et ne
 * sont jamais servis en statique, seulement par une route authentifiée. Le nom
 * du fichier sur disque est tiré au sort — celui qu'a choisi le candidat ne
 * dicte jamais un chemin.
 */
final class Cv
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const ACCEPTED = [
        'application/pdf' => '.pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
        'text/plain' => '.txt',
        'text/markdown' => '.md',
    ];

    public static function directory(): string
    {
        $dir = (string) Config::get('cv_dir', '');
        if ($dir === '') {
            $dir = (string) Config::get('data_dir', dirname((string) Config::get('db_path'))) . '/cv';
        }
        return $dir;
    }

    /** Le contenu correspond-il au type annoncé ? À contrôler avant toute écriture. */
    public static function accepts(array $file): bool
    {
        $mime = (string) ($file['mime'] ?? '');
        if (!isset(self::ACCEPTED[$mime])) {
            return false;
        }
        // Le markdown n'a pas de signature : il se contrôle comme du texte.
        $probe = $mime === 'text/markdown' ? 'text/plain' : $mime;
        return FileType::matches((string) ($file['bytes'] ?? ''), $probe);
    }

    /** Écrit le CV reçu et rend son nom de fichier sur disque. */
    public static function save(array $file): ?string
    {
        if (!self::accepts($file)) {
            return null;
        }
        $dir = self::directory();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $name = bin2hex(random_bytes(16)) . (self::ACCEPTED[$file['mime']] ?? '.bin');
        file_put_contents($dir . '/' . $name, $file['bytes']);
        @chmod($dir . '/' . $name, 0600);
        return $name;
    }

    /**
     * Rend le texte d'un CV. Un PDF scanné ne contient pas de texte :
     * l'extraction revient vide, et l'appelant doit le dire plutôt que de
     * noter un dossier sur du vide.
     */
    public static function extractText(string $bytes, string $mime): string
    {
        $text = match (true) {
            $mime === 'application/pdf' => Pdf::text($bytes),
            str_contains($mime, 'wordprocessingml') => self::extractDocx($bytes),
            default => $bytes,
        };

        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /** Un .docx est une archive ZIP : word/document.xml en porte le texte. */
    public static function extractDocx(string $bytes): string
    {
        $length = strlen($bytes);
        $xml = null;

        // On parcourt les en-têtes locaux plutôt que le répertoire central : suffisant ici.
        for ($i = 0; $i < $length - 4; $i++) {
            if (substr($bytes, $i, 4) !== "PK\x03\x04") {
                continue;
            }
            $method = unpack('v', substr($bytes, $i + 8, 2))[1];
            $compressed = unpack('V', substr($bytes, $i + 18, 4))[1];
            $nameLength = unpack('v', substr($bytes, $i + 26, 2))[1];
            $extraLength = unpack('v', substr($bytes, $i + 28, 2))[1];
            $name = substr($bytes, $i + 30, $nameLength);
            $start = $i + 30 + $nameLength + $extraLength;

            if ($name !== 'word/document.xml' || $compressed === 0) {
                continue;
            }
            $data = substr($bytes, $start, $compressed);
            $xml = $method === 8 ? @gzinflate($data) : $data;
            break;
        }

        if ($xml === null || $xml === false) {
            return '';
        }

        // Un saut de paragraphe ou de ligne devient un vrai retour à la ligne.
        $text = str_replace('</w:p>', "\n", $xml);
        $text = (string) preg_replace('/<w:br[^>]*\/>/', "\n", $text);
        $text = (string) preg_replace('/<[^>]+>/', '', $text);
        return strtr($text, [
            '&amp;' => '&', '&lt;' => '<', '&gt;' => '>', '&quot;' => '"', '&apos;' => "'",
        ]);
    }

    public static function remove(?string $fileName): void
    {
        if ($fileName === null || $fileName === '') {
            return;
        }
        // Un nom de fichier venu de la base ne doit jamais sortir du dossier des CV.
        $target = self::pathOf($fileName);
        if (is_file($target)) {
            @unlink($target);
        }
    }

    public static function pathOf(string $fileName): string
    {
        return self::directory() . '/' . basename($fileName);
    }
}
