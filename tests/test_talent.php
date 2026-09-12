<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Talent;
use App\Modules\Users;

Tests::run('un document se publie et s\'accuse une seule fois', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/rh/documents', [
        'title' => 'Règlement intérieur', 'category' => 'Règlement intérieur',
        'description' => 'Version 2026', 'requires_ack' => 'on',
    ]);
    $documents = Talent::documents();
    assertSame(1, count($documents));
    $id = (int) $documents[0]['id'];
    assertSame(1, Talent::pendingAckCount($ids['member']));

    // L'accusé est daté et définitif ; le répéter ne crée pas de doublon.
    Talent::acknowledge($id, $ids['member']);
    Talent::acknowledge($id, $ids['member']);
    assertSame(1, count(Talent::acksOf($id)));
    assertSame(0, Talent::pendingAckCount($ids['member']));
});

Tests::run('un lien de document doit être une vraie adresse', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/rh/documents', ['title' => 'Politique', 'url' => 'javascript:alert(1)']);
    assertSame(0, count(Talent::documents()), 'un lien non http est passé');

    visit('POST', '/rh/documents', ['title' => 'Politique', 'category' => 'Inventée']);
    assertSame(0, count(Talent::documents()), 'une catégorie inconnue est passée');
});

Tests::run('le salarié accuse réception depuis son espace', function (): void {
    $ids = seed();
    $documentId = Talent::createDocument([
        'title' => 'Charte informatique', 'category' => 'Politique', 'description' => '',
        'url' => '', 'requiresAck' => true, 'publishedAt' => null, 'createdBy' => $ids['admin'],
    ]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertContains('Charte informatique', visit('GET', '/mon-espace')->body);
    visit('POST', "/mon-espace/documents/$documentId/accuser");
    assertSame(1, count(Talent::acksOf($documentId)));
});

Tests::run('une session pleine refuse un inscrit de plus', function (): void {
    $ids = seed();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $trainingId = Talent::createTraining(['title' => 'Gestes et postures', 'durationHours' => 7, 'cost' => 320]);
    $sessionId = Talent::createSession(['trainingId' => $trainingId, 'startDate' => '2026-03-10', 'endDate' => null, 'seats' => 1]);

    Talent::requestSeat($sessionId, $ids['member']);
    Talent::requestSeat($sessionId, $autre);
    $registrations = Talent::registrations($sessionId);
    assertSame(2, count($registrations));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/rh/inscriptions/' . (int) $registrations[0]['id'] . '/statut', ['status' => 'Inscrite']);
    visit('POST', '/rh/inscriptions/' . (int) $registrations[1]['id'] . '/statut', ['status' => 'Inscrite']);

    $after = Talent::registrations($sessionId);
    $inscrits = array_filter($after, static fn (array $r): bool => $r['status'] === 'Inscrite');
    assertSame(1, count($inscrits), 'la session a dépassé son quota');
});

Tests::run('on ne s\'inscrit pas deux fois, ni à une session close', function (): void {
    $ids = seed();
    $trainingId = Talent::createTraining(['title' => 'Secourisme', 'durationHours' => 14, 'cost' => null]);
    $sessionId = Talent::createSession(['trainingId' => $trainingId, 'startDate' => '2026-04-06', 'endDate' => null, 'seats' => 0]);

    assertTrue(Talent::requestSeat($sessionId, $ids['member'])['ok']);
    assertSame('already-registered', Talent::requestSeat($sessionId, $ids['member'])['reason']);

    Talent::setSessionStatus($sessionId, 'Annulée');
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    assertSame('closed', Talent::requestSeat($sessionId, $autre)['reason']);
});

Tests::run('une demande de place se retire tant qu\'elle n\'est pas décidée', function (): void {
    $ids = seed();
    $trainingId = Talent::createTraining(['title' => 'Anglais', 'durationHours' => 20, 'cost' => null]);
    $sessionId = Talent::createSession(['trainingId' => $trainingId, 'startDate' => '2026-05-04', 'endDate' => null, 'seats' => 10]);
    Talent::requestSeat($sessionId, $ids['member']);
    $registration = Talent::registrations($sessionId)[0];

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/mon-espace/formations/' . (int) $registration['id'] . '/annuler');
    assertSame(0, count(Talent::registrations($sessionId)));

    // Une fois inscrite, la place ne se retire plus toute seule.
    Talent::requestSeat($sessionId, $ids['member']);
    $again = Talent::registrations($sessionId)[0];
    Talent::reviewRegistration((int) $again['id'], 'Inscrite', $ids['admin']);
    assertTrue(!Talent::cancelOwnRegistration((int) $again['id'], $ids['member']));
});

Tests::run('une session refuse une fin qui précède le début', function (): void {
    seed();
    $trainingId = Talent::createTraining(['title' => 'Excel', 'durationHours' => null, 'cost' => null]);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/rh/sessions', ['training_id' => (string) $trainingId, 'start_date' => '2026-06-10', 'end_date' => '2026-06-01']);
    assertSame(0, count(Talent::sessions()));

    visit('POST', '/rh/sessions', ['training_id' => (string) $trainingId, 'start_date' => '2026-06-10', 'seats' => '5000']);
    assertSame(0, count(Talent::sessions()), 'un quota absurde est passé');

    visit('POST', '/rh/sessions', ['training_id' => (string) $trainingId, 'start_date' => '2026-06-10', 'seats' => '12']);
    assertSame(1, count(Talent::sessions()));
});

Tests::run('un entretien se planifie, se conclut et garde le mot du salarié', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/rh/entretiens', [
        'employee_id' => (string) $ids['member'], 'period' => '2026', 'scheduled_on' => '2026-02-12',
    ]);
    $reviews = Talent::reviews($ids['member']);
    assertSame(1, count($reviews));
    $id = (int) $reviews[0]['id'];

    // Une appréciation hors de l'échelle est refusée.
    visit('POST', "/rh/entretiens/$id/conclure", ['strengths' => 'Rigueur', 'rating' => '9']);
    assertSame('Planifié', Talent::reviewById($id)['status']);

    visit('POST', "/rh/entretiens/$id/conclure", [
        'strengths' => 'Rigueur', 'improvements' => 'Déléguer', 'objectives' => 'Former un binôme', 'rating' => '4',
    ]);
    $done = Talent::reviewById($id);
    assertSame('Réalisé', $done['status']);
    assertSame(4, (int) $done['rating']);

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/mon-espace/entretiens/$id/commentaire", ['employee_comment' => 'Je suis d\'accord sur le binôme.']);
    assertContains('binôme', (string) Talent::reviewById($id)['employee_comment']);
});

Tests::run('un salarié ne commente pas l\'entretien d\'un autre', function (): void {
    $ids = seed();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $id = Talent::createReview(['employeeId' => $autre, 'reviewerId' => null, 'period' => '2026', 'scheduledOn' => null]);
    Talent::completeReview($id, ['strengths' => '', 'improvements' => '', 'objectives' => '', 'rating' => null]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/mon-espace/entretiens/$id/commentaire", ['employee_comment' => 'Intrusion']);
    assertSame('', (string) Talent::reviewById($id)['employee_comment']);
});

Tests::run('un entretien annulé ne se conclut plus', function (): void {
    $ids = seed();
    $id = Talent::createReview(['employeeId' => $ids['member'], 'reviewerId' => null, 'period' => '2026', 'scheduledOn' => null]);
    Talent::cancelReview($id);
    $result = Talent::completeReview($id, ['strengths' => 'x', 'improvements' => '', 'objectives' => '', 'rating' => null]);
    assertTrue(!$result['ok']);
    assertSame('Annulé', Talent::reviewById($id)['status']);
});

Tests::run('les écrans RH portent les trois nouveaux onglets', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $body = visit('GET', '/rh')->body;
    foreach (['id="documents"', 'id="formations"', 'id="entretiens"'] as $anchor) {
        assertContains($anchor, $body);
    }
});
