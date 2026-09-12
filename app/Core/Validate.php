<?php

declare(strict_types=1);

namespace App\Core;

/** Les contrôles de saisie, au même endroit pour tout le produit. */
final class Validate
{
    public static function email(string $value): bool
    {
        return strlen($value) <= 254 && (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $value);
    }

    public static function date(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        return checkdate($m, $d, $y);
    }

    public static function url(string $value): bool
    {
        $parts = parse_url($value);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['http', 'https'], true) && ($parts['host'] ?? '') !== '';
    }

    public static function port(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        return $port === false ? -1 : $port;
    }

    /**
     * Le TJM arrive sous forme de texte de formulaire : on accepte la virgule
     * décimale, comme dans l'édition Node.
     */
    public static function dailyRate(?string $value): array
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => null];
        }
        $number = str_replace(',', '.', $trimmed);
        if (!is_numeric($number)) {
            return ['ok' => false, 'value' => null];
        }
        $amount = (float) $number;
        if ($amount < 0 || $amount > 100000) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => round($amount, 2)];
    }

    /**
     * Mot de passe temporaire : seize caractères, une minuscule, une majuscule
     * et un chiffre garantis, puis mélangés — sans le mélange, les trois
     * premières positions trahiraient la catégorie de chaque caractère.
     */
    public static function generatePassword(): string
    {
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $digit = '23456789';
        $all = $lower . $upper . $digit;

        $chars = [
            $lower[random_int(0, strlen($lower) - 1)],
            $upper[random_int(0, strlen($upper) - 1)],
            $digit[random_int(0, strlen($digit) - 1)],
        ];
        while (count($chars) < 16) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }
        return implode('', $chars);
    }

    /**
     * Une cible de redirection venue d'un formulaire. « / » ne suffit pas :
     * « //site » et « /\site » sont des URL absolues pour le navigateur et
     * sortiraient du site.
     */
    public static function safeRedirect(?string $value, string $fallback = '/'): string
    {
        $path = (string) $value;
        if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_starts_with($path, '/\\')) {
            return $fallback;
        }
        return $path;
    }
}
