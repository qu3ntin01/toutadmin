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
    /** Le périmètre courant. Les abonnements ne sont pas encore sous garde ; quand ils y entreront, c'est l'abonnement qui dira la société de la facture qu'il fait naître. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }
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
        $clause = $activeOnly ? 'AND s.active = 1' : '';
        $rows = Db::all(
            "SELECT s.*, p.name AS partner_name, d.name AS department_name,
                    (SELECT COUNT(*) FROM invoices i WHERE i.subscription_id = s.id) AS issued_count
             FROM subscriptions s
             LEFT JOIN partners p ON p.id = s.partner_id
             LEFT JOIN departments d ON d.id = s.department_id
             WHERE s.company_id = ? $clause
             ORDER BY s.active DESC, s.next_issue",
            [self::ici()]
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
        return Db::get('SELECT * FROM subscriptions WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function create(array $fields): int
    {
        $start = $fields['startDate'];
        return Db::insert(
            'INSERT INTO subscriptions (company_id, direction, partner_id, department_id, label, amount_ht, vat_rate, currency,
                                        period, start_date, next_issue, end_date, payment_days, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['direction'], $fields['partnerId'] ?? null, $fields['departmentId'] ?? null, $fields['label'],
                $fields['amountHt'], $fields['vatRate'], $fields['currency'] ?? Currency::base(), $fields['period'],
                $start, $fields['nextIssue'] ?? $start, $fields['endDate'] ?? null, $fields['paymentDays'] ?? 30,
                $fields['notes'] ?? '', $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function setActive(int $id, bool $active): bool
    {
        return Db::run(
            'UPDATE subscriptions SET active = ? WHERE id = ? AND company_id = ?',
            [$active ? 1 : 0, $id, self::ici()]
        ) > 0;
    }

    public static function remove(int $id): void
    {
        // Les factures déjà émises ne disparaissent pas avec le moule : elles
        // sont dues, et l'abonnement n'en est que l'origine.
        Db::run(
            'UPDATE invoices SET subscription_id = NULL WHERE subscription_id = ? AND company_id = ?',
            [$id, self::ici()]
        );
        Db::run('DELETE FROM subscriptions WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    /** Les abonnements dont l'échéance est atteinte à la date donnée. */
    public static function due(?string $asOf = null): array
    {
        $asOf ??= self::today();
        return Db::all(
            'SELECT * FROM subscriptions
             WHERE company_id = ? AND active = 1 AND next_issue <= ?
               AND (end_date IS NULL OR end_date >= next_issue)
             ORDER BY next_issue',
            [self::ici(), $asOf]
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
     *
     * Travaille dans la société courante, et dans elle seule. La tâche planifiée
     * qui l'appelle n'a pas de session : c'est « runToutes » qui passe dans
     * chacune.
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
                $societe = self::ici();
                $invoiceId = Db::transaction(static function () use ($subscription, $issueDate, $rate, $createdBy, $societe): int {
                    $id = Db::insert(
                        "INSERT INTO invoices (company_id, direction, partner_id, department_id, reference, label, issue_date, due_date,
                                               amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate, subscription_id)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Émise', ?, ?, ?, ?, ?)",
                        [
                            $societe, $subscription['direction'], $subscription['partner_id'], $subscription['department_id'],
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
                        'UPDATE subscriptions SET next_issue = ?, active = ? WHERE id = ? AND company_id = ?',
                        [$next, $stillRunning ? 1 : 0, $subscription['id'], $societe]
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
                    'reason' => Db::estUnDoublon($error) ? 'déjà facturée' : $error->getMessage(),
                ];
            }
        }

        return ['issued' => $issued, 'skipped' => $skipped];
    }

    /**
     * Le même travail, dans chaque société de l'instance.
     *
     * C'est ce que la tâche planifiée appelle, et la raison est simple : une tâche
     * de fond n'a pas de session, donc pas de société. Facturer la seule société
     * qui mène laisserait les abonnements des autres s'accumuler sans rien
     * émettre, et personne ne s'en apercevrait avant le premier impayé. Chaque
     * société est traitée à part, et un taux de change manquant dans l'une
     * n'empêche pas les autres de partir.
     */
    public static function runToutes(?string $asOf = null, ?int $createdBy = null): array
    {
        $issued = [];
        $skipped = [];

        foreach (Societes::toutes(false) as $societe) {
            $verdict = Societes::dans(
                (int) $societe['id'],
                static fn (): array => self::run($asOf, $createdBy),
            );
            foreach ($verdict['issued'] as $ligne) {
                $issued[] = $ligne + ['societeId' => (int) $societe['id']];
            }
            foreach ($verdict['skipped'] as $ligne) {
                $skipped[] = $ligne + ['societeId' => (int) $societe['id']];
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

    /**
     * Les métriques d'un revenu récurrent, sur les abonnements clients et en
     * devise de référence — comme dans l'édition Node :
     *
     * - le revenu mensuel récurrent (MRR) : chaque abonnement en cours ramené
     *   au mois ; l'annuel (ARR) le multiplie par douze ;
     * - le revenu moyen par client, sur les tiers distincts ;
     * - l'attrition sur douze mois : ce qui a atteint sa date de fin dans
     *   l'année, rapporté à ce qui courait il y a un an.
     *
     * Une suspension n'est pas datée : elle sort du revenu, mais n'entre pas
     * dans l'attrition, qui ne compte que les fins datées.
     */
    public static function metriques(?string $asOf = null): array
    {
        $asOf ??= gmdate('Y-m-d');
        $ilYaUnAn = ((int) substr($asOf, 0, 4) - 1) . substr($asOf, 4);
        $mensuel = static function (array $s): ?float {
            $converted = Currency::toBase((float) $s['amount_ht'], (string) $s['currency']);
            return $converted === null ? null : $converted / self::monthsOf((string) $s['period']);
        };
        $enCours = static fn (array $s, string $date): bool => $s['start_date'] <= $date
            && ($s['end_date'] === null || $s['end_date'] === '' || $s['end_date'] >= $date);
        $clients = Db::all("SELECT * FROM subscriptions WHERE company_id = ? AND direction = 'Client'", [self::ici()]);

        $mrr = 0.0;
        $tiers = [];
        foreach ($clients as $s) {
            if ((int) $s['active'] !== 1 || !$enCours($s, $asOf)) {
                continue;
            }
            $m = $mensuel($s);
            if ($m === null) {
                continue;
            }
            $mrr += $m;
            $tiers[$s['partner_id'] === null ? 'abonnement-' . $s['id'] : 'tiers-' . $s['partner_id']] = true;
        }

        $debut = array_values(array_filter($clients, static fn (array $s): bool => $enCours($s, $ilYaUnAn)));
        $perdus = array_values(array_filter($debut, static fn (array $s): bool => $s['end_date'] !== null && $s['end_date'] !== '' && $s['end_date'] < $asOf));
        $somme = static function (array $liste) use ($mensuel): float {
            $t = 0.0;
            foreach ($liste as $s) {
                $t += $mensuel($s) ?? 0.0;
            }
            return $t;
        };
        $mrrDebut = $somme($debut);
        $n = count($tiers);
        return [
            'mrr' => round($mrr, 2),
            'arr' => round($mrr * 12, 2),
            'clients' => $n,
            'arpa' => $n > 0 ? round($mrr / $n, 2) : 0.0,
            'attrition' => [
                'perdus' => count($perdus),
                'sur' => count($debut),
                'taux' => $mrrDebut > 0 ? round($somme($perdus) / $mrrDebut * 100, 1) : null,
            ],
            'currency' => Currency::base(),
        ];
    }
}
