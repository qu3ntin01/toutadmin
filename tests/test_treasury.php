<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Catalogue;
use App\Modules\Finance;
use App\Modules\Treasury;
use App\Modules\Users;

/** Une instance où trésorerie et immobilisations sont ouvertes. */
function seedTreasury(): array
{
    $ids = seed();
    Catalogue::setEnabled('tresorerie', true);
    Catalogue::setEnabled('immobilisations', true);
    $ids['finance'] = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['finance'], 'is_finance', true);
    return $ids;
}

Tests::run('la trésorerie est fermée à qui n\'a pas la gestion', function (): void {
    seedTreasury();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/tresorerie')->status);
    assertSame(403, visit('GET', '/immobilisations')->status);
    assertSame(403, visit('POST', '/tresorerie/comptes', ['label' => 'Compte pirate'])->status);
    assertSame(0, count(Treasury::accounts()));

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    assertSame(200, visit('GET', '/tresorerie')->status);
    assertSame(200, visit('GET', '/immobilisations')->status);
});

Tests::run('le solde se recalcule des mouvements, il n\'est pas stocké', function (): void {
    seedTreasury();
    $id = Treasury::createAccount('Compte courant', 'Banque du Nord', '4321', 1000);
    assertSame(1000.0, Treasury::totalBalance());

    Treasury::addTransaction(['accountId' => $id, 'valueDate' => gmdate('Y-m-d'), 'label' => 'Encaissement', 'amount' => 250]);
    Treasury::addTransaction(['accountId' => $id, 'valueDate' => gmdate('Y-m-d'), 'label' => 'Loyer', 'amount' => -400]);
    assertSame(850.0, Treasury::totalBalance());

    // Un mouvement retiré, et le solde suit.
    $movements = Treasury::transactions($id);
    Treasury::deleteTransaction((int) $movements[0]['id']);
    assertSame(2, count(Treasury::transactions($id)) + 1);
    assertTrue(Treasury::totalBalance() !== 850.0, 'le solde est resté figé après suppression');
});

Tests::run('un compte clôturé sort des soldes sans perdre son histoire', function (): void {
    seedTreasury();
    $id = Treasury::createAccount('Livret', '', '', 5000);
    assertSame(5000.0, Treasury::totalBalance());

    Treasury::closeAccount($id);
    assertSame(0.0, Treasury::totalBalance());
    assertSame(1, count(Treasury::accounts(true)), 'le compte clôturé a disparu de l\'historique');
});

Tests::run('un mouvement nul ou vide est refusé', function (): void {
    seedTreasury();
    $id = Treasury::createAccount('Compte courant');
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);

    foreach (['0', '', 'beaucoup'] as $bad) {
        visit('POST', '/tresorerie/mouvements', [
            'account_id' => (string) $id, 'value_date' => gmdate('Y-m-d'), 'label' => 'Essai', 'amount' => $bad,
        ]);
    }
    assertSame(0, count(Treasury::transactions($id)), 'un montant invalide est passé');

    visit('POST', '/tresorerie/mouvements', [
        'account_id' => (string) $id, 'value_date' => gmdate('Y-m-d'), 'label' => 'Loyer', 'amount' => '-1 200,50',
    ]);
    assertSame(-1200.5, (float) Treasury::transactions($id)[0]['amount']);
});

Tests::run('le rapprochement refuse un mouvement de sens contraire', function (): void {
    $ids = seedTreasury();
    $account = Treasury::createAccount('Compte courant');
    $invoice = (int) Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Prestation', 'issueDate' => gmdate('Y-m-d'),
        'dueDate' => gmdate('Y-m-d'), 'amountHt' => 1000, 'vatRate' => 20,
    ]);
    // Une facture client s'encaisse : un débit ne la règle pas.
    $sortie = Treasury::addTransaction([
        'accountId' => $account, 'valueDate' => gmdate('Y-m-d'), 'label' => 'Sortie', 'amount' => -1200,
    ]);
    assertTrue(!Treasury::reconcile($sortie, $invoice)['ok'], 'un mouvement de sens contraire a été rapproché');
    assertSame('Émise', Finance::invoiceById($invoice)['status']);

    $entree = Treasury::addTransaction([
        'accountId' => $account, 'valueDate' => '2026-03-10', 'label' => 'Virement client', 'amount' => 1200,
    ]);
    assertTrue(Treasury::reconcile($entree, $invoice)['ok']);
    $paid = Finance::invoiceById($invoice);
    assertSame('Payée', $paid['status']);
    assertSame('2026-03-10', $paid['paid_at'], 'la date de paiement n\'est pas celle du mouvement');
    assertSame(0, count(Treasury::unreconciled()) - 1);
});

Tests::run('la projection part du solde et suit les échéances dans l\'ordre', function (): void {
    seedTreasury();
    Treasury::createAccount('Compte courant', '', '', 2000);
    // Une facture client due dans une semaine, une dépense prévue dans deux.
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Étude', 'issueDate' => gmdate('Y-m-d'),
        'dueDate' => gmdate('Y-m-d', strtotime('+7 days')), 'amountHt' => 1000, 'vatRate' => 0,
    ]);
    Treasury::addForecast('Salaires', gmdate('Y-m-d', strtotime('+14 days')), -2500, 'Certain');
    // Hors horizon : ne compte pas.
    Treasury::addForecast('Dans six mois', gmdate('Y-m-d', strtotime('+180 days')), -9000, 'Éventuel');

    $projection = Treasury::projection();
    assertSame(2000.0, $projection['start']);
    assertSame(2, count($projection['points']));
    assertSame(3000.0, $projection['points'][0]['balance']);
    assertSame(500.0, $projection['points'][1]['balance']);
    assertSame(500.0, $projection['lowest']['balance']);
});

Tests::run('l\'amortissement linéaire répartit la dotation à parts égales', function (): void {
    seedTreasury();
    $id = Treasury::createAsset([
        'label' => 'Serveur', 'category' => 'Matériel', 'acquiredOn' => '2026-01-15',
        'amount' => 6000, 'durationYears' => 3, 'method' => 'Linéaire',
    ]);
    $rows = Treasury::schedule((array) Treasury::assetById($id));
    assertSame(3, count($rows));
    assertSame(2000.0, $rows[0]['charge']);
    assertSame(2000.0, $rows[2]['charge']);
    assertSame(0.0, $rows[2]['residual'], 'le tableau ne se solde pas à zéro');
});

Tests::run('le dégressif bascule sur le linéaire quand celui-ci devient plus favorable', function (): void {
    seedTreasury();
    // Cinq ans : coefficient 1,75, soit 35 % la première année.
    assertSame(1.75, Treasury::coefficientFor(5));
    assertSame(1.25, Treasury::coefficientFor(3));
    assertSame(2.25, Treasury::coefficientFor(10));

    $id = Treasury::createAsset([
        'label' => 'Machine', 'category' => 'Matériel', 'acquiredOn' => '2026-01-01',
        'amount' => 10000, 'durationYears' => 5, 'method' => 'Dégressif',
    ]);
    $rows = Treasury::schedule((array) Treasury::assetById($id));
    assertSame(3500.0, $rows[0]['charge'], 'la première dotation dégressive est fausse');
    assertSame(2275.0, $rows[1]['charge']);
    assertSame(1478.75, $rows[2]['charge']);
    // Les deux dernières annuités sont égales au centime d'arrondi près : la
    // bascule sur le linéaire des années restantes a eu lieu.
    assertTrue(
        round(abs($rows[3]['charge'] - $rows[4]['charge']), 2) <= 0.01,
        'la bascule sur le linéaire n\'a pas eu lieu'
    );
    $total = array_sum(array_column($rows, 'charge'));
    assertSame(10000.0, round($total, 2), 'le total amorti ne fait pas la valeur d\'origine');
    assertSame(0.0, $rows[4]['residual']);
});

Tests::run('une immobilisation cédée sort des totaux', function (): void {
    seedTreasury();
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', '/immobilisations', [
        'label' => 'Véhicule', 'acquired_on' => '2026-02-01', 'amount' => '18 000',
        'duration_years' => '5', 'method' => 'Linéaire',
    ]);
    assertSame(1, Treasury::assetSummary()['count']);
    assertSame(18000.0, Treasury::assetSummary()['gross']);

    $id = (int) Treasury::assets()[0]['id'];
    // Une cession antérieure à l'acquisition n'a pas de sens.
    visit('POST', "/immobilisations/$id/ceder", ['disposed_on' => '2025-01-01']);
    assertSame(null, Treasury::assetById($id)['disposed_on']);

    visit('POST', "/immobilisations/$id/ceder", ['disposed_on' => '2026-11-30']);
    assertSame('2026-11-30', Treasury::assetById($id)['disposed_on']);
    assertSame(0, Treasury::assetSummary()['count']);
});

Tests::run('une durée hors bornes est refusée', function (): void {
    seedTreasury();
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    foreach ([['duration_years' => '0'], ['duration_years' => '80'], ['method' => 'Au hasard'], ['amount' => '-5']] as $override) {
        visit('POST', '/immobilisations', array_merge([
            'label' => 'Essai', 'acquired_on' => '2026-01-01', 'amount' => '1000',
            'duration_years' => '5', 'method' => 'Linéaire',
        ], $override));
    }
    assertSame(0, count(Treasury::assets()), 'une immobilisation invalide a été enregistrée');
});

Tests::run('les écrans de trésorerie et d\'immobilisations rendent leurs données', function (): void {
    seedTreasury();
    Treasury::createAccount('Compte courant', 'Banque du Nord', '4321', 1500);
    Treasury::addForecast('Acompte marché', gmdate('Y-m-d', strtotime('+10 days')), 4000, 'Probable');
    Treasury::createAsset([
        'label' => 'Serveur de production', 'category' => 'Informatique', 'acquiredOn' => '2026-01-15',
        'amount' => 6000, 'durationYears' => 3, 'method' => 'Linéaire',
    ]);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $tresorerie = visit('GET', '/tresorerie');
    assertSame(200, $tresorerie->status);
    assertContains('Compte courant', $tresorerie->body);
    assertContains('Acompte marché', $tresorerie->body);
    assertContains('4321', $tresorerie->body);

    $immo = visit('GET', '/immobilisations');
    assertSame(200, $immo->status);
    assertContains('Serveur de production', $immo->body);
    assertContains('2 000,00', $immo->body);
});
