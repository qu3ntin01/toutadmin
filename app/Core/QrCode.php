<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Code QR, écrit à la main.
 *
 * Un seul écran en a besoin : la mise en service de la double authentification,
 * où il faut montrer à un téléphone une adresse « otpauth:// » trop longue pour
 * être recopiée sans faute. Plutôt que d'ajouter une dépendance pour cela, le
 * codeur tient ici — comme l'archive tar, le PDF ou le lecteur IMAP ailleurs
 * dans ce produit.
 *
 * Volontairement réduit à ce qui sert : mode octet, correction de niveau M,
 * versions 1 à 10. Au-delà, la donnée à coder n'est plus une adresse otpauth.
 * Le rendu est un SVG écrit dans la page : pas de fichier, pas d'image
 * distante, et donc rien à autoriser dans la politique de contenu.
 */
final class QrCode
{
    /** Codets de données par version (1 à 10), correction M. */
    private const DATA_CODEWORDS = [16, 28, 44, 64, 86, 108, 124, 154, 182, 216];

    /** Codets de correction par bloc, correction M. */
    private const EC_CODEWORDS = [10, 16, 26, 18, 24, 16, 18, 22, 22, 26];

    /** Blocs par version : [nombre, codets de données] par groupe. */
    private const BLOCKS = [
        [[1, 16]], [[1, 28]], [[1, 44]], [[2, 32]], [[2, 43]],
        [[4, 27]], [[4, 31]], [[2, 38], [2, 39]], [[3, 36], [2, 37]], [[4, 43], [1, 44]],
    ];

    /** Centres des motifs d'alignement, par version. */
    private const ALIGNMENT = [
        [], [6, 18], [6, 22], [6, 26], [6, 30],
        [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    ];

    private static array $exp = [];
    private static array $log = [];

    // ---------- Corps fini GF(256) ----------

    private static function initGalois(): void
    {
        if (self::$exp !== []) {
            return;
        }
        $x = 1;
        for ($i = 0; $i < 256; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 256; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** Polynôme générateur de degré $degree. */
    private static function generator(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $next = array_fill(0, count($poly) + 1, 0);
            foreach ($poly as $index => $coefficient) {
                $next[$index] ^= $coefficient;
                $next[$index + 1] ^= self::mul($coefficient, self::$exp[$i]);
            }
            $poly = $next;
        }
        return $poly;
    }

    /** Codets de correction d'un bloc (Reed-Solomon). */
    private static function remainder(array $data, int $ecCount): array
    {
        $generator = self::generator($ecCount);
        $residual = array_merge($data, array_fill(0, $ecCount, 0));

        for ($i = 0; $i < count($data); $i++) {
            $lead = $residual[$i];
            if ($lead === 0) {
                continue;
            }
            foreach ($generator as $offset => $coefficient) {
                $residual[$i + $offset] ^= self::mul($coefficient, $lead);
            }
        }
        return array_slice($residual, count($data));
    }

    // ---------- Codage ----------

    /** La version qui accueille la donnée, ou null si elle est trop longue. */
    private static function versionFor(int $bytes): ?int
    {
        foreach (self::DATA_CODEWORDS as $index => $capacity) {
            $version = $index + 1;
            $header = 4 + ($version < 10 ? 8 : 16);
            if ($bytes * 8 + $header <= $capacity * 8) {
                return $version;
            }
        }
        return null;
    }

    private static function codewords(string $text, int $version): array
    {
        $capacity = self::DATA_CODEWORDS[$version - 1];
        $bits = '0100'; // mode octet
        $bits .= str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        for ($i = 0; $i < strlen($text); $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        // Terminateur, puis alignement sur l'octet.
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - strlen($bits) % 8);
        }

        $words = [];
        foreach (str_split($bits, 8) as $byte) {
            $words[] = bindec($byte);
        }
        // Remplissage réglementaire, alterné, jusqu'à la capacité.
        $padding = [0xEC, 0x11];
        for ($turn = 0; count($words) < $capacity; $turn++) {
            $words[] = $padding[$turn % 2];
        }
        return $words;
    }

    /** Répartit les codets en blocs, puis les entrelace avec leur correction. */
    private static function interleave(array $words, int $version): array
    {
        $ecCount = self::EC_CODEWORDS[$version - 1];
        $blocks = [];
        $ecBlocks = [];
        $offset = 0;

        foreach (self::BLOCKS[$version - 1] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($words, $offset, $size);
                $offset += $size;
                $blocks[] = $block;
                $ecBlocks[] = self::remainder($block, $ecCount);
            }
        }

        $out = [];
        $longest = max(array_map('count', $blocks));
        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $out[] = $block[$i];
                }
            }
        }
        for ($i = 0; $i < $ecCount; $i++) {
            foreach ($ecBlocks as $block) {
                $out[] = $block[$i];
            }
        }
        return $out;
    }

    // ---------- Trame ----------

    /** Motifs fixes : repères, séparateurs, synchronisation, alignement. */
    private static function functionPatterns(int $version, array &$grid, array &$reserved): void
    {
        $size = 17 + 4 * $version;

        $finder = static function (int $top, int $left) use (&$grid, &$reserved, $size): void {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $y = $top + $r;
                    $x = $left + $c;
                    if ($y < 0 || $y >= $size || $x < 0 || $x >= $size) {
                        continue;
                    }
                    $inRing = ($r >= 0 && $r <= 6 && ($c === 0 || $c === 6))
                        || ($c >= 0 && $c <= 6 && ($r === 0 || $r === 6));
                    $inCore = $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4;
                    $grid[$y][$x] = $inRing || $inCore;
                    $reserved[$y][$x] = true;
                }
            }
        };

        $finder(0, 0);
        $finder(0, $size - 7);
        $finder($size - 7, 0);

        // Synchronisation : une ligne et une colonne alternées.
        for ($i = 8; $i < $size - 8; $i++) {
            $grid[6][$i] = $i % 2 === 0;
            $reserved[6][$i] = true;
            $grid[$i][6] = $i % 2 === 0;
            $reserved[$i][6] = true;
        }

        // Alignement : partout sauf sur les repères.
        $centers = self::ALIGNMENT[$version - 1];
        foreach ($centers as $row) {
            foreach ($centers as $col) {
                $onFinder = ($row <= 8 && $col <= 8)
                    || ($row <= 8 && $col >= $size - 9)
                    || ($row >= $size - 9 && $col <= 8);
                if ($onFinder) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $grid[$row + $r][$col + $c] = max(abs($r), abs($c)) !== 1;
                        $reserved[$row + $r][$col + $c] = true;
                    }
                }
            }
        }

        // Module toujours sombre, et emplacements réservés au format.
        $grid[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;
        for ($i = 0; $i <= 8; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $reserved[$size - 1 - $i][8] = true;
        }
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $reserved[$i][$size - 11 + $j] = true;
                    $reserved[$size - 11 + $j][$i] = true;
                }
            }
        }
    }

    /** Parcours en zigzag depuis le bas à droite, colonne 6 exclue. */
    private static function placeData(array $words, array &$grid, array $reserved, int $size): void
    {
        $bits = '';
        foreach ($words as $word) {
            $bits .= str_pad(decbin($word), 8, '0', STR_PAD_LEFT);
        }

        $index = 0;
        $upward = true;
        for ($right = $size - 1; $right > 0; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($step = 0; $step < $size; $step++) {
                $row = $upward ? $size - 1 - $step : $step;
                foreach ([$right, $right - 1] as $col) {
                    if (!empty($reserved[$row][$col])) {
                        continue;
                    }
                    $grid[$row][$col] = $index < strlen($bits) && $bits[$index] === '1';
                    $index++;
                }
            }
            $upward = !$upward;
        }
    }

    private static function maskAt(int $mask, int $row, int $col): bool
    {
        return match ($mask) {
            0 => ($row + $col) % 2 === 0,
            1 => $row % 2 === 0,
            2 => $col % 3 === 0,
            3 => ($row + $col) % 3 === 0,
            4 => (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0,
            5 => ($row * $col) % 2 + ($row * $col) % 3 === 0,
            6 => ((($row * $col) % 2 + ($row * $col) % 3) % 2) === 0,
            default => (((($row + $col) % 2) + ($row * $col) % 3) % 2) === 0,
        };
    }

    /** Les quatre pénalités de la norme : le masque retenu est le moins pénalisé. */
    private static function penalty(array $grid, int $size): int
    {
        $score = 0;

        // 1. Suites de cinq modules de même couleur et plus.
        for ($i = 0; $i < $size; $i++) {
            foreach ([true, false] as $horizontal) {
                $run = 1;
                for ($j = 1; $j < $size; $j++) {
                    $current = $horizontal ? $grid[$i][$j] : $grid[$j][$i];
                    $previous = $horizontal ? $grid[$i][$j - 1] : $grid[$j - 1][$i];
                    if ($current === $previous) {
                        $run++;
                        continue;
                    }
                    if ($run >= 5) {
                        $score += 3 + ($run - 5);
                    }
                    $run = 1;
                }
                if ($run >= 5) {
                    $score += 3 + ($run - 5);
                }
            }
        }

        // 2. Carrés de 2 × 2 d'une seule couleur.
        for ($r = 0; $r < $size - 1; $r++) {
            for ($c = 0; $c < $size - 1; $c++) {
                if ($grid[$r][$c] === $grid[$r][$c + 1]
                    && $grid[$r][$c] === $grid[$r + 1][$c]
                    && $grid[$r][$c] === $grid[$r + 1][$c + 1]) {
                    $score += 3;
                }
            }
        }

        // 3. Motif qui imite un repère de position.
        $needles = ['10111010000', '00001011101'];
        for ($i = 0; $i < $size; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $size; $j++) {
                $row .= $grid[$i][$j] ? '1' : '0';
                $col .= $grid[$j][$i] ? '1' : '0';
            }
            foreach ($needles as $needle) {
                $score += 40 * (substr_count($row, $needle) + substr_count($col, $needle));
            }
        }

        // 4. Déséquilibre entre sombre et clair.
        $dark = 0;
        foreach ($grid as $line) {
            foreach ($line as $module) {
                $dark += $module ? 1 : 0;
            }
        }
        $ratio = (int) floor(abs($dark * 100 / ($size * $size) - 50) / 5);
        return $score + $ratio * 10;
    }

    /** Information de format : niveau M, masque retenu, BCH(15, 5). */
    private static function formatBits(int $mask): int
    {
        $data = (0b00 << 3) | $mask;
        $value = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($value & (1 << $i)) {
                $value ^= 0b10100110111 << ($i - 10);
            }
        }
        return (($data << 10) | $value) ^ 0b101010000010010;
    }

    /** Information de version, à partir de la version 7 : BCH(18, 6). */
    private static function versionBits(int $version): int
    {
        $value = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ($value & (1 << $i)) {
                $value ^= 0b1111100100101 << ($i - 12);
            }
        }
        return ($version << 12) | $value;
    }

    private static function writeFormat(array &$grid, int $size, int $mask): void
    {
        $bits = self::formatBits($mask);
        for ($i = 0; $i < 15; $i++) {
            $bit = (bool) (($bits >> $i) & 1);

            // Première copie, en L autour du repère supérieur gauche.
            if ($i < 6) {
                $grid[$i][8] = $bit;
            } elseif ($i === 6) {
                $grid[7][8] = $bit;
            } elseif ($i === 7) {
                $grid[8][8] = $bit;
            } elseif ($i === 8) {
                $grid[8][7] = $bit;
            } else {
                $grid[8][14 - $i] = $bit;
            }

            // Seconde copie, partagée entre les deux autres repères : elle
            // rend le code lisible même si un coin est abîmé.
            // Huit modules le long du repère supérieur droit, sept le long du
            // repère inférieur gauche : le module toujours sombre s'intercale
            // entre les deux.
            if ($i < 8) {
                $grid[8][$size - 1 - $i] = $bit;
            } else {
                $grid[$size - 15 + $i][8] = $bit;
            }
        }
    }

    private static function writeVersion(array &$grid, int $size, int $version): void
    {
        if ($version < 7) {
            return;
        }
        $bits = self::versionBits($version);
        for ($i = 0; $i < 18; $i++) {
            $bit = (bool) (($bits >> $i) & 1);
            $row = intdiv($i, 3);
            $col = $size - 11 + $i % 3;
            $grid[$row][$col] = $bit;
            $grid[$col][$row] = $bit;
        }
    }

    /**
     * La trame du code, en booléens : true pour un module sombre.
     *
     * @param int|null $forcedMask masque imposé, pour les tests ; sinon le
     *                              moins pénalisé des huit
     * @throws \RuntimeException si la donnée dépasse ce que ce codeur couvre
     */
    public static function matrix(string $text, ?int $forcedMask = null): array
    {
        self::initGalois();

        $version = self::versionFor(strlen($text));
        if ($version === null) {
            throw new \RuntimeException('Donnée trop longue pour un code QR de version 10.');
        }

        $size = 17 + 4 * $version;
        $grid = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::functionPatterns($version, $grid, $reserved);
        self::placeData(self::interleave(self::codewords($text, $version), $version), $grid, $reserved, $size);

        $best = null;
        $bestScore = PHP_INT_MAX;
        for ($mask = $forcedMask ?? 0; $mask < 8; $mask++) {
            $candidate = $grid;
            for ($r = 0; $r < $size; $r++) {
                for ($c = 0; $c < $size; $c++) {
                    if (empty($reserved[$r][$c]) && self::maskAt($mask, $r, $c)) {
                        $candidate[$r][$c] = !$candidate[$r][$c];
                    }
                }
            }
            self::writeFormat($candidate, $size, $mask);
            self::writeVersion($candidate, $size, $version);

            if ($forcedMask !== null) {
                return $candidate;
            }
            $score = self::penalty($candidate, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }
        return $best;
    }

    /**
     * Le code, en SVG écrit dans la page. Une marge de quatre modules est
     * réservée autour : sans elle, les lecteurs ne trouvent pas le code.
     */
    public static function svg(string $text, int $pixels = 220, string $alt = ''): string
    {
        $grid = self::matrix($text);
        $size = count($grid);
        $quiet = 4;
        $span = $size + 2 * $quiet;

        $path = '';
        foreach ($grid as $row => $line) {
            foreach ($line as $col => $module) {
                if ($module) {
                    $path .= 'M' . ($col + $quiet) . ' ' . ($row + $quiet) . 'h1v1h-1z';
                }
            }
        }

        return '<svg class="totp-qr" xmlns="http://www.w3.org/2000/svg" width="' . $pixels . '" height="' . $pixels
            . '" viewBox="0 0 ' . $span . ' ' . $span . '" role="img" aria-label="' . htmlspecialchars($alt, ENT_QUOTES) . '">'
            . '<rect width="' . $span . '" height="' . $span . '" fill="#ffffff"/>'
            . '<path d="' . $path . '" fill="#000000"/></svg>';
    }
}
