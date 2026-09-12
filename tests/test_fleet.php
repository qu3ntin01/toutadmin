<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Fleet;
use App\Modules\FrontDesk;
use App\Modules\Users;

/** Une instance avec quelqu'un à la gestion : la flotte relève des moyens généraux. */
function seedFleet(): array
{
    $ids = seed();
    $ids['finance'] = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['finance'], 'is_finance', true);
    return $ids;
}

Tests::run('la flotte relève de la gestion, pas de tout le monde', function (): void {
    seedFleet();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/flotte')->status);
    assertSame(403, visit('POST', '/flotte', ['registration' => 'AB-123-CD', 'kind' => 'Voiture'])->status);
    assertSame(0, count(Fleet::vehicles()));

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    assertSame(200, visit('GET', '/flotte')->status);
});

Tests::run("un véhicule ne s'enregistre pas deux fois", function (): void {
    seedFleet();
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);

    visit('POST', '/flotte', ['registration' => 'ab-123-cd', 'kind' => 'Voiture', 'brand' => 'Renault']);
    $vehicles = Fleet::vehicles();
    assertSame(1, count($vehicles));
    assertSame('AB-123-CD', $vehicles[0]['registration'], "l'immatriculation n'est pas normalisée");

    visit('POST', '/flotte', ['registration' => 'AB-123-CD', 'kind' => 'Utilitaire']);
    assertSame(1, count(Fleet::vehicles()));

    // Un type inventé est refusé.
    visit('POST', '/flotte', ['registration' => 'CD-456-EF', 'kind' => 'Fusée']);
    assertSame(1, count(Fleet::vehicles()));
});

Tests::run('le compteur ne recule pas', function (): void {
    seedFleet();
    $vehicle = Fleet::create(['registration' => 'AB-123-CD', 'kind' => 'Voiture', 'mileage' => 40000]);
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);

    visit('POST', '/flotte/' . $vehicle . '/modifier', [
        'kind' => 'Voiture', 'status' => 'En service', 'mileage' => '35000',
    ]);
    assertSame(40000, (int) Fleet::byId($vehicle)['mileage']);

    visit('POST', '/flotte/' . $vehicle . '/modifier', [
        'kind' => 'Voiture', 'status' => 'En service', 'mileage' => '45000',
    ]);
    assertSame(45000, (int) Fleet::byId($vehicle)['mileage']);
});

Tests::run('un relevé fait avancer le compteur, jamais reculer', function (): void {
    seedFleet();
    $vehicle = Fleet::create(['registration' => 'AB-123-CD', 'kind' => 'Voiture', 'mileage' => 40000]);

    Fleet::addEvent(['vehicleId' => $vehicle, 'kind' => 'Entretien', 'occurredOn' => gmdate('Y-m-d'),
        'mileage' => 52000, 'cost' => 320.5, 'note' => 'Vidange']);
    assertSame(52000, (int) Fleet::byId($vehicle)['mileage']);

    // Un relevé inférieur ne fait pas reculer le compteur.
    Fleet::addEvent(['vehicleId' => $vehicle, 'kind' => 'Carburant', 'occurredOn' => gmdate('Y-m-d'), 'mileage' => 48000]);
    assertSame(52000, (int) Fleet::byId($vehicle)['mileage']);

    assertSame(2, count(Fleet::events($vehicle)));
    assertSame(320.5, (float) Fleet::vehicles()[0]['total_cost']);
    assertSame(320.5, Fleet::summary()['cost']);
});

Tests::run('les échéances disent ce qui est dépassé et ce qui approche', function (): void {
    seedFleet();
    $late = Fleet::create(['registration' => 'AA-111-AA', 'kind' => 'Voiture',
        'inspectionDue' => gmdate('Y-m-d', strtotime('-10 days'))]);
    Fleet::create(['registration' => 'BB-222-BB', 'kind' => 'Utilitaire',
        'insuranceDue' => gmdate('Y-m-d', strtotime('+20 days'))]);
    // Au-delà de la fenêtre, rien ne remonte.
    Fleet::create(['registration' => 'CC-333-CC', 'kind' => 'Camion',
        'serviceDue' => gmdate('Y-m-d', strtotime('+200 days'))]);

    $deadlines = Fleet::deadlines();
    assertSame(2, count($deadlines));
    assertSame('Contrôle technique', $deadlines[0]['label']);
    assertTrue($deadlines[0]['overdue']);
    assertSame($late, (int) $deadlines[0]['vehicle']['id']);
    assertTrue(!$deadlines[1]['overdue']);
    assertSame(1, Fleet::summary()['overdue']);

    // Un véhicule cédé sort du parc, et de ses échéances.
    Fleet::update($late, ['kind' => 'Voiture', 'status' => 'Cédé',
        'inspectionDue' => gmdate('Y-m-d', strtotime('-10 days')), 'mileage' => 0]);
    assertSame(1, count(Fleet::deadlines()));
    assertSame(2, count(Fleet::vehicles()));
    assertSame(3, count(Fleet::vehicles(true)));
});

// ---------------------------------------------------------------- accueil

Tests::run("l'accueil tient des données de tiers : RH et administration", function (): void {
    seedFleet();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/accueil')->status);
    assertSame(403, visit('POST', '/accueil/visiteurs', ['last_name' => 'Dupont'])->status);
    assertSame(0, count(FrontDesk::visitors()));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/accueil')->status);
});

Tests::run("la liste d'évacuation, c'est qui est entré sans être sorti", function (): void {
    $ids = seedFleet();
    $inside = FrontDesk::checkIn(['lastName' => 'Dupont', 'firstName' => 'Marie', 'company' => 'Client X',
        'hostId' => $ids['member'], 'arrivedAt' => '09:15', 'badge' => 'B-12']);
    $gone = FrontDesk::checkIn(['lastName' => 'Martin', 'firstName' => 'Paul', 'arrivedAt' => '08:00']);
    // Une visite d'hier ne compte pas parmi ceux qui sont dans les murs.
    FrontDesk::checkIn(['lastName' => 'Hier', 'arrivedAt' => '10:00',
        'visitedOn' => gmdate('Y-m-d', strtotime('-1 day'))]);

    assertSame(3, count(FrontDesk::present()) + 1, 'le registre du jour ne distingue pas les visites passées');
    assertSame(2, count(FrontDesk::present()));

    assertTrue(FrontDesk::checkOut($gone, '12:30'));
    assertTrue(!FrontDesk::checkOut($gone, '13:00'), 'on sort deux fois du même bâtiment');
    $present = FrontDesk::present();
    assertSame(1, count($present));
    assertSame($inside, (int) $present[0]['id']);

    $summary = FrontDesk::summary();
    assertSame(1, $summary['presentNow']);
    assertSame(2, $summary['visitorsToday']);
    assertSame(3, $summary['visitorsMonth']);
});

Tests::run('un courrier reste « à remettre » tant que personne ne signe', function (): void {
    $ids = seedFleet();
    $letter = FrontDesk::logMail(['direction' => 'Entrant', 'kind' => 'Recommandé avec AR',
        'correspondent' => 'URSSAF', 'recipientId' => $ids['member'], 'subject' => 'Mise en demeure']);
    FrontDesk::logMail(['direction' => 'Sortant', 'kind' => 'Lettre', 'correspondent' => 'Client X',
        'subject' => 'Devis']);

    assertSame('À remettre', FrontDesk::mail('Entrant')[0]['status']);
    assertSame(1, count(FrontDesk::mailFor($ids['member'])));
    $summary = FrontDesk::summary();
    assertSame(2, $summary['pendingMail']);
    assertSame(1, $summary['registered'], "les recommandés ne sont pas comptés à part");

    // La remise est datée et signée d'un nom.
    assertTrue(FrontDesk::handOver($letter, $ids['admin']));
    $handed = Db::get('SELECT * FROM mail_items WHERE id = ?', [$letter]);
    assertSame('Remis', $handed['status']);
    assertSame(gmdate('Y-m-d'), $handed['handed_on']);
    assertSame($ids['admin'], (int) $handed['handed_by']);

    // Et on ne remet pas deux fois le même pli.
    assertTrue(!FrontDesk::handOver($letter, $ids['admin']));
    assertSame(0, count(FrontDesk::mailFor($ids['member'])));
    assertTrue(FrontDesk::archiveMail($letter));
    assertSame('Archivé', Db::get('SELECT status FROM mail_items WHERE id = ?', [$letter])['status']);
});

Tests::run("les écrans de l'accueil contrôlent la saisie", function (): void {
    $ids = seedFleet();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // Un visiteur sans nom n'entre pas au registre.
    visit('POST', '/accueil/visiteurs', ['first_name' => 'Marie']);
    assertSame(0, count(FrontDesk::visitors()));

    visit('POST', '/accueil/visiteurs', ['last_name' => 'Dupont', 'first_name' => 'Marie',
        'company' => 'Client X', 'host_id' => (string) $ids['member'], 'arrived_at' => '09:15']);
    $visitors = FrontDesk::visitors();
    assertSame(1, count($visitors));
    assertSame('09:15', $visitors[0]['arrived_at']);

    // Un courrier sans objet ni correspondant ne dit rien : refusé.
    visit('POST', '/accueil/courrier', ['direction' => 'Entrant', 'kind' => 'Lettre']);
    assertSame(0, count(FrontDesk::mail()));

    // Un sens ou une nature inventés non plus.
    visit('POST', '/accueil/courrier', ['direction' => 'Transversal', 'kind' => 'Lettre', 'subject' => 'Test']);
    visit('POST', '/accueil/courrier', ['direction' => 'Entrant', 'kind' => 'Pigeon', 'subject' => 'Test']);
    assertSame(0, count(FrontDesk::mail()));

    visit('POST', '/accueil/courrier', ['direction' => 'Entrant', 'kind' => 'Colis',
        'correspondent' => 'Fournisseur Y', 'subject' => 'Pièces détachées']);
    assertSame(1, count(FrontDesk::mail()));

    $page = visit('GET', '/accueil');
    assertSame(200, $page->status);
    assertContains('Dupont', $page->body);
    assertContains('Pièces détachées', $page->body);
});
