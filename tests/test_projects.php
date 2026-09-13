<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Projects;
use App\Modules\Users;

function projet(array $ids, ?int $leadId = null): int
{
    return Projects::create([
        'code' => 'PRJ-1', 'name' => 'Refonte de la ligne 2', 'leadId' => $leadId,
        'status' => 'En cours', 'startDate' => '2026-01-05', 'dueDate' => '2026-06-30',
        'budgetAmount' => 20000, 'hourlyRate' => 80, 'description' => '',
    ]);
}

Tests::run('ouvrir un projet est réservé à la gestion et aux managers', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('POST', '/projets', ['name' => 'Tentative'])->status);
    assertSame(0, count(Projects::list(true)));

    // Devenue manager, elle peut ouvrir un projet.
    $teamId = Org::createTeam('Ligne 2', null);
    Org::addManager('team', $teamId, $ids['member']);
    visit('POST', '/projets', ['name' => 'Refonte', 'status' => 'Cadrage']);
    assertSame(1, count(Projects::list(true)));
});

Tests::run('un manager qui ouvre un projet en reste responsable', function (): void {
    $ids = seed();
    $teamId = Org::createTeam('Ligne 2', null);
    Org::addManager('team', $teamId, $ids['member']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/projets', ['name' => 'Refonte', 'status' => 'Cadrage']);
    $project = Projects::list(true)[0];
    assertSame($ids['member'], (int) $project['lead_id'], 'le créateur n\'est pas responsable');
    // Et il en est membre de fait, sinon il ne verrait pas son propre projet.
    assertSame(1, count(Projects::forUser($ids['member'])));
});

Tests::run('un projet n\'est ouvert qu\'à son équipe', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', "/projets/$id")->status);

    Projects::addMember($id, $ids['member']);
    assertSame(200, visit('GET', "/projets/$id")->status);
});

Tests::run('un membre participe mais ne conduit pas le projet', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::addMember($id, $ids['member']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    assertSame(403, visit('POST', "/projets/$id/modifier", ['name' => 'Renommé', 'status' => 'En cours'])->status);
    assertSame(403, visit('POST', "/projets/$id/supprimer")->status);
    assertSame('Refonte de la ligne 2', Projects::byId($id)['name']);

    // Il peut en revanche créer une tâche et saisir son temps.
    visit('POST', "/projets/$id/taches", ['title' => 'Chiffrage']);
    assertSame(1, count(Projects::tasks($id)));
});

Tests::run('une tâche terminée reçoit sa date de fin toute seule', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    $taskId = Projects::createTask(['projectId' => $id, 'title' => 'Chiffrage']);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    visit('POST', "/projets/taches/$taskId/statut", ['status' => 'Terminée']);
    $task = Projects::taskById($taskId);
    assertSame('Terminée', $task['status']);
    assertSame(gmdate('Y-m-d'), $task['done_at']);

    // Revenir en arrière efface la date : elle ne se saisit pas à la main.
    visit('POST', "/projets/taches/$taskId/statut", ['status' => 'En cours']);
    assertSame(null, Projects::taskById($taskId)['done_at']);

    visit('POST', "/projets/taches/$taskId/statut", ['status' => 'Inventé']);
    assertSame('En cours', Projects::taskById($taskId)['status']);
});

Tests::run('le tableau range les tâches par colonne', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::createTask(['projectId' => $id, 'title' => 'A']);
    $b = Projects::createTask(['projectId' => $id, 'title' => 'B']);
    Projects::setTaskStatus($b, 'En revue');

    $board = Projects::board($id);
    assertSame(4, count($board));
    assertSame('À faire', $board[0]['status']);
    assertSame(1, count($board[0]['items']));
    assertSame('En revue', $board[2]['status']);
    assertSame('B', $board[2]['items'][0]['title']);
});

Tests::run('une saisie de temps reste dans ses bornes', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::addMember($id, $ids['member']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    visit('POST', "/projets/$id/temps", ['spent_on' => '2026-01-06', 'hours' => '30']);
    visit('POST', "/projets/$id/temps", ['spent_on' => '2026-01-06', 'hours' => '0']);
    visit('POST', "/projets/$id/temps", ['spent_on' => gmdate('Y-m-d', strtotime('+3 days')), 'hours' => '2']);
    assertSame(0, count(Projects::timeEntries($id)), 'une saisie hors bornes est passée');

    visit('POST', "/projets/$id/temps", ['spent_on' => '2026-01-06', 'hours' => '7,5', 'note' => 'Chiffrage']);
    $entries = Projects::timeEntries($id);
    assertSame(1, count($entries));
    assertSame(7.5, (float) $entries[0]['hours']);
});

Tests::run('le temps ne s\'impute pas sur la tâche d\'un autre projet', function (): void {
    $ids = seed();
    $a = projet($ids, $ids['admin']);
    $b = Projects::create(['name' => 'Autre projet', 'leadId' => $ids['admin'], 'status' => 'En cours']);
    $taskOfB = Projects::createTask(['projectId' => $b, 'title' => 'Ailleurs']);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', "/projets/$a/temps", ['spent_on' => '2026-01-06', 'hours' => '2', 'task_id' => (string) $taskOfB]);
    assertSame(0, count(Projects::timeEntries($a)));
});

Tests::run('la rentabilité compte les heures et l\'écart au budget', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::logTime(['projectId' => $id, 'userId' => $ids['admin'], 'spentOn' => '2026-01-06', 'hours' => 10]);
    Projects::logTime(['projectId' => $id, 'userId' => $ids['admin'], 'spentOn' => '2026-01-07', 'hours' => 5, 'billable' => false]);

    $profit = Projects::profitability((array) Projects::byId($id));
    assertSame(15.0, $profit['hours']);
    assertSame(10.0, $profit['billableHours']);
    assertSame(1200.0, $profit['cost']);        // 15 h × 80 €
    assertSame(18800.0, $profit['margin']);     // 20 000 € − 1 200 €
    assertSame(6, $profit['consumed']);
});

Tests::run('sans taux horaire, on rend les heures sans inventer un coût', function (): void {
    $ids = seed();
    $id = Projects::create(['name' => 'Sans taux', 'leadId' => $ids['admin'], 'status' => 'En cours', 'budgetAmount' => 5000]);
    Projects::logTime(['projectId' => $id, 'userId' => $ids['admin'], 'spentOn' => '2026-01-06', 'hours' => 4]);
    $profit = Projects::profitability((array) Projects::byId($id));
    assertSame(4.0, $profit['hours']);
    assertSame(null, $profit['cost']);
    assertSame(null, $profit['margin']);
    assertSame(null, $profit['consumed']);
});

Tests::run('chacun efface ses saisies, la gestion celles de tous', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::addMember($id, $ids['member']);
    Projects::logTime(['projectId' => $id, 'userId' => $ids['admin'], 'spentOn' => '2026-01-06', 'hours' => 3]);
    $entry = Projects::timeEntries($id)[0];

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/projets/temps/' . (int) $entry['id'] . '/supprimer');
    assertSame(1, count(Projects::timeEntries($id)), 'la saisie d\'un autre a été effacée');

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/projets/temps/' . (int) $entry['id'] . '/supprimer');
    assertSame(0, count(Projects::timeEntries($id)));
});

Tests::run('un jalon se pose, s\'atteint et se retire', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', "/projets/$id/jalons", ['title' => 'Recette', 'due_date' => '2026-04-30']);
    $milestone = Projects::milestones($id)[0];
    assertSame(null, $milestone['reached_on']);

    visit('POST', '/projets/jalons/' . (int) $milestone['id'] . '/basculer');
    assertSame(gmdate('Y-m-d'), Projects::milestones($id)[0]['reached_on']);

    visit('POST', '/projets/jalons/' . (int) $milestone['id'] . '/supprimer');
    assertSame(0, count(Projects::milestones($id)));
});

Tests::run('la liste des projets s\'affiche selon la place de chacun', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertContains('Refonte de la ligne 2', visit('GET', '/projets')->body);

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertTrue(!str_contains(visit('GET', '/projets')->body, 'Refonte de la ligne 2'),
        'un projet étranger apparaît dans la liste');
});

Tests::run('un temps non facturable se saisit, et sort de la rentabilité', function (): void {
    $ids = seed();
    $id = projet($ids, $ids['admin']);
    Projects::addMember($id, $ids['member']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    // Le formulaire porte un champ caché : une case décochée n'envoie rien, et
    // sans lui le « non » du salarié ne parviendrait jamais au serveur.
    $form = visit('GET', "/projets/$id")->body;
    assertContains('name="billable" value="0"', $form);
    assertContains('name="billable" value="1"', $form);

    visit('POST', "/projets/$id/temps", ['spent_on' => '2026-01-06', 'hours' => '4', 'billable' => '1']);
    visit('POST', "/projets/$id/temps", ['spent_on' => '2026-01-07', 'hours' => '3', 'billable' => '0']);

    $entries = Projects::timeEntries($id);
    assertSame(2, count($entries));
    assertSame(0, (int) $entries[0]['billable'], 'la dernière saisie est non facturable');
    assertSame(1, (int) $entries[1]['billable']);

    // Les heures sont toutes comptées — c'est le temps passé —, mais seules
    // les heures facturables se retrouvent dans ce qu'on peut facturer.
    $profit = Projects::profitability((array) Projects::byId($id));
    assertSame(7.0, $profit['hours']);
    assertSame(4.0, $profit['billableHours']);

    // L'écran distingue les deux d'une astérisque, comme l'autre édition.
    assertContains('3 h *', visit('GET', "/projets/$id")->body);
});
