<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Accounting;
use App\Modules\Catalogue;
use App\Modules\Finance;
use App\Modules\Users;

/** Une instance où la comptabilité est ouverte, avec un compte gestion. */
function seedAccounting(): array
{
    $ids = seed();
    Catalogue::setEnabled('comptabilite', true);
    Accounting::seedDefaults();
    $ids['finance'] = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['finance'], 'is_finance', true);
    return $ids;
}

Tests::run('un module éteint n\'a pas d\'écran', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(404, visit('GET', '/comptabilite')->status);
    assertSame(404, visit('GET', '/paie')->status);
});

Tests::run('activer la comptabilité pose un plan, deux fois sans doublon', function (): void {
    seed();
    assertSame(0, count(Accounting::accounts()));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/admin/modules/comptabilite', ['enabled' => 'on']);
    $posed = count(Accounting::accounts());
    assertTrue($posed >= 10, 'le plan comptable n\'a pas été posé');
    assertSame(4, count(Accounting::journals()));

    visit('POST', '/admin/modules/comptabilite', ['enabled' => '']);
    visit('POST', '/admin/modules/comptabilite', ['enabled' => 'on']);
    assertSame($posed, count(Accounting::accounts()), 'le plan a été dupliqué à la réactivation');
});

Tests::run('la comptabilité est fermée à qui n\'a pas la gestion', function (): void {
    seedAccounting();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/comptabilite')->status);
    assertSame(403, visit('POST', '/comptabilite/comptes', ['code' => '999', 'label' => 'X', 'kind' => 'Actif'])->status);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    assertSame(200, visit('GET', '/comptabilite')->status);
});

Tests::run('une écriture déséquilibrée est refusée', function (): void {
    $ids = seedAccounting();
    $journal = Accounting::journals()[0];
    $clients = Accounting::accountByCode('411');
    $ventes = Accounting::accountByCode('706');

    $result = Accounting::createEntry([
        'journalId' => (int) $journal['id'], 'entryDate' => gmdate('Y-m-d'), 'label' => 'Bancale',
        'lines' => [
            ['accountId' => $clients['id'], 'debit' => 120, 'credit' => 0],
            ['accountId' => $ventes['id'], 'debit' => 0, 'credit' => 100],
        ],
        'createdBy' => $ids['admin'],
    ]);
    assertTrue(!$result['ok']);
    assertSame('unbalanced', $result['reason']);
    assertSame(0, count(Accounting::entries()));

    // Une ligne qui porte débit et crédit à la fois n'est pas une ligne.
    $both = Accounting::createEntry([
        'journalId' => (int) $journal['id'], 'entryDate' => gmdate('Y-m-d'), 'label' => 'Les deux',
        'lines' => [
            ['accountId' => $clients['id'], 'debit' => 100, 'credit' => 100],
            ['accountId' => $ventes['id'], 'debit' => 0, 'credit' => 100],
        ],
    ]);
    assertSame('both-sides', $both['reason']);

    // Une seule ligne mouvementée non plus.
    $lonely = Accounting::createEntry([
        'journalId' => (int) $journal['id'], 'entryDate' => gmdate('Y-m-d'), 'label' => 'Seule',
        'lines' => [['accountId' => $clients['id'], 'debit' => 100, 'credit' => 0]],
    ]);
    assertSame('too-few-lines', $lonely['reason']);
});

Tests::run('une écriture équilibrée passe, et nourrit la balance', function (): void {
    $ids = seedAccounting();
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);

    $journal = Accounting::journals()[0];
    $clients = Accounting::accountByCode('411');
    $ventes = Accounting::accountByCode('706');
    visit('POST', '/comptabilite/ecritures', [
        'journal_id' => (string) $journal['id'],
        'entry_date' => gmdate('Y-m-d'),
        'label' => 'Vente de prestation',
        'account_id' => [(string) $clients['id'], (string) $ventes['id'], ''],
        'line_label' => ['Client', 'Produit', ''],
        'debit' => ['1200,00', '', ''],
        'credit' => ['', '1200,00', ''],
    ]);

    $entries = Accounting::entries();
    assertSame(1, count($entries));
    assertSame(1200.0, (float) $entries[0]['total_debit']);
    assertSame(2, count(Accounting::linesOf((int) $entries[0]['id'])), 'la ligne vide a été enregistrée');

    $balance = Accounting::balance(gmdate('Y') . '-01-01', gmdate('Y') . '-12-31');
    $codes = array_column($balance, 'code');
    assertTrue(in_array('411', $codes, true));
    assertSame(1200.0, Accounting::income(gmdate('Y') . '-01-01', gmdate('Y') . '-12-31')['revenue']);
});

Tests::run('une facture se comptabilise une seule fois, au taux figé', function (): void {
    $ids = seedAccounting();
    \App\Modules\Currency::setRate('USD', 0.90);
    $invoiceId = Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Étude', 'issueDate' => gmdate('Y-m-d'),
        'amountHt' => 1000, 'vatRate' => 20, 'currency' => 'USD',
    ]);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', "/comptabilite/factures/$invoiceId/comptabiliser");
    assertSame(1, count(Accounting::entries()));

    // 1000 USD au taux 0,90 : 900 de produit, 180 de TVA, 1080 au client.
    $lines = Accounting::linesOf((int) Accounting::entries()[0]['id']);
    $byCode = [];
    foreach ($lines as $line) {
        $byCode[$line['code']] = $line;
    }
    assertSame(1080.0, (float) $byCode['411']['debit']);
    assertSame(900.0, (float) $byCode['706']['credit']);
    assertSame(180.0, (float) $byCode['4457']['credit']);

    // Deux fois, non : la pièce serait comptée deux fois.
    visit('POST', "/comptabilite/factures/$invoiceId/comptabiliser");
    assertSame(1, count(Accounting::entries()));
});

Tests::run('un achat s\'écrit dans l\'autre sens', function (): void {
    $ids = seedAccounting();
    $invoiceId = Finance::createInvoice([
        'direction' => 'Fournisseur', 'label' => 'Loyer', 'issueDate' => gmdate('Y-m-d'),
        'amountHt' => 500, 'vatRate' => 20,
    ]);
    assertTrue(Accounting::entryFromInvoice((array) Finance::invoiceById((int) $invoiceId), $ids['admin'])['ok']);

    $byCode = [];
    foreach (Accounting::linesOf((int) Accounting::entries()[0]['id']) as $line) {
        $byCode[$line['code']] = $line;
    }
    assertSame(500.0, (float) $byCode['606']['debit']);
    assertSame(100.0, (float) $byCode['4456']['debit']);
    assertSame(600.0, (float) $byCode['401']['credit']);
});

Tests::run('un compte mouvementé est désactivé, pas supprimé', function (): void {
    $ids = seedAccounting();
    $invoiceId = Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Vente', 'issueDate' => gmdate('Y-m-d'),
        'amountHt' => 100, 'vatRate' => 20,
    ]);
    Accounting::entryFromInvoice((array) Finance::invoiceById((int) $invoiceId), $ids['admin']);

    $clients = Accounting::accountByCode('411');
    $result = Accounting::deleteAccount((int) $clients['id']);
    assertTrue($result['deactivated'], 'un compte mouvementé a été supprimé');
    assertSame(0, (int) Accounting::accountByCode('411')['active']);

    // Un compte jamais mouvementé, lui, s'efface.
    Accounting::createAccount('9999', 'Compte d\'essai', 'Charge');
    assertTrue(!Accounting::deleteAccount((int) Accounting::accountByCode('9999')['id'])['deactivated']);
    assertSame(null, Accounting::accountByCode('9999'));
});

Tests::run('le grand livre suit le solde ligne à ligne', function (): void {
    $ids = seedAccounting();
    $journal = Accounting::journals()[0];
    $banque = Accounting::accountByCode('512');
    $ventes = Accounting::accountByCode('706');

    foreach ([[100, '2026-01-05'], [250, '2026-02-10']] as [$amount, $date]) {
        Accounting::createEntry([
            'journalId' => (int) $journal['id'], 'entryDate' => $date, 'label' => 'Encaissement',
            'lines' => [
                ['accountId' => $banque['id'], 'debit' => $amount, 'credit' => 0],
                ['accountId' => $ventes['id'], 'debit' => 0, 'credit' => $amount],
            ],
            'createdBy' => $ids['admin'],
        ]);
    }

    $ledger = Accounting::ledger((int) $banque['id'], '2026-01-01', '2026-12-31');
    assertSame(2, count($ledger));
    assertSame(100.0, $ledger[0]['running']);
    assertSame(350.0, $ledger[1]['running']);
});

Tests::run('la balance s\'exporte en CSV', function (): void {
    $ids = seedAccounting();
    $journal = Accounting::journals()[0];
    Accounting::createEntry([
        'journalId' => (int) $journal['id'], 'entryDate' => '2026-05-05', 'label' => 'Vente',
        'lines' => [
            ['accountId' => Accounting::accountByCode('411')['id'], 'debit' => 600, 'credit' => 0],
            ['accountId' => Accounting::accountByCode('706')['id'], 'debit' => 0, 'credit' => 600],
        ],
        'createdBy' => $ids['admin'],
    ]);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $page = visit('GET', '/comptabilite/balance.csv', [], ['annee' => '2026']);
    assertSame(200, $page->status);
    assertContains('Compte;Libellé;Type;Débit;Crédit;Solde', $page->body);
    assertContains('411;Clients;Actif;600.00;0.00;600.00', $page->body);
});
