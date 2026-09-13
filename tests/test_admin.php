<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\Settings;
use App\Modules\Catalogue;
use App\Modules\Org;
use App\Modules\Themes;
use App\Modules\Tools;
use App\Modules\Users;

function admin(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

function membre(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run('la console est fermée à qui n\'est pas administrateur', function (): void {
    seed();
    membre();
    assertSame(403, visit('GET', '/admin')->status);
    assertSame(403, visit('POST', '/admin/services', ['name' => 'Tentative'])->status);
    assertSame(0, count(Org::departments()), 'un salarié a pu créer un service');
});

Tests::run('la console s\'ouvre pour l\'administration', function (): void {
    seed();
    admin();
    $response = visit('GET', '/admin');
    assertSame(200, $response->status);
    assertContains('Moreau', $response->body);
});

Tests::run('un service se crée, se renomme et se supprime sans emporter ses membres', function (): void {
    $ids = seed();
    admin();
    visit('POST', '/admin/services', ['name' => 'Production', 'description' => 'Ateliers']);
    $departments = Org::departments();
    assertSame(1, count($departments));
    assertSame('Production', $departments[0]['name']);

    $id = (int) $departments[0]['id'];
    visit('POST', "/admin/services/$id/modifier", ['name' => 'Production industrielle']);
    assertSame('Production industrielle', Org::departmentById($id)['name']);

    visit('POST', "/admin/employes/{$ids['member']}/rattachement", ['department_id' => (string) $id]);
    assertSame($id, (int) Users::byId($ids['member'])['department_id']);

    visit('POST', "/admin/services/$id/supprimer");
    assertSame(null, Org::departmentById($id));
    assertTrue(Users::byId($ids['member']) !== null, 'le membre a été supprimé avec son service');
});

Tests::run('deux services ne peuvent pas porter le même nom', function (): void {
    seed();
    admin();
    visit('POST', '/admin/services', ['name' => 'Production']);
    visit('POST', '/admin/services', ['name' => 'production']);
    assertSame(1, count(Org::departments()), 'un doublon de nom est passé');
});

Tests::run('une équipe porte le service de son rattachement', function (): void {
    $ids = seed();
    admin();
    visit('POST', '/admin/services', ['name' => 'Production']);
    $departmentId = (int) Org::departments()[0]['id'];
    visit('POST', '/admin/equipes', ['name' => 'Ligne 2', 'department_id' => (string) $departmentId]);
    $teamId = (int) Org::teams()[0]['id'];

    // Rattaché à l'équipe seulement : le service suit, il ne diverge pas.
    visit('POST', "/admin/employes/{$ids['member']}/rattachement", ['team_id' => (string) $teamId]);
    $member = Users::byId($ids['member']);
    assertSame($teamId, (int) $member['team_id']);
    assertSame($departmentId, (int) $member['department_id']);
});

Tests::run('l\'encadrement accepte plusieurs managers et se retire', function (): void {
    $ids = seed();
    admin();
    visit('POST', '/admin/services', ['name' => 'Production']);
    $departmentId = (int) Org::departments()[0]['id'];

    visit('POST', '/admin/encadrement', [
        'scope' => 'department', 'scope_id' => (string) $departmentId, 'user_id' => (string) $ids['member'],
    ]);
    assertSame(1, count(Org::managersOf('department', $departmentId)));
    assertTrue(Org::isManager($ids['member']));

    visit('POST', '/admin/encadrement/retirer', [
        'scope' => 'department', 'scope_id' => (string) $departmentId, 'user_id' => (string) $ids['member'],
    ]);
    assertSame(0, count(Org::managersOf('department', $departmentId)));
});

Tests::run('un membre créé reçoit un mot de passe temporaire à changer', function (): void {
    seed();
    admin();
    visit('POST', '/admin/employes', [
        'first_name' => 'Marc', 'last_name' => 'Leroy', 'email' => 'marc.leroy@entreprise.com',
        'grade' => 'Technicien', 'contract_type' => 'CDI',
    ]);
    $created = Users::byEmail('marc.leroy@entreprise.com');
    assertTrue($created !== null, 'aucun compte créé');
    assertSame('employee', $created['role']);
    assertSame(1, (int) $created['must_change_password']);
    assertSame(25, (int) $created['leave_balance'], 'quota de congés non appliqué');

    // Le mot de passe temporaire est affiché une fois, dans le message.
    $flash = \App\Core\Session::get('flash');
    assertContains('Mot de passe temporaire', $flash['message']);
});

Tests::run('un freelance n\'ouvre pas de compteur de congés', function (): void {
    seed();
    admin();
    visit('POST', '/admin/employes', [
        'first_name' => 'Sofia', 'last_name' => 'Nadir', 'email' => 'sofia@entreprise.com',
        'grade' => 'Responsable', 'contract_type' => 'Freelance', 'daily_rate' => '520,50',
    ]);
    $created = Users::byEmail('sofia@entreprise.com');
    assertSame(0, (int) $created['leave_balance']);
    assertSame(520.5, (float) $created['daily_rate'], 'la virgule décimale n\'a pas été acceptée');
});

Tests::run('la création refuse un grade ou un contrat inventé', function (): void {
    seed();
    admin();
    visit('POST', '/admin/employes', [
        'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@entreprise.com',
        'grade' => 'Empereur', 'contract_type' => 'CDI',
    ]);
    assertSame(null, Users::byEmail('x@entreprise.com'), 'un grade inconnu est passé');

    visit('POST', '/admin/employes', [
        'first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@entreprise.com',
        'grade' => 'Technicien', 'contract_type' => 'Esclavage',
    ]);
    assertSame(null, Users::byEmail('x@entreprise.com'), 'un type de contrat inconnu est passé');
});

Tests::run('la réinitialisation ferme les sessions du membre', function (): void {
    $ids = seed();
    Db::run('INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)',
        ['session-du-membre', $ids['member'], '{}', time() + 3600]);
    admin();
    visit('POST', "/admin/employes/{$ids['member']}/reinitialiser");

    assertSame(null, Db::get('SELECT sid FROM sessions WHERE sid = ?', ['session-du-membre']), 'session survivante');
    assertSame(1, (int) Users::byId($ids['member'])['must_change_password']);
});

Tests::run('désactiver un membre ferme aussi ses sessions', function (): void {
    $ids = seed();
    Db::run('INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)',
        ['session-du-membre', $ids['member'], '{}', time() + 3600]);
    admin();
    visit('POST', "/admin/employes/{$ids['member']}/statut");
    assertSame(0, (int) Users::byId($ids['member'])['active']);
    assertSame(null, Db::get('SELECT sid FROM sessions WHERE sid = ?', ['session-du-membre']));
});

Tests::run('la messagerie du membre se règle depuis l\'administration', function (): void {
    $ids = seed();
    admin();
    visit('POST', "/admin/employes/{$ids['member']}/messagerie", [
        'mail_address' => 'claire@vertane.test', 'mail_imap_host' => 'imap.vertane.test',
        'mail_imap_port' => '993', 'mail_smtp_host' => 'smtp.vertane.test', 'mail_smtp_port' => '587',
    ]);
    $member = Users::byId($ids['member']);
    assertSame('claire@vertane.test', $member['mail_address']);
    assertSame(993, (int) $member['mail_imap_port']);

    // Un port hors bornes est refusé plutôt qu'écrit.
    visit('POST', "/admin/employes/{$ids['member']}/messagerie", [
        'mail_address' => 'claire@vertane.test', 'mail_imap_port' => '70000',
    ]);
    assertSame(993, (int) Users::byId($ids['member'])['mail_imap_port'], 'un port invalide est passé');
});

Tests::run('un outil s\'ajoute, se modifie et s\'affecte une seule fois', function (): void {
    $ids = seed();
    admin();
    visit('POST', '/admin/outils', ['name' => 'Suite bureautique', 'category' => 'Bureautique', 'login_url' => 'https://exemple.fr']);
    $tools = Tools::all();
    assertSame(1, count($tools));
    $toolId = (int) $tools[0]['id'];

    visit('POST', "/admin/outils/$toolId/modifier", ['name' => 'Suite bureautique v2', 'login_url' => 'pas-une-url']);
    assertSame('Suite bureautique', Tools::byId($toolId)['name'], 'une URL invalide est passée');

    visit('POST', '/admin/affectations', ['employee_id' => (string) $ids['member'], 'tool_id' => (string) $toolId, 'username' => 'c.moreau']);
    assertSame(1, count(Tools::assignments()));

    // Deux fois le même outil pour la même personne : refusé par l'index unique.
    visit('POST', '/admin/affectations', ['employee_id' => (string) $ids['member'], 'tool_id' => (string) $toolId]);
    assertSame(1, count(Tools::assignments()), 'affectation en double acceptée');
});

Tests::run('une actualité vise l\'entreprise ou un périmètre réel', function (): void {
    seed();
    admin();
    visit('POST', '/admin/actualites', ['title' => 'Fermeture estivale', 'body' => 'Du 5 au 17 août.', 'target' => 'company']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM announcements'));

    visit('POST', '/admin/actualites', ['title' => 'Périmètre inventé', 'target' => 'department:999']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM announcements'), 'un périmètre inexistant est passé');
});

Tests::run('les droits transverses s\'accordent et se retirent', function (): void {
    $ids = seed();
    admin();
    foreach (array_keys(Users::ROLE_FLAGS) as $flag) {
        visit('POST', "/admin/droits/$flag", ['employee_id' => (string) $ids['member']]);
        assertSame(1, (int) Users::byId($ids['member'])[$flag], "droit $flag non accordé");
        visit('POST', "/admin/droits/$flag/{$ids['member']}/retirer");
        assertSame(0, (int) Users::byId($ids['member'])[$flag], "droit $flag non retiré");
    }
});

Tests::run('un module s\'active et se désactive sans perdre ses données', function (): void {
    seed();
    admin();
    assertTrue(!Catalogue::isEnabled('comptabilite'), 'un module est actif par défaut');
    visit('POST', '/admin/modules/comptabilite', ['enabled' => 'on']);
    assertTrue(Catalogue::isEnabled('comptabilite'));
    visit('POST', '/admin/modules/comptabilite', []);
    assertTrue(!Catalogue::isEnabled('comptabilite'));

    visit('POST', '/admin/modules/inconnu', ['enabled' => 'on']);
    assertSame('', Settings::get('module.inconnu'));
});

Tests::run('la palette et le nom de l\'instance se changent depuis l\'administration', function (): void {
    seed();
    admin();
    visit('POST', '/admin/apparence', ['palette' => 'moderne']);
    assertSame('moderne', Themes::current());

    visit('POST', '/admin/apparence', ['palette' => 'inventee']);
    assertSame('moderne', Themes::current(), 'une palette inconnue est passée');

    visit('POST', '/admin/entreprise', ['company_name' => 'Vertane SA', 'annual_leave_days' => '27']);
    assertSame('Vertane SA', Settings::get('company_name'));
    assertSame('27', Settings::get('annual_leave_days'));
});

Tests::run('l\'organigramme montre les sans-rattachement', function (): void {
    $ids = seed();
    admin();
    $response = visit('GET', '/organigramme');
    assertSame(200, $response->status);
    assertContains('Moreau', $response->body);
    assertContains('Sans rattachement', $response->body);
});

Tests::run('l\'organigramme cache hors annuaire, sauf pour l\'administration', function (): void {
    seed();
    // Une troisième personne, retirée de l'annuaire : c'est elle qu'on cherche
    // dans la page, pas la personne connectée — dont le nom figure de toute
    // façon en en-tête.
    $hidden = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Db::run('UPDATE users SET directory_hidden = 1 WHERE id = ?', [$hidden]);

    admin();
    assertContains('Leroy', visit('GET', '/organigramme')->body);

    \App\Core\Session::destroy();
    membre();
    assertTrue(!str_contains(visit('GET', '/organigramme')->body, 'Leroy'),
        'une personne hors annuaire apparaît pour les autres');
});

Tests::run('chaque décision d\'administration laisse une trace', function (): void {
    seed();
    admin();
    visit('POST', '/admin/services', ['name' => 'Production']);
    $actions = array_column(Db::all('SELECT action FROM audit_log'), 'action');
    assertTrue(in_array('service.cree', $actions, true), 'création de service non tracée');
    assertSame(null, \App\Core\Audit::verify(), 'journal rompu');
});

Tests::run('un droit transverse se donne en sachant ce qu’il ouvre', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $page = visit('GET', '/admin')->body;

    // Chaque droit dit sa portée et sa nuance avant qu'on l'accorde.
    assertContains('Demandes, soldes de congés et fiches de paie', $page);
    assertContains('Tiers, contrats, factures, budgets', $page);
    assertContains('Parc logiciel, accès applicatifs', $page);
    assertContains('Recueil des signalements', $page);
    // Celle du dispositif d'alerte est la plus importante à lire : on désigne
    // les référents, on ne lit pas les signalements.
    assertContains('vous ne lisez pas les signalements', $page);

    // Les quatre droits sont attribuables, et la liste part vide.
    foreach (['is_hr', 'is_finance', 'is_it', 'is_referent'] as $flag) {
        assertContains('/admin/droits/' . $flag, $page);
    }
    assertContains('Aucun référent désigné', $page);

    visit('POST', '/admin/droits/is_hr', ['employee_id' => (string) $ids['member']]);
    $page = visit('GET', '/admin')->body;
    assertContains('claire.moreau@entreprise.com', $page, 'le membre nommé figure avec son adresse');
    assertContains('/admin/droits/is_hr/' . $ids['member'] . '/retirer', $page);
    assertSame(1, (int) \App\Core\Db::value('SELECT is_hr FROM users WHERE id = ?', [$ids['member']]));

    visit('POST', '/admin/droits/is_hr/' . $ids['member'] . '/retirer');
    assertSame(0, (int) \App\Core\Db::value('SELECT is_hr FROM users WHERE id = ?', [$ids['member']]));
});
