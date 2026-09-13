<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Announcements;
use App\Modules\Hr;
use App\Modules\OneOnOne;
use App\Modules\Org;
use App\Modules\Users;

/** Une équipe encadrée par Claire, avec Marc dedans. */
function equipe(array $ids): array
{
    $departmentId = Org::createDepartment('Production');
    $teamId = Org::createTeam('Ligne 2', $departmentId);
    $marc = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Org::assignMembership($marc, null, $teamId);
    Org::addManager('team', $teamId, $ids['member']);
    return ['department' => $departmentId, 'team' => $teamId, 'marc' => $marc];
}

Tests::run('l\'espace manager est fermé à qui n\'encadre personne', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/mon-equipe')->status);
});

Tests::run('encadrer une équipe ouvre l\'espace manager', function (): void {
    $ids = seed();
    equipe($ids);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $response = visit('GET', '/mon-equipe');
    assertSame(200, $response->status);
    assertContains('Leroy', $response->body);
});

Tests::run('le manager voit les absences de son équipe, pas des autres', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $ailleurs = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);
    Hr::createRequest($team['marc'], 'Congés payés', '2026-01-05', '2026-01-09', 5, 'Ski');
    Hr::createRequest($ailleurs, 'Congés payés', '2026-02-02', '2026-02-06', 5, 'Randonnée');

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $body = visit('GET', '/mon-equipe')->body;
    assertContains('Ski', $body);
    assertTrue(!str_contains($body, 'Randonnée'), 'une absence hors périmètre est visible');
});

Tests::run('un manager ne publie que sur un périmètre qu\'il encadre', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $autreTeam = Org::createTeam('Ligne 3', $team['department']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/mon-equipe/actualites', ['title' => 'Chez les autres', 'target' => 'team:' . $autreTeam]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM announcements'), 'publication hors périmètre acceptée');

    visit('POST', '/mon-equipe/actualites', ['title' => 'Réunion sécurité', 'body' => 'Mardi 9h', 'target' => 'team:' . $team['team']]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM announcements'));
});

Tests::run('un manager ne supprime pas une actualité d\'entreprise', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $companyWide = Announcements::create($ids['admin'], 'company', null, "Fermeture estivale", '');
    $mine = Announcements::create($ids['member'], 'team', $team['team'], 'Réunion', '');

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/mon-equipe/actualites/$companyWide/supprimer");
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM announcements'), "une actualité d'entreprise a été supprimée");

    visit('POST', "/mon-equipe/actualites/$mine/supprimer");
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM announcements'));
});

Tests::run('un point individuel ne se planifie que dans son périmètre', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $ailleurs = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/mon-equipe/points', ['employee_id' => (string) $ailleurs, 'scheduled_on' => '2026-03-02']);
    assertSame(0, count(OneOnOne::forManager($ids['member'])), 'un point a été pris hors périmètre');

    visit('POST', '/mon-equipe/points', ['employee_id' => (string) $team['marc'], 'scheduled_on' => '2026-03-02', 'topics' => 'Objectifs']);
    assertSame(1, count(OneOnOne::forManager($ids['member'])));
});

Tests::run('les notes privées du manager ne sortent jamais de son écran', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $point = OneOnOne::create($ids['member'], $team['marc'], '2026-03-02', 'Objectifs');
    OneOnOne::update((int) $point['id'], $ids['member'], [
        'scheduledOn' => '2026-03-02', 'heldOn' => '2026-03-02', 'topics' => 'Objectifs',
        'sharedNote' => 'Nous avons parlé de la charge.', 'privateNote' => 'À surveiller : fatigue.',
        'mood' => 3, 'nextOn' => '2026-04-02', 'status' => 'Tenu',
    ]);

    $shared = OneOnOne::forEmployee($team['marc']);
    assertSame(1, count($shared));
    assertTrue(!array_key_exists('private_note', $shared[0]), 'la note privée figure dans la vue du collaborateur');
    assertContains('charge', $shared[0]['shared_note']);
});

Tests::run('un point « tenu » exige sa date de tenue', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $point = OneOnOne::create($ids['member'], $team['marc'], '2026-03-02');
    $id = (int) $point['id'];

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/mon-equipe/points/$id/modifier", ['scheduled_on' => '2026-03-02', 'status' => 'Tenu']);
    assertSame('Planifié', OneOnOne::byId($id)['status'], 'un point tenu sans date est passé');

    visit('POST', "/mon-equipe/points/$id/modifier", [
        'scheduled_on' => '2026-03-02', 'held_on' => '2026-03-02', 'status' => 'Tenu', 'mood' => '4',
    ]);
    assertSame('Tenu', OneOnOne::byId($id)['status']);
    assertSame(4, (int) OneOnOne::byId($id)['mood']);
});

Tests::run('une humeur hors échelle est refusée', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $id = (int) OneOnOne::create($ids['member'], $team['marc'], '2026-03-02')['id'];
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/mon-equipe/points/$id/modifier", [
        'scheduled_on' => '2026-03-02', 'held_on' => '2026-03-02', 'status' => 'Tenu', 'mood' => '9',
    ]);
    assertSame('Planifié', OneOnOne::byId($id)['status']);
});

Tests::run('un point ne s\'ouvre pas à un autre manager', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $autreManager = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);
    $autreTeam = Org::createTeam('Ligne 3', $team['department']);
    Org::addManager('team', $autreTeam, $autreManager);
    $id = (int) OneOnOne::create($ids['member'], $team['marc'], '2026-03-02')['id'];

    assertSame(null, OneOnOne::ownedBy($id, $autreManager));
    assertTrue(!OneOnOne::remove($id, $autreManager), 'un autre manager a supprimé le point');
});

Tests::run('perdre le périmètre ferme l\'accès au point', function (): void {
    $ids = seed();
    $team = equipe($ids);
    $id = (int) OneOnOne::create($ids['member'], $team['marc'], '2026-03-02')['id'];
    assertTrue(OneOnOne::ownedBy($id, $ids['member']) !== null);

    // Marc change d'équipe : le point n'est plus accessible, sans rechargement.
    Org::assignMembership($team['marc'], null, null);
    assertSame(null, OneOnOne::ownedBy($id, $ids['member']));
});

Tests::run('le salarié lit ses points individuels, sans la note privée du manager', function (): void {
    $ids = seed();
    $team = equipe($ids);
    // Claire encadre Marc : c'est donc Marc qui lit son point sur son espace.
    $point = OneOnOne::create($ids['member'], $team['marc'], '2099-03-02', 'Charge de travail');
    OneOnOne::update((int) $point['id'], $ids['member'], [
        'scheduledOn' => '2099-03-02', 'heldOn' => '2099-03-02', 'topics' => 'Charge de travail',
        'sharedNote' => 'Point partagé : priorités revues.', 'privateNote' => 'À surveiller, entre nous.',
        'mood' => 4, 'nextOn' => '2099-06-02', 'status' => 'Tenu',
    ]);

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    $page = visit('GET', '/mon-espace')->body;

    assertContains('02/03/2099', $page);
    assertContains('Charge de travail', $page);
    assertContains('Point partagé', $page);
    assertContains('02/06/2099', $page, 'le prochain rendez-vous est annoncé');
    // Ce que le manager garde pour lui ne traverse pas l'écran du salarié.
    assertTrue(!str_contains($page, 'entre nous'), 'la note privée reste privée');
});
