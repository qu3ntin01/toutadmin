<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Déclarations de TVA.
 *
 * La TVA collectée est celle des factures client, la déductible celle des
 * factures fournisseur ; la différence est due à l'État, ou lui reste due.
 * Le CMS calcule, ventile par taux et conserve la déclaration ; il ne
 * télétransmet pas — l'échange avec l'administration demande un agrément et un
 * format qui change chaque année.
 *
 * Deux partis pris qui comptent :
 * — On déclare **sur les débits** (la facture, pas l'encaissement), le régime
 *   le plus courant pour les ventes de biens et les prestations sur option.
 *   Un cabinet qui déclare sur les encaissements ne doit pas s'y fier.
 * — Une facture en devise est ramenée en devise de référence **au taux figé à
 *   son émission**, celui-là même qui a servi à la comptabiliser.
 */
final class Vat
{
    public const REGIMES = [
        ['key' => 'Mensuel', 'months' => 1],
        ['key' => 'Trimestriel', 'months' => 3],
    ];
    public const STATUSES = ['Brouillon', 'Déclarée', 'Payée'];

    private const MONTHS_FR = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    public static function regimeKeys(): array
    {
        return array_column(self::REGIMES, 'key');
    }

    private static function lastDayOf(int $year, int $month): string
    {
        return gmdate('Y-m-t', gmmktime(0, 0, 0, $month, 1, $year));
    }

    /** Les périodes d'un exercice, dans le régime choisi. */
    public static function periods(int $year, string $regime = 'Mensuel'): array
    {
        $step = self::REGIMES[0]['months'];
        foreach (self::REGIMES as $entry) {
            if ($entry['key'] === $regime) {
                $step = $entry['months'];
            }
        }

        $rows = [];
        for ($month = 1; $month <= 12; $month += $step) {
            $end = $month + $step - 1;
            $start = sprintf('%04d-%02d-01', $year, $month);
            $stop = self::lastDayOf($year, $end);
            $label = $step === 1
                ? self::MONTHS_FR[$month] . ' ' . $year
                : 'T' . (int) ceil($month / 3) . ' ' . $year;
            $rows[] = [
                'regime' => $regime, 'label' => $label, 'start' => $start, 'end' => $stop,
                'key' => $regime . ':' . $start . ':' . $stop,
            ];
        }
        return $rows;
    }

    /**
     * Retrouve une période à partir de la clé envoyée par le formulaire. Le
     * serveur reconstruit la liste et exige une correspondance exacte : une
     * période ne se saisit pas à la main, elle se choisit — trois jours de
     * décalage feraient une déclaration fausse que personne ne verrait passer.
     */
    public static function periodByKey(string $key): ?array
    {
        $parts = explode(':', $key);
        if (count($parts) !== 3) {
            return null;
        }
        $year = (int) substr($parts[1], 0, 4);
        if ($year < 2000 || $year > 2100) {
            return null;
        }
        foreach (self::REGIMES as $regime) {
            foreach (self::periods($year, $regime['key']) as $period) {
                if ($period['key'] === $key) {
                    return $period;
                }
            }
        }
        return null;
    }

    /**
     * Le calcul de la période. Les factures annulées et les brouillons en sont
     * exclus : une facture qui n'a pas été émise n'a pas généré de TVA.
     */
    public static function compute(string $from, string $to): array
    {
        $rows = Db::all(
            "SELECT direction, amount_ht, vat_rate, currency, exchange_rate FROM invoices
             WHERE status IN ('Émise','Payée') AND issue_date >= ? AND issue_date <= ?",
            [$from, $to]
        );

        $byRate = [];
        $collected = 0.0;
        $deductible = 0.0;
        $baseCollected = 0.0;
        $baseDeductible = 0.0;

        foreach ($rows as $row) {
            $ht = Currency::toBase((float) $row['amount_ht'], $row['currency'], (float) $row['exchange_rate']);
            if ($ht === null) {
                continue;
            }
            $rate = (float) $row['vat_rate'];
            $vat = round($ht * ($rate / 100), 2);
            $key = (string) $rate;
            $byRate[$key] ??= ['rate' => $rate, 'baseCollected' => 0.0, 'collected' => 0.0, 'baseDeductible' => 0.0, 'deductible' => 0.0];

            if ($row['direction'] === 'Client') {
                $collected += $vat;
                $baseCollected += $ht;
                $byRate[$key]['collected'] += $vat;
                $byRate[$key]['baseCollected'] += $ht;
            } else {
                $deductible += $vat;
                $baseDeductible += $ht;
                $byRate[$key]['deductible'] += $vat;
                $byRate[$key]['baseDeductible'] += $ht;
            }
        }

        $detail = array_values(array_map(static fn (array $entry): array => [
            'rate' => $entry['rate'],
            'collected' => round($entry['collected'], 2),
            'deductible' => round($entry['deductible'], 2),
            'baseCollected' => round($entry['baseCollected'], 2),
            'baseDeductible' => round($entry['baseDeductible'], 2),
        ], $byRate));
        usort($detail, static fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);

        $balance = round($collected - $deductible, 2);
        return [
            'from' => $from,
            'to' => $to,
            'invoices' => count($rows),
            'currency' => Currency::base(),
            'baseCollected' => round($baseCollected, 2),
            'baseDeductible' => round($baseDeductible, 2),
            'collected' => round($collected, 2),
            'deductible' => round($deductible, 2),
            // Une TVA négative n'est pas une dette : c'est un crédit reportable.
            'due' => $balance > 0 ? $balance : 0.0,
            'credit' => $balance < 0 ? round(-$balance, 2) : 0.0,
            'byRate' => $detail,
        ];
    }

    public static function list(int $limit = 60): array
    {
        return array_map(static function (array $row): array {
            $row['detail'] = empty($row['breakdown']) ? [] : (array) json_decode((string) $row['breakdown'], true);
            return $row;
        }, Db::all('SELECT * FROM vat_returns ORDER BY period_start DESC LIMIT ?', [$limit]));
    }

    public static function byId(int $id): ?array
    {
        $row = Db::get('SELECT * FROM vat_returns WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $row['detail'] = empty($row['breakdown']) ? [] : (array) json_decode((string) $row['breakdown'], true);
        return $row;
    }

    /**
     * Arrête la déclaration d'une période. Une déclaration déjà déposée n'est
     * pas réécrite en silence : c'est une pièce, pas un tableau de bord.
     */
    public static function save(array $fields): array
    {
        $from = $fields['from'];
        $to = $fields['to'];
        $existing = Db::get('SELECT * FROM vat_returns WHERE period_start = ? AND period_end = ?', [$from, $to]);
        if ($existing !== null && $existing['status'] !== 'Brouillon') {
            return ['ok' => false, 'message' => 'La déclaration ' . $existing['period_label']
                . ' est déjà ' . mb_strtolower($existing['status']) . ' : elle ne se recalcule plus.'];
        }

        $totals = self::compute($from, $to);
        $values = [
            $fields['regime'], $fields['label'], $from, $to, $totals['collected'], $totals['deductible'],
            $totals['due'], $totals['credit'], json_encode($totals['byRate'], JSON_UNESCAPED_UNICODE),
            $fields['notes'] ?? '', $fields['createdBy'] ?? null,
        ];

        if ($existing !== null) {
            Db::run(
                'UPDATE vat_returns SET regime = ?, period_label = ?, period_start = ?, period_end = ?, collected = ?,
                        deductible = ?, due = ?, credit = ?, breakdown = ?, notes = ?, created_by = ? WHERE id = ?',
                [...$values, $existing['id']]
            );
            return ['ok' => true, 'id' => (int) $existing['id'], 'totals' => $totals, 'updated' => true];
        }

        $id = Db::insert(
            'INSERT INTO vat_returns (regime, period_label, period_start, period_end, collected, deductible, due, credit, breakdown, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            $values
        );
        return ['ok' => true, 'id' => $id, 'totals' => $totals];
    }

    public static function setStatus(int $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'message' => 'Statut inconnu.'];
        }
        $stamp = gmdate('Y-m-d');
        if ($status === 'Déclarée') {
            Db::run('UPDATE vat_returns SET status = ?, filed_on = ? WHERE id = ?', [$status, $stamp, $id]);
        } elseif ($status === 'Payée') {
            Db::run(
                'UPDATE vat_returns SET status = ?, paid_on = ?, filed_on = COALESCE(filed_on, ?) WHERE id = ?',
                [$status, $stamp, $stamp, $id]
            );
        } else {
            Db::run('UPDATE vat_returns SET status = ?, filed_on = NULL, paid_on = NULL WHERE id = ?', [$status, $id]);
        }
        return ['ok' => true];
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM vat_returns WHERE id = ?', [$id]);
    }

    /** Ce qui reste à déposer ou à payer : c'est ce qui intéresse la direction. */
    public static function summary(): array
    {
        $pending = Db::get("SELECT COUNT(*) AS n, COALESCE(SUM(due), 0) AS total FROM vat_returns WHERE status != 'Payée'");
        return [
            'pending' => (int) $pending['n'],
            'pendingAmount' => round((float) $pending['total'], 2),
            'lastFiled' => Db::get("SELECT period_label, filed_on FROM vat_returns WHERE status != 'Brouillon' ORDER BY period_end DESC LIMIT 1"),
            'currency' => Currency::base(),
        ];
    }
}
