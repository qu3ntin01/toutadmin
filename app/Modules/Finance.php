<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Tiers, contrats, factures, budgets et notes de frais.
 *
 * Deux principes traversent le module. Un retard se déduit de la date
 * d'échéance, jamais d'un statut qu'il faudrait entretenir à la main. Et les
 * montants existent en deux exemplaires : dans la devise de la pièce, qui est
 * ce que le client paie, et dans la devise de référence, qui est ce qui
 * s'additionne — au taux figé à l'émission.
 */
final class Finance
{
    public const PARTNER_KINDS = ['Client', 'Fournisseur', 'Client et fournisseur'];
    public const CONTRACT_STATUSES = ['Brouillon', 'Actif', 'Résilié', 'Échu'];
    public const BILLING_PERIODS = ['Ponctuel', 'Mensuel', 'Trimestriel', 'Annuel'];
    public const INVOICE_DIRECTIONS = ['Client', 'Fournisseur'];
    public const INVOICE_STATUSES = ['Brouillon', 'Émise', 'Payée', 'Annulée'];
    public const EXPENSE_CATEGORIES = ['Transport', 'Hébergement', 'Repas', 'Fournitures', 'Formation', 'Autre'];
    public const EXPENSE_STATUSES = ['En attente', 'Approuvée', 'Refusée', 'Remboursée'];

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    // ---------- Tiers ----------

    public static function partners(?string $kind = null): array
    {
        $rows = Db::all(
            'SELECT p.*,
               (SELECT COUNT(*) FROM partner_contracts c WHERE c.partner_id = p.id) AS contract_count,
               (SELECT COUNT(*) FROM invoices i WHERE i.partner_id = p.id) AS invoice_count
             FROM partners p
             ORDER BY p.name COLLATE NOCASE'
        );
        if ($kind === null) {
            return $rows;
        }
        // « Client et fournisseur » relève des deux listes : le filtre le reflète.
        return array_values(array_filter(
            $rows,
            static fn (array $p): bool => $p['kind'] === $kind || $p['kind'] === 'Client et fournisseur'
        ));
    }

    public static function partnerById(int $id): ?array
    {
        return Db::get('SELECT * FROM partners WHERE id = ?', [$id]);
    }

    public static function createPartner(array $data): int
    {
        $id = Db::insert(
            'INSERT INTO partners (kind, name, registration, contact_name, email, phone, address, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['kind'], $data['name'], $data['registration'] ?? '', $data['contactName'] ?? '',
                $data['email'] ?? '', $data['phone'] ?? '', $data['address'] ?? '', $data['notes'] ?? '',
            ]
        );
        Audit::log('tiers.cree', 'partners', $id, ['nom' => $data['name']]);
        return $id;
    }

    public static function updatePartner(int $id, array $data): void
    {
        Db::run(
            'UPDATE partners SET kind = ?, name = ?, registration = ?, contact_name = ?, email = ?, phone = ?,
                    address = ?, notes = ?, active = ?
             WHERE id = ?',
            [
                $data['kind'], $data['name'], $data['registration'] ?? '', $data['contactName'] ?? '',
                $data['email'] ?? '', $data['phone'] ?? '', $data['address'] ?? '', $data['notes'] ?? '',
                !empty($data['active']) ? 1 : 0, $id,
            ]
        );
        Audit::log('tiers.modifie', 'partners', $id);
    }

    public static function deletePartner(int $id): void
    {
        Db::run('DELETE FROM partners WHERE id = ?', [$id]);
        Audit::log('tiers.supprime', 'partners', $id);
    }

    // ---------- Contrats ----------

    public static function contracts(): array
    {
        return Db::all(
            "SELECT c.*, p.name AS partner_name, p.kind AS partner_kind,
                    u.first_name AS owner_first_name, u.last_name AS owner_last_name
             FROM partner_contracts c
             JOIN partners p ON p.id = c.partner_id
             LEFT JOIN users u ON u.id = c.owner_id
             ORDER BY COALESCE(c.end_date, '9999-12-31'), c.title COLLATE NOCASE"
        );
    }

    public static function contractById(int $id): ?array
    {
        return Db::get('SELECT * FROM partner_contracts WHERE id = ?', [$id]);
    }

    public static function createContract(array $data): int
    {
        return Db::insert(
            'INSERT INTO partner_contracts (partner_id, reference, title, start_date, end_date, notice_days,
                                            amount, billing_period, owner_id, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['partnerId'], $data['reference'] ?? '', $data['title'], $data['startDate'] ?? null,
                $data['endDate'] ?? null, $data['noticeDays'] ?? 0, $data['amount'] ?? null,
                $data['billingPeriod'] ?? 'Annuel', $data['ownerId'] ?? null, $data['status'] ?? 'Actif',
                $data['notes'] ?? '',
            ]
        );
    }

    public static function setContractStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::CONTRACT_STATUSES, true)) {
            return false;
        }
        return Db::run('UPDATE partner_contracts SET status = ? WHERE id = ?', [$status, $id]) > 0;
    }

    public static function deleteContract(int $id): void
    {
        Db::run('DELETE FROM partner_contracts WHERE id = ?', [$id]);
    }

    /**
     * Contrats dont le préavis court déjà : passé cette date, la reconduction
     * tacite est acquise. C'est l'alerte qui justifie de tenir des contrats ici.
     */
    public static function contractsToRenew(int $withinDays = 90): array
    {
        $limit = gmdate('Y-m-d', strtotime("+$withinDays days"));
        $out = [];
        foreach (self::contracts() as $contract) {
            if ($contract['status'] !== 'Actif' || empty($contract['end_date'])) {
                continue;
            }
            // La date à ne pas dépasser pour dénoncer le contrat.
            $deadline = gmdate('Y-m-d', strtotime($contract['end_date'] . ' -' . (int) $contract['notice_days'] . ' days'));
            if ($deadline > $limit) {
                continue;
            }
            $out[] = $contract + ['noticeDeadline' => $deadline, 'noticeElapsed' => $deadline < self::today()];
        }
        return $out;
    }

    // ---------- Factures ----------

    private static function amountTtc(array $invoice): float
    {
        return round((float) $invoice['amount_ht'] * (1 + (float) $invoice['vat_rate'] / 100), 2);
    }

    public static function withAmounts(array $invoice): array
    {
        $ttc = self::amountTtc($invoice);
        $rate = (float) ($invoice['exchange_rate'] ?: 1);
        return $invoice + [
            'amount_ttc' => $ttc,
            'amount_base_ht' => round((float) $invoice['amount_ht'] * $rate, 2),
            'amount_base_ttc' => round($ttc * $rate, 2),
            'foreign' => $invoice['currency'] !== Currency::base(),
        ];
    }

    public static function invoices(?string $direction = null): array
    {
        $where = $direction === null ? '' : 'WHERE i.direction = ?';
        $rows = Db::all(
            "SELECT i.*, p.name AS partner_name, d.name AS department_name
             FROM invoices i
             LEFT JOIN partners p ON p.id = i.partner_id
             LEFT JOIN departments d ON d.id = i.department_id
             $where
             ORDER BY i.issue_date DESC, i.id DESC",
            $direction === null ? [] : [$direction]
        );
        // Le retard se déduit de l'échéance : aucun statut à maintenir à la main.
        return array_map(static function (array $invoice): array {
            $decorated = self::withAmounts($invoice);
            $decorated['overdue'] = $invoice['status'] === 'Émise'
                && !empty($invoice['due_date'])
                && $invoice['due_date'] < self::today();
            return $decorated;
        }, $rows);
    }

    public static function invoiceById(int $id): ?array
    {
        $invoice = Db::get('SELECT * FROM invoices WHERE id = ?', [$id]);
        return $invoice === null ? null : self::withAmounts($invoice);
    }

    public static function createInvoice(array $data): ?int
    {
        $code = $data['currency'] ?: Currency::base();
        // Le taux est figé ici, une fois pour toutes.
        $rate = $data['exchangeRate'] ?? Currency::rateOf($code);
        if ($rate === null) {
            return null;
        }
        $id = Db::insert(
            'INSERT INTO invoices (direction, partner_id, department_id, reference, label, issue_date, due_date,
                                   amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['direction'], $data['partnerId'] ?? null, $data['departmentId'] ?? null,
                $data['reference'] ?? '', $data['label'], $data['issueDate'], $data['dueDate'] ?? null,
                $data['amountHt'], $data['vatRate'], $data['status'] ?? 'Émise', $data['notes'] ?? '',
                $data['createdBy'] ?? null, $code, $rate,
            ]
        );
        Audit::log('facture.creee', 'invoices', $id, ['libelle' => $data['label'], 'sens' => $data['direction']]);
        Webhooks::emit('facture.emise', [
            'id' => $id, 'reference' => $data['reference'] ?? '', 'libelle' => $data['label'],
            'sens' => $data['direction'], 'montant_ht' => $data['amountHt'], 'devise' => $code,
            'echeance' => $data['dueDate'] ?? null,
        ]);
        return $id;
    }

    public static function setInvoiceStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::INVOICE_STATUSES, true)) {
            return false;
        }
        $changed = Db::run(
            'UPDATE invoices SET status = ?, paid_at = ? WHERE id = ?',
            [$status, $status === 'Payée' ? gmdate('c') : null, $id]
        ) > 0;

        if ($changed && $status === 'Payée') {
            $invoice = self::invoiceById($id);
            Audit::log('facture.payee', 'invoices', $id);
            Webhooks::emit('facture.payee', [
                'id' => $id, 'reference' => $invoice['reference'], 'libelle' => $invoice['label'],
                'sens' => $invoice['direction'], 'montant_ttc' => $invoice['amount_ttc'], 'devise' => $invoice['currency'],
            ]);
        }
        return $changed;
    }

    public static function deleteInvoice(int $id): void
    {
        Db::run('DELETE FROM invoices WHERE id = ?', [$id]);
        Audit::log('facture.supprimee', 'invoices', $id);
    }

    /** Recettes, dépenses et encours, sur une année civile. */
    public static function financialSummary(int $year): array
    {
        $from = "$year-01-01";
        $to = "$year-12-31";
        $all = array_values(array_filter(
            self::invoices(),
            static fn (array $i): bool => $i['issue_date'] >= $from && $i['issue_date'] <= $to && $i['status'] !== 'Annulée'
        ));
        // On additionne les montants ramenés en devise de référence :
        // additionner des euros et des dollars ne voudrait rien dire.
        $sum = static fn (array $rows): float => round(array_sum(array_column($rows, 'amount_base_ttc')), 2);
        $income = array_values(array_filter($all, static fn (array $i): bool => $i['direction'] === 'Client'));
        $spending = array_values(array_filter($all, static fn (array $i): bool => $i['direction'] === 'Fournisseur'));
        $unpaid = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $i): bool => $i['status'] !== 'Payée'));

        return [
            'year' => $year,
            'income' => $sum($income),
            'spending' => $sum($spending),
            'balance' => round($sum($income) - $sum($spending), 2),
            'unpaidIncome' => $sum($unpaid($income)),
            'unpaidSpending' => $sum($unpaid($spending)),
            'overdue' => count(array_filter($all, static fn (array $i): bool => $i['overdue'])),
            'currency' => Currency::base(),
        ];
    }

    // ---------- Budgets ----------

    public static function budgets(int $year): array
    {
        $rows = Db::all(
            "SELECT b.*, d.name AS department_name,
               (SELECT COALESCE(SUM(i.amount_ht * (1 + i.vat_rate / 100.0)), 0)
                FROM invoices i
                WHERE i.department_id = b.department_id AND i.direction = 'Fournisseur'
                  AND i.status != 'Annulée' AND i.issue_date BETWEEN ? AND ?) AS invoiced,
               (SELECT COALESCE(SUM(e.amount), 0)
                FROM expense_claims e
                JOIN users u ON u.id = e.employee_id
                WHERE u.department_id = b.department_id AND e.status IN ('Approuvée','Remboursée')
                  AND e.spent_on BETWEEN ? AND ?) AS claimed
             FROM budgets b
             JOIN departments d ON d.id = b.department_id
             WHERE b.year = ?
             ORDER BY d.name COLLATE NOCASE",
            ["$year-01-01", "$year-12-31", "$year-01-01", "$year-12-31", $year]
        );
        return array_map(static function (array $budget): array {
            // Le consommé agrège les factures fournisseurs du service et les
            // frais de ses membres.
            $consumed = round((float) $budget['invoiced'] + (float) $budget['claimed'], 2);
            $amount = (float) $budget['amount'];
            return $budget + [
                'consumed' => $consumed,
                'remaining' => round($amount - $consumed, 2),
                'ratio' => $amount > 0 ? round($consumed / $amount * 100, 1) : 0.0,
            ];
        }, $rows);
    }

    public static function setBudget(int $departmentId, int $year, float $amount, string $notes = ''): void
    {
        Db::run(
            'INSERT INTO budgets (department_id, year, amount, notes) VALUES (?, ?, ?, ?)
             ON CONFLICT(department_id, year) DO UPDATE SET amount = excluded.amount, notes = excluded.notes',
            [$departmentId, $year, $amount, $notes]
        );
    }

    public static function deleteBudget(int $id): void
    {
        Db::run('DELETE FROM budgets WHERE id = ?', [$id]);
    }

    // ---------- Notes de frais ----------

    public static function claimsFor(int $employeeId, int $limit = 100): array
    {
        return Db::all('SELECT * FROM expense_claims WHERE employee_id = ? ORDER BY spent_on DESC LIMIT ?', [$employeeId, $limit]);
    }

    public static function allClaims(?string $status = null): array
    {
        $where = $status === null ? '' : 'WHERE e.status = ?';
        return Db::all(
            "SELECT e.*, u.first_name, u.last_name, d.name AS department_name
             FROM expense_claims e
             JOIN users u ON u.id = e.employee_id
             LEFT JOIN departments d ON d.id = u.department_id
             $where
             ORDER BY e.created_at DESC",
            $status === null ? [] : [$status]
        );
    }

    public static function createClaim(int $employeeId, string $spentOn, string $category, string $description, float $amount): int
    {
        return Db::insert(
            'INSERT INTO expense_claims (employee_id, spent_on, category, description, amount) VALUES (?, ?, ?, ?, ?)',
            [$employeeId, $spentOn, $category, $description, $amount]
        );
    }

    public static function claimById(int $id): ?array
    {
        return Db::get('SELECT * FROM expense_claims WHERE id = ?', [$id]);
    }

    /** Un salarié ne retire que sa note, et seulement tant qu'elle est en attente. */
    public static function cancelOwnClaim(int $id, int $employeeId): bool
    {
        return Db::run(
            "DELETE FROM expense_claims WHERE id = ? AND employee_id = ? AND status = 'En attente'",
            [$id, $employeeId]
        ) > 0;
    }

    public static function reviewClaim(int $id, string $status, int $reviewerId, string $note = ''): array
    {
        if (!in_array($status, self::EXPENSE_STATUSES, true)) {
            return ['ok' => false, 'reason' => 'bad-status'];
        }
        $claim = self::claimById($id);
        if ($claim === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        // Un remboursement suppose une note approuvée : on ne rembourse pas ce
        // qui n'a pas été validé.
        if ($status === 'Remboursée' && $claim['status'] !== 'Approuvée') {
            return ['ok' => false, 'reason' => 'not-approved'];
        }
        if ($status !== 'Remboursée' && $claim['status'] !== 'En attente') {
            return ['ok' => false, 'reason' => 'not-pending'];
        }
        Db::run(
            'UPDATE expense_claims SET status = ?, reviewed_by = ?, review_note = ?, reviewed_at = ?, reimbursed_at = ?
             WHERE id = ?',
            [
                $status, $reviewerId, $note !== '' ? $note : $claim['review_note'], gmdate('c'),
                $status === 'Remboursée' ? gmdate('c') : null, $id,
            ]
        );
        Audit::log('note_de_frais.decidee', 'expense_claims', $id, ['statut' => $status]);
        return ['ok' => true];
    }
}
