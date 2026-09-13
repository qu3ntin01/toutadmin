<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Timesheet;
use App\Modules\Users;

/** Une instance avec un freelance au TJM de 400 €, et un salarié en CDI. */
function seedTimesheet(): array
{
    $ids = seed();
    $ids['freelance'] = Users::create([
        'role' => 'employee', 'email' => 'marc.dubois@entreprise.com', 'password' => 'Freelance-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Dubois', 'contract_type' => 'Freelance',
    ]);
    Db::run('UPDATE users SET daily_rate = 400 WHERE id = ?', [$ids['freelance']]);
    return $ids;
}

function loginFreelance(): void
{
    visit('POST', '/connexion', ['email' => 'marc.dubois@entreprise.com', 'password' => 'Freelance-Demo-2026!']);
}

/** Pose une entrée close, en heures écoulées depuis maintenant. */
function pastEntry(int $employeeId, float $startHoursAgo, float $endHoursAgo): int
{
    $iso = static fn (float $hoursAgo): string => gmdate('Y-m-d\TH:i:s', time() - (int) round($hoursAgo * 3600)) . '.000Z';
    return Db::insert(
        'INSERT INTO time_entries (employee_id, clock_in, clock_out) VALUES (?, ?, ?)',
        [$employeeId, $iso($startHoursAgo), $iso($endHoursAgo)]
    );
}

Tests::run('un seul pointage ouvert à la fois', function (): void {
    $ids = seedTimesheet();

    assertSame(true, Timesheet::clockIn($ids['freelance'])['ok']);
    $second = Timesheet::clockIn($ids['freelance']);
    // Deux pointages ouverts compteraient les heures deux fois : le second est
    // refusé, pas empilé.
    assertSame(false, $second['ok']);
    assertSame('already-open', $second['reason']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM time_entries WHERE employee_id = ?', [$ids['freelance']]));

    assertSame(true, Timesheet::clockOut($ids['freelance'])['ok']);
    assertSame(null, Timesheet::openEntry($ids['freelance']));

    // Rien à fermer : le refus le dit, sans rien écrire.
    $again = Timesheet::clockOut($ids['freelance']);
    assertSame(false, $again['ok']);
    assertSame('no-open-entry', $again['reason']);

    // Et l'on repointe : une nouvelle entrée, pas la réouverture de l'ancienne.
    assertSame(true, Timesheet::clockIn($ids['freelance'])['ok']);
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM time_entries WHERE employee_id = ?', [$ids['freelance']]));
});

Tests::run('la durée du pointage en cours se mesure jusqu’à maintenant', function (): void {
    $ids = seedTimesheet();
    pastEntry($ids['freelance'], 5, 3);
    assertSame(2.0, round(Timesheet::durationHours(Timesheet::entries($ids['freelance'])[0]), 6));

    // Une entrée ouverte court : sa durée dépend de l'instant où on la lit.
    Db::run(
        'INSERT INTO time_entries (employee_id, clock_in) VALUES (?, ?)',
        [$ids['freelance'], gmdate('Y-m-d\TH:i:s', time() - 3600) . '.000Z']
    );
    $open = Timesheet::openEntry($ids['freelance']);
    assertSame(1.0, round(Timesheet::durationHours($open), 3));
    assertSame(2.0, round(Timesheet::durationHours($open, time() + 3600), 3), 'une heure plus tard, une heure de plus');

    // Une horloge qui recule ne rend pas une durée négative.
    assertSame(0.0, Timesheet::durationHours($open, time() - 7200));
});

Tests::run('le taux horaire se déduit du TJM sur une base de huit heures', function (): void {
    $ids = seedTimesheet();
    assertSame(50.0, Timesheet::hourlyRate(400));
    assertSame(0.0, Timesheet::hourlyRate(null), 'sans TJM, pas d’estimation inventée');
    assertSame(0.0, Timesheet::hourlyRate(0));

    pastEntry($ids['freelance'], 6, 4);
    $stats = Timesheet::stats($ids['freelance'], 400);
    assertSame(1, $stats['entryCount']);
    assertSame(2.0, round($stats['totalHours'], 6));
    assertSame(50.0, $stats['hourlyRate']);
    assertSame(100.0, round($stats['totalEstimate'], 6));

    // Sans TJM, les heures restent comptées : c'est l'estimation qui tombe à zéro.
    $sansTjm = Timesheet::stats($ids['freelance'], null);
    assertSame(2.0, round($sansTjm['totalHours'], 6));
    assertSame(0.0, $sansTjm['totalEstimate']);
});

Tests::run('le mois en cours se compte à part du total', function (): void {
    $ids = seedTimesheet();
    pastEntry($ids['freelance'], 3, 1);

    // Une entrée du mois précédent : hors du décompte mensuel, dans le total.
    $lastMonth = gmdate('Y-m-d\TH:i:s', (int) strtotime('first day of last month 09:00 UTC')) . '.000Z';
    Db::run(
        'INSERT INTO time_entries (employee_id, clock_in, clock_out) VALUES (?, ?, ?)',
        [$ids['freelance'], $lastMonth, gmdate('Y-m-d\TH:i:s', (int) strtotime($lastMonth) + 4 * 3600) . '.000Z']
    );

    $stats = Timesheet::stats($ids['freelance'], 400);
    assertSame(2, $stats['entryCount']);
    assertSame(6.0, round($stats['totalHours'], 6));
    assertSame(2.0, round($stats['monthHours'], 6));
    assertSame(100.0, round($stats['monthEstimate'], 6));
    assertSame(300.0, round($stats['totalEstimate'], 6));
});

Tests::run('le pointage est celui du freelance, et de lui seul', function (): void {
    $ids = seedTimesheet();

    // Une salariée en CDI n'a pas de pointage : ses heures ne se comptent pas ainsi.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/mon-espace')->body;
    assertTrue(!str_contains($page, '/mon-espace/pointage/commencer'), "le pointage n'est pas proposé");

    assertSame(302, visit('POST', '/mon-espace/pointage/commencer')->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM time_entries'), 'rien n’est écrit');

    // Le freelance, lui, pointe pour lui-même.
    loginFreelance();
    assertContains('/mon-espace/pointage/commencer', visit('GET', '/mon-espace')->body);
    visit('POST', '/mon-espace/pointage/commencer');
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM time_entries WHERE employee_id = ?', [$ids['freelance']]));

    // L'écran montre alors le pointage en cours et le bouton qui le termine.
    $page = visit('GET', '/mon-espace')->body;
    assertContains('/mon-espace/pointage/terminer', $page);
    assertContains('id="live-timer"', $page);

    visit('POST', '/mon-espace/pointage/terminer');
    assertSame(null, Timesheet::openEntry($ids['freelance']));
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM time_entries'), 'terminer ne crée pas d’entrée');
});

Tests::run('la fiche de temps est un écran RH, fermé aux autres', function (): void {
    $ids = seedTimesheet();
    pastEntry($ids['freelance'], 4, 2);

    assertSame(302, visit('GET', '/rh/temps/' . $ids['freelance'])->status, 'sans session, rien');

    // Le freelance ne voit pas la supervision, même la sienne.
    loginFreelance();
    assertSame(403, visit('GET', '/rh/temps/' . $ids['freelance'])->status);
    assertSame(403, visit('POST', '/rh/temps/' . $ids['freelance'] . '/cloturer')->status);

    // Les RH, oui.
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/rh/temps/' . $ids['freelance'])->body;
    assertContains('Marc Dubois', $page);
    assertContains('2,00 h', $page, 'la durée de l’entrée est lisible');
    assertContains('400,00 €', $page, 'le TJM figure en sous-titre');

    // Un membre qui n'existe pas renvoie à la liste plutôt que d'ouvrir une fiche vide.
    assertSame(302, visit('GET', '/rh/temps/9999')->status);
});

Tests::run('les RH clôturent un pointage oublié et corrigent une entrée', function (): void {
    $ids = seedTimesheet();
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    Timesheet::clockIn($ids['freelance']);
    $entry = pastEntry($ids['freelance'], 30, 28);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    // Un pointage laissé ouvert gonfle les heures : les RH le ferment.
    assertSame(302, visit('POST', '/rh/temps/' . $ids['freelance'] . '/cloturer')->status);
    assertSame(null, Timesheet::openEntry($ids['freelance']));
    // Une seconde clôture ne trouve plus rien à fermer.
    visit('POST', '/rh/temps/' . $ids['freelance'] . '/cloturer');

    // Une entrée fausse se supprime ; la suppression est bornée au membre visé.
    Timesheet::removeEntry($ids['member'], $entry);
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM time_entries'), 'le mauvais membre ne supprime rien');

    visit('POST', '/rh/temps/' . $ids['freelance'] . '/' . $entry . '/supprimer');
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM time_entries'));
});

Tests::run('la rémunération des freelances se lit dans l’espace RH', function (): void {
    $ids = seedTimesheet();
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    pastEntry($ids['freelance'], 5, 3);
    Timesheet::clockIn($ids['freelance']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/rh')->body;

    assertContains('id="remuneration"', $page);
    assertContains('Marc Dubois', $page);
    assertContains('/rh/temps/' . $ids['freelance'], $page);
    // Deux heures comptées, le TJM rappelé, et le pointage en cours qui se voit.
    // L'estimation n'est pas figée dans le test : l'entrée ouverte avance à
    // chaque seconde, et c'est bien ce qu'on attend d'elle.
    assertContains('2,0 h', $page);
    assertContains('400,00 €', $page);
    assertContains('status-on', $page);
});
