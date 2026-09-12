<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Support;
use App\Modules\Users;

Tests::run('la référence et le délai suivent la priorité', function (): void {
    $ids = seed();
    $id = Support::create([
        'subject' => 'Écran en panne', 'body' => '', 'category' => 'Informatique',
        'priority' => 'Critique', 'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    $ticket = Support::byId($id);
    assertSame('T-00001', $ticket['reference']);
    // Critique : deux heures.
    $delta = strtotime((string) $ticket['due_at']) - time();
    assertTrue($delta > 6900 && $delta < 7300, 'délai de première réponse inattendu : ' . $delta);
});

Tests::run('changer la priorité recalcule le délai depuis l\'ouverture', function (): void {
    $ids = seed();
    $id = Support::create([
        'subject' => 'Écran', 'category' => 'Informatique', 'priority' => 'Basse',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    // L'ouverture est antidatée de dix heures.
    Db::run("UPDATE tickets SET created_at = datetime('now', '-10 hours') WHERE id = ?", [$id]);
    Support::setPriority($id, 'Haute');

    // Huit heures après une ouverture vieille de dix : le délai est dépassé.
    $ticket = Support::byId($id);
    assertTrue(strtotime((string) $ticket['due_at']) < time(), 'le délai a été recalculé depuis maintenant');
    assertTrue(Support::isOverdue($ticket), 'le ticket devrait être en retard');
});

Tests::run('répondre arrête le compteur de première réponse', function (): void {
    $ids = seed();
    $id = Support::create([
        'subject' => 'Écran', 'category' => 'Informatique', 'priority' => 'Critique',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    Db::run("UPDATE tickets SET due_at = datetime('now', '-1 hour') WHERE id = ?", [$id]);
    assertTrue(Support::isOverdue((array) Support::byId($id)));

    Support::reply($id, $ids['admin'], 'Nous regardons.');
    assertTrue(!Support::isOverdue((array) Support::byId($id)), 'le compteur tourne encore après réponse');
});

Tests::run('une note interne ne se montre pas au demandeur', function (): void {
    $ids = seed();
    $id = Support::create([
        'subject' => 'Écran', 'category' => 'Informatique', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    Support::reply($id, $ids['admin'], 'Réponse visible.');
    Support::reply($id, $ids['admin'], 'Commander un écran de rechange.', true);

    assertSame(2, count(Support::messages($id, true)));
    assertSame(1, count(Support::messages($id, false)));

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', "/support/tickets/$id");
    assertSame(200, $page->status);
    assertContains('Réponse visible', $page->body);
    assertTrue(!str_contains($page->body, 'écran de rechange'), 'une note interne a fuité');
});

Tests::run('un salarié ne voit que ses tickets', function (): void {
    $ids = seed();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $sien = Support::create([
        'subject' => 'Mon souci', 'category' => 'Informatique', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    $autreTicket = Support::create([
        'subject' => 'Souci de Marc', 'category' => 'Informatique', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $autre,
    ]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/support');
    assertContains('Mon souci', $page->body);
    assertTrue(!str_contains($page->body, 'Souci de Marc'), 'le ticket d\'un collègue est visible');
    assertSame(403, visit('GET', "/support/tickets/$autreTicket")->status);
});

Tests::run('les demandes RH ne passent pas par la file de la gestion', function (): void {
    $ids = seed();
    $gestion = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($gestion, 'is_finance', true);
    $rh = Support::create([
        'subject' => 'Erreur sur mon bulletin', 'category' => 'Ressources humaines', 'priority' => 'Haute',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    $info = Support::create([
        'subject' => 'Écran en panne', 'category' => 'Informatique', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $page = visit('GET', '/support');
    assertContains('Écran en panne', $page->body);
    assertTrue(!str_contains($page->body, 'bulletin'), 'une demande RH apparaît dans la file de la gestion');
    assertSame(403, visit('GET', "/support/tickets/$rh")->status);
    assertSame(403, visit('POST', "/support/tickets/$rh/statut", ['status' => 'Clos'])->status);
    assertSame(200, visit('GET', "/support/tickets/$info")->status);
});

Tests::run('les RH traitent les demandes RH', function (): void {
    $ids = seed();
    $rh = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($rh, 'is_hr', true);
    $id = Support::create([
        'subject' => 'Erreur sur mon bulletin', 'category' => 'Ressources humaines', 'priority' => 'Haute',
        'origin' => 'Interne', 'requesterId' => $ids['member'],
    ]);
    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    assertSame(200, visit('GET', "/support/tickets/$id")->status);
    visit('POST', "/support/tickets/$id/statut", ['status' => 'Résolu']);
    assertSame('Résolu', Support::byId($id)['status']);
});

Tests::run('un salarié n\'ouvre pas un ticket d\'origine client', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/support/tickets', [
        'subject' => 'Pour un client', 'category' => 'Client', 'priority' => 'Normale', 'origin' => 'Client',
    ]);
    $tickets = Support::list([]);
    assertSame(1, count($tickets));
    assertSame('Interne', $tickets[0]['origin'], 'un salarié a ouvert un ticket au nom d\'un client');
});

Tests::run('un ticket refuse une catégorie ou une priorité inventée', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/support/tickets', ['subject' => 'X', 'category' => 'Magie', 'priority' => 'Normale']);
    visit('POST', '/support/tickets', ['subject' => 'X', 'category' => 'Informatique', 'priority' => 'Urgentissime']);
    assertSame(0, count(Support::list([])));
});

Tests::run('un manager traite les tickets de son périmètre, hors RH', function (): void {
    $ids = seed();
    $teamId = Org::createTeam('Ligne 2', null);
    Org::addManager('team', $teamId, $ids['member']);
    $info = Support::create([
        'subject' => 'Écran en panne', 'category' => 'Informatique', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $ids['admin'],
    ]);
    $rh = Support::create([
        'subject' => 'Bulletin', 'category' => 'Ressources humaines', 'priority' => 'Normale',
        'origin' => 'Interne', 'requesterId' => $ids['admin'],
    ]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', "/support/tickets/$info")->status);
    assertSame(403, visit('GET', "/support/tickets/$rh")->status);
});

Tests::run('un article de la base de connaissances reste dans sa portée', function (): void {
    $ids = seed();
    $departmentId = Org::createDepartment('Production');
    Org::assignMembership($ids['member'], $departmentId, null);

    Support::createArticle(['title' => 'Procédure interne', 'category' => 'Admin', 'body' => '',
        'visibility' => 'Administration', 'scopeId' => null, 'authorId' => $ids['admin']]);
    Support::createArticle(['title' => 'Guide production', 'category' => 'Métier', 'body' => '',
        'visibility' => 'Service', 'scopeId' => $departmentId, 'authorId' => $ids['admin']]);
    Support::createArticle(['title' => 'Charte', 'category' => 'Général', 'body' => '',
        'visibility' => 'Entreprise', 'scopeId' => null, 'authorId' => $ids['admin']]);
    Db::run('UPDATE kb_articles SET published = 1');

    $claire = (array) Users::byId($ids['member']);
    $titres = array_column(Support::articles('', '', $claire), 'title');
    assertTrue(in_array('Charte', $titres, true));
    assertTrue(in_array('Guide production', $titres, true));
    assertTrue(!in_array('Procédure interne', $titres, true), 'un article « Administration » a fuité');

    $admin = (array) Users::byId($ids['admin']);
    assertSame(3, count(Support::articles('', '', $admin)));
});
