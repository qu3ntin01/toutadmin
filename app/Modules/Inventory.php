<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Stock et demandes d'achat.
 *
 * Le stock n'est pas une colonne : c'est la somme des mouvements depuis le
 * dernier inventaire. Un inventaire repose le compteur, les entrées et les
 * sorties le font varier depuis là — un stock stocké et corrigé à la main finit
 * toujours par mentir.
 *
 * Stock mono-dépôt, valorisé au dernier prix unitaire connu : ni inventaire
 * tournant, ni FIFO, ni CUMP.
 */
final class Inventory
{
    public const MOVEMENT_KINDS = ['Entrée', 'Sortie', 'Inventaire'];
    public const REQUEST_STATUSES = ['Manager', 'Gestion', 'Approuvée', 'Refusée', 'Annulée', 'Commandée'];

    // Au-delà de ce montant, la gestion valide après le manager. En deçà,
    // l'accord du manager suffit : c'est le seuil qui rend l'approbation
    // « à plusieurs niveaux ».
    public const FINANCE_THRESHOLD = 500.0;

    // ---------- Articles et stock ----------

    public static function items(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE i.active = 1' : '';
        $rows = Db::all(
            "SELECT i.*, p.name AS partner_name,
               COALESCE((
                 SELECT SUM(CASE m.kind WHEN 'Entrée' THEN m.quantity WHEN 'Sortie' THEN -m.quantity ELSE 0 END)
                 FROM stock_movements m
                 WHERE m.item_id = i.id AND m.id > COALESCE((
                   SELECT MAX(r.id) FROM stock_movements r WHERE r.item_id = i.id AND r.kind = 'Inventaire'
                 ), 0)
               ), 0)
               + COALESCE((
                 SELECT r.quantity FROM stock_movements r
                 WHERE r.item_id = i.id AND r.kind = 'Inventaire' ORDER BY r.id DESC LIMIT 1
               ), 0) AS stock
             FROM items i
             LEFT JOIN partners p ON p.id = i.partner_id
             $where
             ORDER BY i.category COLLATE NOCASE, i.label COLLATE NOCASE"
        );
        return array_map(static function (array $item): array {
            $item['stock'] = round((float) $item['stock'], 2);
            $item['below'] = $item['stock'] < (float) $item['stock_min'];
            return $item;
        }, $rows);
    }

    public static function itemById(int $id): ?array
    {
        foreach (self::items() as $item) {
            if ((int) $item['id'] === $id) {
                return $item;
            }
        }
        return null;
    }

    public static function createItem(array $data): int
    {
        return Db::insert(
            'INSERT INTO items (reference, label, unit, category, stock_min, unit_price, partner_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['reference'] ?? '', $data['label'], $data['unit'] ?? 'unité', $data['category'] ?? '',
                $data['stockMin'] ?? 0, $data['unitPrice'] ?? null, $data['partnerId'] ?? null,
            ]
        );
    }

    public static function toggleItem(int $id): bool
    {
        $item = Db::get('SELECT * FROM items WHERE id = ?', [$id]);
        if ($item === null) {
            return false;
        }
        Db::run('UPDATE items SET active = ? WHERE id = ?', [(int) $item['active'] === 1 ? 0 : 1, $id]);
        return true;
    }

    public static function deleteItem(int $id): void
    {
        Db::run('DELETE FROM items WHERE id = ?', [$id]);
    }

    public static function move(array $data): array
    {
        $kind = (string) $data['kind'];
        if (!in_array($kind, self::MOVEMENT_KINDS, true)) {
            return ['ok' => false, 'reason' => 'bad-kind'];
        }
        $item = self::itemById((int) $data['itemId']);
        if ($item === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        $quantity = (float) $data['quantity'];
        if ($quantity < 0 || ($kind !== 'Inventaire' && $quantity <= 0)) {
            return ['ok' => false, 'reason' => 'bad-quantity'];
        }
        // Une sortie ne peut pas faire passer le stock sous zéro : on ne sort
        // pas ce qu'on n'a pas.
        if ($kind === 'Sortie' && $quantity > $item['stock']) {
            return ['ok' => false, 'reason' => 'insufficient', 'stock' => $item['stock']];
        }

        Db::insert(
            'INSERT INTO stock_movements (item_id, kind, quantity, reason, moved_on, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $data['itemId'], $kind, round($quantity, 2), $data['reason'] ?? '',
                $data['movedOn'] ?? gmdate('Y-m-d'), $data['createdBy'] ?? null,
            ]
        );
        return ['ok' => true];
    }

    public static function movements(?int $itemId = null, int $limit = 100): array
    {
        $where = $itemId === null ? '' : 'WHERE m.item_id = ?';
        $params = $itemId === null ? [$limit] : [$itemId, $limit];
        return Db::all(
            "SELECT m.*, i.label AS item_label, i.unit, u.first_name, u.last_name
             FROM stock_movements m
             JOIN items i ON i.id = m.item_id
             LEFT JOIN users u ON u.id = m.created_by
             $where
             ORDER BY m.moved_on DESC, m.id DESC
             LIMIT ?",
            $params
        );
    }

    /** Valorisation au dernier prix unitaire connu — la seule que ce module promet. */
    public static function stockValue(): float
    {
        $total = 0.0;
        foreach (self::items(true) as $item) {
            $total += $item['stock'] * (float) ($item['unit_price'] ?? 0);
        }
        return round($total, 2);
    }

    // ---------- Demandes d'achat ----------

    public static function requests(?string $status = null): array
    {
        $where = $status === null ? '' : 'WHERE r.status = ?';
        return Db::all(
            "SELECT r.*, u.first_name, u.last_name, u.team_id, u.department_id,
                    d.name AS department_name, i.label AS item_label
             FROM purchase_requests r
             JOIN users u ON u.id = r.requester_id
             LEFT JOIN departments d ON d.id = r.department_id
             LEFT JOIN items i ON i.id = r.item_id
             $where
             ORDER BY r.created_at DESC",
            $status === null ? [] : [$status]
        );
    }

    public static function requestById(int $id): ?array
    {
        return Db::get('SELECT * FROM purchase_requests WHERE id = ?', [$id]);
    }

    public static function requestsFor(int $employeeId): array
    {
        return Db::all(
            'SELECT r.*, i.label AS item_label FROM purchase_requests r
             LEFT JOIN items i ON i.id = r.item_id
             WHERE r.requester_id = ? ORDER BY r.created_at DESC',
            [$employeeId]
        );
    }

    public static function createRequest(array $data): array
    {
        $quantity = (float) $data['quantity'];
        $amount = (float) $data['estimatedAmount'];
        if ($quantity <= 0) {
            return ['ok' => false, 'reason' => 'bad-quantity'];
        }
        if ($amount < 0) {
            return ['ok' => false, 'reason' => 'bad-amount'];
        }

        Db::insert(
            'INSERT INTO purchase_requests (requester_id, item_id, label, quantity, estimated_amount, department_id, justification)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['requesterId'], $data['itemId'] ?? null, $data['label'], round($quantity, 2),
                round($amount, 2), $data['departmentId'] ?? null, $data['justification'] ?? '',
            ]
        );
        return ['ok' => true];
    }

    /**
     * Validation en deux temps : le manager d'abord, la gestion ensuite si le
     * montant dépasse le seuil. Sous le seuil, l'accord du manager approuve.
     */
    public static function managerDecision(int $id, bool $approve, int $reviewerId, string $note = ''): array
    {
        $request = self::requestById($id);
        if ($request === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($request['status'] !== 'Manager') {
            return ['ok' => false, 'reason' => 'not-pending'];
        }

        $next = $approve
            ? ((float) $request['estimated_amount'] > self::FINANCE_THRESHOLD ? 'Gestion' : 'Approuvée')
            : 'Refusée';
        Db::run(
            'UPDATE purchase_requests SET status = ?, manager_reviewed_by = ?, manager_reviewed_at = ?, review_note = ?
             WHERE id = ?',
            [$next, $reviewerId, gmdate('c'), $note !== '' ? $note : $request['review_note'], $id]
        );
        return ['ok' => true, 'status' => $next];
    }

    public static function financeDecision(int $id, bool $approve, int $reviewerId, string $note = ''): array
    {
        $request = self::requestById($id);
        if ($request === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($request['status'] !== 'Gestion') {
            return ['ok' => false, 'reason' => 'not-pending'];
        }
        Db::run(
            'UPDATE purchase_requests SET status = ?, finance_reviewed_by = ?, finance_reviewed_at = ?, review_note = ?
             WHERE id = ?',
            [$approve ? 'Approuvée' : 'Refusée', $reviewerId, gmdate('c'), $note !== '' ? $note : $request['review_note'], $id]
        );
        return ['ok' => true];
    }

    /** Une demande approuvée passe en commande, ce qui entre l'article en stock. */
    public static function markOrdered(int $id, ?int $createdBy = null): array
    {
        $request = self::requestById($id);
        if ($request === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($request['status'] !== 'Approuvée') {
            return ['ok' => false, 'reason' => 'not-approved'];
        }

        Db::transaction(static function () use ($id, $request, $createdBy): void {
            Db::run("UPDATE purchase_requests SET status = 'Commandée' WHERE id = ?", [$id]);
            if (!empty($request['item_id'])) {
                Db::insert(
                    "INSERT INTO stock_movements (item_id, kind, quantity, reason, created_by) VALUES (?, 'Entrée', ?, ?, ?)",
                    [$request['item_id'], $request['quantity'], "Demande d'achat #$id", $createdBy]
                );
            }
        });
        return ['ok' => true, 'stocked' => !empty($request['item_id'])];
    }

    public static function cancelOwnRequest(int $id, int $requesterId): bool
    {
        return Db::run(
            "UPDATE purchase_requests SET status = 'Annulée' WHERE id = ? AND requester_id = ? AND status = 'Manager'",
            [$id, $requesterId]
        ) > 0;
    }
}
