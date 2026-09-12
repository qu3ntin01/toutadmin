<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Events;
use App\Modules\Notifications;
use App\Modules\Org;
use App\Modules\Users;

/** Une instance avec un événement ouvert, trois places, et deux salariés de plus. */
function seedEvents(int $capacity = 3): array
{
    $ids = seed();
    $ids['marc'] = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $ids['sofia'] = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);
    $ids['event'] = Events::create([
        'title' => 'Séminaire annuel', 'kind' => 'Séminaire', 'startsAt' => '2026-11-05 09:00',
        'scope' => 'company', 'capacity' => $capacity, 'status' => 'Ouvert',
        'organizerId' => $ids['admin'],
    ]);
    return $ids;
}

function loginAdminEvents(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

Tests::run("un brouillon n'existe pas encore pour l'entreprise", function (): void {
    $ids = seedEvents();
    $draft = Events::create([
        'title' => 'Fête surprise', 'kind' => 'Convivialité', 'startsAt' => '2026-12-20 18:00',
        'scope' => 'company', 'capacity' => 0, 'status' => 'Brouillon',
    ]);

    $member = (array) Users::byId($ids['member']);
    $visible = array_column(Events::visibleTo($member), 'id');
    assertTrue(!in_array($draft, array_map('intval', $visible), true), 'un brouillon est visible');

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(404, visit('GET', '/evenements/' . $draft)->status);
    // L'organisateur, lui, le voit.
    loginAdminEvents();
    assertSame(200, visit('GET', '/evenements/' . $draft)->status);
});

Tests::run('un événement de service ne sort pas du service', function (): void {
    $ids = seedEvents();
    $department = Org::createDepartment('Production');
    $other = Org::createDepartment('Commerce');
    Org::assignMembership($ids['member'], $department, null);

    $event = Events::create([
        'title' => 'Réunion production', 'kind' => 'Réunion générale', 'startsAt' => '2026-11-10 09:00',
        'scope' => 'department', 'scopeId' => $department, 'capacity' => 0, 'status' => 'Ouvert',
    ]);

    assertSame(true, Events::concerns((array) Events::byId($event), (array) Users::byId($ids['member'])));
    assertSame(false, Events::concerns((array) Events::byId($event), (array) Users::byId($ids['marc'])));

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(404, visit('GET', '/evenements/' . $event)->status);
    assertSame(403, visit('POST', '/evenements/' . $event . '/inscription')->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM event_registrations'));
});

Tests::run("au-delà de la capacité on n'est pas refusé, on attend", function (): void {
    $ids = seedEvents(2);

    assertSame('Inscrit', Events::register($ids['event'], $ids['member'])['status']);
    assertSame('Inscrit', Events::register($ids['event'], $ids['marc'])['status']);
    // Les places prises, l'événement se déclare complet de lui-même.
    assertSame('Complet', Events::byId($ids['event'])['status']);

    assertSame("Liste d'attente", Events::register($ids['event'], $ids['sofia'])['status']);
    assertSame(2, (int) Events::byId($ids['event'])['taken']);
    assertSame(1, (int) Events::byId($ids['event'])['waiting']);

    // S'inscrire deux fois ne se peut pas.
    assertSame('deja', Events::register($ids['event'], $ids['member'])['reason']);
});

Tests::run('un désistement fait monter le premier qui attend, et le prévient', function (): void {
    $ids = seedEvents(1);
    Events::register($ids['event'], $ids['member']);
    Events::register($ids['event'], $ids['marc']);
    Events::register($ids['event'], $ids['sofia']);

    // Le premier arrivé en liste d'attente monte, pas le dernier.
    $verdict = Events::cancel($ids['event'], $ids['member']);
    assertSame(true, $verdict['ok']);
    assertSame($ids['marc'], $verdict['promoted']);
    assertSame('Inscrit', Events::registrationOf($ids['event'], $ids['marc'])['status']);
    assertSame("Liste d'attente", Events::registrationOf($ids['event'], $ids['sofia'])['status']);

    // Une place libérée que personne ne voit est une place perdue : il est prévenu.
    $notice = Notifications::forUser($ids['marc'])[0];
    assertContains('Place libérée', $notice['title']);
    assertSame('/evenements/' . $ids['event'], $notice['link']);

    // La place étant reprise, l'événement reste complet.
    assertSame('Complet', Events::byId($ids['event'])['status']);
});

Tests::run('une absence constatée garde la place, une annulation la rend', function (): void {
    $ids = seedEvents(2);
    Events::register($ids['event'], $ids['member']);
    Events::register($ids['event'], $ids['marc']);

    $registration = Events::registrationOf($ids['event'], $ids['member']);
    assertSame(true, Events::markAttendance((int) $registration['id'], 'Absent'));
    // La place a été réservée et l'événement a eu lieu : elle reste occupée.
    assertSame(2, (int) Events::byId($ids['event'])['taken']);
    assertSame(false, Events::markAttendance((int) $registration['id'], 'Peut-être'));

    Events::cancel($ids['event'], $ids['marc']);
    assertSame(1, (int) Events::byId($ids['event'])['taken']);
    assertSame('Ouvert', Events::byId($ids['event'])['status'], 'une place rendue rouvre les inscriptions');
});

Tests::run('les inscriptions closes ferment la porte', function (): void {
    $ids = seedEvents();
    Db::run('UPDATE company_events SET registration_closes_on = ? WHERE id = ?', ['2020-01-01', $ids['event']]);
    assertSame('cloture', Events::register($ids['event'], $ids['member'])['reason']);

    Db::run("UPDATE company_events SET registration_closes_on = NULL, status = 'Clos' WHERE id = ?", [$ids['event']]);
    assertSame('ferme', Events::register($ids['event'], $ids['member'])['reason']);
});

Tests::run("annuler un événement prévient ceux qui s'étaient inscrits", function (): void {
    $ids = seedEvents();
    Events::register($ids['event'], $ids['member']);
    Events::register($ids['event'], $ids['marc']);
    Events::cancel($ids['event'], $ids['marc']);

    loginAdminEvents();
    visit('POST', '/evenements/' . $ids['event'] . '/modifier', [
        'title' => 'Séminaire annuel', 'kind' => 'Séminaire', 'starts_at' => '2026-11-05 09:00',
        'scope' => 'company', 'capacity' => '3', 'status' => 'Annulé',
    ]);

    assertSame('Annulé', Events::byId($ids['event'])['status']);
    assertSame(1, count(Notifications::forUser($ids['member'])));
    assertContains('Annulé', Notifications::forUser($ids['member'])[0]['title']);
    // Celui qui s'était désisté n'a pas à être prévenu : il ne venait pas.
    assertSame(0, count(Notifications::forUser($ids['marc'])));
});

Tests::run("la capacité ne descend pas sous le nombre d'inscrits", function (): void {
    $ids = seedEvents(3);
    Events::register($ids['event'], $ids['member']);
    Events::register($ids['event'], $ids['marc']);

    loginAdminEvents();
    visit('POST', '/evenements/' . $ids['event'] . '/modifier', [
        'title' => 'Séminaire annuel', 'kind' => 'Séminaire', 'starts_at' => '2026-11-05 09:00',
        'scope' => 'company', 'capacity' => '1', 'status' => 'Ouvert',
    ]);
    assertSame(3, (int) Events::byId($ids['event'])['capacity'], 'la capacité est descendue sous les inscrits');
});

Tests::run("l'organisation est réservée à l'administration et aux RH", function (): void {
    $ids = seedEvents();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('POST', '/evenements', [
        'title' => 'Pirate', 'kind' => 'Atelier', 'starts_at' => '2026-11-05 09:00', 'scope' => 'company',
    ])->status);
    assertSame(403, visit('POST', '/evenements/' . $ids['event'] . '/supprimer')->status);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM company_events'));

    // S'inscrire, en revanche, est ouvert à tous.
    assertSame(302, visit('POST', '/evenements/' . $ids['event'] . '/inscription')->status);
    assertSame('Inscrit', Events::registrationOf($ids['event'], $ids['member'])['status']);

    // La liste nominative des participants reste à l'organisateur.
    $page = visit('GET', '/evenements/' . $ids['event']);
    assertSame(200, $page->status);
    assertTrue(!str_contains($page->body, 'Émargement'), 'un salarié voit la feuille d\'émargement');
});

Tests::run("les dates et montants d'un événement sont contrôlés", function (): void {
    $ids = seedEvents();
    loginAdminEvents();

    $refuse = static function (array $body): void {
        visit('POST', '/evenements', array_merge([
            'title' => 'Atelier', 'kind' => 'Atelier', 'starts_at' => '2026-11-05 09:00', 'scope' => 'company',
        ], $body));
    };

    $refuse(['starts_at' => '2026-13-45 99:99']);
    $refuse(['ends_at' => '2026-11-04 09:00']);
    $refuse(['registration_closes_on' => '2026-11-30']);
    $refuse(['kind' => 'Rave']);
    $refuse(['scope' => 'department', 'scope_id' => '9999']);
    $refuse(['budget' => 'beaucoup']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM company_events'), 'un événement invalide a été créé');

    // Et un événement valide passe, en brouillon.
    visit('POST', '/evenements', [
        'title' => 'Atelier sécurité', 'kind' => 'Atelier', 'starts_at' => '2026-11-05 09:00',
        'ends_at' => '2026-11-05 12:00', 'scope' => 'company', 'capacity' => '10',
        'registration_closes_on' => '2026-11-01', 'budget' => '1 200,50',
    ]);
    $created = Db::get("SELECT * FROM company_events WHERE title = 'Atelier sécurité'");
    assertSame('Brouillon', $created['status']);
    assertSame(1200.5, (float) $created['budget']);
});

Tests::run("l'écran des événements s'affiche des deux côtés", function (): void {
    $ids = seedEvents();
    Events::register($ids['event'], $ids['member']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/evenements');
    assertSame(200, $page->status);
    assertContains('Séminaire annuel', $page->body);

    loginAdminEvents();
    $manage = visit('GET', '/evenements/' . $ids['event']);
    assertSame(200, $manage->status);
    assertContains('Claire', $manage->body);
});
