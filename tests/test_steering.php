<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Deadlines;
use App\Modules\Finance;
use App\Modules\Notifications;
use App\Modules\Steering;
use App\Modules\Users;

function loginSteering(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

Tests::run('le pilotage est réservé à la direction, à la gestion et aux RH', function (): void {
    $ids = seed();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/pilotage')->status);
    assertSame(403, visit('POST', '/pilotage/objectifs', ['title' => 'Pirate', 'scope' => 'Entreprise'])->status);

    Users::setRoleFlag($ids['member'], 'is_hr', true);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/pilotage')->status);
});

Tests::run("ce qui manque rend null plutôt que zéro", function (): void {
    seed();
    $overview = Steering::overview(2026);

    // Aucun salaire renseigné, aucun compte bancaire : une absence, pas un zéro.
    assertSame(null, $overview['payroll']);
    assertSame(null, $overview['treasury']);
    assertSame(0.0, $overview['revenue']['sales']);
});

Tests::run("le chiffre d'affaires est ramené en devise de référence", function (): void {
    $ids = seed();
    \App\Modules\Currency::setRate('USD', 0.5);

    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Prestation', 'issueDate' => '2026-02-01',
        'amountHt' => 1000.0, 'vatRate' => 20.0, 'currency' => 'EUR',
    ]);
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Prestation export', 'issueDate' => '2026-03-01',
        'amountHt' => 1000.0, 'vatRate' => 0.0, 'currency' => 'USD',
    ]);
    Finance::createInvoice([
        'direction' => 'Fournisseur', 'label' => 'Achat', 'issueDate' => '2026-03-05',
        'amountHt' => 400.0, 'vatRate' => 20.0, 'currency' => 'EUR',
    ]);
    // Hors exercice : ne compte pas.
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Vieille facture', 'issueDate' => '2025-12-31',
        'amountHt' => 9999.0, 'vatRate' => 20.0, 'currency' => 'EUR',
    ]);

    $revenue = Steering::revenue(2026);
    assertSame(1500.0, $revenue['sales'], 'la facture en dollars doit être convertie au taux figé');
    assertSame(400.0, $revenue['purchases']);
    assertSame(1100.0, $revenue['margin']);
});

Tests::run("l'avancement se mesure sur l'échelle de chaque résultat clé", function (): void {
    // Un compteur qui part de 40 et vise 60 est à moitié quand il atteint 50.
    assertSame(50, Steering::progressOf([
        ['start_value' => 40, 'target_value' => 60, 'current_value' => 50],
    ]));
    // Une cible atteinte vaut 100 %, un dépassement ne va pas au-delà.
    assertSame(100, Steering::progressOf([
        ['start_value' => 0, 'target_value' => 10, 'current_value' => 15],
    ]));
    // Une régression sous le départ ne descend pas sous zéro.
    assertSame(0, Steering::progressOf([
        ['start_value' => 10, 'target_value' => 20, 'current_value' => 5],
    ]));
    // Un objectif à la baisse se mesure de la même façon.
    assertSame(50, Steering::progressOf([
        ['start_value' => 100, 'target_value' => 50, 'current_value' => 75],
    ]));
    assertSame(0, Steering::progressOf([]));
    // La moyenne des résultats clés, pas leur somme.
    assertSame(50, Steering::progressOf([
        ['start_value' => 0, 'target_value' => 10, 'current_value' => 10],
        ['start_value' => 0, 'target_value' => 10, 'current_value' => 0],
    ]));
});

Tests::run('un objectif se mesure, sinon il ne sert à rien', function (): void {
    seed();
    loginSteering();

    visit('POST', '/pilotage/objectifs', ['title' => 'Sans portée', 'scope' => 'Service']);
    assertSame(0, count(Steering::objectives()), 'une portée de service sans service a été acceptée');

    visit('POST', '/pilotage/objectifs', [
        'title' => 'Réduire les retards de livraison', 'scope' => 'Entreprise', 'period' => '2026-T1',
    ]);
    $objective = Steering::objectives()[0];
    assertSame(0, $objective['progress']);

    // Départ et cible identiques : il n'y a rien à mesurer.
    visit('POST', '/pilotage/objectifs/' . $objective['id'] . '/resultats', [
        'title' => 'Taux de retard', 'start_value' => '10', 'target_value' => '10',
    ]);
    assertSame(0, count(Steering::keyResults((int) $objective['id'])));

    visit('POST', '/pilotage/objectifs/' . $objective['id'] . '/resultats', [
        'title' => 'Taux de retard', 'start_value' => '10', 'target_value' => '2', 'unit' => '%',
    ]);
    $result = Steering::keyResults((int) $objective['id'])[0];
    assertSame(10.0, (float) $result['current_value'], 'sans valeur courante, on part du départ');

    visit('POST', '/pilotage/resultats/' . $result['id'], ['current_value' => '6']);
    assertSame(50, Steering::objectives()[0]['progress']);

    visit('POST', '/pilotage/resultats/' . $result['id'], ['current_value' => 'beaucoup']);
    assertSame(6.0, (float) Steering::keyResults((int) $objective['id'])[0]['current_value']);

    visit('POST', '/pilotage/objectifs/' . $objective['id'] . '/statut', ['status' => 'Atteint']);
    assertSame('Atteint', Steering::objectives()[0]['status']);
    visit('POST', '/pilotage/objectifs/' . $objective['id'] . '/statut', ['status' => 'Oublié']);
    assertSame('Atteint', Steering::objectives()[0]['status']);

    // Supprimer l'objectif emporte ses résultats clés.
    visit('POST', '/pilotage/objectifs/' . $objective['id'] . '/supprimer');
    assertSame(0, count(Steering::objectives()));
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM key_results'));
});

Tests::run('les échéances de toutes les sources se rassemblent en une liste', function (): void {
    $ids = seed();
    $soon = gmdate('Y-m-d', strtotime('+10 days'));
    $past = gmdate('Y-m-d', strtotime('-3 days'));
    $far = gmdate('Y-m-d', strtotime('+200 days'));

    // Une facture en retard, un contrat de travail qui se termine, et une
    // échéance hors horizon qui ne doit pas remonter.
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Prestation', 'issueDate' => '2026-01-01', 'dueDate' => $past,
        'amountHt' => 100.0, 'vatRate' => 20.0, 'reference' => 'F-001',
    ]);
    Db::run('UPDATE users SET contract_end_date = ? WHERE id = ?', [$soon, $ids['member']]);
    Db::run("INSERT INTO vehicles (registration, brand, model, status, insurance_due) VALUES ('AA-123-BB', 'X', 'Y', 'En service', ?)", [$far]);

    $summary = Deadlines::summary();
    $sources = array_column($summary['rows'], 'source');
    assertTrue(in_array('Facture', $sources, true));
    assertTrue(in_array('Contrat de travail', $sources, true));
    assertTrue(!in_array('Véhicule', $sources, true), 'une échéance à 200 jours est remontée');

    assertSame(1, $summary['overdue']);
    assertSame(2, $summary['total']);
    // La liste est rendue par date croissante.
    assertSame($past, $summary['rows'][0]['due']);
    assertSame(1, $summary['bySource']['Facture']['overdue']);

    // Les notifications se déduisent, et ne se répètent pas.
    $created = Deadlines::notify(45);
    assertTrue($created > 0);
    assertSame(0, Deadlines::notify(45), 'une même échéance a alerté deux fois');
    $invoiceNotice = null;
    foreach (Notifications::forUser($ids['admin']) as $notice) {
        if (str_starts_with($notice['title'], 'Facture')) {
            $invoiceNotice = $notice;
        }
    }
    assertTrue($invoiceNotice !== null, "la facture en retard n'a pas alerté");
    assertContains('F-001', $invoiceNotice['title']);
    assertContains('échéance dépassée', $invoiceNotice['body']);
});

Tests::run("l'écran de pilotage s'affiche avec ses quatre onglets", function (): void {
    $ids = seed();
    loginSteering();
    visit('POST', '/pilotage/objectifs', [
        'title' => 'Réduire les retards', 'scope' => 'Entreprise', 'period' => '2026-T1',
    ]);

    $page = visit('GET', '/pilotage', [], ['annee' => '2026']);
    assertSame(200, $page->status);
    assertContains('Réduire les retards', $page->body);
    assertContains('id="echeances"', $page->body);
    assertContains('id="projets"', $page->body);
});
