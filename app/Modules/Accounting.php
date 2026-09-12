<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Comptabilité en partie double.
 *
 * Une écriture n'est enregistrée que si elle est équilibrée : c'est la règle
 * qui fait la différence entre une comptabilité et une liste de montants. Tout
 * le reste — balance, grand livre, résultat — se déduit des écritures, et rien
 * n'est tenu à la main en double.
 *
 * Tenue de comptes interne : ni liasse fiscale, ni télétransmission. L'export
 * de la balance alimente l'expert-comptable.
 */
final class Accounting
{
    public const ACCOUNT_KINDS = ['Actif', 'Passif', 'Charge', 'Produit'];

    // Un plan et des journaux minimaux, posés à l'activation du module pour que
    // l'écran ne s'ouvre pas sur une page vide. Tout reste modifiable ensuite.
    private const DEFAULT_ACCOUNTS = [
        ['401', 'Fournisseurs', 'Passif'],
        ['411', 'Clients', 'Actif'],
        ['4456', 'TVA déductible', 'Actif'],
        ['4457', 'TVA collectée', 'Passif'],
        ['512', 'Banque', 'Actif'],
        ['606', 'Achats non stockés', 'Charge'],
        ['613', 'Locations', 'Charge'],
        ['641', 'Rémunérations du personnel', 'Charge'],
        ['645', 'Charges de sécurité sociale', 'Charge'],
        ['706', 'Prestations de services', 'Produit'],
    ];

    private const DEFAULT_JOURNALS = [
        ['VE', 'Ventes'],
        ['AC', 'Achats'],
        ['BQ', 'Banque'],
        ['OD', 'Opérations diverses'],
    ];

    /** Idempotent : réactiver le module ne duplique pas le plan. */
    public static function seedDefaults(): void
    {
        Db::transaction(static function (): void {
            foreach (self::DEFAULT_ACCOUNTS as $row) {
                Db::run('INSERT OR IGNORE INTO accounts (code, label, kind) VALUES (?, ?, ?)', $row);
            }
            foreach (self::DEFAULT_JOURNALS as $row) {
                Db::run('INSERT OR IGNORE INTO journals (code, label) VALUES (?, ?)', $row);
            }
        });
    }

    // ---------- Plan comptable ----------

    public static function accounts(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';
        return Db::all("SELECT * FROM accounts $where ORDER BY code");
    }

    public static function accountByCode(string $code): ?array
    {
        return Db::get('SELECT * FROM accounts WHERE code = ?', [$code]);
    }

    public static function createAccount(string $code, string $label, string $kind): array
    {
        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            return ['ok' => false, 'reason' => 'bad-kind'];
        }
        if (self::accountByCode($code) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        Db::insert('INSERT INTO accounts (code, label, kind) VALUES (?, ?, ?)', [$code, $label, $kind]);
        return ['ok' => true];
    }

    /** Un compte mouvementé n'est pas supprimable : il est désactivé. */
    public static function deleteAccount(int $id): array
    {
        if (Db::get('SELECT 1 AS ok FROM entry_lines WHERE account_id = ? LIMIT 1', [$id]) !== null) {
            Db::run('UPDATE accounts SET active = 0 WHERE id = ?', [$id]);
            return ['ok' => true, 'deactivated' => true];
        }
        Db::run('DELETE FROM accounts WHERE id = ?', [$id]);
        return ['ok' => true, 'deactivated' => false];
    }

    public static function journals(): array
    {
        return Db::all('SELECT * FROM journals ORDER BY code');
    }

    public static function createJournal(string $code, string $label): array
    {
        if (Db::get('SELECT 1 AS ok FROM journals WHERE code = ?', [$code]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        Db::insert('INSERT INTO journals (code, label) VALUES (?, ?)', [$code, $label]);
        return ['ok' => true];
    }

    // ---------- Écritures ----------

    public static function entries(int $limit = 200): array
    {
        return Db::all(
            'SELECT e.*, j.code AS journal_code, j.label AS journal_label,
                (SELECT COALESCE(SUM(l.debit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_debit,
                (SELECT COALESCE(SUM(l.credit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_credit
             FROM entries e JOIN journals j ON j.id = e.journal_id
             ORDER BY e.entry_date DESC, e.id DESC
             LIMIT ?',
            [$limit]
        );
    }

    public static function linesOf(int $entryId): array
    {
        return Db::all(
            'SELECT l.*, a.code, a.label AS account_label
             FROM entry_lines l JOIN accounts a ON a.id = l.account_id
             WHERE l.entry_id = ? ORDER BY l.id',
            [$entryId]
        );
    }

    /**
     * Une écriture n'est enregistrée que si elle est équilibrée. Les lignes
     * vides sont écartées avant le contrôle : un formulaire à dix lignes dont
     * trois sont servies reste une écriture à trois lignes.
     */
    public static function createEntry(array $data): array
    {
        $journalId = (int) $data['journalId'];
        if (Db::get('SELECT 1 AS ok FROM journals WHERE id = ?', [$journalId]) === null) {
            return ['ok' => false, 'reason' => 'no-journal'];
        }

        $clean = [];
        foreach ($data['lines'] as $line) {
            $entry = [
                'accountId' => (int) ($line['accountId'] ?? 0),
                'label' => mb_substr((string) ($line['label'] ?? ''), 0, 160),
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
            ];
            if ($entry['accountId'] !== 0 && ($entry['debit'] > 0 || $entry['credit'] > 0)) {
                $clean[] = $entry;
            }
        }

        if (count($clean) < 2) {
            return ['ok' => false, 'reason' => 'too-few-lines'];
        }
        foreach ($clean as $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                return ['ok' => false, 'reason' => 'both-sides'];
            }
            if ($line['debit'] < 0 || $line['credit'] < 0) {
                return ['ok' => false, 'reason' => 'negative'];
            }
        }

        $known = array_map(static fn (array $a): int => (int) $a['id'], self::accounts());
        foreach ($clean as $line) {
            if (!in_array($line['accountId'], $known, true)) {
                return ['ok' => false, 'reason' => 'no-account'];
            }
        }

        $totalDebit = round(array_sum(array_column($clean, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($clean, 'credit')), 2);
        if ($totalDebit !== $totalCredit) {
            return ['ok' => false, 'reason' => 'unbalanced', 'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit];
        }
        if ($totalDebit === 0.0) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        $id = Db::transaction(static function () use ($data, $journalId, $clean): int {
            $entryId = Db::insert(
                'INSERT INTO entries (journal_id, entry_date, reference, label, invoice_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $journalId, $data['entryDate'], $data['reference'] ?? '', $data['label'],
                    $data['invoiceId'] ?? null, $data['createdBy'] ?? null,
                ]
            );
            foreach ($clean as $line) {
                Db::insert(
                    'INSERT INTO entry_lines (entry_id, account_id, label, debit, credit) VALUES (?, ?, ?, ?, ?)',
                    [$entryId, $line['accountId'], $line['label'], $line['debit'], $line['credit']]
                );
            }
            return $entryId;
        });
        return ['ok' => true, 'id' => $id];
    }

    public static function deleteEntry(int $id): void
    {
        Db::run('DELETE FROM entries WHERE id = ?', [$id]);
    }

    /**
     * Passe une facture en écriture : la vente débite le client et crédite le
     * produit et la TVA collectée ; l'achat fait l'inverse.
     */
    public static function entryFromInvoice(array $invoice, ?int $createdBy = null): array
    {
        if (Db::get('SELECT 1 AS ok FROM entries WHERE invoice_id = ?', [$invoice['id']]) !== null) {
            return ['ok' => false, 'reason' => 'already-posted'];
        }

        $isSale = $invoice['direction'] === 'Client';
        $journal = Db::get('SELECT id FROM journals WHERE code = ?', [$isSale ? 'VE' : 'AC']);
        if ($journal === null) {
            return ['ok' => false, 'reason' => 'no-journal'];
        }

        // Une écriture se passe dans la devise de tenue des comptes : le montant
        // est converti au taux figé sur la facture, pas au taux du jour.
        $ht = round((float) $invoice['amount_ht'] * ((float) $invoice['exchange_rate'] ?: 1), 2);
        $vat = round($ht * ((float) $invoice['vat_rate'] / 100), 2);
        $ttc = round($ht + $vat, 2);

        $codes = $isSale
            ? ['third' => '411', 'income' => '706', 'vat' => '4457']
            : ['third' => '401', 'income' => '606', 'vat' => '4456'];

        $third = self::accountByCode($codes['third']);
        $result = self::accountByCode($codes['income']);
        $vatAccount = self::accountByCode($codes['vat']);
        if ($third === null || $result === null || $vatAccount === null) {
            return ['ok' => false, 'reason' => 'missing-accounts'];
        }

        $lines = $isSale
            ? [
                ['accountId' => $third['id'], 'label' => $invoice['label'], 'debit' => $ttc, 'credit' => 0],
                ['accountId' => $result['id'], 'label' => $invoice['label'], 'debit' => 0, 'credit' => $ht],
                ['accountId' => $vatAccount['id'], 'label' => 'TVA', 'debit' => 0, 'credit' => $vat],
            ]
            : [
                ['accountId' => $result['id'], 'label' => $invoice['label'], 'debit' => $ht, 'credit' => 0],
                ['accountId' => $vatAccount['id'], 'label' => 'TVA', 'debit' => $vat, 'credit' => 0],
                ['accountId' => $third['id'], 'label' => $invoice['label'], 'debit' => 0, 'credit' => $ttc],
            ];

        return self::createEntry([
            'journalId' => (int) $journal['id'],
            'entryDate' => $invoice['issue_date'],
            'reference' => $invoice['reference'],
            'label' => $invoice['label'],
            'invoiceId' => (int) $invoice['id'],
            'lines' => $lines,
            'createdBy' => $createdBy,
        ]);
    }

    // ---------- Restitutions ----------

    /** Balance générale : un solde par compte mouvementé, sur une période. */
    public static function balance(?string $from = null, ?string $to = null): array
    {
        $clauses = [];
        $params = [];
        if ($from !== null) {
            $clauses[] = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'e.entry_date <= ?';
            $params[] = $to;
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        $rows = Db::all(
            "SELECT a.id, a.code, a.label, a.kind,
                COALESCE(SUM(l.debit), 0) AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
             FROM accounts a
             JOIN entry_lines l ON l.account_id = a.id
             JOIN entries e ON e.id = l.entry_id
             $where
             GROUP BY a.id
             ORDER BY a.code",
            $params
        );
        return array_map(static function (array $row): array {
            $row['debit'] = round((float) $row['debit'], 2);
            $row['credit'] = round((float) $row['credit'], 2);
            $row['balance'] = round($row['debit'] - $row['credit'], 2);
            return $row;
        }, $rows);
    }

    /** Grand livre d'un compte : le détail qui explique son solde. */
    public static function ledger(int $accountId, ?string $from = null, ?string $to = null): array
    {
        $clauses = ['l.account_id = ?'];
        $params = [$accountId];
        if ($from !== null) {
            $clauses[] = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'e.entry_date <= ?';
            $params[] = $to;
        }

        $rows = Db::all(
            'SELECT l.*, e.entry_date, e.label AS entry_label, e.reference, j.code AS journal_code
             FROM entry_lines l
             JOIN entries e ON e.id = l.entry_id
             JOIN journals j ON j.id = e.journal_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY e.entry_date, l.id',
            $params
        );

        $running = 0.0;
        return array_map(static function (array $row) use (&$running): array {
            $running = round($running + (float) $row['debit'] - (float) $row['credit'], 2);
            $row['running'] = $running;
            return $row;
        }, $rows);
    }

    /** Le résultat de l'exercice : produits moins charges. */
    public static function income(?string $from = null, ?string $to = null): array
    {
        $rows = self::balance($from, $to);
        $sum = static function (string $kind) use ($rows): float {
            $total = 0.0;
            foreach ($rows as $row) {
                if ($row['kind'] !== $kind) {
                    continue;
                }
                $total += $kind === 'Produit'
                    ? $row['credit'] - $row['debit']
                    : $row['debit'] - $row['credit'];
            }
            return round($total, 2);
        };
        $revenue = $sum('Produit');
        $expenses = $sum('Charge');
        return ['revenue' => $revenue, 'expenses' => $expenses, 'result' => round($revenue - $expenses, 2)];
    }

    /** Export CSV de la balance, pour l'expert-comptable. */
    public static function balanceCsv(?string $from = null, ?string $to = null): string
    {
        $lines = ['Compte;Libellé;Type;Débit;Crédit;Solde'];
        foreach (self::balance($from, $to) as $row) {
            $lines[] = implode(';', [
                $row['code'], str_replace(';', ',', (string) $row['label']), $row['kind'],
                number_format($row['debit'], 2, '.', ''),
                number_format($row['credit'], 2, '.', ''),
                number_format($row['balance'], 2, '.', ''),
            ]);
        }
        return implode("\n", $lines);
    }
}
