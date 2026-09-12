<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Planning;
use App\Modules\Users;

/** Une équipe encadrée par Claire, avec Marc dedans : le planning a un périmètre. */
function equipePlanning(array $ids): array
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

function loginClaire(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run('la semaine commence un lundi, quel que soit le jour demandé', function (): void {
    // 2026-09-12 est un samedi.
    assertSame('2026-09-07', Planning::weekStart('2026-09-12'));
    assertSame('2026-09-07', Planning::weekStart('2026-09-07'));
    assertSame('2026-09-14', Planning::weekStart('2026-09-14'));

    $days = Planning::weekDays('2026-09-07');
    assertSame(7, count($days));
    assertSame('2026-09-07', $days[0]['date']);
    assertSame('Lundi', $days[0]['label']);
    assertSame('2026-09-13', $days[6]['date']);
});

Tests::run('la durée d\'un créneau se compte en heures, même de nuit', function (): void {
    assertSame(8.0, Planning::hoursBetween('2026-09-07T09:00', '2026-09-07T17:00'));
    assertSame(7.5, Planning::hoursBetween('2026-09-07T22:00', '2026-09-08T05:30'));
    // Un créneau qui finit avant de commencer ne vaut rien.
    assertSame(0.0, Planning::hoursBetween('2026-09-07T17:00', '2026-09-07T09:00'));
});

Tests::run('planifier est réservé à qui encadre', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);

    // Consulter reste ouvert à tous : un planning illisible ne sert à personne.
    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(200, visit('GET', '/planning')->status);
    assertSame(403, visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste',
        'day' => '2026-09-07', 'start_time' => '09:00', 'end_time' => '17:00',
    ])->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM shifts'));

    loginClaire();
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-07', 'start_time' => '09:00', 'end_time' => '17:00', 'label' => 'Ouverture ligne',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts'));
});

Tests::run('un manager ne planifie que les siens', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    $etranger = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);

    loginClaire();
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $etranger, 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-07', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM shifts'), 'créneau posé hors périmètre');

    // L'administration, elle, planifie tout le monde.
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $etranger, 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-07', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts'));
});

Tests::run('deux créneaux qui se chevauchent sont refusés', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    loginClaire();

    $marc = $team['marc'];
    $poser = static function (string $start, string $end) use ($marc): void {
        visit('POST', '/planning/creneaux', [
            'user_id' => (string) $marc, 'kind' => 'Poste', 'week' => '2026-09-07',
            'day' => '2026-09-07', 'start_time' => $start, 'end_time' => $end,
        ]);
    };

    $poser('09:00', '17:00');
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts'));

    // Chevauchement partiel : refusé.
    $poser('16:00', '20:00');
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts'), 'chevauchement accepté');

    // Bord à bord : accepté, il n'y a pas de conflit.
    $poser('17:00', '20:00');
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM shifts'));

    // Un créneau qui finit avant de commencer n'existe pas.
    $poser('20:00', '19:00');
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM shifts'));
});

Tests::run('une absence accordée interdit de planifier par-dessus', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    Db::run(
        "INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, status)
         VALUES (?, 'Congés payés', '2026-09-08', '2026-09-09', 2, 'Approuvée')",
        [$team['marc']]
    );

    $clash = Planning::conflicts($team['marc'], '2026-09-08T09:00', '2026-09-08T17:00');
    assertSame(true, $clash['blocked']);
    assertSame(1, count($clash['leave']));

    loginClaire();
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-08', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM shifts'), 'créneau posé pendant des congés accordés');

    // Une demande encore en attente n'empêche rien : elle n'engage personne.
    Db::run("UPDATE hr_requests SET status = 'En attente'");
    assertSame(false, Planning::conflicts($team['marc'], '2026-09-08T09:00', '2026-09-08T17:00')['blocked']);
});

Tests::run('un brouillon reste un brouillon jusqu\'à la publication de la semaine', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    loginClaire();

    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-07', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste', 'week' => '2026-09-07',
        'day' => '2026-09-08', 'start_time' => '09:00', 'end_time' => '17:00', 'published' => '1',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts WHERE published = 0'));

    // La semaine suivante n'est pas concernée par la publication de celle-ci.
    visit('POST', '/planning/creneaux', [
        'user_id' => (string) $team['marc'], 'kind' => 'Poste', 'week' => '2026-09-14',
        'day' => '2026-09-14', 'start_time' => '09:00', 'end_time' => '17:00',
    ]);

    visit('POST', '/planning/publier', ['week' => '2026-09-07']);
    assertSame(0, (int) Db::value("SELECT COUNT(*) FROM shifts WHERE published = 0 AND starts_at < '2026-09-14'"));
    assertSame(1, (int) Db::value("SELECT COUNT(*) FROM shifts WHERE published = 0 AND starts_at >= '2026-09-14'"));
});

Tests::run('un roulement pose les jours choisis et saute les conflits', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    Db::run(
        "INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, status)
         VALUES (?, 'Congés payés', '2026-09-09', '2026-09-09', 1, 'Approuvée')",
        [$team['marc']]
    );
    loginClaire();

    visit('POST', '/planning/roulements', [
        'week' => '2026-09-07', 'name' => 'Équipe du matin', 'kind' => 'Poste',
        'start_time' => '06:00', 'end_time' => '14:00', 'weekdays' => ['1', '3', '5'],
    ]);
    $template = Planning::templates()[0];
    assertSame([1, 3, 5], $template['days']);

    $verdict = Planning::applyTemplate((int) $template['id'], [
        'userId' => $team['marc'], 'from' => '2026-09-07', 'to' => '2026-09-13',
    ]);
    assertSame(true, $verdict['ok']);
    // Lundi et vendredi posés ; mercredi sauté, il est en congés.
    assertSame(['2026-09-07', '2026-09-11'], $verdict['created']);
    assertSame(1, count($verdict['skipped']));
    assertSame('2026-09-09', $verdict['skipped'][0]['day']);
    assertSame('absence accordée', $verdict['skipped'][0]['reason']);
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM shifts'));

    // Réappliqué, le roulement ne double pas les créneaux déjà posés.
    $again = Planning::applyTemplate((int) $template['id'], [
        'userId' => $team['marc'], 'from' => '2026-09-07', 'to' => '2026-09-13',
    ]);
    assertSame([], $again['created']);
    assertSame(3, count($again['skipped']));
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM shifts'));
});

Tests::run('un poste de nuit se termine le lendemain', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);

    $id = Planning::createTemplate([
        'name' => 'Nuit', 'kind' => 'Poste', 'startTime' => '22:00', 'endTime' => '06:00', 'weekdays' => [1],
    ]);
    Planning::applyTemplate($id, ['userId' => $team['marc'], 'from' => '2026-09-07', 'to' => '2026-09-07']);

    $shift = Db::get('SELECT * FROM shifts');
    assertSame('2026-09-07T22:00', $shift['starts_at']);
    assertSame('2026-09-08T06:00', $shift['ends_at']);
    assertSame(8.0, Planning::hoursBetween($shift['starts_at'], $shift['ends_at']));
});

Tests::run('un roulement ne s\'applique pas sur plus d\'un trimestre', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    loginClaire();
    $id = Planning::createTemplate([
        'name' => 'Matin', 'kind' => 'Poste', 'startTime' => '06:00', 'endTime' => '14:00', 'weekdays' => [1],
    ]);

    visit('POST', '/planning/roulements/' . $id . '/appliquer', [
        'week' => '2026-09-07', 'user_id' => (string) $team['marc'], 'from' => '2026-01-01', 'to' => '2026-12-31',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM shifts'), 'roulement appliqué sur une année entière');

    // Supprimer le roulement ne retire pas les créneaux déjà posés.
    Planning::applyTemplate($id, ['userId' => $team['marc'], 'from' => '2026-09-07', 'to' => '2026-09-07']);
    visit('POST', '/planning/roulements/' . $id . '/supprimer', ['week' => '2026-09-07']);
    assertSame(0, count(Planning::templates()));
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM shifts'));
});

Tests::run('les astreintes se lisent à la semaine et à l\'instant', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);

    Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-07T18:00', 'endsAt' => '2026-09-08T08:00',
        'kind' => 'Astreinte', 'published' => true,
    ]);
    Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-09T09:00', 'endsAt' => '2026-09-09T17:00',
        'kind' => 'Poste', 'published' => true,
    ]);

    $onCall = Planning::onCall('2026-09-07', '2026-09-13');
    assertSame(1, count($onCall), 'un poste ordinaire est compté comme une astreinte');
    assertSame('Astreinte', $onCall[0]['kind']);

    // Qui est d'astreinte à 3 h du matin : la question se pose ainsi.
    assertSame(1, count(Planning::whoIsOnCall('2026-09-08T03:00')));
    assertSame('Leroy', Planning::whoIsOnCall('2026-09-08T03:00')[0]['last_name']);
    assertSame(0, count(Planning::whoIsOnCall('2026-09-08T09:00')));
});

Tests::run('la charge et la grille montrent la semaine par personne', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);

    Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-07T09:00', 'endsAt' => '2026-09-07T17:00',
        'kind' => 'Poste', 'teamId' => $team['team'], 'published' => true,
    ]);
    Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-08T18:00', 'endsAt' => '2026-09-09T06:00',
        'kind' => 'Astreinte', 'teamId' => $team['team'], 'published' => true,
    ]);
    Planning::create([
        'userId' => $ids['member'], 'startsAt' => '2026-09-07T09:00', 'endsAt' => '2026-09-07T12:00',
        'kind' => 'Poste', 'published' => true,
    ]);

    $load = Planning::load('2026-09-07', '2026-09-13');
    assertSame(2, count($load));
    // Le plus chargé vient en tête : 8 h + 12 h.
    assertSame(20.0, $load[0]['hours']);
    assertSame(1, $load[0]['onCall']);
    assertSame(3.0, $load[1]['hours']);

    // Filtrée sur l'équipe, la charge ne retient que ses créneaux.
    $teamLoad = Planning::load('2026-09-07', '2026-09-13', $team['team']);
    assertSame(1, count($teamLoad));

    $grid = Planning::grid('2026-09-07');
    assertSame(7, count($grid['days']));
    assertSame(2, count($grid['rows']));
    // Une ligne par personne, une colonne par jour, le créneau rangé sous son jour.
    $marcRow = $grid['rows'][0]['name'] === 'Marc Leroy' ? $grid['rows'][0] : $grid['rows'][1];
    assertSame(1, count($marcRow['days']['2026-09-07']));
    assertSame(0, count($marcRow['days']['2026-09-10']));
    assertSame(20.0, $marcRow['hours']);

    // Sans droit de planification, on ne voit que son propre planning.
    $mine = Planning::grid('2026-09-07', ['userIds' => [$ids['member']]]);
    assertSame(1, count($mine['rows']));
    assertSame(3.0, $mine['rows'][0]['hours']);
});

Tests::run('un créneau se retire, mais pas hors de son périmètre', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    $etranger = Users::create([
        'role' => 'employee', 'email' => 'sofia@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Sofia', 'last_name' => 'Nadir',
    ]);
    $sien = Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-07T09:00', 'endsAt' => '2026-09-07T17:00', 'kind' => 'Poste',
    ]);
    $autre = Planning::create([
        'userId' => $etranger, 'startsAt' => '2026-09-07T09:00', 'endsAt' => '2026-09-07T17:00', 'kind' => 'Poste',
    ]);

    loginClaire();
    visit('POST', '/planning/creneaux/' . $autre . '/supprimer');
    assertTrue(Planning::byId($autre) !== null, 'créneau supprimé hors périmètre');

    visit('POST', '/planning/creneaux/' . $sien . '/supprimer');
    assertSame(null, Planning::byId($sien));
});

Tests::run('l\'écran du planning s\'affiche pour le manager comme pour le salarié', function (): void {
    $ids = seed();
    $team = equipePlanning($ids);
    Planning::create([
        'userId' => $team['marc'], 'startsAt' => '2026-09-07T09:00', 'endsAt' => '2026-09-07T17:00',
        'kind' => 'Poste', 'label' => 'Ouverture ligne', 'published' => true,
    ]);
    Planning::createTemplate([
        'name' => 'Équipe du matin', 'kind' => 'Poste', 'startTime' => '06:00', 'endTime' => '14:00', 'weekdays' => [1, 3],
    ]);

    loginClaire();
    $page = visit('GET', '/planning', [], ['semaine' => '2026-09-07']);
    assertSame(200, $page->status);
    assertContains('Ouverture ligne', $page->body);
    assertContains('Équipe du matin', $page->body);
    assertContains('Marc Leroy', $page->body);

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    $mine = visit('GET', '/planning', [], ['semaine' => '2026-09-07']);
    assertSame(200, $mine->status);
    assertContains('Ouverture ligne', $mine->body);
    // Le salarié ne voit pas le formulaire de pose : il ne planifie personne.
    assertSame(false, str_contains($mine->body, '/planning/publier'));
});
