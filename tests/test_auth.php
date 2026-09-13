<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\Security;
use App\Core\Session;
use App\Modules\Users;

Tests::run('sans compte, tout mène à l\'installation', function (): void {
    $response = visit('GET', '/mon-espace');
    assertSame(302, $response->status);
    assertSame('/installation', $response->headers['Location']);
});

Tests::run('l\'installation crée l\'entreprise et son administrateur', function (): void {
    $response = visit('POST', '/installation', [
        'company_name' => 'Vertane Industries', 'locale' => 'fr',
        'email' => 'admin@demo.test', 'password' => 'Administration-2026!', 'confirm' => 'Administration-2026!',
    ]);
    assertSame(302, $response->status);
    assertSame('/connexion', $response->headers['Location']);
    assertSame(1, Users::count());
    assertSame('admin', Users::byEmail('admin@demo.test')['role']);
    assertSame('Vertane Industries', \App\Core\Settings::get('company_name'));
});

Tests::run('l\'installation refuse un mot de passe trop court', function (): void {
    visit('POST', '/installation', [
        'company_name' => 'Vertane', 'email' => 'admin@demo.test',
        'password' => 'court', 'confirm' => 'court',
    ]);
    assertSame(0, Users::count());
});

Tests::run("le jeton d'installation garde l'instance avant qu'elle soit à quelqu'un", function (): void {
    // Entre le dépôt des fichiers et le passage de l'assistant, l'instance est
    // à qui la trouve : le jeton referme cette fenêtre.
    \App\Core\Config::set('install_token', 'jeton-de-mise-en-ligne');
    assertSame(true, \App\Controllers\InstallController::tokenRequired());
    assertContains("Jeton d'installation", visit('GET', '/installation')->body);

    $fields = [
        'company_name' => 'Vertane Industries', 'locale' => 'fr', 'annual_leave_days' => '25',
        'email' => 'admin@demo.test', 'password' => 'Administration-2026!', 'confirm' => 'Administration-2026!',
    ];

    // Sans jeton, ou avec le mauvais, rien n'est créé — et le refus est au journal.
    visit('POST', '/installation', $fields);
    visit('POST', '/installation', array_merge($fields, ['install_token' => 'presque']));
    assertSame(0, Users::count());
    assertSame(2, (int) Db::value("SELECT COUNT(*) FROM audit_log WHERE action = 'installation.jeton_refuse'"));

    // Un préfixe juste ne renseigne pas davantage : la comparaison est à temps
    // constant, et le verdict est le même.
    visit('POST', '/installation', array_merge($fields, ['install_token' => 'jeton-de-mise-en-lign']));
    assertSame(0, Users::count());

    visit('POST', '/installation', array_merge($fields, ['install_token' => 'jeton-de-mise-en-ligne']));
    assertSame(1, Users::count());
    \App\Core\Config::set('install_token', '');
});

Tests::run("l'installation retient les congés annuels de l'entreprise", function (): void {
    visit('POST', '/installation', [
        'company_name' => 'Vertane Industries', 'locale' => 'fr', 'annual_leave_days' => '30',
        'email' => 'admin@demo.test', 'password' => 'Administration-2026!', 'confirm' => 'Administration-2026!',
    ]);
    assertSame('30', \App\Core\Settings::get('annual_leave_days'));

    // Un nombre de jours absurde est refusé avant que quoi que ce soit existe.
    Tests::fresh();
    visit('POST', '/installation', [
        'company_name' => 'Vertane Industries', 'locale' => 'fr', 'annual_leave_days' => '400',
        'email' => 'admin@demo.test', 'password' => 'Administration-2026!', 'confirm' => 'Administration-2026!',
    ]);
    assertSame(0, Users::count());
});

Tests::run('une fois installée, la page d\'installation se ferme', function (): void {
    seed();
    $response = visit('GET', '/installation');
    assertSame(302, $response->status);
    assertSame('/connexion', $response->headers['Location']);
});

Tests::run('la connexion ouvre une session', function (): void {
    seed();
    $response = visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(302, $response->status);
    assertSame('/mon-espace', $response->headers['Location']);
    assertTrue(is_array(Session::get('user')), 'aucune session ouverte');
});

Tests::run('un mot de passe faux ne dit pas pourquoi', function (): void {
    seed();
    $response = visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'faux']);
    assertSame('/connexion', $response->headers['Location']);
    assertSame(null, Session::get('user'));
    $flash = \App\Core\Session::get('flash');
    assertSame('Identifiants incorrects.', $flash['message']);
});

Tests::run('une adresse inconnue donne le même message', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'personne@nulle.part', 'password' => 'peu-importe']);
    $flash = \App\Core\Session::get('flash');
    assertSame('Identifiants incorrects.', $flash['message']);
});

Tests::run('cinq échecs verrouillent le compte', function (): void {
    seed();
    for ($i = 0; $i < 5; $i++) {
        visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'faux']);
    }
    $user = Users::byEmail('admin@demo.test');
    assertTrue(Security::isLocked($user), 'compte non verrouillé après cinq échecs');

    // Même avec le bon mot de passe, l'entrée reste fermée le temps du verrou.
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(null, Session::get('user'), 'un compte verrouillé a pu entrer');
});

Tests::run('une connexion réussie remet le compteur à zéro', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'faux']);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(0, (int) Users::byEmail('admin@demo.test')['failed_attempts']);
});

Tests::run('un compte désactivé n\'entre pas', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['member']]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(null, Session::get('user'));
});

Tests::run('un contrat échu ferme le compte à la connexion', function (): void {
    $ids = seed();
    Db::run("UPDATE users SET contract_end_date = date('now', '-1 day') WHERE id = ?", [$ids['member']]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(0, (int) Users::byId($ids['member'])['active'], 'le compte est resté actif');
    assertSame(null, Session::get('user'));
});

Tests::run('un POST sans jeton est refusé', function (): void {
    seed();
    $response = visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!', '_csrf' => 'faux']);
    assertSame(403, $response->status);
    assertSame(null, Session::get('user'));
});

Tests::run('l\'identifiant de session change à l\'ouverture', function (): void {
    seed();
    $before = Session::id();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertTrue($before !== Session::id(), 'identifiant de session inchangé après connexion');
});

Tests::run('le second facteur retient l\'entrée', function (): void {
    $ids = seed();
    $secret = \App\Core\Totp::generateSecret();
    Db::run('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?', [$secret, $ids['admin']]);

    $response = visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame('/connexion/code', $response->headers['Location']);
    assertSame(null, Session::get('user'), 'session ouverte avant le second facteur');

    $response = visit('POST', '/connexion/code', ['code' => \App\Core\Totp::currentCode($secret)]);
    assertSame('/mon-espace', $response->headers['Location']);
    assertTrue(is_array(Session::get('user')), 'le bon code n\'a pas ouvert la session');
});

Tests::run('un code de secours ne sert qu\'une fois', function (): void {
    $ids = seed();
    $secret = \App\Core\Totp::generateSecret();
    $code = \App\Core\Totp::generateRecoveryCodes(1)[0];
    Db::run('UPDATE users SET totp_enabled = 1, totp_secret = ? WHERE id = ?', [$secret, $ids['admin']]);
    Db::run('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (?, ?)',
        [$ids['admin'], \App\Core\Totp::hashRecoveryCode($code)]);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $response = visit('POST', '/connexion/code', ['code' => $code]);
    assertSame('/mon-espace', $response->headers['Location']);

    // Le même code, une seconde fois : refusé.
    Session::destroy();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $response = visit('POST', '/connexion/code', ['code' => $code]);
    assertSame('/connexion/code', $response->headers['Location']);
    assertSame(null, Session::get('user'), 'un code de secours a resservi');
});

Tests::run('le mot de passe à changer barre le reste du site', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET must_change_password = 1 WHERE id = ?', [$ids['admin']]);
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $response = visit('GET', '/annuaire');
    assertSame('/mot-de-passe', $response->headers['Location']);
});

Tests::run('changer de mot de passe ferme toutes les sessions, la sienne comprise', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $id = Users::byEmail('admin@demo.test')['id'];
    // Une seconde session, ouverte ailleurs.
    Db::run('INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)',
        ['autre-session', $id, '{}', time() + 3600]);

    $response = visit('POST', '/mot-de-passe', [
        'current' => 'Administration-2026!', 'password' => 'Nouveau-Passe-2026!', 'confirm' => 'Nouveau-Passe-2026!',
    ]);
    assertSame(null, Db::get('SELECT sid FROM sessions WHERE sid = ?', ['autre-session']), 'une session ailleurs a survécu');
    assertTrue(Security::verifyPassword('Nouveau-Passe-2026!', Users::byId((int) $id)['password_hash']));

    // On ne sait pas laquelle des sessions ouvertes est celle de l'intrus : la
    // courante tombe donc avec les autres, et l'on se reconnecte.
    assertSame('/connexion', $response->headers['Location']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$id]));
    assertSame(302, visit('GET', '/mon-espace')->status);
    $back = visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Nouveau-Passe-2026!']);
    assertSame('/mon-espace', $back->headers['Location']);
});

Tests::run('la déconnexion efface la session', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/deconnexion');
    assertSame(null, Session::get('user'));
    $response = visit('GET', '/mon-espace');
    assertSame('/connexion', $response->headers['Location']);
});
