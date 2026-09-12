<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Relation client : contacts, affaires, devis et relances.
 *
 * Le pipeline n'est pas une promesse : c'est un montant en jeu, pondéré par la
 * probabilité que chacun lui accorde. Un devis accepté devient une facture —
 * une seule fois — et c'est là que le commercial rejoint la gestion.
 */
final class Crm
{
    public const STAGES = ['Qualification', 'Proposition', 'Négociation', 'Gagnée', 'Perdue'];
    public const OPEN_STAGES = ['Qualification', 'Proposition', 'Négociation'];
    public const QUOTE_STATUSES = ['Brouillon', 'Envoyé', 'Accepté', 'Refusé', 'Expiré'];
    public const ACTIVITY_KINDS = ['Relance', 'Appel', 'Rendez-vous', 'Email', 'Autre'];

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    private static function partnerExists(int $partnerId): bool
    {
        return Db::get('SELECT 1 AS ok FROM partners WHERE id = ?', [$partnerId]) !== null;
    }

    // ---------- Contacts ----------

    public static function contacts(?int $partnerId = null): array
    {
        $where = $partnerId === null ? '' : 'WHERE c.partner_id = ?';
        return Db::all(
            "SELECT c.*, p.name AS partner_name
             FROM crm_contacts c JOIN partners p ON p.id = c.partner_id
             $where
             ORDER BY p.name COLLATE NOCASE, c.last_name COLLATE NOCASE",
            $partnerId === null ? [] : [$partnerId]
        );
    }

    public static function createContact(array $data): array
    {
        if (!self::partnerExists((int) $data['partnerId'])) {
            return ['ok' => false, 'reason' => 'no-partner'];
        }
        Db::run(
            'INSERT INTO crm_contacts (partner_id, first_name, last_name, role, email, phone, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['partnerId'], $data['firstName'], $data['lastName'], $data['role'] ?? '',
                $data['email'] ?? '', $data['phone'] ?? '', $data['notes'] ?? '',
            ]
        );
        return ['ok' => true];
    }

    public static function deleteContact(int $id): void
    {
        Db::run('DELETE FROM crm_contacts WHERE id = ?', [$id]);
    }

    // ---------- Opportunités ----------

    public static function opportunities(bool $openOnly = false): array
    {
        $where = $openOnly
            ? 'WHERE o.stage IN (' . implode(',', array_fill(0, count(self::OPEN_STAGES), '?')) . ')'
            : '';
        return Db::all(
            "SELECT o.*, p.name AS partner_name, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                (SELECT COUNT(*) FROM quotes q WHERE q.opportunity_id = o.id) AS quote_count
             FROM opportunities o
             JOIN partners p ON p.id = o.partner_id
             LEFT JOIN users u ON u.id = o.owner_id
             $where
             ORDER BY COALESCE(o.expected_close, '9999-12-31'), o.id DESC",
            $openOnly ? self::OPEN_STAGES : []
        );
    }

    public static function opportunityById(int $id): ?array
    {
        return Db::get('SELECT * FROM opportunities WHERE id = ?', [$id]);
    }

    public static function createOpportunity(array $data): array
    {
        if (!self::partnerExists((int) $data['partnerId'])) {
            return ['ok' => false, 'reason' => 'no-partner'];
        }
        $amount = $data['amount'];
        if (!is_numeric($amount) || (float) $amount < 0) {
            return ['ok' => false, 'reason' => 'bad-amount'];
        }
        $probability = $data['probability'];
        if (!is_int($probability) || $probability < 0 || $probability > 100) {
            return ['ok' => false, 'reason' => 'bad-probability'];
        }

        Db::run(
            'INSERT INTO opportunities (partner_id, title, amount, probability, expected_close, owner_id, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['partnerId'], $data['title'], round((float) $amount, 2), $probability,
                $data['expectedClose'] ?? null, $data['ownerId'] ?? null, $data['notes'] ?? '',
            ]
        );
        return ['ok' => true];
    }

    /** Gagner ou perdre une affaire la ferme et date sa clôture. */
    public static function setStage(int $id, string $stage): array
    {
        if (!in_array($stage, self::STAGES, true)) {
            return ['ok' => false, 'reason' => 'bad-stage'];
        }
        $opportunity = self::opportunityById($id);
        if ($opportunity === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }

        $closed = in_array($stage, ['Gagnée', 'Perdue'], true);
        $probability = match ($stage) {
            'Gagnée' => 100,
            'Perdue' => 0,
            default => (int) $opportunity['probability'],
        };
        Db::run(
            'UPDATE opportunities SET stage = ?, probability = ?, closed_at = ? WHERE id = ?',
            [$stage, $probability, $closed ? gmdate('c') : null, $id]
        );
        return ['ok' => true];
    }

    public static function deleteOpportunity(int $id): void
    {
        Db::run('DELETE FROM opportunities WHERE id = ?', [$id]);
    }

    /** Le pipeline : le montant en jeu et sa pondération par la probabilité. */
    public static function pipeline(): array
    {
        $open = self::opportunities(true);
        $byStage = [];
        foreach (self::OPEN_STAGES as $stage) {
            $rows = array_filter($open, static fn (array $row): bool => $row['stage'] === $stage);
            $byStage[] = [
                'stage' => $stage,
                'count' => count($rows),
                'amount' => round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows)), 2),
            ];
        }

        $all = self::opportunities();
        $won = array_filter($all, static fn (array $row): bool => $row['stage'] === 'Gagnée');
        $lost = array_filter($all, static fn (array $row): bool => $row['stage'] === 'Perdue');
        $decided = count($won) + count($lost);

        $weighted = 0.0;
        foreach ($open as $row) {
            $weighted += (float) $row['amount'] * ((int) $row['probability'] / 100);
        }

        return [
            'byStage' => $byStage,
            'total' => round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $open)), 2),
            'weighted' => round($weighted, 2),
            'won' => round(array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $won)), 2),
            'wonCount' => count($won),
            'lostCount' => count($lost),
            'winRate' => $decided > 0 ? round(count($won) / $decided * 100, 1) : 0.0,
        ];
    }

    // ---------- Devis ----------

    public static function quotes(): array
    {
        $today = self::today();
        return array_map(static function (array $quote) use ($today): array {
            $quote['amount_ttc'] = round((float) $quote['amount_ht'] * (1 + (float) $quote['vat_rate'] / 100), 2);
            $quote['expired'] = $quote['status'] === 'Envoyé'
                && !empty($quote['valid_until']) && $quote['valid_until'] < $today;
            return $quote;
        }, Db::all(
            'SELECT q.*, p.name AS partner_name, o.title AS opportunity_title
             FROM quotes q
             JOIN partners p ON p.id = q.partner_id
             LEFT JOIN opportunities o ON o.id = q.opportunity_id
             ORDER BY q.issue_date DESC, q.id DESC'
        ));
    }

    public static function quoteById(int $id): ?array
    {
        return Db::get('SELECT * FROM quotes WHERE id = ?', [$id]);
    }

    public static function createQuote(array $data): array
    {
        if (!self::partnerExists((int) $data['partnerId'])) {
            return ['ok' => false, 'reason' => 'no-partner'];
        }
        if (!is_numeric($data['amountHt']) || (float) $data['amountHt'] < 0) {
            return ['ok' => false, 'reason' => 'bad-amount'];
        }
        if (!is_numeric($data['vatRate']) || (float) $data['vatRate'] < 0 || (float) $data['vatRate'] > 100) {
            return ['ok' => false, 'reason' => 'bad-vat'];
        }
        if (!empty($data['validUntil']) && $data['validUntil'] < $data['issueDate']) {
            return ['ok' => false, 'reason' => 'bad-validity'];
        }

        Db::run(
            'INSERT INTO quotes (partner_id, opportunity_id, reference, label, issue_date, valid_until,
                                 amount_ht, vat_rate, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['partnerId'], $data['opportunityId'] ?? null, $data['reference'] ?? '',
                $data['label'], $data['issueDate'], $data['validUntil'] ?? null,
                round((float) $data['amountHt'], 2), (float) $data['vatRate'],
                $data['status'] ?? 'Brouillon', $data['createdBy'] ?? null,
            ]
        );
        return ['ok' => true];
    }

    public static function setQuoteStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::QUOTE_STATUSES, true)) {
            return false;
        }
        return Db::run('UPDATE quotes SET status = ? WHERE id = ?', [$status, $id]) > 0;
    }

    public static function deleteQuote(int $id): void
    {
        Db::run('DELETE FROM quotes WHERE id = ?', [$id]);
    }

    /**
     * Un devis accepté devient une facture client : c'est le point de jonction
     * entre le commercial et la gestion. Un devis déjà facturé ne l'est pas deux fois.
     */
    public static function convertToInvoice(int $id, ?int $createdBy): array
    {
        $quote = self::quoteById($id);
        if ($quote === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($quote['status'] !== 'Accepté') {
            return ['ok' => false, 'reason' => 'not-accepted'];
        }
        if ($quote['invoice_id'] !== null) {
            return ['ok' => false, 'reason' => 'already-invoiced'];
        }

        $invoiceId = Db::transaction(static function () use ($quote, $createdBy, $id): int {
            $invoiceId = Db::insert(
                "INSERT INTO invoices (direction, partner_id, reference, label, issue_date, due_date,
                                       amount_ht, vat_rate, status, notes, created_by, currency, exchange_rate)
                 VALUES ('Client', ?, ?, ?, date('now'), date('now', '+30 days'), ?, ?, 'Émise', ?, ?, ?, 1)",
                [
                    (int) $quote['partner_id'], $quote['reference'], $quote['label'],
                    $quote['amount_ht'], $quote['vat_rate'],
                    'Issue du devis ' . ($quote['reference'] !== '' ? $quote['reference'] : '#' . (int) $quote['id']),
                    $createdBy,
                    // Un devis se chiffre dans la devise de tenue des comptes : la
                    // facture qui en sort n'a donc pas de conversion à faire.
                    Currency::base(),
                ]
            );
            Db::run('UPDATE quotes SET invoice_id = ? WHERE id = ?', [$invoiceId, $id]);
            return $invoiceId;
        });

        return ['ok' => true, 'invoiceId' => $invoiceId];
    }

    // ---------- Relances ----------

    public static function activities(bool $pendingOnly = false): array
    {
        $today = self::today();
        $where = $pendingOnly ? 'WHERE a.done_at IS NULL' : '';

        return array_map(static function (array $activity) use ($today): array {
            $activity['overdue'] = $activity['done_at'] === null && $activity['due_on'] < $today;
            return $activity;
        }, Db::all(
            "SELECT a.*, p.name AS partner_name, o.title AS opportunity_title,
                    u.first_name AS owner_first_name, u.last_name AS owner_last_name
             FROM crm_activities a
             LEFT JOIN partners p ON p.id = a.partner_id
             LEFT JOIN opportunities o ON o.id = a.opportunity_id
             LEFT JOIN users u ON u.id = a.owner_id
             $where
             ORDER BY a.due_on, a.id"
        ));
    }

    public static function createActivity(array $data): array
    {
        if (empty($data['partnerId']) && empty($data['opportunityId'])) {
            return ['ok' => false, 'reason' => 'no-target'];
        }
        Db::run(
            'INSERT INTO crm_activities (partner_id, opportunity_id, kind, due_on, note, owner_id)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['partnerId'] ?? null, $data['opportunityId'] ?? null, $data['kind'] ?? 'Relance',
                $data['dueOn'], $data['note'] ?? '', $data['ownerId'] ?? null,
            ]
        );
        return ['ok' => true];
    }

    public static function completeActivity(int $id): bool
    {
        return Db::run(
            'UPDATE crm_activities SET done_at = ? WHERE id = ? AND done_at IS NULL',
            [gmdate('c'), $id]
        ) > 0;
    }

    public static function deleteActivity(int $id): void
    {
        Db::run('DELETE FROM crm_activities WHERE id = ?', [$id]);
    }
}
