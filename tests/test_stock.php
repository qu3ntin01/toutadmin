<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Catalogue;
use App\Modules\Finance;
use App\Modules\Inventory;
use App\Modules\Org;
use App\Modules\Purchasing;
use App\Modules\Users;

/** Une instance où le stock est ouvert : un manager, son collaborateur, la gestion. */
function seedStock(): array
{
    $ids = seed();
    Catalogue::setEnabled('stock', true);
    $ids['finance'] = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['finance'], 'is_finance', true);

    $ids['manager'] = Users::create([
        'role' => 'employee', 'email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!',
        'first_name' => 'Paul', 'last_name' => 'Rivière',
    ]);
    $ids['team'] = Org::createTeam('Qualité', null);
    Org::addManager('team', $ids['team'], $ids['manager']);
    Db::run('UPDATE users SET team_id = ? WHERE id = ?', [$ids['team'], $ids['member']]);
    return $ids;
}

Tests::run('le stock est ouvert à tous, ses articles et commandes à la gestion', function (): void {
    seedStock();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/stock')->status, 'un salarié ne peut plus demander un achat');
    assertSame(403, visit('POST', '/stock/articles', ['label' => 'Ramettes'])->status);
    assertSame(403, visit('POST', '/stock/mouvements', ['item_id' => '1', 'kind' => 'Entrée', 'quantity' => '5'])->status);
    assertSame(403, visit('GET', '/stock/commandes/1')->status);
    assertSame(0, count(Inventory::items()));

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', '/stock/articles', ['label' => 'Ramettes A4', 'stock_min' => '10', 'unit_price' => '4,50']);
    assertSame(1, count(Inventory::items()));
});

Tests::run('le stock est la somme des mouvements depuis le dernier inventaire', function (): void {
    $ids = seedStock();
    $item = Inventory::createItem(['label' => 'Ramettes A4', 'stockMin' => 10, 'unitPrice' => 4.5]);

    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 50, 'createdBy' => $ids['admin']]);
    Inventory::move(['itemId' => $item, 'kind' => 'Sortie', 'quantity' => 12, 'createdBy' => $ids['admin']]);
    assertSame(38.0, Inventory::itemById($item)['stock']);

    // Un inventaire repose le compteur ; les mouvements d'avant ne comptent plus.
    Inventory::move(['itemId' => $item, 'kind' => 'Inventaire', 'quantity' => 30, 'createdBy' => $ids['admin']]);
    assertSame(30.0, Inventory::itemById($item)['stock']);
    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 5, 'createdBy' => $ids['admin']]);
    assertSame(35.0, Inventory::itemById($item)['stock']);
    assertSame(157.5, Inventory::stockValue());
});

Tests::run('on ne sort pas ce qu\'on n\'a pas', function (): void {
    $ids = seedStock();
    $item = Inventory::createItem(['label' => 'Ramettes', 'stockMin' => 0]);
    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 3, 'createdBy' => $ids['admin']]);

    $result = Inventory::move(['itemId' => $item, 'kind' => 'Sortie', 'quantity' => 4, 'createdBy' => $ids['admin']]);
    assertTrue(!$result['ok']);
    assertSame('insufficient', $result['reason']);
    assertSame(3.0, Inventory::itemById($item)['stock']);

    assertSame('bad-kind', Inventory::move(['itemId' => $item, 'kind' => 'Perte', 'quantity' => 1])['reason']);
    assertSame('bad-quantity', Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 0])['reason']);
});

Tests::run('le seuil d\'alerte se déduit du stock', function (): void {
    $ids = seedStock();
    $item = Inventory::createItem(['label' => 'Ramettes', 'stockMin' => 10]);
    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 8, 'createdBy' => $ids['admin']]);
    assertTrue(Inventory::itemById($item)['below'], 'un stock sous le seuil n\'est pas signalé');

    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 5, 'createdBy' => $ids['admin']]);
    assertTrue(!Inventory::itemById($item)['below']);
});

Tests::run('sous le seuil, l\'accord du manager suffit', function (): void {
    $ids = seedStock();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/stock/demandes', ['label' => 'Casque audio', 'quantity' => '1', 'estimated_amount' => '120']);
    $demandes = Inventory::requestsFor($ids['member']);
    assertSame(1, count($demandes));
    assertSame('Manager', $demandes[0]['status']);

    visit('POST', '/connexion', ['email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!']);
    visit('POST', '/stock/demandes/' . $demandes[0]['id'] . '/manager', ['decision' => 'approuver']);
    assertSame('Approuvée', Inventory::requestById((int) $demandes[0]['id'])['status']);
});

Tests::run('au-delà du seuil, la gestion tranche après le manager', function (): void {
    $ids = seedStock();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/stock/demandes', ['label' => 'Poste de travail', 'quantity' => '1', 'estimated_amount' => '1500']);
    $id = (int) Inventory::requestsFor($ids['member'])[0]['id'];

    // La gestion ne peut pas trancher avant le manager.
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', "/stock/demandes/$id/gestion", ['decision' => 'approuver']);
    assertSame('Manager', Inventory::requestById($id)['status']);

    visit('POST', '/connexion', ['email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!']);
    visit('POST', "/stock/demandes/$id/manager", ['decision' => 'approuver']);
    assertSame('Gestion', Inventory::requestById($id)['status'], 'au-delà du seuil, le manager ne doit pas approuver seul');

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', "/stock/demandes/$id/gestion", ['decision' => 'approuver']);
    assertSame('Approuvée', Inventory::requestById($id)['status']);
});

Tests::run('un manager n\'arbitre que ses collaborateurs', function (): void {
    $ids = seedStock();
    $etranger = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Inventory::createRequest([
        'requesterId' => $etranger, 'label' => 'Écran', 'quantity' => 1, 'estimatedAmount' => 200,
    ]);
    $id = (int) Inventory::requests('Manager')[0]['id'];

    visit('POST', '/connexion', ['email' => 'manager@entreprise.com', 'password' => 'Manager-Demo-2026!']);
    visit('POST', "/stock/demandes/$id/manager", ['decision' => 'approuver']);
    assertSame('Manager', Inventory::requestById($id)['status'], 'un manager a arbitré hors de son périmètre');
});

Tests::run('une demande commandée entre l\'article en stock', function (): void {
    $ids = seedStock();
    $item = Inventory::createItem(['label' => 'Ramettes A4', 'stockMin' => 0]);
    Inventory::createRequest([
        'requesterId' => $ids['member'], 'itemId' => $item, 'label' => 'Ramettes',
        'quantity' => 20, 'estimatedAmount' => 90,
    ]);
    $id = (int) Inventory::requests('Manager')[0]['id'];

    // Une demande pas encore approuvée ne se commande pas.
    assertSame('not-approved', Inventory::markOrdered($id, $ids['finance'])['reason']);
    Inventory::managerDecision($id, true, $ids['manager']);
    assertSame('Approuvée', Inventory::requestById($id)['status']);

    assertTrue(Inventory::markOrdered($id, $ids['finance'])['ok']);
    assertSame('Commandée', Inventory::requestById($id)['status']);
    assertSame(20.0, Inventory::itemById($item)['stock']);
});

Tests::run('une demande ne s\'annule que par son auteur, tant qu\'elle attend', function (): void {
    $ids = seedStock();
    Inventory::createRequest(['requesterId' => $ids['member'], 'label' => 'Chaise', 'quantity' => 1, 'estimatedAmount' => 200]);
    $id = (int) Inventory::requests('Manager')[0]['id'];

    assertTrue(!Inventory::cancelOwnRequest($id, $ids['manager']), 'un tiers a annulé la demande');
    assertTrue(Inventory::cancelOwnRequest($id, $ids['member']));
    assertSame('Annulée', Inventory::requestById($id)['status']);
    // Annulée, elle ne se ré-annule pas.
    assertTrue(!Inventory::cancelOwnRequest($id, $ids['member']));
});

// ---------- Bons de commande et rapprochement à trois ----------

Tests::run('une réception fait suivre le statut de la commande et entre en stock', function (): void {
    $ids = seedStock();
    $partner = Finance::createPartner(['kind' => 'Fournisseur', 'name' => 'Papeterie Lemoine']);
    $item = Inventory::createItem(['label' => 'Ramettes A4', 'stockMin' => 0]);
    $order = Purchasing::createOrder(['partnerId' => $partner, 'orderedOn' => gmdate('Y-m-d'), 'createdBy' => $ids['finance']]);
    Db::run("UPDATE purchase_orders SET status = 'Envoyée' WHERE id = ?", [$order]);
    $line = Purchasing::addLine(['orderId' => $order, 'itemId' => $item, 'label' => 'Ramettes', 'quantity' => 10, 'unitPrice' => 5]);

    assertSame('BC-' . gmdate('Y') . '-0001', Purchasing::orderById($order)['reference']);

    // Réception partielle.
    assertTrue(Purchasing::receive(['lineId' => $line, 'quantity' => 4, 'receivedOn' => gmdate('Y-m-d'), 'receivedBy' => $ids['finance']])['ok']);
    assertSame('Reçue partiellement', Purchasing::orderById($order)['status']);
    assertSame(4.0, Inventory::itemById($item)['stock']);

    // Recevoir plus que commandé est refusé.
    $trop = Purchasing::receive(['lineId' => $line, 'quantity' => 9, 'receivedOn' => gmdate('Y-m-d')]);
    assertTrue(!$trop['ok']);
    assertSame('depassement', $trop['reason']);
    assertSame(6.0, $trop['remaining']);

    // Le solde, et la commande passe à « Reçue ».
    Purchasing::receive(['lineId' => $line, 'quantity' => 6, 'receivedOn' => gmdate('Y-m-d'), 'receivedBy' => $ids['finance']]);
    assertSame('Reçue', Purchasing::orderById($order)['status']);
    assertSame(10.0, Inventory::itemById($item)['stock']);
});

Tests::run('une ligne réceptionnée ne se retire plus, ni sa commande', function (): void {
    $ids = seedStock();
    $partner = Finance::createPartner(['kind' => 'Fournisseur', 'name' => 'Papeterie']);
    $order = Purchasing::createOrder(['partnerId' => $partner, 'orderedOn' => gmdate('Y-m-d')]);
    Db::run("UPDATE purchase_orders SET status = 'Envoyée' WHERE id = ?", [$order]);
    $line = Purchasing::addLine(['orderId' => $order, 'label' => 'Ramettes', 'quantity' => 10, 'unitPrice' => 5]);

    assertTrue(Purchasing::removeLine($line), 'une ligne vierge devrait se retirer');
    $line = Purchasing::addLine(['orderId' => $order, 'label' => 'Ramettes', 'quantity' => 10, 'unitPrice' => 5]);
    Purchasing::receive(['lineId' => $line, 'quantity' => 2, 'receivedOn' => gmdate('Y-m-d')]);
    assertTrue(!Purchasing::removeLine($line), 'une ligne réceptionnée a été retirée');

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', "/stock/commandes/$order/supprimer");
    assertTrue(Purchasing::orderById($order) !== null, 'une commande réceptionnée a été supprimée');
});

Tests::run('le rapprochement à trois nomme les écarts', function (): void {
    $ids = seedStock();
    $partner = Finance::createPartner(['kind' => 'Fournisseur', 'name' => 'Papeterie']);
    $order = Purchasing::createOrder(['partnerId' => $partner, 'orderedOn' => gmdate('Y-m-d')]);
    Db::run("UPDATE purchase_orders SET status = 'Envoyée' WHERE id = ?", [$order]);
    $line = Purchasing::addLine(['orderId' => $order, 'label' => 'Ramettes', 'quantity' => 10, 'unitPrice' => 50]);

    // Commandé 500, reçu 250, facturé 600 : les deux écarts se déclarent.
    Purchasing::receive(['lineId' => $line, 'quantity' => 5, 'receivedOn' => gmdate('Y-m-d')]);
    $invoice = (int) Finance::createInvoice([
        'direction' => 'Fournisseur', 'partnerId' => $partner, 'label' => 'Fournitures',
        'issueDate' => gmdate('Y-m-d'), 'amountHt' => 600, 'vatRate' => 20,
    ]);
    Db::run('UPDATE invoices SET purchase_order_id = ? WHERE id = ?', [$order, $invoice]);

    $match = Purchasing::match((array) Purchasing::orderById($order));
    assertSame(500.0, $match['ordered']);
    assertSame(250.0, $match['received']);
    assertSame(600.0, $match['invoiced']);
    assertTrue(!$match['ok']);
    assertSame(['sur_commande', 'sur_reception'], array_column($match['issues'], 'kind'));
    assertSame(100.0, $match['issues'][0]['gap']);
    assertSame(350.0, $match['issues'][1]['gap']);
    assertSame(1, count(Purchasing::discrepancies()));

    // Reçu au-delà du facturé : c'est la facture qui reste à venir, pas un écart.
    Db::run('UPDATE invoices SET amount_ht = 200 WHERE id = ?', [$invoice]);
    $sain = Purchasing::match((array) Purchasing::orderById($order));
    assertTrue($sain['ok'], 'une facture en retard est comptée comme un écart');
    assertSame(50.0, $sain['pending']);
});

Tests::run('les écrans du stock et d\'un bon de commande rendent leurs données', function (): void {
    $ids = seedStock();
    $partner = Finance::createPartner(['kind' => 'Fournisseur', 'name' => 'Papeterie Lemoine']);
    $item = Inventory::createItem(['label' => 'Ramettes A4', 'stockMin' => 20, 'unitPrice' => 4.5]);
    Inventory::move(['itemId' => $item, 'kind' => 'Entrée', 'quantity' => 5, 'createdBy' => $ids['finance']]);
    $order = Purchasing::createOrder(['partnerId' => $partner, 'orderedOn' => gmdate('Y-m-d'), 'createdBy' => $ids['finance']]);
    Purchasing::addLine(['orderId' => $order, 'itemId' => $item, 'label' => 'Ramettes A4', 'quantity' => 40, 'unitPrice' => 4.5]);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $page = visit('GET', '/stock');
    assertSame(200, $page->status);
    assertContains('Ramettes A4', $page->body);
    assertContains('Papeterie Lemoine', $page->body);
    // L'alerte de seuil est visible : 5 en stock pour un seuil de 20.
    assertContains('seuil', $page->body);

    $sheet = visit('GET', "/stock/commandes/$order");
    assertSame(200, $sheet->status);
    assertContains('BC-' . gmdate('Y'), $sheet->body);
    assertContains('180,00', $sheet->body);
});
