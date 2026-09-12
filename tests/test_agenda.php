<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Calendar;
use App\Modules\Hr;
use App\Modules\Org;
use App\Modules\Rooms;
use App\Modules\Users;

Tests::run('la grille du mois commence le lundi et fait six semaines', function (): void {
    $weeks = Calendar::buildGrid('2026-01');
    assertSame(6, count($weeks));
    assertSame(7, count($weeks[0]));
    // Le 1er janvier 2026 est un jeudi : la grille commence donc le lundi 29 décembre.
    assertSame('2025-12-29', $weeks[0][0]['iso']);
    assertTrue(!$weeks[0][0]['inMonth'], 'un jour du mois précédent est marqué dans le mois');
    assertTrue($weeks[0][3]['inMonth']);
});

Tests::run('un mois invalide retombe sur le mois courant', function (): void {
    assertSame(gmdate('Y-m'), Calendar::normalizeMonth('2026-13'));
    assertSame(gmdate('Y-m'), Calendar::normalizeMonth('bonjour'));
    assertSame('2026-02', Calendar::normalizeMonth('2026-02'));
    assertSame('2025-12', Calendar::shiftMonth('2026-01', -1));
    assertSame('2026-03', Calendar::shiftMonth('2026-01', 2));
});

Tests::run('un événement de plusieurs jours occupe chacun d\'eux', function (): void {
    $ids = seed();
    Calendar::createEvent([
        'userId' => $ids['member'], 'title' => 'Salon professionnel', 'startDate' => '2026-01-05',
        'endDate' => '2026-01-07', 'category' => 'Déplacement', 'visibility' => 'Privé',
    ]);
    $agenda = Calendar::monthAgenda((array) Users::byId($ids['member']), '2026-01');
    foreach (['2026-01-05', '2026-01-06', '2026-01-07'] as $day) {
        assertTrue(isset($agenda[$day]), "jour $day absent");
        assertSame('Salon professionnel', $agenda[$day][0]['title']);
    }
    assertTrue(!isset($agenda['2026-01-08']));
});

Tests::run('les congés approuvés se posent d\'eux-mêmes dans l\'agenda', function (): void {
    $ids = seed();
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-06', 2, '');
    $agenda = Calendar::monthAgenda((array) Users::byId($ids['member']), '2026-01');
    assertTrue(!isset($agenda['2026-01-05']), 'une demande en attente occupe déjà l\'agenda');

    Hr::approve($id, $ids['admin'], '');
    $agenda = Calendar::monthAgenda((array) Users::byId($ids['member']), '2026-01');
    assertSame('Congés payés', $agenda['2026-01-05'][0]['title']);
    assertSame('conge', $agenda['2026-01-05'][0]['source']);
});

Tests::run('un événement privé ne franchit pas l\'agenda partagé', function (): void {
    $ids = seed();
    $teamId = Org::createTeam('Ligne 2', null);
    $marc = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Org::assignMembership($ids['member'], null, $teamId);
    Org::assignMembership($marc, null, $teamId);

    Calendar::createEvent([
        'userId' => $marc, 'title' => 'Rendez-vous médical', 'startDate' => '2026-01-05',
        'endDate' => '2026-01-05', 'category' => 'Personnel', 'visibility' => 'Privé',
    ]);
    Calendar::createEvent([
        'userId' => $marc, 'title' => 'Revue de production', 'startDate' => '2026-01-06',
        'endDate' => '2026-01-06', 'category' => 'Réunion', 'visibility' => 'Équipe',
    ]);

    $claire = (array) Users::byId($ids['member']);
    $agenda = Calendar::monthAgenda($claire, '2026-01', false, true);
    $titres = [];
    foreach ($agenda as $entries) {
        foreach ($entries as $entry) {
            $titres[] = $entry['title'];
        }
    }
    assertTrue(in_array('Revue de production', $titres, true), 'un événement d\'équipe n\'est pas partagé');
    assertTrue(!in_array('Rendez-vous médical', $titres, true), 'un événement privé a fuité');
});

Tests::run('l\'agenda partagé dit qu\'un collègue est absent, pas pourquoi', function (): void {
    $ids = seed();
    $teamId = Org::createTeam('Ligne 2', null);
    $marc = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Org::assignMembership($ids['member'], null, $teamId);
    Org::assignMembership($marc, null, $teamId);
    $requestId = Hr::createRequest($marc, 'Absence maladie', '2026-01-05', '2026-01-06', 2, 'Grippe');
    Hr::approve($requestId, $ids['admin'], '');

    $agenda = Calendar::monthAgenda((array) Users::byId($ids['member']), '2026-01', false, true);
    $entry = null;
    foreach ($agenda['2026-01-05'] as $candidate) {
        if ($candidate['source'] === 'absence') {
            $entry = $candidate;
        }
    }
    assertTrue($entry !== null, 'l\'absence du collègue n\'apparaît pas');
    assertSame('Absent', $entry['title']);
    assertTrue(!str_contains(json_encode($agenda, JSON_UNESCAPED_UNICODE), 'Grippe'), 'le motif a fuité');
    assertTrue(!str_contains(json_encode($agenda, JSON_UNESCAPED_UNICODE), 'maladie'), 'le type a fuité');
});

Tests::run('un événement se crée et se supprime depuis l\'écran', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/agenda', [
        'mois' => '2026-01', 'title' => 'Comité de pilotage', 'start_date' => '2026-01-12',
        'start_time' => '09:30', 'end_time' => '11:00', 'category' => 'Réunion', 'visibility' => 'Privé',
    ]);
    $events = Calendar::personalEvents($ids['member'], '2026-01-01', '2026-01-31');
    assertSame(1, count($events));

    // Heure de fin avant l'heure de début : refusée.
    visit('POST', '/agenda', [
        'mois' => '2026-01', 'title' => 'Impossible', 'start_date' => '2026-01-13',
        'start_time' => '15:00', 'end_time' => '09:00', 'visibility' => 'Privé',
    ]);
    assertSame(1, count(Calendar::personalEvents($ids['member'], '2026-01-01', '2026-01-31')));

    visit('POST', '/agenda/' . (int) $events[0]['id'] . '/supprimer', ['mois' => '2026-01']);
    assertSame(0, count(Calendar::personalEvents($ids['member'], '2026-01-01', '2026-01-31')));
});

Tests::run('on ne supprime pas l\'événement d\'un autre', function (): void {
    $ids = seed();
    $marc = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $eventId = Calendar::createEvent([
        'userId' => $marc, 'title' => 'À moi', 'startDate' => '2026-01-05', 'endDate' => '2026-01-05',
        'category' => 'Personnel', 'visibility' => 'Privé',
    ]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/agenda/$eventId/supprimer", ['mois' => '2026-01']);
    assertSame(1, count(Calendar::personalEvents($marc, '2026-01-01', '2026-01-31')));
});

Tests::run('deux réservations ne se chevauchent pas sur la même salle', function (): void {
    $ids = seed();
    $roomId = Rooms::create(['name' => 'Salle Atlas', 'location' => '1er étage', 'capacity' => 8]);
    assertTrue(Rooms::book($roomId, $ids['member'], 'Atelier', '2026-01-12', '09:00', '10:30')['ok']);

    $clash = Rooms::book($roomId, $ids['admin'], 'Autre', '2026-01-12', '10:00', '11:00');
    assertSame('clash', $clash['reason']);

    // Le créneau qui commence exactement à la fin du précédent passe.
    assertTrue(Rooms::book($roomId, $ids['admin'], 'Suivant', '2026-01-12', '10:30', '11:30')['ok']);
});

Tests::run('une réservation contrôle ses horaires', function (): void {
    $ids = seed();
    $roomId = Rooms::create(['name' => 'Salle Atlas']);
    assertSame('bad-time', Rooms::book($roomId, $ids['member'], 'x', '2026-01-12', '9h', '10:00')['reason']);
    assertSame('bad-range', Rooms::book($roomId, $ids['member'], 'x', '2026-01-12', '11:00', '10:00')['reason']);

    Rooms::toggle($roomId);
    assertSame('inactive', Rooms::book($roomId, $ids['member'], 'x', '2026-01-12', '09:00', '10:00')['reason']);
});

Tests::run('chacun n\'annule que ses propres réservations', function (): void {
    $ids = seed();
    $roomId = Rooms::create(['name' => 'Salle Atlas']);
    Rooms::book($roomId, $ids['admin'], 'Direction', '2026-01-12', '09:00', '10:00');
    $booking = Rooms::bookings('2026-01-12', '2026-01-12')[0];

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/salles/' . (int) $booking['id'] . '/annuler', ['jour' => '2026-01-12']);
    assertSame(1, count(Rooms::bookings('2026-01-12', '2026-01-12')), 'la réservation d\'un autre a été annulée');
});

Tests::run('une salle réservée occupe l\'agenda de qui l\'a prise', function (): void {
    $ids = seed();
    $roomId = Rooms::create(['name' => 'Salle Atlas']);
    Rooms::book($roomId, $ids['member'], 'Atelier qualité', '2026-01-12', '09:00', '10:30');
    $agenda = Calendar::monthAgenda((array) Users::byId($ids['member']), '2026-01');
    assertSame('Atelier qualité', $agenda['2026-01-12'][0]['title']);
    assertSame('salle', $agenda['2026-01-12'][0]['source']);
});

Tests::run('les écrans agenda et salles s\'affichent', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $agenda = visit('GET', '/agenda', [], ['mois' => '2026-01']);
    assertSame(200, $agenda->status);
    assertContains('2026-01', $agenda->body);
    assertSame(200, visit('GET', '/salles')->status);
});
