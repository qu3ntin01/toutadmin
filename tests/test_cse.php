<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Cse;
use App\Modules\Users;

/** Une instance avec plusieurs salariés : un scrutin demande des électeurs. */
function seedCse(int $extra = 3): array
{
    $ids = seed();
    $ids['people'] = [$ids['member']];
    for ($i = 0; $i < $extra; $i++) {
        $ids['people'][] = Users::create([
            'role' => 'employee', 'email' => "elu$i@entreprise.com", 'password' => 'Salariee-Demo-2026!',
            'first_name' => 'Élu', 'last_name' => "Numéro$i",
        ]);
    }
    return $ids;
}

Tests::run("le CSE ne s'ouvre pas à l'administration ni aux freelances", function (): void {
    $ids = seedCse();

    // L'administration n'est pas représentée par le comité.
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(403, visit('GET', '/cse')->status);

    // Un salarié, oui.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/cse')->status);

    // Un freelance non salarié, non.
    Db::run("UPDATE users SET contract_type = 'Freelance' WHERE id = ?", [$ids['member']]);
    assertSame(403, visit('GET', '/cse')->status);
});

Tests::run("la gestion du CSE est réservée aux élus en mandat", function (): void {
    $ids = seedCse();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/cse/gestion')->status);

    Cse::addMandate(['userId' => $ids['member'], 'mandateRole' => 'Titulaire',
        'startedOn' => '2026-01-01', 'createdBy' => $ids['admin']]);
    assertSame(200, visit('GET', '/cse/gestion')->status);

    // Un mandat échu ne rouvre pas la porte.
    Db::run('UPDATE cse_mandates SET ends_on = ? WHERE user_id = ?',
        [gmdate('Y-m-d', strtotime('-1 day')), $ids['member']]);
    assertTrue(!Cse::isElected($ids['member']));
    assertSame(403, visit('GET', '/cse/gestion')->status);
});

Tests::run("un scrutin sans candidat validé ne s'ouvre pas", function (): void {
    $ids = seedCse();
    $election = Cse::createElection(['title' => 'Élection 2026', 'seats' => 2, 'createdBy' => $ids['admin']]);

    $refused = Cse::setElectionStatus($election, 'Vote');
    assertTrue(!$refused['ok']);
    assertSame('no-candidate', $refused['reason']);

    // Une candidature déposée ne suffit pas : il faut qu'elle soit validée.
    Cse::applyForElection($election, $ids['member'], 'Je me présente.');
    assertSame('no-candidate', Cse::setElectionStatus($election, 'Vote')['reason']);

    $candidacy = Cse::candidacies($election)[0];
    assertTrue(Cse::reviewCandidacy((int) $candidacy['id'], 'Validée', $ids['admin'])['ok']);
    assertTrue(Cse::setElectionStatus($election, 'Vote')['ok']);
    assertSame('Vote', Cse::electionById($election)['status']);
});

Tests::run('une candidature ne se retire plus une fois le vote ouvert', function (): void {
    $ids = seedCse();
    $election = Cse::createElection(['title' => 'Élection', 'seats' => 2, 'createdBy' => $ids['admin']]);
    Cse::applyForElection($election, $ids['member'], 'Profession de foi.');

    // On ne se présente pas deux fois.
    assertSame('already-applied', Cse::applyForElection($election, $ids['member'], '')['reason']);

    $candidacy = Cse::candidacies($election)[0];
    Cse::reviewCandidacy((int) $candidacy['id'], 'Validée', $ids['admin']);
    Cse::setElectionStatus($election, 'Vote');

    $refused = Cse::withdrawCandidacy($election, $ids['member']);
    assertTrue(!$refused['ok']);
    assertSame('closed', $refused['reason']);
    assertSame(1, count(Cse::candidacies($election)));

    // Et l'examen des candidatures se ferme avec la phase.
    assertSame('closed', Cse::reviewCandidacy((int) $candidacy['id'], 'Refusée', $ids['admin'])['reason']);
});

Tests::run("le bulletin et l'émargement restent deux lignes sans lien", function (): void {
    $ids = seedCse();
    $election = Cse::createElection(['title' => 'Élection', 'seats' => 1, 'createdBy' => $ids['admin']]);
    Cse::applyForElection($election, $ids['people'][1], 'Moi.');
    $candidacy = (int) Cse::candidacies($election)[0]['id'];
    Cse::reviewCandidacy($candidacy, 'Validée', $ids['admin']);
    Cse::setElectionStatus($election, 'Vote');

    assertTrue(Cse::castBallot($election, $ids['member'], $candidacy)['ok']);
    assertTrue(Cse::hasVoted($election, $ids['member']));

    // Le bulletin ne porte pas le votant.
    $ballot = Db::get('SELECT * FROM cse_ballots WHERE election_id = ?', [$election]);
    assertTrue(!array_key_exists('user_id', $ballot), 'un bulletin porte le nom du votant');
    assertSame($candidacy, (int) $ballot['candidacy_id']);

    // On ne vote qu'une fois, et pas pour un candidat refusé ou d'ailleurs.
    assertSame('already-voted', Cse::castBallot($election, $ids['member'], $candidacy)['reason']);
    assertSame('bad-candidate', Cse::castBallot($election, $ids['people'][2], 9999)['reason']);
    assertSame(1, Cse::turnout($election)['voters']);
});

Tests::run('le taux de participation se lit du corps électoral', function (): void {
    $ids = seedCse(3);
    $election = Cse::createElection(['title' => 'Élection', 'seats' => 1, 'createdBy' => $ids['admin']]);
    Cse::applyForElection($election, $ids['people'][1], '');
    $candidacy = (int) Cse::candidacies($election)[0]['id'];
    Cse::reviewCandidacy($candidacy, 'Validée', $ids['admin']);
    Cse::setElectionStatus($election, 'Vote');

    // Quatre salariés au corps électoral (l'administration n'en est pas).
    assertSame(4, Cse::turnout($election)['electorate']);
    Cse::castBallot($election, $ids['people'][0], $candidacy);
    Cse::castBallot($election, $ids['people'][2], $candidacy);
    assertSame(50.0, Cse::turnout($election)['rate']);

    // Un freelance ne vote pas, et ne pèse pas au dénominateur.
    Db::run("UPDATE users SET contract_type = 'Freelance' WHERE id = ?", [$ids['people'][3]]);
    assertSame(3, Cse::turnout($election)['electorate']);

    $results = Cse::results($election);
    assertSame(2, (int) $results[0]['votes']);
});

Tests::run('un avantage périmé disparaît de la vue salarié', function (): void {
    $ids = seedCse();
    Cse::createBenefit(['title' => 'Cinéma', 'category' => 'Culture', 'discount' => '-30 %',
        'validUntil' => gmdate('Y-m-d', strtotime('+30 days')), 'createdBy' => $ids['admin']]);
    $expired = Cse::createBenefit(['title' => 'Parc expiré', 'category' => 'Famille',
        'validUntil' => gmdate('Y-m-d', strtotime('-1 day')), 'createdBy' => $ids['admin']]);
    $off = Cse::createBenefit(['title' => 'Désactivé', 'createdBy' => $ids['admin']]);
    Cse::updateBenefit($off, ['title' => 'Désactivé', 'active' => false]);

    assertSame(3, count(Cse::benefits()));
    assertSame(1, count(Cse::benefits(true)), 'un avantage périmé ou éteint reste visible');
    assertSame('Cinéma', Cse::benefits(true)[0]['title']);
    assertSame($expired, (int) Cse::benefitById($expired)['id']);
});

Tests::run('le compte-rendu se publie, ou reste en brouillon', function (): void {
    $ids = seedCse();
    $meeting = Cse::createMeeting(['title' => 'Réunion de mars', 'meetingDate' => gmdate('Y-m-d'),
        'meetingTime' => '14:00', 'agenda' => 'Budget des activités', 'createdBy' => $ids['admin']]);

    assertTrue(Cse::saveMinutes($meeting, 'Brouillon du compte-rendu.', false));
    assertSame(0, (int) Db::get('SELECT * FROM cse_meetings WHERE id = ?', [$meeting])['minutes_published']);

    // Tant qu'il n'est pas publié, le salarié voit la réunion sans son compte-rendu.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/cse');
    assertContains('Réunion de mars', $page->body);
    assertTrue(!str_contains($page->body, 'Brouillon du compte-rendu'), 'un brouillon est lu par tous');

    assertTrue(Cse::saveMinutes($meeting, 'Compte-rendu définitif.', true));
    assertContains('Compte-rendu définitif', visit('GET', '/cse')->body);
    assertTrue(!Cse::saveMinutes(9999, '', true));
});

Tests::run("les RH tiennent les mandats, les scrutins et les convocations", function (): void {
    $ids = seedCse();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // Un mandat qui finit avant de commencer est refusé.
    visit('POST', '/rh/cse/mandats', ['user_id' => (string) $ids['member'], 'mandate_role' => 'Titulaire',
        'started_on' => '2026-06-01', 'ends_on' => '2026-05-01']);
    assertSame(0, count(Cse::mandates()));

    // Un rôle inventé aussi.
    visit('POST', '/rh/cse/mandats', ['user_id' => (string) $ids['member'], 'mandate_role' => 'Chef',
        'started_on' => '2026-01-01']);
    assertSame(0, count(Cse::mandates()));

    visit('POST', '/rh/cse/mandats', ['user_id' => (string) $ids['member'], 'mandate_role' => 'Secrétaire',
        'started_on' => '2026-01-01']);
    assertSame(1, count(Cse::mandates()));
    assertSame('Secrétaire', Cse::mandateFor($ids['member'])['mandate_role']);

    // Une élection dont le vote se clôt avant de s'ouvrir est refusée.
    visit('POST', '/rh/cse/elections', ['title' => 'Élection', 'seats' => '4',
        'vote_start' => '2026-06-10', 'vote_end' => '2026-06-01']);
    assertSame(0, count(Cse::elections()));

    visit('POST', '/rh/cse/elections', ['title' => 'Élection 2026', 'seats' => '4']);
    assertSame(1, count(Cse::elections()));

    // Une heure de réunion invalide est refusée.
    visit('POST', '/rh/cse/reunions', ['title' => 'Réunion', 'meeting_date' => gmdate('Y-m-d'), 'meeting_time' => '25:00']);
    assertSame(0, count(Cse::meetings()));
    visit('POST', '/rh/cse/reunions', ['title' => 'Réunion de mars', 'meeting_date' => gmdate('Y-m-d'),
        'meeting_time' => '14:00']);
    assertSame(1, count(Cse::meetings()));

    $page = visit('GET', '/rh');
    assertSame(200, $page->status);
    assertContains('Élection 2026', $page->body);
    assertContains('Réunion de mars', $page->body);
});

Tests::run("le salarié se présente, puis vote, par les écrans", function (): void {
    $ids = seedCse();
    $election = Cse::createElection(['title' => 'Élection', 'seats' => 1, 'createdBy' => $ids['admin']]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/cse/candidature', ['statement' => 'Je propose de rouvrir la billetterie.']);
    assertSame(1, count(Cse::candidacies($election)));
    assertContains('rouvrir la billetterie', visit('GET', '/cse')->body);

    // Retirée tant que les candidatures courent.
    visit('POST', '/cse/candidature/retirer');
    assertSame(0, count(Cse::candidacies($election)));

    // Un autre se présente, est validé, et le scrutin s'ouvre.
    Cse::applyForElection($election, $ids['people'][1], 'Moi aussi.');
    $candidacy = (int) Cse::candidacies($election)[0]['id'];
    Cse::reviewCandidacy($candidacy, 'Validée', $ids['admin']);
    Cse::setElectionStatus($election, 'Vote');

    visit('POST', '/cse/vote', ['candidacy_id' => (string) $candidacy]);
    assertTrue(Cse::hasVoted($election, $ids['member']));
    assertContains('anonyme', visit('GET', '/cse')->body);
});
