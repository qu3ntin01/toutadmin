<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Facturation récurrente.
 *
 * Un abonnement est un moule ; la facture est la pièce. Le moule porte la date
 * de la prochaine émission plutôt qu'une règle à rejouer depuis l'origine :
 * une échéance sautée ou avancée à la main ne dérègle pas la suite.
 *
 * Rien n'est émis d'avance : la facture du mois prochain n'existe pas encore,
 * et l'abonnement peut être arrêté d'ici là.
 */
final class Billing
{
    public const PERIODS = [
        ['key' => 'Mensuelle', 'months' => 1],
        ['key' => 'Trimestrielle', 'months' => 3],
        ['key' => 'Semestrielle', 'months' => 6],
        ['key' => 'Annuelle', 'months' => 12],
    ];

    public const DIRECTIONS = ['Client', 'Fournisseur'];

    /** Les clés de période, dans l'ordre, pour les listes déroulantes. */
    public static function periodKeys(): array
    {
        return array_column(self::PERIODS, 'key');
    }

    public static function monthsOf(string $period): int
    {
        foreach (self::PERIODS as $entry) {
            if ($entry['key'] === $period) {
                return $entry['months'];
            }
        }
        return self::PERIODS[0]['months'];
    }

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    private static function addDays(string $iso, int $days): string
    {
        return gmdate('Y-m-d', strtotime("$iso +$days days"));
    }

    /**
     * Ajoute des mois sans déborder sur le mois suivant : le 31 janvier plus un
     * mois donne le 28 ou le 29 février, pas le 2 mars.
     */
    public static function addMonths(string $iso, int $months): string
    {
        [$year, $month, $day] = array_map('intval', explode('-', $iso));
        $target = $month - 1 + $months;
        $targetYear = $year + intdiv($target, 12);
        $targetMonth = $target % 12;
        if ($targetMonth < 0) {
            $targetMonth += 12;
            $targetYear -= 1;
        }
        $lastDay = (int) gmdate('t', gmmktime(0, 0, 0, $targetMonth + 1, 1, $targetYear));
        return sprintf('%04d-%02d-%02d', $targetYear, $targetMonth + 1, min($day, $lastDay));
    }

    public static function list(bool $activeOnly = false): array
    {
        $clause = $activeOnly ? 'WHERE s.active = 1' : '';
        $rows = Db::all(
            "SELECT s.*, p.name AS partner_name, d.name AS department_name,
                    (SELECT COUNT(*) FROM invoices i WHERE i.subscription_id = s.id) AS issued_count
             FROM subscriptions s
             LEFT JOIN partners p ON p.id = s.partner_id
             LEFT JOIN departments d ON d.id = s.department_id
             $clause
             ORDER BY s.active DESC, s.next_issue"
        );
        $today = self::today();
        return array_map(static function (array $row) use ($today): array {
            // Le montant se lit dans sa devise ; le total, lui, se lit en référence.
            $row['amountTtc'] = round((float) $row['amount_ht'] * (1 + (float) $row['vat_rate'] / 100), 2);
            $row['over'] = !empty($row['end_date']) && $row['end_date'] < $today;
            return $row;
        }, $rows);
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM subscriptions WHERE id = ?', [$id]);
    }

    public static function create(array $fields): int
    {
        $start = $fields['startDate'];
        return Db::insert(
            'INSERT INTO subscriptions (direction, partner_id, department_id, label, amount_ht, vat_rate, currency,
                                        period, start_date, next_issue, end_date, payment_days, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['direction'], $fields['partnerId'] ?? null, $fields['departmentId'] ?? null, $fields['label'],
                $fields['amountHt'], $fields['vatRate'], $fields['currency'] ?? Currency::base(), $fields['period'],
                $start, $fields['nextIssue'] ?? $start, $fields['endDate'] ?? null, $fields['paymentDays'] ?? 30,
                $fields['notes'] ?? '', $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function setActive(int $id, bool $active): bool
    {
        return Db::run('UPDATE subscriptions SET active = ? WHERE id = ?', [$active ? 1 : 0, $id]) > 0;
    }

    public static function remove(int $id): void
    {
        // Les factures déjà émises ne disparaissent pas avec le moule : elles
        // sont dues, et l'abonnement n'en est que l'origine.
        Db::run('UPDATE invoices SET subscription_id = NULL WHERE subscription_id = ?', [$id]);
        Db::run('DELETE FROM subscriptions WHERE id = ?', [$id]);
    }

    /** Les abonnements dont l'échéance est atteinte à la date donnée. */
    public static function due(?string $asOf = null): array
    {
        $asOf ??= self::today();
        return Db::all(
            'SELECT * FROM subscriptions
             WHERE active = 1 AND next_issue <= ? AND (end_date IS NULL OR end_date >= next_issue)
             ORDER BY next_issue',
            [$asOf]
        );
    }

    public static function invoiceReference(array $subscription, string $issueDate): string
    {
        return 'AB-' . (int) $subscription['id'] . '-' . substr($issueDate, 0, 7);
    }

    /**
     * Émet les factures dues et avance chaque abonnement d'une période. Chaque
     * abonnement est traité à part : un taux de change manquant sur l'un ne doit
     * pas empêcher les autres de partir.
     */
    public static function run(?string $asOf = null, ?int $createdBy = null): array
    {
        $asOf ??= self::today();
        $issued = [];
        $skipped = [];

        foreach (self::due($asOf) as $subscription) {
            $rate = Currency::rateOf($subscription['currency']);
            if ($rate === null) {
                $skipped[] = [
                    'id' => (int) $subscription['id'], 'label' => $subscription['label'],
                    'reason' => 'taux ' . $subscription['currency'] . ' inconnu',
                ];
                continue;
            }

            $issueDate = $subscription['next_issue'];
            try {
                $invoiceId = Db::transaction(static function () use ($subscription, $issueDate, $rate, $createdBy): int {
                    $id = Db::insert(
                        "INSERT INTO invoices (direction, partner_id, department_id, reference, label, issue_date, due_date,
                                               amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate, subscription_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Émise', ?, ?, ?, ?, ?)",
                        [
                            $subscription['direction'], $subscription['partner_id'], $subscription['department_id'],
                            self::invoiceReference($subscription, $issueDate),
                            $subscription['label'] . ' — ' . substr($issueDate, 0, 7),
                            $issueDate, self::addDays($issueDate, (int) $subscription['payment_days']),
                            $subscription['amount_ht'], $subscription['vat_rate'],
                            'Émise automatiquement depuis l\'abonnement « ' . $subscription['label'] . ' ».',
                            $createdBy, $subscription['currency'], $rate, $subscription['id'],
                        ]
                    );

                    $next = self::addMonths($issueDate, self::monthsOf($subscription['period']));
                    // Un abonnement dont le terme est passé s'éteint de lui-même
                    // plutôt que de facturer au-delà de ce qui a été signé.
                    $stillRunning = empty($subscription['end_date']) || $next <= $subscription['end_date'];
                    Db::run(
                        'UPDATE subscriptions SET next_issue = ?, active = ? WHERE id = ?',
                        [$next, $stillRunning ? 1 : 0, $subscription['id']]
                    );
                    return $id;
                });
                $issued[] = [
                    'id' => $invoiceId, 'subscriptionId' => (int) $subscription['id'],
                    'label' => $subscription['label'], 'issueDate' => $issueDate,
                ];
            } catch (\Throwable $error) {
                // L'index unique (abonnement, date) a parlé : l'échéance est déjà facturée.
                $skipped[] = [
                    'id' => (int) $subscription['id'], 'label' => $subscription['label'],
                    'reason' => preg_match('/UNIQUE/i', $error->getMessage()) === 1 ? 'déjà facturée' : $error->getMessage(),
                ];
            }
        }

        return ['issued' => $issued, 'skipped' => $skipped];
    }

    /** Ce que les abonnements actifs représentent sur douze mois, en devise de référence. */
    public static function annualValue(): array
    {
        $perYear = ['Mensuelle' => 12, 'Trimestrielle' => 4, 'Semestrielle' => 2, 'Annuelle' => 1];
        $client = 0.0;
        $supplier = 0.0;

        foreach (self::list(true) as $subscription) {
            $converted = Currency::toBase((float) $subscription['amount_ht'], $subscription['currency']);
            if ($converted === null) {
                continue;
            }
            $yearly = $converted * ($perYear[$subscription['period']] ?? 1);
            if ($subscription['direction'] === 'Client') {
                $client += $yearly;
            } else {
                $supplier += $yearly;
            }
        }

        return [
            'client' => round($client, 2),
            'supplier' => round($supplier, 2),
            'net' => round($client - $supplier, 2),
            'currency' => Currency::base(),
        ];
    }
}
