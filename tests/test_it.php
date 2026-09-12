<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Dev;
use App\Modules\It;
use App\Modules\Users;

/** Une instance avec un référent informatique, désigné par l'administration. */
function seedIt(): array
{
    $ids = seed();
    $ids['it'] = Users::create([
        'role' => 'employee', 'email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!',
        'first_name' => 'Yanis', 'last_name' => 'Berger',
    ]);
    Users::setRoleFlag($ids['it'], 'is_it', true);
    return $ids;
}

Tests::run("le parc logiciel est fermé à qui n'en répond pas", function (): void {
    seedIt();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/informatique')->status);
    assertSame(403, visit('GET', '/developpement')->status);
    assertSame(403, visit('POST', '/informatique/logiciels', ['name' => 'Logiciel'])->status);
    assertSame(0, count(It::licences()));

    // Le service informatique, lui, entre dans les deux espaces.
    visit('POST', '/connexion', ['email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!']);
    assertSame(200, visit('GET', '/informatique')->status);
    assertSame(200, visit('GET', '/developpement')->status);
});

Tests::run("on n'ouvre pas plus d'accès qu'il n'y a de sièges", function (): void {
    $ids = seedIt();
    $licence = It::createLicence(['name' => 'Suite bureautique', 'kind' => 'Abonnement', 'seats' => 2]);

    assertTrue(It::grantAccess(['licenceId' => $licence, 'userId' => $ids['member']])['ok']);
    assertTrue(It::grantAccess(['licenceId' => $licence, 'userId' => $ids['it']])['ok']);

    $refused = It::grantAccess(['licenceId' => $licence, 'userId' => $ids['admin']]);
    assertTrue(!$refused['ok']);
    assertSame('complet', $refused['reason']);
    assertSame(2, $refused['seats']);

    // Deux fois la même personne non plus.
    assertSame('deja', It::grantAccess(['licenceId' => $licence, 'userId' => $ids['member']])['reason']);
    assertSame('introuvable', It::grantAccess(['licenceId' => 9999, 'userId' => $ids['member']])['reason']);

    // Une révocation libère le siège.
    $access = It::accesses($licence, false)[0];
    assertTrue(It::revokeAccess((int) $access['id']));
    assertSame(gmdate('Y-m-d'), Db::get('SELECT revoked_on FROM software_accesses WHERE id = ?', [(int) $access['id']])['revoked_on']);
    assertTrue(It::grantAccess(['licenceId' => $licence, 'userId' => $ids['admin']])['ok']);
});

Tests::run("réduire les sièges sous les accès ouverts est refusé", function (): void {
    $ids = seedIt();
    $licence = It::createLicence(['name' => 'Outil', 'kind' => 'Abonnement', 'seats' => 5, 'criticality' => 'Importante']);
    It::grantAccess(['licenceId' => $licence, 'userId' => $ids['member']]);
    It::grantAccess(['licenceId' => $licence, 'userId' => $ids['it']]);

    visit('POST', '/connexion', ['email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!']);
    visit('POST', '/informatique/logiciels/' . $licence . '/modifier', [
        'name' => 'Outil', 'kind' => 'Abonnement', 'criticality' => 'Importante',
        'billing_period' => 'Annuel', 'seats' => '1', 'status' => 'Actif',
    ]);
    assertSame(5, (int) It::licenceById($licence)['seats'], 'les sièges sont passés sous les accès ouverts');

    // Au-dessus du nombre d'accès, la modification passe.
    visit('POST', '/informatique/logiciels/' . $licence . '/modifier', [
        'name' => 'Outil', 'kind' => 'Abonnement', 'criticality' => 'Importante',
        'billing_period' => 'Annuel', 'seats' => '3', 'status' => 'Actif',
    ]);
    assertSame(3, (int) It::licenceById($licence)['seats']);
});

Tests::run("le coût annualisé suit la périodicité et les sièges", function (): void {
    seedIt();
    $monthly = It::licenceById(It::createLicence([
        'name' => 'Mensuel', 'kind' => 'Abonnement', 'seats' => 10, 'unitCost' => 12.5, 'billingPeriod' => 'Mensuel',
    ]));
    assertSame(1500.0, It::yearlyCost($monthly));

    $yearly = It::licenceById(It::createLicence([
        'name' => 'Annuel', 'kind' => 'Abonnement', 'seats' => 3, 'unitCost' => 240.0, 'billingPeriod' => 'Annuel',
    ]));
    assertSame(720.0, It::yearlyCost($yearly));

    // Sans coût, pas de calcul ; un achat ponctuel ne pèse pas sur l'année.
    $free = It::licenceById(It::createLicence(['name' => 'Libre', 'kind' => 'Logiciel libre', 'seats' => 20]));
    assertSame(null, It::yearlyCost($free));
    $once = It::licenceById(It::createLicence([
        'name' => 'Perpétuelle', 'kind' => 'Licence perpétuelle', 'seats' => 2,
        'unitCost' => 900.0, 'billingPeriod' => 'Ponctuel',
    ]));
    assertSame(0.0, It::yearlyCost($once));
    assertSame(2220.0, It::summary()['yearlyCost']);
});

Tests::run("la revue des accès remonte d'abord les comptes fermés", function (): void {
    $ids = seedIt();
    $licence = It::createLicence(['name' => 'Outil', 'kind' => 'Abonnement']);

    It::grantAccess(['licenceId' => $licence, 'userId' => $ids['member']]);
    It::grantAccess(['licenceId' => $licence, 'userId' => $ids['it'], 'level' => 'Administrateur']);
    assertSame(1, count(It::accessReview()['flagged']), "l'accès administrateur n'est pas signalé");

    // Un compte désactivé qui garde son accès est critique.
    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['member']]);
    $review = It::accessReview();
    assertSame(2, count($review['flagged']));
    // La liste suit le logiciel puis le nom ; c'est le motif qui porte la gravité.
    $closed = array_values(array_filter(
        $review['flagged'],
        static fn (array $row): bool => (int) $row['user_id'] === $ids['member']
    ))[0];
    assertSame('inactif', $closed['reason']);
    assertSame('Critique', $closed['severity']);

    // Un accès jamais revu depuis plus d'un an est signalé, sans gravité.
    Db::run('UPDATE users SET active = 1 WHERE id = ?', [$ids['member']]);
    Db::run("UPDATE software_accesses SET granted_on = date('now', '-18 months') WHERE user_id = ?", [$ids['member']]);
    $stale = It::accessReview()['flagged'];
    assertSame(2, count($stale));
    $reasons = array_column($stale, 'reason');
    assertTrue(in_array('ancien', $reasons, true));

    // Le marquer revu le sort de la liste ; l'accès administrateur, lui, y reste.
    $stale = Db::get('SELECT id FROM software_accesses WHERE user_id = ?', [$ids['member']]);
    assertTrue(It::markReviewed((int) $stale['id']));
    $left = It::accessReview()['flagged'];
    assertSame(1, count($left));
    assertSame('admin', $left[0]['reason']);
});

Tests::run("un incident ne se clôt pas sans heure de rétablissement", function (): void {
    seedIt();
    visit('POST', '/connexion', ['email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!']);

    visit('POST', '/informatique/incidents', [
        'title' => 'Panne de la messagerie', 'severity' => 'Critique', 'started_at' => '2026-04-12T09:30',
    ]);
    $incident = It::incidents()[0];
    assertSame('Ouvert', $incident['status']);
    assertSame('2026-04-12 09:30', $incident['started_at']);

    // Clore sans rétablissement : refusé.
    visit('POST', '/informatique/incidents/' . (int) $incident['id'] . '/modifier', [
        'title' => 'Panne de la messagerie', 'severity' => 'Critique',
        'started_at' => '2026-04-12T09:30', 'status' => 'Résolu',
    ]);
    assertSame('Ouvert', It::incidentById((int) $incident['id'])['status']);

    // Un rétablissement antérieur au début : refusé aussi.
    visit('POST', '/informatique/incidents/' . (int) $incident['id'] . '/modifier', [
        'title' => 'Panne de la messagerie', 'severity' => 'Critique',
        'started_at' => '2026-04-12T09:30', 'resolved_at' => '2026-04-12T08:00', 'status' => 'Résolu',
    ]);
    assertSame('Ouvert', It::incidentById((int) $incident['id'])['status']);

    visit('POST', '/informatique/incidents/' . (int) $incident['id'] . '/modifier', [
        'title' => 'Panne de la messagerie', 'severity' => 'Critique',
        'started_at' => '2026-04-12T09:30', 'resolved_at' => '2026-04-12T11:00', 'status' => 'Résolu',
    ]);
    $closed = It::incidentById((int) $incident['id']);
    assertSame('Résolu', $closed['status']);
    assertSame(90, It::downtimeMinutes($closed));
});

Tests::run("le délai moyen ne compte que les incidents rétablis", function (): void {
    seedIt();
    $today = gmdate('Y-m-d');
    $resolved = It::createIncident(['title' => 'Coupure', 'severity' => 'Majeur', 'startedAt' => "$today 09:00"]);
    It::updateIncident($resolved, ['title' => 'Coupure', 'severity' => 'Majeur', 'startedAt' => "$today 09:00",
        'resolvedAt' => "$today 10:00", 'status' => 'Résolu']);
    // Un incident encore ouvert n'a pas de durée : le compter à zéro flatterait l'indicateur.
    It::createIncident(['title' => 'En cours', 'severity' => 'Critique', 'startedAt' => "$today 08:00"]);

    $stats = It::incidentStats();
    assertSame(2, $stats['total']);
    assertSame(1, $stats['open']);
    assertSame(1, $stats['critical']);
    assertSame(1, $stats['resolved']);
    assertSame(60, $stats['meanMinutes']);

    // Hors fenêtre, un incident ancien ne compte plus.
    It::createIncident(['title' => 'Vieux', 'severity' => 'Mineur',
        'startedAt' => gmdate('Y-m-d', strtotime('-200 days')) . ' 09:00']);
    assertSame(2, It::incidentStats()['total']);
});

// ---------------------------------------------------------------- développement

Tests::run("la version en production est la dernière livrée", function (): void {
    $ids = seedIt();
    $service = Dev::createService(['name' => 'API facturation', 'code' => 'FACT', 'criticality' => 'Vitale']);

    Dev::createRelease(['serviceId' => $service, 'version' => '1.0.0', 'environment' => 'Production',
        'releasedOn' => '2026-01-10', 'status' => 'Livrée', 'authorId' => $ids['it']]);
    Dev::createRelease(['serviceId' => $service, 'version' => '1.1.0', 'environment' => 'Production',
        'releasedOn' => '2026-03-01', 'status' => 'Livrée']);
    // Une livraison en recette ne change pas la version en production.
    Dev::createRelease(['serviceId' => $service, 'version' => '2.0.0-rc', 'environment' => 'Recette',
        'releasedOn' => '2026-03-20', 'status' => 'Livrée']);
    // Une livraison échouée non plus.
    Dev::createRelease(['serviceId' => $service, 'version' => '1.2.0', 'environment' => 'Production',
        'releasedOn' => '2026-03-25', 'status' => 'Échouée']);

    $row = Dev::serviceById($service);
    assertSame('1.1.0', $row['live_version']);
    assertSame('2026-03-01', $row['live_since']);
    assertSame(4, (int) $row['release_count']);
});

Tests::run("le taux d'échec compte les livraisons retirées", function (): void {
    seedIt();
    $service = Dev::createService(['name' => 'Portail', 'criticality' => 'Importante']);
    $today = gmdate('Y-m-d');

    foreach ([['1.0', 'Livrée'], ['1.1', 'Livrée'], ['1.2', 'Échouée'], ['1.3', 'Retirée']] as [$version, $status]) {
        Dev::createRelease(['serviceId' => $service, 'version' => $version, 'environment' => 'Production',
            'releasedOn' => $today, 'status' => $status]);
    }
    // Une livraison planifiée n'est pas une tentative.
    Dev::createRelease(['serviceId' => $service, 'version' => '1.4', 'environment' => 'Production',
        'plannedOn' => $today, 'status' => 'Planifiée']);

    $stats = Dev::deliveryStats();
    assertSame(4, $stats['attempts']);
    assertSame(2, $stats['delivered']);
    assertSame(2, $stats['failed']);
    assertSame(50, $stats['failureRate']);
    assertSame(1, $stats['planned']);

    $summary = Dev::summary();
    assertSame(1, $summary['live']);
    assertSame(0, $summary['vital']);
    assertSame(1, $summary['orphan'], "un service sans responsable n'est pas signalé");
});

Tests::run("une livraison sortie du champ « prévue » demande sa date", function (): void {
    seedIt();
    $service = Dev::createService(['name' => 'Portail', 'criticality' => 'Importante']);
    visit('POST', '/connexion', ['email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!']);

    visit('POST', '/developpement/services/' . $service . '/livraisons', [
        'version' => '1.0.0', 'environment' => 'Production', 'status' => 'Livrée',
    ]);
    assertSame(0, count(Dev::releases($service)), 'une livraison sans date est entrée dans le registre');

    visit('POST', '/developpement/services/' . $service . '/livraisons', [
        'version' => '1.0.0', 'environment' => 'Production', 'status' => 'Planifiée', 'planned_on' => '2026-05-01',
    ]);
    assertSame(1, count(Dev::releases($service)));

    // Un environnement inventé est refusé.
    visit('POST', '/developpement/services/' . $service . '/livraisons', [
        'version' => '1.0.1', 'environment' => 'Bac à sable', 'status' => 'Planifiée',
    ]);
    assertSame(1, count(Dev::releases($service)));
});

Tests::run("une adresse de dépôt doit être cliquable sans risque", function (): void {
    seedIt();
    visit('POST', '/connexion', ['email' => 'si@entreprise.com', 'password' => 'Systeme-Demo-2026!']);

    visit('POST', '/developpement/services', [
        'name' => 'Service piégé', 'criticality' => 'Importante', 'repository' => 'javascript:alert(1)',
    ]);
    assertSame(0, count(Dev::services()), 'une adresse javascript: est entrée au référentiel');

    visit('POST', '/developpement/services', [
        'name' => 'API facturation', 'criticality' => 'Vitale', 'repository' => 'https://git.example.test/api',
    ]);
    $services = Dev::services();
    assertSame(1, count($services));
    assertSame('https://git.example.test/api', $services[0]['repository']);

    // Supprimer le service emporte ses livraisons.
    Dev::createRelease(['serviceId' => (int) $services[0]['id'], 'version' => '1.0', 'environment' => 'Production',
        'releasedOn' => gmdate('Y-m-d'), 'status' => 'Livrée']);
    Dev::removeService((int) $services[0]['id']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM releases'));
});
