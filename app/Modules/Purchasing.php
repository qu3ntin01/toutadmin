<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Achats : bons de commande, réceptions, et rapprochement à trois.
 *
 * Le bon de commande n'est pas la fonction utile — c'est le rapprochement qui
 * l'est. Trois chiffres doivent s'accorder : ce qui a été commandé, ce qui a
 * été effectivement reçu, ce qui est facturé. Quand ils divergent, on paie soit
 * ce qu'on n'a pas commandé, soit ce qui n'est jamais arrivé, et on ne s'en
 * aperçoit qu'à l'inventaire ou au bilan.
 *
 * Le module ne bloque pas le règlement : il n'en a pas le pouvoir, et une
 * livraison partielle facturée d'avance est parfois convenue. Il nomme l'écart
 * au moment où quelqu'un regarde la facture, ce qui suffit à ce qu'on décide.
 */
final class Purchasing
{
    public const ORDER_STATUSES = ['Brouillon', 'Envoyée', 'Reçue partiellement', 'Reçue', 'Annulée'];

    private const ORDER_COLUMNS = "
        o.*, p.name AS partner_name, d.name AS department_name,
        u.first_name, u.last_name,
        (SELECT COALESCE(SUM(l.quantity * l.unit_price), 0) FROM purchase_order_lines l WHERE l.order_id = o.id) AS ordered_amount,
        (SELECT COALESCE(SUM(l.received_quantity * l.unit_price), 0) FROM purchase_order_lines l WHERE l.order_id = o.id) AS received_amount,
        (SELECT COALESCE(SUM(i.amount_ht), 0) FROM invoices i WHERE i.purchase_order_id = o.id AND i.status != 'Annulée') AS invoiced_amount
    ";

    public static function nextReference(): string
    {
        $year = gmdate('Y');
        $count = (int) Db::value('SELECT COUNT(*) FROM purchase_orders WHERE reference LIKE ?', ["BC-$year-%"]);
        return sprintf('BC-%s-%04d', $year, $count + 1);
    }

    // ---------- Commandes ----------

    public static function orders(bool $includeClosed = true): array
    {
        $clause = $includeClosed ? '' : "WHERE o.status NOT IN ('Reçue','Annulée')";
        return Db::all(
            'SELECT ' . self::ORDER_COLUMNS . "
             FROM purchase_orders o
             JOIN partners p ON p.id = o.partner_id
             LEFT JOIN departments d ON d.id = o.department_id
             LEFT JOIN users u ON u.id = o.created_by
             $clause
             ORDER BY o.ordered_on DESC, o.id DESC"
        );
    }

    public static function orderById(int $id): ?array
    {
        return Db::get(
            'SELECT ' . self::ORDER_COLUMNS . '
             FROM purchase_orders o
             JOIN partners p ON p.id = o.partner_id
             LEFT JOIN departments d ON d.id = o.department_id
             LEFT JOIN users u ON u.id = o.created_by
             WHERE o.id = ?',
            [$id]
        );
    }

    public static function lines(int $orderId): array
    {
        return Db::all(
            'SELECT l.*, i.label AS item_name
             FROM purchase_order_lines l LEFT JOIN items i ON i.id = l.item_id
             WHERE l.order_id = ? ORDER BY l.id',
            [$orderId]
        );
    }

    public static function createOrder(array $fields): int
    {
        return Db::insert(
            'INSERT INTO purchase_orders (reference, partner_id, request_id, department_id, ordered_on, expected_on, notes, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::nextReference(), $fields['partnerId'], $fields['requestId'] ?? null,
                $fields['departmentId'] ?? null, $fields['orderedOn'], $fields['expectedOn'] ?? null,
                $fields['notes'] ?? '', $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function updateOrder(int $id, array $fields): void
    {
        Db::run(
            'UPDATE purchase_orders
             SET partner_id = ?, department_id = ?, ordered_on = ?, expected_on = ?, notes = ?, status = ?
             WHERE id = ?',
            [
                $fields['partnerId'], $fields['departmentId'] ?? null, $fields['orderedOn'],
                $fields['expectedOn'] ?? null, $fields['notes'] ?? '', $fields['status'], $id,
            ]
        );
    }

    public static function removeOrder(int $id): void
    {
        Db::run('DELETE FROM purchase_orders WHERE id = ?', [$id]);
    }

    public static function addLine(array $data): int
    {
        return Db::insert(
            'INSERT INTO purchase_order_lines (order_id, item_id, label, quantity, unit_price) VALUES (?, ?, ?, ?, ?)',
            [$data['orderId'], $data['itemId'] ?? null, $data['label'], $data['quantity'], $data['unitPrice']]
        );
    }

    /** Une ligne déjà réceptionnée ne se retire pas : la réception l'a engagée. */
    public static function removeLine(int $id): bool
    {
        $line = Db::get('SELECT * FROM purchase_order_lines WHERE id = ?', [$id]);
        if ($line === null || (float) $line['received_quantity'] > 0) {
            return false;
        }
        Db::run('DELETE FROM purchase_order_lines WHERE id = ?', [$id]);
        return true;
    }

    // ---------- Réceptions ----------

    public static function receipts(int $orderId): array
    {
        return Db::all(
            'SELECT r.*, l.label, u.first_name, u.last_name
             FROM purchase_receipts r
             JOIN purchase_order_lines l ON l.id = r.line_id
             LEFT JOIN users u ON u.id = r.received_by
             WHERE l.order_id = ? ORDER BY r.received_on DESC, r.id DESC',
            [$orderId]
        );
    }

    /**
     * Réceptionne une quantité sur une ligne. Recevoir plus que commandé est
     * refusé : c'est le plus souvent une erreur de saisie, et quand ce n'en est
     * pas une, la commande doit être corrigée pour que le rapprochement garde
     * un sens.
     *
     * Une ligne rattachée à un article entre aussi en stock, dans le même geste :
     * ressaisir la même réception deux fois est le meilleur moyen de ne jamais
     * savoir ce qu'on a.
     */
    public static function receive(array $data): array
    {
        $lineId = (int) $data['lineId'];
        $quantity = round((float) $data['quantity'], 2);
        $line = Db::get('SELECT * FROM purchase_order_lines WHERE id = ?', [$lineId]);
        if ($line === null) {
            return ['ok' => false, 'reason' => 'introuvable'];
        }
        $remaining = round((float) $line['quantity'] - (float) $line['received_quantity'], 2);
        if ($quantity > $remaining) {
            return ['ok' => false, 'reason' => 'depassement', 'remaining' => $remaining];
        }

        Db::transaction(static function () use ($line, $lineId, $quantity, $data): void {
            Db::insert(
                'INSERT INTO purchase_receipts (line_id, quantity, received_on, received_by, note) VALUES (?, ?, ?, ?, ?)',
                [$lineId, $quantity, $data['receivedOn'], $data['receivedBy'] ?? null, mb_substr((string) ($data['note'] ?? ''), 0, 300)]
            );
            Db::run(
                'UPDATE purchase_order_lines SET received_quantity = received_quantity + ? WHERE id = ?',
                [$quantity, $lineId]
            );

            if (!empty($line['item_id'])) {
                $reference = (string) Db::value('SELECT reference FROM purchase_orders WHERE id = ?', [$line['order_id']]);
                Inventory::move([
                    'itemId' => (int) $line['item_id'],
                    'kind' => 'Entrée',
                    'quantity' => $quantity,
                    'reason' => "Réception $reference",
                    'movedOn' => $data['receivedOn'],
                    'createdBy' => $data['receivedBy'] ?? null,
                ]);
            }
            self::syncOrderStatus((int) $line['order_id']);
        });
        return ['ok' => true];
    }

    /** Le statut suit les réceptions : il n'est pas à tenir à la main. */
    public static function syncOrderStatus(int $orderId): void
    {
        $order = Db::get('SELECT status FROM purchase_orders WHERE id = ?', [$orderId]);
        if ($order === null || $order['status'] === 'Annulée' || $order['status'] === 'Brouillon') {
            return;
        }
        $rows = Db::all('SELECT quantity, received_quantity FROM purchase_order_lines WHERE order_id = ?', [$orderId]);
        if ($rows === []) {
            return;
        }

        $complete = true;
        $started = false;
        foreach ($rows as $line) {
            if ((float) $line['received_quantity'] < (float) $line['quantity']) {
                $complete = false;
            }
            if ((float) $line['received_quantity'] > 0) {
                $started = true;
            }
        }
        $status = $complete ? 'Reçue' : ($started ? 'Reçue partiellement' : 'Envoyée');
        Db::run('UPDATE purchase_orders SET status = ? WHERE id = ?', [$status, $orderId]);
    }

    // ---------- Rapprochement ----------

    /**
     * Le rapprochement à trois, rendu sous forme d'écarts nommés plutôt que d'un
     * verdict binaire. Trois situations méritent d'être distinguées :
     *
     * - facturé au-delà du commandé : écart de prix ou ligne ajoutée sans commande ;
     * - facturé au-delà du reçu : on règle ce qui n'est pas encore arrivé ;
     * - reçu au-delà du facturé : la facture reste à venir, ce qui est normal.
     */
    public static function match(array $order): array
    {
        $ordered = round((float) $order['ordered_amount'], 2);
        $received = round((float) $order['received_amount'], 2);
        $invoiced = round((float) $order['invoiced_amount'], 2);

        $issues = [];
        if ($invoiced > $ordered + 0.01) {
            $issues[] = ['kind' => 'sur_commande', 'gap' => round($invoiced - $ordered, 2)];
        }
        if ($invoiced > $received + 0.01) {
            $issues[] = ['kind' => 'sur_reception', 'gap' => round($invoiced - $received, 2)];
        }

        return [
            'ordered' => $ordered,
            'received' => $received,
            'invoiced' => $invoiced,
            'pending' => round($received - $invoiced, 2),
            'issues' => $issues,
            'ok' => $issues === [],
        ];
    }

    public static function invoicesOf(int $orderId): array
    {
        return Db::all(
            'SELECT id, reference, label, issue_date, amount_ht, status
             FROM invoices WHERE purchase_order_id = ? ORDER BY issue_date, id',
            [$orderId]
        );
    }

    /** Les commandes dont la facturation s'écarte de ce qui a été commandé ou reçu. */
    public static function discrepancies(): array
    {
        $out = [];
        foreach (self::orders() as $order) {
            if ($order['status'] === 'Annulée' || (float) $order['invoiced_amount'] <= 0) {
                continue;
            }
            $match = self::match($order);
            if (!$match['ok']) {
                $out[] = ['order' => $order, 'match' => $match];
            }
        }
        return $out;
    }

    public static function summary(): array
    {
        $open = (int) Db::value(
            "SELECT COUNT(*) FROM purchase_orders WHERE status IN ('Envoyée','Reçue partiellement')"
        );
        $engaged = (float) Db::value(
            "SELECT COALESCE(SUM(l.quantity * l.unit_price), 0)
             FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id
             WHERE o.status IN ('Envoyée','Reçue partiellement')"
        );
        $awaited = (float) Db::value(
            "SELECT COALESCE(SUM((l.quantity - l.received_quantity) * l.unit_price), 0)
             FROM purchase_order_lines l JOIN purchase_orders o ON o.id = l.order_id
             WHERE o.status IN ('Envoyée','Reçue partiellement')"
        );
        return [
            'open' => $open,
            'engaged' => round($engaged, 2),
            'awaited' => round($awaited, 2),
            'discrepancies' => count(self::discrepancies()),
        ];
    }
}
