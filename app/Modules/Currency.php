<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

/**
 * Multidevise.
 *
 * Une entreprise qui facture à l'étranger encaisse dans une monnaie et tient
 * ses comptes dans une autre. Deux règles suffisent à ne pas s'y perdre :
 *
 * 1. La devise de référence de l'instance est celle des totaux. Tout ce qui
 *    s'additionne y est ramené ; additionner des euros et des dollars ne veut
 *    rien dire.
 * 2. Le taux qui a servi à une facture est copié dans la facture. Le taux du
 *    jour sert à la convertir une fois, à son émission, et plus jamais après :
 *    sans cela, la mise à jour d'un taux réécrirait le chiffre d'affaires de
 *    l'an dernier.
 */
final class Currency
{
    public const CURRENCIES = [
        ['code' => 'EUR', 'label' => 'Euro', 'symbol' => '€'],
        ['code' => 'USD', 'label' => 'Dollar américain', 'symbol' => '$'],
        ['code' => 'GBP', 'label' => 'Livre sterling', 'symbol' => '£'],
        ['code' => 'CHF', 'label' => 'Franc suisse', 'symbol' => 'CHF'],
        ['code' => 'CAD', 'label' => 'Dollar canadien', 'symbol' => '$ CA'],
        ['code' => 'MAD', 'label' => 'Dirham marocain', 'symbol' => 'DH'],
        ['code' => 'XOF', 'label' => 'Franc CFA (UEMOA)', 'symbol' => 'F CFA'],
        ['code' => 'JPY', 'label' => 'Yen', 'symbol' => '¥'],
        ['code' => 'CNY', 'label' => 'Yuan', 'symbol' => '¥ CN'],
        ['code' => 'AED', 'label' => 'Dirham des Émirats', 'symbol' => 'AED'],
    ];

    private const BASE_KEY = 'currency.base';

    public static function codes(): array
    {
        return array_column(self::CURRENCIES, 'code');
    }

    public static function isKnown(?string $code): bool
    {
        return in_array(strtoupper((string) $code), self::codes(), true);
    }

    public static function base(): string
    {
        $stored = Settings::get(self::BASE_KEY);
        return self::isKnown($stored) ? strtoupper($stored) : 'EUR';
    }

    public static function setBase(string $code): bool
    {
        if (!self::isKnown($code)) {
            return false;
        }
        Settings::set(self::BASE_KEY, strtoupper($code));
        return true;
    }

    public static function byCode(?string $code): ?array
    {
        foreach (self::CURRENCIES as $currency) {
            if ($currency['code'] === strtoupper((string) $code)) {
                return $currency;
            }
        }
        return null;
    }

    // ---------- Taux ----------

    public static function rates(): array
    {
        $stored = [];
        foreach (Db::all('SELECT * FROM exchange_rates') as $row) {
            $stored[$row['code']] = $row;
        }
        $base = self::base();
        return array_map(static function (array $currency) use ($stored, $base): array {
            $row = $stored[$currency['code']] ?? null;
            return $currency + [
                'isBase' => $currency['code'] === $base,
                // La devise de référence vaut toujours 1 : ce n'est pas une
                // donnée qu'on saisit, c'est une définition.
                'rate' => $currency['code'] === $base ? 1.0 : ($row === null ? null : (float) $row['rate']),
                'updatedAt' => $row['updated_at'] ?? null,
            ];
        }, self::CURRENCIES);
    }

    /** Le taux d'une devise, ou null si personne ne l'a renseigné. */
    public static function rateOf(?string $code): ?float
    {
        $wanted = strtoupper((string) $code);
        if (!self::isKnown($wanted)) {
            return null;
        }
        if ($wanted === self::base()) {
            return 1.0;
        }
        $row = Db::get('SELECT rate FROM exchange_rates WHERE code = ?', [$wanted]);
        return $row === null ? null : (float) $row['rate'];
    }

    public static function setRate(string $code, mixed $rate, ?int $userId = null): array
    {
        $wanted = strtoupper($code);
        if (!self::isKnown($wanted)) {
            return ['ok' => false, 'message' => 'Devise inconnue.'];
        }
        if ($wanted === self::base()) {
            return ['ok' => false, 'message' => 'La devise de référence vaut 1 par définition.'];
        }
        $value = is_numeric($rate) ? (float) $rate : 0;
        if ($value <= 0) {
            return ['ok' => false, 'message' => 'Un taux se saisit strictement positif.'];
        }
        Db::run(
            "INSERT INTO exchange_rates (code, rate, updated_by) VALUES (?, ?, ?)
             ON CONFLICT(code) DO UPDATE SET rate = excluded.rate, updated_at = datetime('now'), updated_by = excluded.updated_by",
            [$wanted, $value, $userId]
        );
        return ['ok' => true];
    }

    public static function forgetRate(string $code): void
    {
        Db::run('DELETE FROM exchange_rates WHERE code = ?', [strtoupper($code)]);
    }

    /** Ramène un montant en devise de référence, au taux fourni ou au taux courant. */
    public static function toBase(float $amount, string $code, ?float $rate = null): ?float
    {
        $applied = $rate ?? self::rateOf($code);
        if ($applied === null || $applied <= 0) {
            return null;
        }
        return round($amount * $applied, 2);
    }

    /**
     * Le taux à figer sur une pièce émise aujourd'hui. On rend null plutôt que
     * 1 quand le taux manque : facturer en dollars sans taux connu doit être
     * refusé, pas converti au petit bonheur.
     */
    public static function rateForNewDocument(string $code): ?float
    {
        return self::rateOf($code);
    }

    public static function format(float $amount, ?string $code): string
    {
        $currency = self::byCode($code);
        return number_format($amount, 2, ',', ' ') . ' ' . ($currency === null ? '' : $currency['symbol']);
    }

    /** Les devises utilisables : la référence, et celles dont le taux est connu. */
    public static function usable(): array
    {
        return array_values(array_filter(self::rates(), static fn (array $c): bool => $c['rate'] !== null));
    }
}
