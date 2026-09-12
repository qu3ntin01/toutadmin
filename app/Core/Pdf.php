<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Extraction du texte d'un PDF, sans dépendance.
 *
 * L'édition Node passe par une bibliothèque ; ici, le format est lu tel qu'il
 * est : un PDF est une suite d'objets, dont certains portent un flux compressé
 * en zlib qui contient les opérateurs de dessin. Le texte se trouve dans les
 * opérateurs Tj, TJ, ' et " — le reste (polices, coordonnées, images) ne nous
 * intéresse pas.
 *
 * Ce que cette lecture ne sait pas faire, et qu'il vaut mieux annoncer : un PDF
 * scanné ne contient aucun texte, seulement une image, et aucune extraction n'en
 * tirera quoi que ce soit sans reconnaissance de caractères ; une police à
 * encodage propre (CID sans table ToUnicode) rendra des caractères faux. Dans les
 * deux cas le texte revient vide ou illisible, et l'appelant le dit plutôt que
 * de noter un dossier sur du vide.
 */
final class Pdf
{
    /** Le texte d'un PDF, ou une chaîne vide s'il n'en contient pas. */
    public static function text(string $bytes): string
    {
        $pieces = [];
        foreach (self::streams($bytes) as $stream) {
            $piece = self::fromContent($stream);
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        $text = implode("\n", $pieces);
        $text = str_replace("\r\n", "\n", $text);
        $text = (string) preg_replace('/[ \t]+/', ' ', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /** Les flux du document, décompressés quand ils le sont. */
    private static function streams(string $bytes): array
    {
        $out = [];
        $offset = 0;

        while (($start = strpos($bytes, 'stream', $offset)) !== false) {
            $header = substr($bytes, max(0, $start - 300), min(300, $start));
            $begin = $start + 6;
            // Après « stream » vient un saut de ligne, précédé ou non d'un retour chariot.
            if (substr($bytes, $begin, 2) === "\r\n") {
                $begin += 2;
            } elseif ($bytes[$begin] === "\n" || $bytes[$begin] === "\r") {
                $begin += 1;
            }

            $end = strpos($bytes, 'endstream', $begin);
            if ($end === false) {
                break;
            }
            $raw = substr($bytes, $begin, $end - $begin);
            $offset = $end + 9;

            if (str_contains($header, '/FlateDecode')) {
                $inflated = @gzuncompress($raw);
                if ($inflated === false) {
                    $inflated = @gzinflate($raw);
                }
                if ($inflated === false) {
                    continue;
                }
                $raw = $inflated;
            } elseif (str_contains($header, '/DCTDecode') || str_contains($header, '/Image')) {
                // Une image n'a pas de texte à rendre.
                continue;
            }

            $out[] = $raw;
        }
        return $out;
    }

    /** Le texte d'un flux de contenu : les opérateurs Tj, TJ, ' et ". */
    private static function fromContent(string $content): string
    {
        if (!preg_match('/(Tj|TJ|BT)\b/', $content)) {
            return '';
        }

        $out = '';
        $length = strlen($content);
        $i = 0;
        $pending = [];

        while ($i < $length) {
            $char = $content[$i];

            if ($char === '(') {
                [$literal, $i] = self::literal($content, $i);
                $pending[] = $literal;
                continue;
            }
            if ($char === '<' && isset($content[$i + 1]) && $content[$i + 1] !== '<') {
                $close = strpos($content, '>', $i);
                if ($close === false) {
                    break;
                }
                $hex = preg_replace('/[^0-9A-Fa-f]/', '', substr($content, $i + 1, $close - $i - 1));
                $pending[] = self::fromHex((string) $hex);
                $i = $close + 1;
                continue;
            }
            // Les opérateurs qui posent le texte accumulé.
            if ($char === 'T' && isset($content[$i + 1]) && ($content[$i + 1] === 'j' || $content[$i + 1] === 'J')) {
                $out .= implode('', $pending);
                $pending = [];
                $i += 2;
                continue;
            }
            if (($char === "'" || $char === '"') && $pending !== []) {
                $out .= "\n" . implode('', $pending);
                $pending = [];
                $i += 1;
                continue;
            }
            // Un saut de ligne de texte (Td, TD, T*) sépare deux lignes.
            if ($char === 'T' && isset($content[$i + 1]) && in_array($content[$i + 1], ['d', 'D', '*'], true)) {
                $out .= "\n";
                $i += 2;
                continue;
            }
            $i += 1;
        }

        return trim($out);
    }

    /** Une chaîne littérale « (…) », parenthèses imbriquées et échappements compris. */
    private static function literal(string $content, int $start): array
    {
        $out = '';
        $depth = 1;
        $i = $start + 1;
        $length = strlen($content);

        while ($i < $length && $depth > 0) {
            $char = $content[$i];

            if ($char === '\\') {
                $next = $content[$i + 1] ?? '';
                $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c",
                           '(' => '(', ')' => ')', '\\' => '\\'];
                if (isset($simple[$next])) {
                    $out .= $simple[$next];
                    $i += 2;
                    continue;
                }
                if ($next !== '' && ctype_digit($next)) {
                    $octal = '';
                    $j = $i + 1;
                    while ($j < $length && strlen($octal) < 3 && ctype_digit($content[$j]) && $content[$j] < '8') {
                        $octal .= $content[$j];
                        $j++;
                    }
                    $out .= chr(octdec($octal) % 256);
                    $i = $j;
                    continue;
                }
                // Une barre suivie d'un saut de ligne prolonge la chaîne.
                $i += 2;
                continue;
            }
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth === 0) {
                    $i++;
                    break;
                }
            }
            $out .= $char;
            $i++;
        }

        return [self::decode($out), $i];
    }

    /** Une chaîne hexadécimale « <…> ». */
    private static function fromHex(string $hex): string
    {
        if (strlen($hex) % 2 === 1) {
            $hex .= '0';
        }
        $raw = (string) hex2bin($hex);
        return self::decode($raw);
    }

    /**
     * Les chaînes d'un PDF sont en PDFDocEncoding (proche de Latin-1) ou en
     * UTF-16BE quand elles commencent par la marque d'ordre des octets.
     */
    private static function decode(string $raw): string
    {
        if (str_starts_with($raw, "\xFE\xFF")) {
            $converted = @mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
            return $converted === false ? '' : $converted;
        }
        if (mb_check_encoding($raw, 'UTF-8')) {
            return $raw;
        }
        return (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
}
