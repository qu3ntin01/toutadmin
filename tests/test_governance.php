<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Governance;
use App\Modules\Surveys;

Tests::run('la direction est fermée à qui ne dirige pas', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    assertSame(403, visit('GET', '/direction')->status);
    assertSame(403, visit('POST', '/direction/reunions', ['title' => 'Comité', 'kind' => 'Comité de direction',
        'held_on' => gmdate('Y-m-d')])->status);
    assertSame(403, visit('POST', '/direction/risques', ['title' => 'Risque'])->status);
    assertSame(0, count(Governance::meetings()));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/direction')->status);
});

Tests::run('un compte rendu marque la réunion tenue', function (): void {
    $ids = seed();
    $meeting = Governance::createMeeting(['title' => 'Comité de direction de mai', 'kind' => 'Comité de direction',
        'heldOn' => '2026-05-12', 'agenda' => "Budget, recrutement", 'chairId' => $ids['admin']]);

    assertSame('Planifiée', Governance::meetingById($meeting)['status']);
    Governance::setMinutes($meeting, 'Budget arrêté, recrutement reporté.');

    $after = Governance::meetingById($meeting);
    assertSame('Tenue', $after['status']);
    assertContains('Budget arrêté', $after['minutes']);
    assertSame("Budget, recrutement", $after['agenda'], "le compte rendu a écrasé l'ordre du jour");
});

Tests::run('un participant convié une fois ne compte pas deux fois', function (): void {
    $ids = seed();
    $meeting = Governance::createMeeting(['title' => 'Revue', 'kind' => 'Revue de direction', 'heldOn' => '2026-05-12']);

    assertTrue(Governance::invite($meeting, $ids['member']));
    assertTrue(!Governance::invite($meeting, $ids['member']), 'le même participant entre deux fois');
    assertSame(1, count(Governance::attendees($meeting)));
    assertSame('Attendu', Governance::attendees($meeting)[0]['attendance']);

    assertTrue(Governance::setAttendance($meeting, $ids['member'], 'Excusé'));
    assertTrue(!Governance::setAttendance($meeting, $ids['member'], 'Endormi'));
    assertSame('Excusé', Governance::attendees($meeting)[0]['attendance']);

    Governance::removeAttendee($meeting, $ids['member']);
    assertSame(0, count(Governance::attendees($meeting)));
});

Tests::run('supprimer une réunion laisse les décisions au registre', function (): void {
    $ids = seed();
    $meeting = Governance::createMeeting(['title' => 'Comité', 'kind' => 'Comité de direction', 'heldOn' => '2026-05-12']);
    Governance::createDecision(['meetingId' => $meeting, 'title' => 'Ouvrir un poste',
        'decidedOn' => '2026-05-12', 'decidedBy' => $ids['admin'], 'scope' => 'Entreprise']);

    Governance::deleteMeeting($meeting);
    $decisions = Governance::decisions();
    assertSame(1, count($decisions), 'la décision est partie avec la réunion');
    assertSame(null, $decisions[0]['meeting_id']);
});

Tests::run('la criticité retenue est la résiduelle, quand elle est cotée', function (): void {
    seed();
    // Sans cotation résiduelle, on retient la criticité brute.
    $gross = Governance::createRisk(['category' => 'Financier', 'title' => 'Dépendance à un client',
        'likelihood' => 4, 'impact' => 4, 'treatment' => 'Réduire']);
    $risk = Governance::riskById($gross);
    assertSame(16, $risk['gross']);
    assertSame(null, $risk['residual']);
    assertSame(16, $risk['retained']);
    assertTrue($risk['critical']);

    // Une fois le traitement coté, c'est lui qui compte.
    Governance::updateRisk($gross, ['category' => 'Financier', 'title' => 'Dépendance à un client',
        'likelihood' => 4, 'impact' => 4, 'treatment' => 'Réduire', 'residualLikelihood' => 2,
        'residualImpact' => 2, 'status' => 'Maîtrisé']);
    $treated = Governance::riskById($gross);
    assertSame(16, $treated['gross']);
    assertSame(4, $treated['residual']);
    assertSame(4, $treated['retained']);
    assertTrue(!$treated['critical']);
});

Tests::run('la matrice place chaque risque à sa case', function (): void {
    seed();
    Governance::createRisk(['category' => 'Informatique', 'title' => 'Panne du SI',
        'likelihood' => 5, 'impact' => 5, 'treatment' => 'Réduire']);
    Governance::createRisk(['category' => 'Juridique et conformité', 'title' => 'Contentieux',
        'likelihood' => 1, 'impact' => 1, 'treatment' => 'Accepter']);
    // Un risque clos ne figure pas à la matrice.
    $closed = Governance::createRisk(['category' => 'Réputation', 'title' => 'Avis en ligne',
        'likelihood' => 3, 'impact' => 3, 'treatment' => 'Accepter']);
    Governance::updateRisk($closed, ['category' => 'Réputation', 'title' => 'Avis en ligne',
        'likelihood' => 3, 'impact' => 3, 'treatment' => 'Accepter', 'status' => 'Clos']);

    $matrix = Governance::matrix();
    // Probabilité 5 en haut, impact 5 à droite.
    assertSame(1, count($matrix[0][4]));
    assertSame('Panne du SI', $matrix[0][4][0]['title']);
    assertSame(1, count($matrix[4][0]));
    assertSame(0, count($matrix[2][2]), 'un risque clos reste à la matrice');
});

Tests::run('les indicateurs de direction comptent le retard', function (): void {
    $ids = seed();
    Governance::createMeeting(['title' => 'Comité', 'kind' => 'Comité de direction', 'heldOn' => gmdate('Y-m-d')]);
    // Une réunion d'il y a un an sort de la fenêtre de quatre-vingt-dix jours.
    Governance::createMeeting(['title' => 'Vieux comité', 'kind' => 'Comité de direction',
        'heldOn' => gmdate('Y-m-d', strtotime('-200 days'))]);

    $decision = Governance::createDecision(['title' => 'Décider', 'decidedOn' => gmdate('Y-m-d'), 'scope' => 'Entreprise']);
    Governance::createAction(['decisionId' => $decision, 'label' => 'En retard',
        'assigneeId' => $ids['member'], 'dueDate' => gmdate('Y-m-d', strtotime('-10 days'))]);
    Governance::createAction(['decisionId' => $decision, 'label' => 'À venir',
        'dueDate' => gmdate('Y-m-d', strtotime('+10 days'))]);
    $done = Governance::createAction(['decisionId' => $decision, 'label' => 'Faite',
        'dueDate' => gmdate('Y-m-d', strtotime('-30 days'))]);
    Governance::setActionStatus($done, 'Faite');

    $summary = Governance::summary();
    assertSame(1, $summary['meetings']);
    assertSame(1, $summary['decisions']);
    assertSame(2, $summary['openActions'], 'une action faite compte encore comme ouverte');
    assertSame(1, $summary['overdueActions']);
    assertSame(gmdate('Y-m-d'), Db::get('SELECT done_on FROM meeting_actions WHERE id = ?', [$done])['done_on']);
});

Tests::run("l'écran de direction inscrit réunions, décisions et risques", function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    visit('POST', '/direction/reunions', ['title' => 'Comité de mai', 'kind' => 'Comité de direction',
        'held_on' => '2026-05-12', 'starts_at' => '09:00', 'location' => 'Salle du conseil']);
    assertSame(1, count(Governance::meetings()));

    // Un type de réunion inventé n'entre pas.
    visit('POST', '/direction/reunions', ['title' => 'Autre', 'kind' => 'Apéritif', 'held_on' => '2026-05-12']);
    assertSame(1, count(Governance::meetings()));

    // Une cotation hors échelle est refusée.
    visit('POST', '/direction/risques', ['title' => 'Risque', 'category' => 'Financier',
        'treatment' => 'Réduire', 'likelihood' => '9', 'impact' => '3']);
    assertSame(0, count(Governance::risks()));

    visit('POST', '/direction/risques', ['title' => 'Dépendance client', 'category' => 'Financier',
        'treatment' => 'Réduire', 'likelihood' => '4', 'impact' => '4', 'reference' => 'R-2026-01']);
    assertSame(1, count(Governance::risks()));

    $page = visit('GET', '/direction');
    assertSame(200, $page->status);
    assertContains('Comité de mai', $page->body);
    assertContains('Dépendance client', $page->body);
    assertSame(404, visit('GET', '/direction/reunions/9999')->status);
});

// ---------------------------------------------------------------- sondages

Tests::run("un sondage ouvert ne se modifie plus", function (): void {
    $ids = seed();
    $survey = Surveys::create(['title' => 'Baromètre du printemps', 'kind' => 'Baromètre social',
        'audience' => 'Tous', 'createdBy' => $ids['admin']]);

    // Sans question, il ne s'ouvre pas : un questionnaire vide ne mesure rien.
    $refused = Surveys::open($survey);
    assertTrue(!$refused['ok']);
    assertContains('au moins une question', $refused['message']);

    Surveys::addQuestion($survey, ['label' => 'Ambiance ?', 'type' => 'echelle', 'required' => true]);
    assertTrue(Surveys::open($survey)['ok']);
    assertSame('Ouvert', Surveys::byId($survey)['status']);
    assertTrue(!Surveys::open($survey)['ok'], 'un sondage ouvert se rouvre');

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/direction/sondages/' . $survey . '/questions', ['label' => 'Tardive', 'type' => 'texte']);
    assertSame(1, count(Surveys::questions($survey)), 'une question entre après ouverture');
});

Tests::run("les réponses ne portent aucun nom", function (): void {
    $ids = seed();
    $survey = Surveys::create(['title' => 'Baromètre', 'kind' => 'Baromètre social',
        'audience' => 'Tous', 'createdBy' => $ids['admin']]);
    $question = Surveys::addQuestion($survey, ['label' => 'Ambiance ?', 'type' => 'echelle', 'required' => true]);
    Surveys::open($survey);

    $verdict = Surveys::submit($survey, $ids['member'], ['q_' . $question => '4']);
    assertTrue($verdict['ok']);

    // La table des réponses ne connaît que la question et la valeur.
    $answer = Db::get('SELECT * FROM survey_answers WHERE question_id = ?', [$question]);
    assertSame('4', $answer['value']);
    assertTrue(!array_key_exists('user_id', $answer), 'une réponse porte un identifiant de personne');

    // La participation, elle, est nominative : elle empêche de voter deux fois.
    assertTrue(Surveys::hasAnswered($survey, $ids['member']));
    assertTrue(!Surveys::submit($survey, $ids['member'], ['q_' . $question => '5'])['ok']);
    assertSame(1, Surveys::participationCount($survey));
});

Tests::run('une valeur hors barème est refusée', function (): void {
    $ids = seed();
    $survey = Surveys::create(['title' => 'Enquête', 'kind' => 'Enquête', 'audience' => 'Tous', 'createdBy' => $ids['admin']]);
    $scale = Surveys::addQuestion($survey, ['label' => 'Note ?', 'type' => 'echelle', 'required' => true]);
    $yesNo = Surveys::addQuestion($survey, ['label' => 'Satisfait ?', 'type' => 'oui_non', 'required' => false]);
    $choice = Surveys::addQuestion($survey, ['label' => 'Site ?', 'type' => 'choix',
        'choices' => ['Paris', 'Lyon'], 'required' => false]);
    Surveys::open($survey);

    assertContains("hors de l'échelle", Surveys::submit($survey, $ids['member'], ['q_' . $scale => '9'])['message']);
    assertContains('Oui ou Non', Surveys::submit($survey, $ids['member'],
        ['q_' . $scale => '3', 'q_' . $yesNo => 'Peut-être'])['message']);
    assertContains('Choix inconnu', Surveys::submit($survey, $ids['member'],
        ['q_' . $scale => '3', 'q_' . $choice => 'Marseille'])['message']);
    assertContains('Question sans réponse', Surveys::submit($survey, $ids['member'], [])['message']);
    assertSame(0, Surveys::participationCount($survey), 'un refus a quand même laissé une participation');
});

Tests::run("sous le seuil d'anonymat, aucun résultat ne sort", function (): void {
    $ids = seed();
    // Dix salariés, pour que le seuil de cinq réponses soit atteignable.
    $people = [$ids['member']];
    for ($i = 0; $i < 9; $i++) {
        $people[] = \App\Modules\Users::create([
            'role' => 'employee', 'email' => "membre$i@entreprise.com", 'password' => 'Salariee-Demo-2026!',
            'first_name' => 'Membre', 'last_name' => "N$i",
        ]);
    }

    $survey = Surveys::create(['title' => 'Baromètre', 'kind' => 'Baromètre social',
        'audience' => 'Tous', 'createdBy' => $ids['admin']]);
    $question = Surveys::addQuestion($survey, ['label' => 'Ambiance ?', 'type' => 'echelle', 'required' => true]);
    Surveys::open($survey);

    foreach (array_slice($people, 0, 4) as $person) {
        Surveys::submit($survey, $person, ['q_' . $question => '4']);
    }
    $withheld = Surveys::results($survey);
    assertTrue($withheld['withheld'], 'quatre réponses suffisent à dévoiler le détail');
    assertSame(0, $withheld['questions'][0]['count']);
    assertSame(null, $withheld['questions'][0]['average']);

    // La cinquième réponse fait passer le seuil.
    Surveys::submit($survey, $people[4], ['q_' . $question => '2']);
    $shown = Surveys::results($survey);
    assertTrue(!$shown['withheld']);
    assertSame(5, $shown['answered']);
    assertSame(3.6, $shown['questions'][0]['average']);
    assertSame(50, $shown['rate']);
});

Tests::run("le baromètre ne retient que les sondages clos et lisibles", function (): void {
    $ids = seed();
    $people = [$ids['member']];
    for ($i = 0; $i < 9; $i++) {
        $people[] = \App\Modules\Users::create([
            'role' => 'employee', 'email' => "membre$i@entreprise.com", 'password' => 'Salariee-Demo-2026!',
            'first_name' => 'Membre', 'last_name' => "N$i",
        ]);
    }

    $survey = Surveys::create(['title' => 'Baromètre 2026', 'kind' => 'Baromètre social',
        'audience' => 'Tous', 'closesOn' => '2026-03-31', 'createdBy' => $ids['admin']]);
    $question = Surveys::addQuestion($survey, ['label' => 'Ambiance ?', 'type' => 'echelle', 'required' => true]);
    Surveys::open($survey);
    foreach (array_slice($people, 0, 6) as $person) {
        Surveys::submit($survey, $person, ['q_' . $question => '4']);
    }

    // Tant qu'il est ouvert, il n'entre pas au baromètre.
    assertSame(0, count(Surveys::barometer()));
    assertTrue(Surveys::close($survey));

    $points = Surveys::barometer();
    assertSame(1, count($points));
    assertSame(4.0, $points[0]['score']);
    assertSame('2026-03-31', $points[0]['closedOn']);

    // Une enquête ordinaire, même close, n'est pas un baromètre.
    $other = Surveys::create(['title' => 'Enquête', 'kind' => 'Enquête', 'audience' => 'Tous', 'createdBy' => $ids['admin']]);
    Surveys::addQuestion($other, ['label' => 'Note ?', 'type' => 'echelle', 'required' => true]);
    Surveys::open($other);
    Surveys::close($other);
    assertSame(1, count(Surveys::barometer()));
});

Tests::run("un sondage de service ne s'ouvre qu'à ce service", function (): void {
    $ids = seed();
    $department = \App\Modules\Org::createDepartment('Production', '');
    $other = \App\Modules\Users::create([
        'role' => 'employee', 'email' => 'autre@entreprise.com', 'password' => 'Salariee-Demo-2026!',
        'first_name' => 'Autre', 'last_name' => 'Personne',
    ]);
    Db::run('UPDATE users SET department_id = ? WHERE id = ?', [$department, $ids['member']]);

    $survey = Surveys::create(['title' => 'Production', 'kind' => 'Enquête', 'audience' => 'Service',
        'audienceId' => $department, 'createdBy' => $ids['admin']]);
    Surveys::addQuestion($survey, ['label' => 'Ça va ?', 'type' => 'oui_non']);
    Surveys::open($survey);

    assertSame(1, count(Surveys::audienceUsers(Surveys::byId($survey))));
    assertTrue(Surveys::isInvited(Surveys::byId($survey), $ids['member']));
    assertTrue(!Surveys::isInvited(Surveys::byId($survey), $other));
    assertSame(1, Surveys::pendingCountFor($ids['member']));
    assertSame(0, Surveys::pendingCountFor($other));

    // Le salarié d'un autre service ne peut pas répondre par la porte de derrière.
    assertContains('ne vous est pas destiné', Surveys::submit($survey, $other, [])['message']);
});
