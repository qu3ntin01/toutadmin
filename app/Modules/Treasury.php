<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Trésorerie et immobilisations.
 *
 * La comptabilité dit ce qui a été engagé ; la trésorerie dit ce qu'il reste en
 * banque et ce qui va en sortir. Les deux ne se confondent pas : une facture
 * comptabilisée n'est pas une facture encaissée.
 *
 * Saisie et import manuels : aucune connexion bancaire n'est établie. Le solde
 * est celui qui a été saisi, pas celui de la banque.
 */
final class Treasury
{
    public const CERTAINTIES = ['Certain', 'Probable', 'Éventuel'];
    public const DEPRECIATION_METHODS = ['Linéaire', 'Dégressif'];

    // Coefficients du dégressif selon la durée, tels que l'usage les fixe.
    private const DEGRESSIVE_COEFFICIENTS = [
        ['maxYears' => 4, 'coefficient' => 1.25],
        ['maxYears' => 6, 'coefficient' => 1.75],
    ];
    private const DEGRESSIVE_BEYOND = 2.25;

    // ---------- Comptes bancaires ----------

    public static function accounts(bool $includeClosed = false): array
    {
        $where = $includeClosed ? '' : 'WHERE active = 1';
        return array_map(static function (array $account): array {
            $account['balance'] = self::balanceOf($account);
            return $account;
        }, Db::all("SELECT * FROM bank_accounts $where ORDER BY label COLLATE NOCASE"));
    }

    public static function accountById(int $id): ?array
    {
        return Db::get('SELECT * FROM bank_accounts WHERE id = ?', [$id]);
    }

    public static function createAccount(string $label, string $bank = '', string $ibanLast4 = '', float $openingBalance = 0): int
    {
        return Db::insert(
            'INSERT INTO bank_accounts (label, bank, iban_last4, opening_balance) VALUES (?, ?, ?, ?)',
            [$label, $bank, $ibanLast4, $openingBalance]
        );
    }

    public static function closeAccount(int $id): void
    {
        Db::run('UPDATE bank_accounts SET active = 0 WHERE id = ?', [$id]);
    }

    public static function deleteAccount(int $id): void
    {
        Db::run('DELETE FROM bank_accounts WHERE id = ?', [$id]);
    }

    /** Le solde n'est jamais stocké : il se recalcule des mouvements, toujours juste. */
    public static function balanceOf(array $account): float
    {
        $moved = (float) Db::value(
            'SELECT COALESCE(SUM(amount), 0) FROM bank_transactions WHERE account_id = ?',
            [$account['id']]
        );
        return round((float) $account['opening_balance'] + $moved, 2);
    }

    public static function totalBalance(): float
    {
        $total = 0.0;
        foreach (self::accounts() as $account) {
            $total += $account['balance'];
        }
        return round($total, 2);
    }

    // ---------- Mouvements ----------

    public static function transactions(?int $accountId = null, int $limit = 200): array
    {
        $clause = $accountId === null ? '' : 'WHERE t.account_id = ?';
        $params = $accountId === null ? [$limit] : [$accountId, $limit];
        return Db::all(
            "SELECT t.*, a.label AS account_label, i.reference AS invoice_reference
             FROM bank_transactions t
             JOIN bank_accounts a ON a.id = t.account_id
             LEFT JOIN invoices i ON i.id = t.invoice_id
             $clause
             ORDER BY t.value_date DESC, t.id DESC LIMIT ?",
            $params
        );
    }

    public static function addTransaction(array $data): int
    {
        return Db::insert(
            'INSERT INTO bank_transactions (account_id, value_date, label, amount, category, invoice_id, claim_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['accountId'], $data['valueDate'], $data['label'], $data['amount'],
                $data['category'] ?? '', $data['invoiceId'] ?? null, $data['claimId'] ?? null,
            ]
        );
    }

    public static function deleteTransaction(int $id): void
    {
        Db::run('DELETE FROM bank_transactions WHERE id = ?', [$id]);
    }

    /** Rapproche un mouvement d'une facture : c'est ce qui distingue émis d'encaissé. */
    public static function reconcile(int $transactionId, int $invoiceId): array
    {
        $transaction = Db::get('SELECT * FROM bank_transactions WHERE id = ?', [$transactionId]);
        $invoice = Db::get('SELECT * FROM invoices WHERE id = ?', [$invoiceId]);
        if ($transaction === null || $invoice === null) {
            return ['ok' => false, 'message' => 'Mouvement ou facture introuvable.'];
        }

        // Un encaissement est positif pour une facture client, négatif pour une
        // facture fournisseur.
        $expected = $invoice['direction'] === 'Client' ? 1 : -1;
        $amount = (float) $transaction['amount'];
        $sign = $amount > 0 ? 1 : ($amount < 0 ? -1 : 0);
        if ($sign !== $expected) {
            return ['ok' => false, 'message' => 'Le sens du mouvement ne correspond pas à celui de la facture.'];
        }

        Db::run('UPDATE bank_transactions SET invoice_id = ? WHERE id = ?', [$invoiceId, $transactionId]);
        Db::run("UPDATE invoices SET status = 'Payée', paid_at = ? WHERE id = ?", [$transaction['value_date'], $invoiceId]);
        return ['ok' => true];
    }

    public static function unreconciled(): array
    {
        return Db::all(
            'SELECT t.*, a.label AS account_label FROM bank_transactions t
             JOIN bank_accounts a ON a.id = t.account_id
             WHERE t.invoice_id IS NULL AND t.claim_id IS NULL
             ORDER BY t.value_date DESC LIMIT 100'
        );
    }

    // ---------- Prévisionnel ----------

    public static function forecasts(): array
    {
        return Db::all('SELECT * FROM cash_forecasts ORDER BY expected_on');
    }

    public static function addForecast(string $label, string $expectedOn, float $amount, string $certainty, string $note = ''): int
    {
        return Db::insert(
            'INSERT INTO cash_forecasts (label, expected_on, amount, certainty, note) VALUES (?, ?, ?, ?, ?)',
            [$label, $expectedOn, $amount, $certainty, $note]
        );
    }

    public static function deleteForecast(int $id): void
    {
        Db::run('DELETE FROM cash_forecasts WHERE id = ?', [$id]);
    }

    /**
     * Projection à N semaines : solde courant, puis chaque échéance prévue et
     * chaque facture encore due. Ce qui compte est le creux, pas le total.
     */
    public static function projection(int $weeks = 12): array
    {
        $start = self::totalBalance();
        $limit = gmdate('Y-m-d', strtotime('+' . ($weeks * 7) . ' days'));

        // Le TTC est ce qui bouge en banque, pas le HT.
        $dues = Db::all(
            "SELECT due_date AS on_date,
                    COALESCE(NULLIF(reference, ''), label) AS label,
                    ROUND(amount_ht * (1 + vat_rate / 100.0) * exchange_rate, 2)
                      * (CASE WHEN direction = 'Client' THEN 1 ELSE -1 END) AS amount,
                    'Facture' AS origin
             FROM invoices
             WHERE status != 'Payée' AND due_date IS NOT NULL AND due_date <= ?",
            [$limit]
        );
        $planned = Db::all(
            'SELECT expected_on AS on_date, label, amount, certainty AS origin
             FROM cash_forecasts WHERE expected_on <= ?',
            [$limit]
        );

        $events = array_merge($dues, $planned);
        usort($events, static fn (array $a, array $b): int => strcmp((string) $a['on_date'], (string) $b['on_date']));

        $running = $start;
        $points = [];
        $lowest = ['balance' => $start, 'on_date' => null];
        foreach ($events as $event) {
            $running = round($running + (float) $event['amount'], 2);
            $event['amount'] = round((float) $event['amount'], 2);
            $event['balance'] = $running;
            $points[] = $event;
            if ($running < $lowest['balance']) {
                $lowest = $event;
            }
        }
        return ['start' => $start, 'points' => $points, 'end' => $running, 'lowest' => $lowest];
    }

    // ---------- Immobilisations ----------

    public static function coefficientFor(int $years): float
    {
        foreach (self::DEGRESSIVE_COEFFICIENTS as $entry) {
            if ($years <= $entry['maxYears']) {
                return $entry['coefficient'];
            }
        }
        return self::DEGRESSIVE_BEYOND;
    }

    /**
     * Tableau d'amortissement d'une immobilisation. En linéaire, la dotation est
     * constante ; en dégressif, elle s'applique à la valeur résiduelle jusqu'à
     * ce que le linéaire sur les années restantes devienne plus favorable —
     * c'est la règle, et elle change l'année de bascule.
     */
    public static function schedule(array $asset): array
    {
        $years = max(1, (int) round((float) $asset['duration_years']));
        $base = (float) $asset['amount'];
        $startYear = (int) substr((string) $asset['acquired_on'], 0, 4);

        $rows = [];
        $residual = $base;
        for ($index = 0; $index < $years; $index++) {
            $remaining = $years - $index;
            if ($asset['method'] === 'Dégressif') {
                $degressive = $residual * (1 / $years) * self::coefficientFor($years);
                $linearOnRemaining = $residual / $remaining;
                $charge = max($degressive, $linearOnRemaining);
            } else {
                $charge = $base / $years;
            }
            $charge = round(min($charge, $residual), 2);
            $residual = round($residual - $charge, 2);
            $rows[] = ['year' => $startYear + $index, 'charge' => $charge, 'residual' => $residual];
        }

        // L'arrondi peut laisser quelques centimes : ils tombent sur la dernière année.
        $last = count($rows) - 1;
        if ($last >= 0 && $residual !== 0.0) {
            $rows[$last]['charge'] = round($rows[$last]['charge'] + $residual, 2);
            $rows[$last]['residual'] = 0.0;
        }
        return $rows;
    }

    public static function assets(): array
    {
        $currentYear = (int) gmdate('Y');
        return array_map(static function (array $asset) use ($currentYear): array {
            $rows = self::schedule($asset);
            $cumulated = 0.0;
            $thisYear = 0.0;
            foreach ($rows as $row) {
                if ($row['year'] < $currentYear) {
                    $cumulated += $row['charge'];
                }
                if ($row['year'] === $currentYear) {
                    $thisYear = $row['charge'];
                }
            }
            $cumulated = round($cumulated, 2);
            $asset['schedule'] = $rows;
            $asset['cumulated'] = $cumulated;
            $asset['currentCharge'] = $thisYear;
            $asset['bookValue'] = round((float) $asset['amount'] - $cumulated - $thisYear, 2);
            return $asset;
        }, Db::all('SELECT * FROM fixed_assets ORDER BY acquired_on DESC, id DESC'));
    }

    public static function assetById(int $id): ?array
    {
        return Db::get('SELECT * FROM fixed_assets WHERE id = ?', [$id]);
    }

    public static function createAsset(array $data): int
    {
        return Db::insert(
            'INSERT INTO fixed_assets (label, category, acquired_on, amount, duration_years, method, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['label'], $data['category'], $data['acquiredOn'], $data['amount'],
                $data['durationYears'], $data['method'], $data['note'] ?? '',
            ]
        );
    }

    public static function disposeAsset(int $id, string $on): void
    {
        Db::run('UPDATE fixed_assets SET disposed_on = ? WHERE id = ?', [$on, $id]);
    }

    public static function deleteAsset(int $id): void
    {
        Db::run('DELETE FROM fixed_assets WHERE id = ?', [$id]);
    }

    public static function assetSummary(): array
    {
        $gross = 0.0;
        $net = 0.0;
        $charge = 0.0;
        $count = 0;
        foreach (self::assets() as $asset) {
            if (!empty($asset['disposed_on'])) {
                continue;
            }
            $count++;
            $gross += (float) $asset['amount'];
            $net += $asset['bookValue'];
            $charge += $asset['currentCharge'];
        }
        return [
            'count' => $count,
            'gross' => round($gross, 2),
            'net' => round($net, 2),
            'charge' => round($charge, 2),
        ];
    }
}
