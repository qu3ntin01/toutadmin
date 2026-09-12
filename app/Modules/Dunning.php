<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Recouvrement : relances échelonnées des factures clients.
 *
 * Le retard seul ne dit pas quoi envoyer. Une facture en retard de deux mois à
 * qui l'on n'a jamais rien écrit appelle un rappel, pas une mise en demeure —
 * et l'inverse, relancer trois fois au même niveau, use la relance sans jamais
 * franchir de palier. Le niveau se déduit donc de ce qui a déjà été envoyé,
 * puis du délai écoulé depuis.
 */
final class Dunning
{
    // Les trois paliers, et le retard à partir duquel chacun se justifie. Le
    // délai du palier suivant se compte depuis la relance précédente, pas depuis
    // l'échéance : c'est le silence qui appelle l'escalade.
    public const LEVELS = [
        ['level' => 1, 'label' => 'Rappel', 'afterDueDays' => 7, 'afterPreviousDays' => 0],
        ['level' => 2, 'label' => 'Relance', 'afterDueDays' => 21, 'afterPreviousDays' => 10],
        ['level' => 3, 'label' => 'Mise en demeure', 'afterDueDays' => 45, 'afterPreviousDays' => 15],
    ];

    // Tranches d'antériorité de la balance âgée.
    public const BUCKETS = [
        ['key' => 'courant', 'from' => -100000, 'to' => 0],
        ['key' => 'j30', 'from' => 1, 'to' => 30],
        ['key' => 'j60', 'from' => 31, 'to' => 60],
        ['key' => 'j90', 'from' => 61, 'to' => 90],
        ['key' => 'plus', 'from' => 91, 'to' => 1000000],
    ];

    private static function today(): string
    {
        return gmdate('Y-m-d');
    }

    public static function daysBetween(string $from, string $to): int
    {
        return (int) floor((strtotime($to . 'T00:00:00Z') - strtotime($from . 'T00:00:00Z')) / 86400);
    }

    /** Les factures clients non réglées, avec leur retard et leur dernière relance. */
    public static function outstanding(): array
    {
        $rows = Db::all(
            "SELECT i.id, i.reference, i.label, i.issue_date, i.due_date, i.amount_ht, i.status,
                    p.id AS partner_id, p.name AS partner_name, p.email AS partner_email,
                    (SELECT MAX(level) FROM dunning_notices n WHERE n.invoice_id = i.id) AS last_level,
                    (SELECT MAX(sent_on) FROM dunning_notices n WHERE n.invoice_id = i.id) AS last_sent
             FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
             WHERE i.direction = 'Client' AND i.status NOT IN ('Payée', 'Annulée', 'Brouillon')
             ORDER BY i.due_date IS NULL, i.due_date"
        );
        $today = self::today();
        return array_map(static function (array $row) use ($today): array {
            $row['overdueDays'] = empty($row['due_date']) ? null : self::daysBetween($row['due_date'], $today);
            return $row;
        }, $rows);
    }

    /**
     * Le palier justifié pour une facture, ou null s'il n'y a rien à envoyer.
     * Rend aussi le motif, pour que l'écran explique au lieu d'ordonner.
     */
    public static function nextLevel(array $invoice, ?string $at = null): ?array
    {
        $at ??= self::today();
        if ($invoice['overdueDays'] === null || $invoice['overdueDays'] <= 0) {
            return null;
        }
        $sentLevel = (int) ($invoice['last_level'] ?? 0);
        if ($sentLevel >= 3) {
            return null;
        }
        $candidate = self::LEVELS[$sentLevel];
        if ($invoice['overdueDays'] < $candidate['afterDueDays']) {
            return null;
        }
        // Le palier suivant demande aussi qu'on ait laissé au client le temps de
        // répondre à la relance précédente.
        if ($sentLevel > 0 && self::daysBetween((string) $invoice['last_sent'], $at) < $candidate['afterPreviousDays']) {
            return null;
        }
        return $candidate;
    }

    /** Ce qui est à relancer aujourd'hui, avec le palier proposé pour chacune. */
    public static function due(): array
    {
        $out = [];
        foreach (self::outstanding() as $invoice) {
            $level = self::nextLevel($invoice);
            if ($level !== null) {
                $out[] = ['invoice' => $invoice, 'level' => $level];
            }
        }
        return $out;
    }

    public static function noticesFor(int $invoiceId): array
    {
        return Db::all(
            'SELECT n.*, u.first_name, u.last_name
             FROM dunning_notices n LEFT JOIN users u ON u.id = n.created_by
             WHERE n.invoice_id = ? ORDER BY n.level, n.sent_on',
            [$invoiceId]
        );
    }

    /**
     * Consigne une relance. Le niveau ne saute pas : passer d'un rappel jamais
     * envoyé à une mise en demeure fragilise juridiquement la mise en demeure
     * elle-même, qui suppose des rappels restés sans effet.
     */
    public static function record(array $fields): array
    {
        $invoiceId = (int) $fields['invoiceId'];
        $invoice = Db::get("SELECT * FROM invoices WHERE id = ? AND direction = 'Client'", [$invoiceId]);
        if ($invoice === null) {
            return ['ok' => false, 'reason' => 'introuvable'];
        }
        if ($invoice['status'] === 'Payée' || $invoice['status'] === 'Annulée') {
            return ['ok' => false, 'reason' => 'reglee'];
        }

        $wanted = (int) $fields['level'];
        if (!in_array($wanted, array_column(self::LEVELS, 'level'), true)) {
            return ['ok' => false, 'reason' => 'niveau'];
        }

        $sent = (int) (Db::value('SELECT MAX(level) FROM dunning_notices WHERE invoice_id = ?', [$invoiceId]) ?? 0);
        if ($wanted > $sent + 1) {
            return ['ok' => false, 'reason' => 'saut', 'expected' => $sent + 1];
        }

        Db::insert(
            'INSERT INTO dunning_notices (invoice_id, level, sent_on, note, created_by) VALUES (?, ?, ?, ?, ?)',
            [$invoiceId, $wanted, $fields['sentOn'], mb_substr((string) ($fields['note'] ?? ''), 0, 500), $fields['createdBy'] ?? null]
        );
        return ['ok' => true];
    }

    public static function remove(int $id): bool
    {
        return Db::run('DELETE FROM dunning_notices WHERE id = ?', [$id]) > 0;
    }

    /** La balance âgée : l'encours client réparti par tranche de retard. */
    public static function agedBalance(): array
    {
        $rows = self::outstanding();
        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[$bucket['key']] = ['count' => 0, 'amount' => 0.0];
        }

        $total = 0.0;
        $overdue = 0.0;
        foreach ($rows as $invoice) {
            $late = $invoice['overdueDays'] ?? 0;
            $key = self::BUCKETS[count(self::BUCKETS) - 1]['key'];
            foreach (self::BUCKETS as $bucket) {
                if ($late >= $bucket['from'] && $late <= $bucket['to']) {
                    $key = $bucket['key'];
                    break;
                }
            }
            $buckets[$key]['count'] += 1;
            $buckets[$key]['amount'] = round($buckets[$key]['amount'] + (float) $invoice['amount_ht'], 2);
            $total += (float) $invoice['amount_ht'];
            if ($late > 0) {
                $overdue += (float) $invoice['amount_ht'];
            }
        }

        return [
            'buckets' => $buckets,
            'total' => round($total, 2),
            'overdue' => round($overdue, 2),
            'count' => count($rows),
        ];
    }

    public static function summary(): array
    {
        $balance = self::agedBalance();
        return [
            'outstanding' => $balance['total'],
            'overdue' => $balance['overdue'],
            'toSend' => count(self::due()),
            'formalNotices' => (int) Db::value('SELECT COUNT(*) FROM dunning_notices WHERE level = 3'),
        ];
    }
}
