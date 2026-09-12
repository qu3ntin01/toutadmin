<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Seize langues, le français pour référence.
 *
 * Une clé absente d'une traduction retombe sur le français plutôt que de
 * laisser un trou dans la page : une traduction incomplète dégrade l'affichage,
 * elle ne casse jamais l'écran.
 */
final class I18n
{
    public const LOCALES = [
        ['code' => 'fr', 'label' => 'Français', 'flag' => '🇫🇷', 'dir' => 'ltr'],
        ['code' => 'en', 'label' => 'English', 'flag' => '🇬🇧', 'dir' => 'ltr'],
        ['code' => 'es', 'label' => 'Español', 'flag' => '🇪🇸', 'dir' => 'ltr'],
        ['code' => 'de', 'label' => 'Deutsch', 'flag' => '🇩🇪', 'dir' => 'ltr'],
        ['code' => 'it', 'label' => 'Italiano', 'flag' => '🇮🇹', 'dir' => 'ltr'],
        ['code' => 'pt', 'label' => 'Português', 'flag' => '🇵🇹', 'dir' => 'ltr'],
        ['code' => 'nl', 'label' => 'Nederlands', 'flag' => '🇳🇱', 'dir' => 'ltr'],
        ['code' => 'pl', 'label' => 'Polski', 'flag' => '🇵🇱', 'dir' => 'ltr'],
        ['code' => 'ru', 'label' => 'Русский', 'flag' => '🇷🇺', 'dir' => 'ltr'],
        ['code' => 'tr', 'label' => 'Türkçe', 'flag' => '🇹🇷', 'dir' => 'ltr'],
        ['code' => 'ar', 'label' => 'العربية', 'flag' => '🇸🇦', 'dir' => 'rtl'],
        ['code' => 'hi', 'label' => 'हिन्दी', 'flag' => '🇮🇳', 'dir' => 'ltr'],
        ['code' => 'zh', 'label' => '中文', 'flag' => '🇨🇳', 'dir' => 'ltr'],
        ['code' => 'ja', 'label' => '日本語', 'flag' => '🇯🇵', 'dir' => 'ltr'],
        ['code' => 'ko', 'label' => '한국어', 'flag' => '🇰🇷', 'dir' => 'ltr'],
        ['code' => 'vi', 'label' => 'Tiếng Việt', 'flag' => '🇻🇳', 'dir' => 'ltr'],
    ];

    public const DEFAULT_LOCALE = 'fr';

    private static array $dictionaries = [];
    private static string $current = self::DEFAULT_LOCALE;

    public static function codes(): array
    {
        return array_column(self::LOCALES, 'code');
    }

    public static function isSupported(?string $code): bool
    {
        return $code !== null && in_array($code, self::codes(), true);
    }

    public static function info(?string $code = null): array
    {
        $code ??= self::$current;
        foreach (self::LOCALES as $locale) {
            if ($locale['code'] === $code) {
                return $locale;
            }
        }
        return self::LOCALES[0];
    }

    public static function use(?string $code): void
    {
        self::$current = self::isSupported($code) ? (string) $code : self::DEFAULT_LOCALE;
    }

    public static function current(): string
    {
        return self::$current;
    }

    public static function dictionary(string $code): array
    {
        if (!isset(self::$dictionaries[$code])) {
            $file = APP_DIR . '/locales/' . $code . '.php';
            self::$dictionaries[$code] = is_file($file) ? (array) require $file : [];
        }
        return self::$dictionaries[$code];
    }

    /**
     * Traduit une clé, avec substitution de {paramètres}. Une clé inconnue est
     * rendue telle quelle : le texte manquant se voit, sans page blanche.
     */
    public static function translate(string $key, array $params = [], ?string $code = null): string
    {
        $code ??= self::$current;
        $value = self::dictionary($code)[$key]
            ?? self::dictionary(self::DEFAULT_LOCALE)[$key]
            ?? $key;

        foreach ($params as $name => $replacement) {
            $value = str_replace('{' . $name . '}', (string) $replacement, $value);
        }
        return $value;
    }

    /**
     * La langue d'une requête : le choix de la personne d'abord, puis celui de
     * l'instance, puis l'en-tête du navigateur.
     */
    public static function negotiate(?string $userLocale, ?string $instanceLocale, ?string $header): string
    {
        if (self::isSupported($userLocale)) {
            return (string) $userLocale;
        }
        if (self::isSupported($instanceLocale)) {
            return (string) $instanceLocale;
        }
        foreach (explode(',', (string) $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            $short = substr($code, 0, 2);
            if (self::isSupported($short)) {
                return $short;
            }
        }
        return self::DEFAULT_LOCALE;
    }
}
