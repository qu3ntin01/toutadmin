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
 *
 * Quand une police porte une table `ToUnicode` — c'est le cas de toute police
 * en sous-ensemble, et de celle que ce produit écrit lui-même pour ses
 * Factur-X —, les codes du flux ne sont pas des caractères : la table est lue
 * et appliquée. Une limite assumée : les polices sont retrouvées par leur nom
 * de ressource, si bien qu'un document où deux pages donnent des sens
 * différents au même « /F1 » serait lu avec la dernière des deux.
 */
final class Pdf
{
    /** Le texte d'un PDF, ou une chaîne vide s'il n'en contient pas. */
    public static function text(string $bytes): string
    {
        $maps = self::fontMaps($bytes);
        $pieces = [];
        foreach (self::streams($bytes) as $stream) {
            $piece = self::fromContent($stream, $maps);
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

    /**
     * Les tables ToUnicode du document, par nom de ressource de police.
     *
     * Un dictionnaire de ressources dit « /F1 12 0 R » ; l'objet 12 est la
     * police, qui renvoie à son tour vers le flux de sa table. Les deux sauts
     * se font ici, en lisant le fichier tel qu'il est.
     *
     * @return array<string, array<int, string>>
     */
    private static function fontMaps(string $bytes): array
    {
        $maps = [];

        if (!preg_match_all('#/Font\s*<<(.*?)>>#s', $bytes, $blocks)) {
            return $maps;
        }

        foreach ($blocks[1] as $block) {
            if (!preg_match_all('#/([A-Za-z0-9]+)\s+(\d+)\s+0\s+R#', $block, $entries, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($entries as [, $name, $id]) {
                $font = self::objectBody($bytes, (int) $id);
                if ($font === null || !preg_match('#/ToUnicode\s+(\d+)\s+0\s+R#', $font, $found)) {
                    continue;
                }
                $cmap = self::objectStream($bytes, (int) $found[1]);
                if ($cmap === null) {
                    continue;
                }
                $table = self::readCMap($cmap);
                if ($table !== []) {
                    $maps[$name] = $table;
                }
            }
        }

        return $maps;
    }

    /** Le corps d'un objet numéroté, tel qu'il est écrit dans le fichier. */
    private static function objectBody(string $bytes, int $id): ?string
    {
        if (!preg_match('#(?:^|[\r\n\s])' . $id . '\s+0\s+obj\b#', $bytes, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $match[0][1];
        $end = strpos($bytes, 'endobj', $start);
        return substr($bytes, $start, ($end === false ? strlen($bytes) : $end) - $start);
    }

    /** Le flux d'un objet numéroté, décompressé s'il l'est. */
    private static function objectStream(string $bytes, int $id): ?string
    {
        $body = self::objectBody($bytes, $id);
        if ($body === null) {
            return null;
        }
        $streams = self::streams($body);
        return $streams[0] ?? null;
    }

    /**
     * Une table ToUnicode : les blocs `beginbfchar` associent un code à un
     * caractère, les blocs `beginbfrange` en associent une plage d'un coup.
     *
     * @return array<int, string>
     */
    private static function readCMap(string $cmap): array
    {
        $table = [];

        if (preg_match_all('#beginbfchar(.*?)endbfchar#s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as [, $code, $value]) {
                    $table[(int) hexdec($code)] = self::fromUtf16Hex($value);
                }
            }
        }

        if (preg_match_all('#beginbfrange(.*?)endbfrange#s', $cmap, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('#<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>#', $block, $spans, PREG_SET_ORDER);
                foreach ($spans as [, $from, $to, $value]) {
                    $start = (int) hexdec($from);
                    $end = (int) hexdec($to);
                    $first = (int) hexdec($value);
                    for ($code = $start; $code <= $end && $code - $start < 512; $code++) {
                        $table[$code] = self::fromCodePoint($first + ($code - $start));
                    }
                }
            }
        }

        return $table;
    }

    /** Une valeur de table ToUnicode : un ou plusieurs points de code en UTF-16BE. */
    private static function fromUtf16Hex(string $hex): string
    {
        $converted = @mb_convert_encoding((string) hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex), 'UTF-8', 'UTF-16BE');
        return $converted === false ? '' : $converted;
    }

    private static function fromCodePoint(int $code): string
    {
        return self::fromUtf16Hex(str_pad(dechex($code), 4, '0', STR_PAD_LEFT));
    }

    /**
     * Le texte d'un flux de contenu : les opérateurs Tj, TJ, ' et ".
     *
     * @param array<string, array<int, string>> $maps
     */
    private static function fromContent(string $content, array $maps = []): string
    {
        if (!preg_match('/(Tj|TJ|BT)\b/', $content)) {
            return '';
        }

        $out = '';
        $length = strlen($content);
        $i = 0;
        $pending = [];
        $font = '';

        while ($i < $length) {
            $char = $content[$i];

            // « /F1 9 Tf » : à partir d'ici, les codes se lisent dans la table
            // de cette police-là.
            if ($char === '/' && preg_match('#^/([A-Za-z0-9]+)\s+[\d.]+\s+Tf#', substr($content, $i, 40), $chosen)) {
                $font = $chosen[1];
                $i += 1;
                continue;
            }

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
                $out .= self::translate(implode('', $pending), $maps[$font] ?? []);
                $pending = [];
                $i += 2;
                continue;
            }
            if (($char === "'" || $char === '"') && $pending !== []) {
                $out .= "\n" . self::translate(implode('', $pending), $maps[$font] ?? []);
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

    /**
     * Applique la table d'une police. Sans table, le texte est rendu tel quel :
     * une police à encodage ordinaire porte déjà ses caractères.
     *
     * @param array<int, string> $table
     */
    private static function translate(string $text, array $table): string
    {
        if ($table === [] || $text === '') {
            return $text;
        }

        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            // Les codes au-delà de 127 ont été rendus en UTF-8 par la lecture
            // de la chaîne : on retrouve l'octet d'origine pour interroger la
            // table, qui est indexée par code et non par caractère.
            $byte = strlen($char) === 1 ? $char : (string) @mb_convert_encoding($char, 'Windows-1252', 'UTF-8');
            $code = strlen($byte) === 1 ? ord($byte) : -1;
            $out .= $code >= 0 && isset($table[$code]) ? $table[$code] : $char;
        }
        return $out;
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
