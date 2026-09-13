<?php

declare(strict_types=1);

/**
 * Parité avec l'édition Node.
 *
 * Vingt-huit points d'entrée de l'édition Node n'ont pas d'équivalent
 * littéral ici : neuf ressources d'API servies par une route paramétrée, un
 * assistant d'installation en une page plutôt qu'en cinq étapes, des droits
 * transverses regroupés sous une seule route, et quelques chemins renommés.
 * Ce fichier ne les prend pas sur parole : il les exerce.
 */

use App\Core\Db;
use App\Modules\ApiTokens;
use App\Modules\Users;

Tests::run('les neuf ressources de l’API répondent sous la route paramétrée', function (): void {
    $ids = seed();
    $token = ApiTokens::create([
        'label' => 'Contrôle de parité',
        'scopes' => ['annuaire', 'rh', 'gestion', 'projets', 'pilotage'],
        'createdBy' => $ids['admin'],
    ])['token'];

    // Les neuf chemins que l'édition Node déclare un par un.
    $resources = [
        'collaborateurs', 'services', 'equipes', 'absences',
        'tiers', 'factures', 'abonnements', 'projets', 'indicateurs',
    ];
    foreach ($resources as $resource) {
        $response = visit('GET', '/api/v1/' . $resource, [], [], [], ['Authorization' => 'Bearer ' . $token]);
        assertSame(200, $response->status, "/api/v1/$resource ne répond pas");
        assertTrue(json_decode($response->body, true) !== null, "/api/v1/$resource ne rend pas du JSON");
    }

    // Et la racine les annonce toutes les neuf.
    $root = json_decode(visit('GET', '/api/v1', [], [], [], ['Authorization' => 'Bearer ' . $token])->body, true);
    assertSame(9, count($root['endpoints']));
});

Tests::run('l’assistant d’installation fait en une page ce que l’autre fait en cinq étapes', function (): void {
    // Langue, prérequis, entreprise, congés annuels, administrateur : les cinq
    // étapes de l'édition Node tiennent dans un seul écran et un seul envoi.
    $page = visit('GET', '/installation')->body;
    assertContains('name="locale"', $page);
    assertContains('name="company_name"', $page);
    assertContains('name="annual_leave_days"', $page);
    assertContains('name="email"', $page);
    assertContains('PHP 8.1', $page, 'les prérequis sont montrés');

    visit('POST', '/installation', [
        'company_name' => 'Vertane Industries', 'locale' => 'fr', 'annual_leave_days' => '27',
        'email' => 'admin@demo.test', 'password' => 'Administration-2026!', 'confirm' => 'Administration-2026!',
    ]);
    assertSame(1, Users::count());
    assertSame('Vertane Industries', \App\Core\Settings::get('company_name'));
    assertSame('27', \App\Core\Settings::get('annual_leave_days'));
    assertSame('fr', \App\Core\Settings::get('default_locale'));

    // Et l'assistant se referme, comme l'autre.
    assertSame('/connexion', visit('GET', '/installation')->headers['Location']);
});

Tests::run('les quatre droits transverses se donnent et se retirent par une seule route', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // L'édition Node a huit routes (nommer et retirer, pour quatre droits) ;
    // ici une seule route paramétrée les porte toutes.
    foreach (['is_hr' => 'rh', 'is_finance' => 'gestion', 'is_it' => 'informatique', 'is_referent' => 'alerte'] as $flag => $node) {
        visit('POST', '/admin/droits/' . $flag, ['employee_id' => (string) $ids['member']]);
        assertSame(1, (int) Db::value("SELECT $flag FROM users WHERE id = ?", [$ids['member']]),
            "le droit « $node » ne s'accorde pas");

        visit('POST', '/admin/droits/' . $flag . '/' . $ids['member'] . '/retirer');
        assertSame(0, (int) Db::value("SELECT $flag FROM users WHERE id = ?", [$ids['member']]),
            "le droit « $node » ne se retire pas");
    }

    // Un droit inventé n'ouvre rien : la route existe, le droit non.
    visit('POST', '/admin/droits/is_king', ['employee_id' => (string) $ids['member']]);
    assertSame(0, (int) Db::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'droit.accorde' AND detail LIKE '%is_king%'"
    ), 'un droit inventé a été journalisé comme accordé');
});

Tests::run('le profil, le mot de passe et le premier accès mènent au même résultat', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    // « /mon-profil/informations » de l'édition Node : ici le même envoi, sur
    // « /mon-profil ».
    visit('POST', '/mon-profil', [
        'first_name' => 'Claire', 'last_name' => 'Moreau',
        'phone' => '06 01 02 03 04', 'bio' => 'Responsable qualité depuis 2019.', 'locale' => 'en',
    ]);
    $row = (array) Users::byId($ids['member']);
    assertSame('06 01 02 03 04', $row['phone']);
    assertSame('Responsable qualité depuis 2019.', $row['bio']);
    assertSame('en', $row['locale']);

    // « /mon-profil/mot-de-passe » : ici « /mot-de-passe ».
    visit('POST', '/mot-de-passe', [
        'current' => 'Salariee-Demo-2026!', 'password' => 'Nouvelle-Passe-2026!', 'confirm' => 'Nouvelle-Passe-2026!',
    ]);
    assertTrue(\App\Core\Security::verifyPassword('Nouvelle-Passe-2026!', Users::byId($ids['member'])['password_hash']));

    // « /mon-profil/premier-acces » : ici le noyau y conduit d'office tant
    // qu'un mot de passe temporaire est en place, et rien d'autre ne s'ouvre.
    Db::run('UPDATE users SET must_change_password = 1 WHERE id = ?', [$ids['member']]);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Nouvelle-Passe-2026!']);
    assertSame('/mot-de-passe', visit('GET', '/mon-espace')->headers['Location']);
    assertSame(200, visit('GET', '/mot-de-passe')->status);

    visit('POST', '/mot-de-passe', [
        'current' => 'Nouvelle-Passe-2026!', 'password' => 'Encore-Une-Passe-2026!', 'confirm' => 'Encore-Une-Passe-2026!',
    ]);
    assertSame(0, (int) Db::value('SELECT must_change_password FROM users WHERE id = ?', [$ids['member']]));
});

Tests::run('marquer toutes les notifications lues porte un autre nom, pas un autre effet', function (): void {
    $ids = seed();
    \App\Modules\Notifications::push($ids['member'], 'Première', ['link' => '/mon-espace']);
    \App\Modules\Notifications::push($ids['member'], 'Seconde', ['link' => '/mon-espace']);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    assertSame(2, \App\Modules\Notifications::unreadCount($ids['member']));
    // « /notifications/tout-lu » dans l'édition Node.
    assertSame(302, visit('POST', '/notifications/tout-lire')->status);
    assertSame(0, \App\Modules\Notifications::unreadCount($ids['member']));
});

/**
 * La tâche planifiée fait-elle tout ce que le balayage de l'édition Node fait ?
 * Le script est lancé pour de vrai, dans son propre processus, sur une base à
 * lui — c'est le seul moyen de voir ce qu'il oublie.
 */
Tests::run('la tâche planifiée reprend tout le balayage périodique', function (): void {
    $dir = sys_get_temp_dir() . '/toutadmin-cron-' . bin2hex(random_bytes(6));
    mkdir($dir, 0770, true);
    $dbPath = $dir . '/app.sqlite';
    $config = $dir . '/config.php';
    file_put_contents($config, "<?php\nreturn " . var_export([
        'db_path' => $dbPath, 'data_dir' => $dir, 'session_secret' => str_repeat('a', 64),
    ], true) . ";\n");

    // Une instance posée à la main dans cette base-là.
    Db::reset();
    \App\Core\Config::set('db_path', $dbPath);
    \App\Core\Config::set('data_dir', $dir);
    Db::migrate();
    $ids = seed();

    // Un contrat échu, une échéance, une notification lue depuis longtemps,
    // une entrée de journal ancienne, un abonnement à émettre : cinq choses
    // que le balayage doit ramasser.
    Db::run("UPDATE users SET contract_end_date = date('now', '-1 day') WHERE id = ?", [$ids['member']]);
    \App\Modules\Notifications::push($ids['admin'], 'Vieille nouvelle');
    Db::run("UPDATE notifications SET read_at = datetime('now', '-200 days')");
    Db::run("UPDATE audit_log SET occurred_at = datetime('now', '-500 days')");
    \App\Core\Settings::setMany(['audit_retention_days' => '30']);

    $partner = Db::insert("INSERT INTO partners (kind, name) VALUES ('Client', 'Vertane')");
    Db::run(
        "INSERT INTO subscriptions (partner_id, label, direction, amount_ht, vat_rate, currency, period,
                                    start_date, next_issue, active)
         VALUES (?, 'Maintenance annuelle', 'Client', 1200, 20, 'EUR', 'Mensuel',
                 date('now', '-40 days'), date('now', '-1 day'), 1)",
        [$partner]
    );

    $before = [
        'factures' => (int) Db::value('SELECT COUNT(*) FROM invoices'),
        'journal' => (int) Db::value('SELECT COUNT(*) FROM audit_log'),
    ];

    // Le script, pour de vrai.
    $output = [];
    $status = 0;
    exec(
        'TOUTADMIN_CONFIG=' . escapeshellarg($config) . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(APP_ROOT . '/tools/cron.php') . ' 2>&1',
        $output,
        $status
    );
    assertSame(0, $status, 'la tâche planifiée est sortie en erreur : ' . implode("\n", $output));

    // 1. Le contrat échu a fermé le compte.
    assertSame(0, (int) Db::value('SELECT active FROM users WHERE id = ?', [$ids['member']]));
    // 2. L'abonnement a émis sa facture tout seul.
    assertSame($before['factures'] + 1, (int) Db::value('SELECT COUNT(*) FROM invoices'),
        'les abonnements n’émettent pas leurs factures sans qu’on clique');
    // 3. La notification lue depuis deux cents jours a été purgée.
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM notifications'));
    // 4. Le journal a été purgé au terme de conservation de l'instance.
    assertTrue((int) Db::value('SELECT COUNT(*) FROM audit_log') < $before['journal'],
        'le journal d’audit ne se purge pas tout seul');
    // 5. Et la sauvegarde planifiée a tourné.
    assertTrue(str_contains(implode("\n", $output), 'Sauvegarde')
        || str_contains(implode("\n", $output), "intervalle de sauvegarde"), implode("\n", $output));
});
