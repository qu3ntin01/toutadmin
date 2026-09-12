<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Finance;
use App\Modules\Inventory;
use App\Modules\Org;
use App\Modules\Purchasing;
use App\Modules\Users;

/**
 * Stock, demandes d'achat et bons de commande.
 *
 * Module optionnel ouvert à tout le monde : chacun demande ce dont il a besoin,
 * son manager arbitre, la gestion tranche au-delà du seuil. Les articles, les
 * mouvements et les bons de commande, eux, restent à la gestion.
 */
final class StockController
{
    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/stock#' . $anchor);
    }

    private static function toOrder(int $orderId, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect("/stock/commandes/$orderId");
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function isFinance(?array $user = null): bool
    {
        return FinanceController::canAccess($user ?? self::user());
    }

    private static function amount(string $raw, float $fallback = 0.0): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        if ($value === '') {
            return $fallback;
        }
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    /** Les demandes qu'un manager doit arbitrer : celles de ses collaborateurs. */
    private static function requestsForManager(int $userId): array
    {
        $managed = array_map(static fn (array $m): int => (int) $m['id'], Org::membersManagedBy($userId));
        return array_values(array_filter(
            Inventory::requests('Manager'),
            static fn (array $r): bool => in_array((int) $r['requester_id'], $managed, true)
        ));
    }

    public static function index(Request $request): Response
    {
        $user = self::user();
        $isFinance = self::isFinance($user);
        $managerRequests = self::requestsForManager((int) $user['id']);
        $financeRequests = $isFinance ? Inventory::requests('Gestion') : [];
        $lowStock = array_values(array_filter(Inventory::items(true), static fn (array $i): bool => $i['below']));

        return Response::html(View::page('stock/index', [
            'title' => t('nav.stock') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.stock'),
            'headerTitle' => t('nav.stock'),
            'headerSubtitle' => t('stk.stockIsSum'),
            'navItems' => array_values(array_filter([
                ['tab' => 'demandes', 'label' => t('stk.tabRequests'),
                 'badge' => (count($managerRequests) + count($financeRequests)) ?: null],
                $isFinance ? ['tab' => 'articles', 'label' => t('stk.tabItems'), 'badge' => count($lowStock) ?: null] : null,
                $isFinance ? ['tab' => 'mouvements', 'label' => t('common.movements')] : null,
                $isFinance ? ['tab' => 'commandes', 'label' => t('ach.tabOrders')] : null,
            ])),
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'isFinance' => $isFinance,
            'items' => Inventory::items(),
            'movementKinds' => Inventory::MOVEMENT_KINDS,
            'movements' => Inventory::movements(),
            'stockValue' => Inventory::stockValue(),
            'lowStock' => $lowStock,
            'partners' => Finance::partners(),
            'departments' => Org::departments(),
            'myRequests' => Inventory::requestsFor((int) $user['id']),
            'managerRequests' => $managerRequests,
            'financeRequests' => $financeRequests,
            'allRequests' => $isFinance ? Inventory::requests() : [],
            'threshold' => Inventory::FINANCE_THRESHOLD,
            'orderList' => $isFinance ? Purchasing::orders() : [],
            'purchaseSummary' => $isFinance ? Purchasing::summary() : null,
            'discrepancies' => $isFinance ? Purchasing::discrepancies() : [],
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Bons de commande : le rapprochement à trois vit ici ----------

    public static function showOrder(Request $request, array $params): Response
    {
        $order = Purchasing::orderById((int) $params['id']);
        if ($order === null) {
            return Response::html(View::page('error', [
                'title' => t('err.notAccessible'),
                'message' => 'Bon de commande introuvable.',
            ]), 404);
        }

        return Response::html(View::page('stock/order', [
            'title' => $order['reference'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.stock'),
            'headerTitle' => $order['reference'],
            'headerSubtitle' => $order['partner_name'],
            'navItems' => [['href' => '/stock', 'label' => t('nav.stock')]],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/confirm.js'],
            'order' => $order,
            'lineList' => Purchasing::lines((int) $order['id']),
            'receiptList' => Purchasing::receipts((int) $order['id']),
            'invoiceList' => Purchasing::invoicesOf((int) $order['id']),
            'reconciliation' => Purchasing::match($order),
            'attachable' => Purchasing::attachableInvoices($order),
            'statuses' => Purchasing::ORDER_STATUSES,
            'partners' => Finance::partners(),
            'departments' => Org::departments(),
            'items' => Inventory::items(true),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function createOrder(Request $request): Response
    {
        $partnerId = (int) $request->input('partner_id');
        if (Finance::partnerById($partnerId) === null) {
            return self::back('commandes', 'error', 'Fournisseur introuvable.');
        }
        $ordered = $request->input('ordered_on');
        $expected = $request->input('expected_on');
        if (!Validate::date($ordered) || ($expected !== '' && !Validate::date($expected))) {
            return self::back('commandes', 'error', 'Date invalide.');
        }

        $id = Purchasing::createOrder([
            'partnerId' => $partnerId,
            'departmentId' => (int) $request->input('department_id') ?: null,
            'orderedOn' => $ordered,
            'expectedOn' => $expected ?: null,
            'notes' => mb_substr($request->input('notes'), 0, 1000),
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        Audit::log('achats.commande_creee', 'purchase_orders', $id);
        return self::toOrder($id, 'success', 'Bon de commande créé. Ajoutez ses lignes, puis envoyez-le.');
    }

    public static function updateOrder(Request $request, array $params): Response
    {
        $order = Purchasing::orderById((int) $params['id']);
        if ($order === null) {
            return self::back('commandes', 'error', 'Bon de commande introuvable.');
        }
        $status = $request->input('status');
        if (!in_array($status, Purchasing::ORDER_STATUSES, true)) {
            return self::toOrder((int) $order['id'], 'error', 'Statut invalide.');
        }
        $ordered = $request->input('ordered_on');
        $expected = $request->input('expected_on');
        if (!Validate::date($ordered) || ($expected !== '' && !Validate::date($expected))) {
            return self::toOrder((int) $order['id'], 'error', 'Date invalide.');
        }

        Purchasing::updateOrder((int) $order['id'], [
            'partnerId' => (int) $request->input('partner_id') ?: (int) $order['partner_id'],
            'departmentId' => (int) $request->input('department_id') ?: null,
            'orderedOn' => $ordered,
            'expectedOn' => $expected ?: null,
            'notes' => mb_substr($request->input('notes'), 0, 1000),
            'status' => $status,
        ]);
        Purchasing::syncOrderStatus((int) $order['id']);
        return self::toOrder((int) $order['id'], 'success', 'Bon de commande mis à jour.');
    }

    public static function deleteOrder(Request $request, array $params): Response
    {
        $order = Purchasing::orderById((int) $params['id']);
        if ($order === null) {
            return self::back('commandes', 'error', 'Bon de commande introuvable.');
        }
        // Une commande déjà réceptionnée a laissé des mouvements de stock : la
        // supprimer laisserait ces entrées sans origine.
        if ((float) $order['received_amount'] > 0) {
            return self::back('commandes', 'error',
                'Cette commande a été réceptionnée : elle ne peut plus être supprimée.');
        }
        Purchasing::removeOrder((int) $order['id']);
        Audit::log('achats.commande_supprimee', 'purchase_orders', (int) $order['id'], ['reference' => $order['reference']]);
        return self::back('commandes', 'success', 'Bon de commande supprimé.');
    }

    public static function addLine(Request $request, array $params): Response
    {
        $order = Purchasing::orderById((int) $params['id']);
        if ($order === null) {
            return self::back('commandes', 'error', 'Bon de commande introuvable.');
        }
        $label = mb_substr($request->input('label'), 0, 160);
        $quantity = self::amount($request->input('quantity'), 0.0);
        $unitPrice = self::amount($request->input('unit_price'), 0.0);

        if ($label === '') {
            return self::toOrder((int) $order['id'], 'error', "L'intitulé de la ligne est obligatoire.");
        }
        if ($quantity === null || $quantity <= 0) {
            return self::toOrder((int) $order['id'], 'error', 'Quantité invalide.');
        }
        if ($unitPrice === null || $unitPrice < 0) {
            return self::toOrder((int) $order['id'], 'error', 'Prix unitaire invalide.');
        }

        Purchasing::addLine([
            'orderId' => (int) $order['id'],
            'itemId' => (int) $request->input('item_id') ?: null,
            'label' => $label,
            'quantity' => $quantity,
            'unitPrice' => $unitPrice,
        ]);
        return self::toOrder((int) $order['id'], 'success', 'Ligne ajoutée.');
    }

    public static function deleteLine(Request $request, array $params): Response
    {
        $line = Db::get('SELECT * FROM purchase_order_lines WHERE id = ?', [(int) $params['id']]);
        if ($line === null) {
            return self::back('commandes', 'error', 'Ligne introuvable.');
        }
        return Purchasing::removeLine((int) $line['id'])
            ? self::toOrder((int) $line['order_id'], 'success', 'Ligne retirée.')
            : self::toOrder((int) $line['order_id'], 'error',
                'Cette ligne a déjà été réceptionnée : elle ne se retire plus.');
    }

    public static function receiveLine(Request $request, array $params): Response
    {
        $line = Db::get('SELECT * FROM purchase_order_lines WHERE id = ?', [(int) $params['id']]);
        if ($line === null) {
            return self::back('commandes', 'error', 'Ligne introuvable.');
        }
        $orderId = (int) $line['order_id'];
        $quantity = self::amount($request->input('quantity'), 0.0);
        $on = $request->input('received_on');

        if ($quantity === null || $quantity <= 0) {
            return self::toOrder($orderId, 'error', 'Quantité invalide.');
        }
        if (!Validate::date($on)) {
            return self::toOrder($orderId, 'error', 'Date invalide.');
        }

        $result = Purchasing::receive([
            'lineId' => (int) $line['id'], 'quantity' => $quantity, 'receivedOn' => $on,
            'receivedBy' => (int) Session::get('user')['id'], 'note' => $request->input('note'),
        ]);
        if (!$result['ok']) {
            return self::toOrder($orderId, 'error', $result['reason'] === 'depassement'
                ? 'Il ne reste que ' . $result['remaining'] . ' à recevoir sur cette ligne.'
                : 'Réception impossible.');
        }
        Audit::log('achats.reception', 'purchase_order_lines', (int) $line['id'], ['quantite' => $quantity]);
        return self::toOrder($orderId, 'success', 'Réception enregistrée.');
    }

    /**
     * Rattacher une facture fournisseur à sa commande : sans ce lien, le
     * rapprochement à trois n'a rien à comparer.
     */
    public static function attachInvoice(Request $request, array $params): Response
    {
        $order = Purchasing::orderById((int) $params['id']);
        if ($order === null) {
            return self::back('commandes', 'error', 'Bon de commande introuvable.');
        }
        $invoiceId = (int) $request->input('invoice_id');
        $result = Purchasing::attachInvoice((int) $order['id'], $invoiceId);
        if (!$result['ok']) {
            $messages = [
                'introuvable' => 'Facture introuvable.',
                'sens' => "Un bon de commande ne se rattache qu'à une facture fournisseur.",
                'deja_rattachee' => 'Cette facture est déjà rattachée à un autre bon de commande.',
                'tiers' => "Cette facture vient d'un autre fournisseur que la commande.",
            ];
            return self::toOrder((int) $order['id'], 'error', $messages[$result['reason']] ?? 'Rattachement impossible.');
        }
        Audit::log('achats.facture_rattachee', 'invoices', $invoiceId, ['commande' => $order['reference']]);
        return self::toOrder((int) $order['id'], 'success', 'Facture rattachée : le rapprochement la prend en compte.');
    }

    public static function detachInvoice(Request $request, array $params): Response
    {
        $invoice = Db::get('SELECT * FROM invoices WHERE id = ?', [(int) $params['id']]);
        if ($invoice === null || empty($invoice['purchase_order_id'])) {
            return self::back('commandes', 'error', 'Facture introuvable.');
        }
        $orderId = (int) $invoice['purchase_order_id'];
        Purchasing::detachInvoice((int) $invoice['id']);
        Audit::log('achats.facture_detachee', 'invoices', (int) $invoice['id']);
        return self::toOrder($orderId, 'success', 'Facture détachée.');
    }

    // ---------- Articles et mouvements : réservés à la gestion ----------

    public static function createItem(Request $request): Response
    {
        $label = mb_substr($request->input('label'), 0, 160);
        $stockMin = self::amount($request->input('stock_min'), 0.0);
        $rawPrice = $request->input('unit_price');
        $unitPrice = $rawPrice === '' ? null : self::amount($rawPrice);

        if ($label === '') {
            return self::back('articles', 'error', "L'intitulé de l'article est obligatoire.");
        }
        if ($stockMin === null || $stockMin < 0) {
            return self::back('articles', 'error', "Seuil d'alerte invalide.");
        }
        if ($unitPrice !== null && $unitPrice < 0) {
            return self::back('articles', 'error', 'Prix unitaire invalide.');
        }

        Inventory::createItem([
            'label' => $label, 'stockMin' => $stockMin, 'unitPrice' => $unitPrice,
            'reference' => mb_substr($request->input('reference'), 0, 60),
            'unit' => mb_substr($request->input('unit', 'unité') ?: 'unité', 0, 20),
            'category' => mb_substr($request->input('category'), 0, 80),
            'partnerId' => (int) $request->input('partner_id') ?: null,
        ]);
        return self::back('articles', 'success', 'Article créé.');
    }

    public static function toggleItem(Request $request, array $params): Response
    {
        if (!Inventory::toggleItem((int) $params['id'])) {
            return self::back('articles', 'error', 'Article introuvable.');
        }
        return self::back('articles', 'success', 'Article mis à jour.');
    }

    public static function deleteItem(Request $request, array $params): Response
    {
        Inventory::deleteItem((int) $params['id']);
        return self::back('articles', 'success', 'Article supprimé, avec ses mouvements.');
    }

    public static function move(Request $request): Response
    {
        $movedOn = $request->input('moved_on');
        if ($movedOn !== '' && !Validate::date($movedOn)) {
            return self::back('mouvements', 'error', 'Date de mouvement invalide.');
        }
        $quantity = self::amount($request->input('quantity'), 0.0);
        if ($quantity === null) {
            return self::back('mouvements', 'error', 'Quantité invalide.');
        }

        $result = Inventory::move([
            'itemId' => (int) $request->input('item_id'),
            'kind' => $request->input('kind'),
            'quantity' => $quantity,
            'reason' => mb_substr($request->input('reason'), 0, 200),
            'movedOn' => $movedOn ?: null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$result['ok']) {
            if ($result['reason'] === 'insufficient') {
                return self::back('mouvements', 'error', 'Stock insuffisant : il reste ' . $result['stock'] . '.');
            }
            $messages = [
                'bad-kind' => 'Type de mouvement invalide.',
                'not-found' => 'Article introuvable.',
                'bad-quantity' => 'Quantité invalide.',
            ];
            return self::back('mouvements', 'error', $messages[$result['reason']] ?? 'Mouvement impossible.');
        }
        return self::back('mouvements', 'success', 'Mouvement enregistré.');
    }

    // ---------- Demandes d'achat ----------

    public static function createRequest(Request $request): Response
    {
        $user = self::user();
        $label = mb_substr($request->input('label'), 0, 160);
        $quantity = self::amount($request->input('quantity'), 1.0);
        $estimated = self::amount($request->input('estimated_amount'), 0.0);

        if ($label === '') {
            return self::back('demandes', 'error', "L'intitulé de la demande est obligatoire.");
        }
        if ($quantity === null) {
            return self::back('demandes', 'error', 'Quantité invalide.');
        }
        if ($estimated === null) {
            return self::back('demandes', 'error', 'Montant estimé invalide.');
        }

        $result = Inventory::createRequest([
            'requesterId' => (int) $user['id'],
            'itemId' => (int) $request->input('item_id') ?: null,
            'label' => $label,
            'quantity' => $quantity,
            'estimatedAmount' => $estimated,
            'departmentId' => $user['department_id'] ?? null,
            'justification' => mb_substr($request->input('justification'), 0, 1000),
        ]);
        if (!$result['ok']) {
            $messages = ['bad-quantity' => 'Quantité invalide.', 'bad-amount' => 'Montant estimé invalide.'];
            return self::back('demandes', 'error', $messages[$result['reason']] ?? 'Demande impossible.');
        }

        return self::back('demandes', 'success', $estimated > Inventory::FINANCE_THRESHOLD
            ? 'Demande envoyée : validation du manager puis de la gestion (au-delà de '
              . Inventory::FINANCE_THRESHOLD . ').'
            : 'Demande envoyée à votre manager.');
    }

    public static function cancelRequest(Request $request, array $params): Response
    {
        if (!Inventory::cancelOwnRequest((int) $params['id'], (int) Session::get('user')['id'])) {
            return self::back('demandes', 'error', "Cette demande n'est plus annulable.");
        }
        return self::back('demandes', 'success', 'Demande annulée.');
    }

    /** Premier niveau : le manager du demandeur, et lui seul. */
    public static function managerDecision(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $userId = (int) Session::get('user')['id'];
        $mine = array_map(static fn (array $r): int => (int) $r['id'], self::requestsForManager($userId));
        if (!in_array($id, $mine, true)) {
            return self::back('demandes', 'error', 'Cette demande ne relève pas de vos collaborateurs.');
        }

        $result = Inventory::managerDecision(
            $id,
            $request->input('decision') === 'approuver',
            $userId,
            mb_substr($request->input('review_note'), 0, 500)
        );
        if (!$result['ok']) {
            return self::back('demandes', 'error', "Cette demande n'attend plus votre arbitrage.");
        }
        return self::back('demandes', 'success', $result['status'] === 'Gestion'
            ? 'Accord donné : la demande passe à la gestion pour validation finale.'
            : 'Demande ' . mb_strtolower($result['status']) . '.');
    }

    /** Second niveau : la gestion, au-delà du seuil. */
    public static function financeDecision(Request $request, array $params): Response
    {
        $result = Inventory::financeDecision(
            (int) $params['id'],
            $request->input('decision') === 'approuver',
            (int) Session::get('user')['id'],
            mb_substr($request->input('review_note'), 0, 500)
        );
        if (!$result['ok']) {
            return self::back('demandes', 'error', "Cette demande n'attend pas la gestion.");
        }
        return self::back('demandes', 'success', 'Décision enregistrée.');
    }

    public static function markOrdered(Request $request, array $params): Response
    {
        $result = Inventory::markOrdered((int) $params['id'], (int) Session::get('user')['id']);
        if (!$result['ok']) {
            $messages = ['not-found' => 'Demande introuvable.', 'not-approved' => "Cette demande n'est pas approuvée."];
            return self::back('demandes', 'error', $messages[$result['reason']] ?? 'Commande impossible.');
        }
        return self::back('demandes', 'success', $result['stocked']
            ? "Demande commandée : l'article est entré en stock."
            : 'Demande commandée.');
    }
}
