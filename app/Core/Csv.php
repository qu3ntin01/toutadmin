<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lecture de fichiers CSV.
 *
 * Écrite à la main plutôt qu'empruntée : un import de masse est une porte
 * d'entrée dans la base, et le code qui lit le fichier est aussi sensible que
 * celui qui l'écrit. Le format est simple, les pièges connus.
 *
 * Ce qui est géré parce qu'on le rencontre vraiment :
 * — le point-virgule, séparateur des tableurs francophones, et la tabulation ;
 * — les guillemets, les virgules et les retours à la ligne à l'intérieur d'un
 *   champ, avec le doublement de guillemet comme échappement ;
 * — la marque d'ordre des octets qu'Excel ajoute en tête, invisible et qui
 *   ferait d'« id » une colonne nommée « ﻿id » ;
 * — les fins de ligne Windows.
 */
final class Csv
{
    public const DELIMITERS = [';', ',', "\t", '|'];
    public const MAX_ROWS = 5000;

    private const BOM = "\xEF\xBB\xBF";

    /** Le séparateur le plus présent dans la première ligne — pas de devinette au-delà. */
    public static function detectDelimiter(string $text): string
    {
        $firstLine = preg_split('/\r?\n/', $text, 2)[0] ?? '';
        $best = ';';
        $bestCount = 0;

        foreach (self::DELIMITERS as $delimiter) {
            // Les séparateurs entre guillemets ne comptent pas.
            $count = 0;
            $quoted = false;
            $length = strlen($firstLine);
            for ($i = 0; $i < $length; $i++) {
                if ($firstLine[$i] === '"') {
                    $quoted = !$quoted;
                } elseif (!$quoted && $firstLine[$i] === $delimiter) {
                    $count++;
                }
            }
            if ($count > $bestCount) {
                $best = $delimiter;
                $bestCount = $count;
            }
        }
        return $best;
    }

    /** Découpe le texte en lignes de champs. Rend [] pour un texte vide. */
    public static function split(string $text, string $delimiter): array
    {
        $rows = [];
        $field = '';
        $row = [];
        $quoted = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($quoted) {
                if ($char === '"') {
                    if (($text[$i + 1] ?? '') === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $char;
                }
                continue;
            }

            if ($char === '"') {
                $quoted = true;
            } elseif ($char === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($char === "\n") {
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
            } elseif ($char !== "\r") {
                $field .= $char;
            }
        }

        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }
        return $rows;
    }

    /** « Prénom », « prenom » et « PRENOM » désignent la même colonne. */
    public static function normalizeHeader(string $value): string
    {
        $map = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss'];

        $clean = mb_strtolower(trim(str_replace(self::BOM, '', $value)));
        $clean = strtr($clean, $map);
        $clean = preg_replace('/[^a-z0-9]+/', '_', $clean) ?? '';
        return trim($clean, '_');
    }

    /**
     * Les lignes sont rendues comme tableaux dont les clés sont les en-têtes
     * normalisés, avec le numéro de ligne d'origine sous « __line » : sans lui,
     * on ne sait pas quelle ligne corriger dans le tableur.
     */
    public static function parse(string $text, array $options = []): array
    {
        $content = trim(str_replace(self::BOM, '', $text));
        if ($content === '') {
            return ['ok' => false, 'message' => 'Fichier vide.', 'headers' => [], 'rows' => []];
        }

        $maxRows = (int) ($options['maxRows'] ?? self::MAX_ROWS);
        $separator = $options['delimiter'] ?? self::detectDelimiter($content);

        $lines = array_values(array_filter(
            self::split($content, $separator),
            static function (array $row): bool {
                foreach ($row as $cell) {
                    if (trim($cell) !== '') {
                        return true;
                    }
                }
                return false;
            }
        ));
        if ($lines === []) {
            return ['ok' => false, 'message' => 'Fichier vide.', 'headers' => [], 'rows' => []];
        }

        $headers = array_map([self::class, 'normalizeHeader'], $lines[0]);
        foreach ($headers as $header) {
            if ($header === '') {
                return ['ok' => false, 'message' => 'Une colonne est sans nom dans la première ligne.',
                        'headers' => [], 'rows' => []];
            }
        }
        if (count(array_unique($headers)) !== count($headers)) {
            return ['ok' => false, 'message' => 'Deux colonnes portent le même nom.', 'headers' => [], 'rows' => []];
        }

        $body = array_slice($lines, 1);
        if (count($body) > $maxRows) {
            return ['ok' => false, 'headers' => $headers, 'rows' => [],
                    'message' => count($body) . " lignes : au-delà de $maxRows, découpez le fichier."];
        }

        $rows = [];
        foreach ($body as $index => $cells) {
            $row = ['__line' => $index + 2];
            foreach ($headers as $column => $header) {
                $row[$header] = trim((string) ($cells[$column] ?? ''));
            }
            $rows[] = $row;
        }

        return ['ok' => true, 'delimiter' => $separator, 'headers' => $headers, 'rows' => $rows];
    }
}
