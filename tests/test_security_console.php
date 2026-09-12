<?php

declare(strict_types=1);

use App\Core\Audit;
use App\Core\Db;
use App\Core\Session;
use App\Core\Settings;
use App\Modules\Privacy;
use App\Modules\Users;

Tests::run('la console de sécurité et le registre RGPD sont fermés aux non-administrateurs', function (): void {
    $ids = seed();
    Users::setRoleFlag($ids['member'], 'is_hr', true);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/securite')->status);
    assertSame(403, visit('GET', '/securite/journal.csv')->status);
    assertSame(403, visit('GET', '/rgpd')->status);
    assertSame(403, visit('POST', '/rgpd/personnes/' . $ids['admin'] . '/effacer', ['confirmation' => 'admin@demo.test'])->status);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(200, visit('GET', '/securite')->status);
    assertSame(200, visit('GET', '/rgpd')->status);
});

Tests::run('le journal se filtre, se pagine et s\'exporte', function (): void {
    $ids = seed();
    for ($i = 0; $i < 60; $i++) {
        Audit::log('essai.page', 'users', $ids['member'], ['n' => $i]);
    }
    Audit::log('autre.action', 'settings');

    $page = Audit::list(['page' => 1]);
    assertSame(50, count($page['rows']), 'une page fait cinquante lignes');
    assertTrue($page['pages'] >= 2);

    $filtered = Audit::list(['action' => 'essai.']);
    assertSame(60, $filtered['total'], 'le filtre par action ne prend pas le bon compte');
    assertTrue(in_array('autre.action', Audit::knownActions(), true));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $csv = visit('GET', '/securite/journal.csv', [], ['action' => 'essai.']);
    assertSame(200, $csv->status);
    assertContains('"Date";"Auteur";"Action"', $csv->body);
    assertContains('essai.page', $csv->body);
    assertTrue(!str_contains($csv->body, 'autre.action'), 'le filtre n\'a pas été appliqué à l\'export');
});

Tests::run('le scellement dit où la chaîne casse', function (): void {
    seed();
    Audit::log('premiere', 'users');
    Audit::log('deuxieme', 'users');
    Audit::log('troisieme', 'users');
    assertTrue(Audit::verifySeal()['ok']);

    // Une entrée réécrite directement en base.
    $row = Db::get("SELECT * FROM audit_log WHERE action = 'deuxieme'");
    Db::run("UPDATE audit_log SET action = 'maquillee' WHERE id = ?", [$row['id']]);
    $seal = Audit::verifySeal();
    assertTrue(!$seal['ok'], 'une réécriture est passée inaperçue');
    assertSame('contenu', $seal['broken']['reason']);
    assertSame((int) $row['id'], (int) $seal['broken']['row']['id']);

    // Remise en état, puis une entrée retirée du milieu.
    Db::run("UPDATE audit_log SET action = 'deuxieme' WHERE id = ?", [$row['id']]);
    assertTrue(Audit::verifySeal()['ok']);
    Db::run('DELETE FROM audit_log WHERE id = ?', [$row['id']]);
    $broken = Audit::verifySeal();
    assertTrue(!$broken['ok']);
    assertSame('chaine', $broken['broken']['reason']);
});

Tests::run('une purge laisse sa propre entrée et ne casse pas le sceau', function (): void {
    seed();
    Audit::log('ancienne', 'users');
    // Tout ce qui précède est daté d'il y a plus d'un an : une purge retire le
    // début de la chaîne, pas une entrée prise au milieu.
    Db::run('UPDATE audit_log SET occurred_at = ?', [gmdate('Y-m-d H:i:s', time() - 400 * 86400)]);
    Audit::log('recente', 'users');

    $removed = Audit::purgeOlderThan(365);
    assertTrue($removed >= 1, 'rien n\'a été purgé');
    assertSame(null, Db::get("SELECT id FROM audit_log WHERE action = 'ancienne'"));
    // La purge se déclare elle-même.
    assertTrue(Db::get("SELECT id FROM audit_log WHERE action = 'journal.purge'") !== null,
        'la purge ne laisse pas de trace');
    // Et la chaîne reste vérifiable depuis la plus ancienne entrée conservée.
    assertTrue(Audit::verifySeal()['ok'], 'la purge a cassé le scellement');
});

Tests::run('le dernier administrateur ne se rétrograde pas, ni lui-même', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // Seul administrateur : impossible.
    visit('POST', '/securite/administrateurs/' . $ids['admin'] . '/retirer');
    assertSame('admin', Users::byId($ids['admin'])['role']);

    // On en nomme un second, puis on essaie de se retirer soi-même.
    visit('POST', '/securite/administrateurs', ['user_id' => (string) $ids['member']]);
    assertSame('admin', Users::byId($ids['member'])['role']);
    visit('POST', '/securite/administrateurs/' . $ids['admin'] . '/retirer');
    assertSame('admin', Users::byId($ids['admin'])['role'], 'un administrateur s\'est retiré ses propres droits');

    // Rétrograder l'autre, en revanche, marche — et ferme ses sessions.
    visit('POST', '/securite/administrateurs/' . $ids['member'] . '/retirer');
    assertSame('employee', Users::byId($ids['member'])['role']);
});

Tests::run('la politique de sécurité borne la conservation du journal', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    $before = Settings::get('audit_retention_days');
    foreach (['10', '5000', 'beaucoup'] as $bad) {
        visit('POST', '/securite/politique', ['audit_retention_days' => $bad]);
    }
    assertSame($before, Settings::get('audit_retention_days'), 'une durée hors bornes a été acceptée');

    visit('POST', '/securite/politique', ['audit_retention_days' => '400', 'require_2fa_admin' => '1']);
    assertSame('400', Settings::get('audit_retention_days'));
    assertSame('1', Settings::get('require_2fa_admin'));
    assertSame('0', Settings::get('require_2fa_all'));
});

Tests::run('déverrouiller un compte et remettre sa double authentification à zéro', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET failed_attempts = 5, locked_until = ?, totp_enabled = 1, totp_secret = ? WHERE id = ?',
        [gmdate('c', time() + 900), 'SECRET', $ids['member']]);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/securite/comptes/' . $ids['member'] . '/deverrouiller');
    $user = (array) Users::byId($ids['member']);
    assertSame(0, (int) $user['failed_attempts']);
    assertSame(null, $user['locked_until']);

    visit('POST', '/securite/2fa/' . $ids['member'] . '/reinitialiser');
    $after = (array) Users::byId($ids['member']);
    assertSame(0, (int) $after['totp_enabled']);
    assertSame(null, $after['totp_secret']);
});

// ---------- Données personnelles ----------

Tests::run('le registre se préremplit une seule fois', function (): void {
    seed();
    assertSame(6, Privacy::seedRecords());
    assertSame(0, Privacy::seedRecords(), 'le préremplissage a été rejoué');
    assertSame(6, count(Privacy::records()));
});

Tests::run('l\'export rassemble ce que les tables savent d\'une personne', function (): void {
    $ids = seed();
    \App\Modules\Hr::createRequest($ids['member'], 'Congés payés', '2026-03-02', '2026-03-06', 5, 'Vacances');
    \App\Modules\Notifications::push($ids['member'], 'Un message pour elle');

    $export = (array) Privacy::exportFor($ids['member']);
    assertSame('claire.moreau@entreprise.com', $export['personne']['email']);
    assertSame(1, count($export['donnees']['Demandes de congés et absences']));
    assertSame(1, count($export['donnees']['Notifications']));
    // Le journal d'audit figure aussi : il parle de la personne.
    assertTrue(isset($export['donnees']["Journal d'audit"]));

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $page = visit('GET', '/rgpd/personnes/' . $ids['member'] . '/export.json');
    assertSame(200, $page->status);
    assertContains('"Vacances"', $page->body);
});

Tests::run('l\'effacement distingue ce qui part de ce qui reste', function (): void {
    $ids = seed();
    \App\Modules\Hr::createRequest($ids['member'], 'Congés payés', '2026-03-02', '2026-03-06', 5, 'Vacances');
    Db::insert('INSERT INTO payslips (employee_id, period, gross_amount, net_amount) VALUES (?, ?, ?, ?)',
        [$ids['member'], '2026-02', 2500, 1950]);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    // Sans l'adresse exacte, rien ne se passe.
    visit('POST', '/rgpd/personnes/' . $ids['member'] . '/effacer', ['confirmation' => 'pas la bonne']);
    assertSame('claire.moreau@entreprise.com', Users::byId($ids['member'])['email']);

    visit('POST', '/rgpd/personnes/' . $ids['member'] . '/effacer',
        ['confirmation' => 'claire.moreau@entreprise.com']);

    $user = (array) Users::byId($ids['member']);
    assertSame('anonyme-' . $ids['member'] . '@invalide.local', $user['email']);
    assertSame('Compte', $user['first_name']);
    assertSame(0, (int) $user['active']);
    // Les congés sont partis, le bulletin est resté.
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM hr_requests WHERE employee_id = ?', [$ids['member']]));
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM payslips WHERE employee_id = ?', [$ids['member']]));
});

Tests::run('un administrateur ne s\'efface pas, ni un autre administrateur tel quel', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    visit('POST', '/rgpd/personnes/' . $ids['admin'] . '/effacer', ['confirmation' => 'admin@demo.test']);
    assertSame('admin@demo.test', Users::byId($ids['admin'])['email'], 'un administrateur s\'est effacé lui-même');

    Db::run("UPDATE users SET role = 'admin' WHERE id = ?", [$ids['member']]);
    visit('POST', '/rgpd/personnes/' . $ids['member'] . '/effacer',
        ['confirmation' => 'claire.moreau@entreprise.com']);
    assertSame('claire.moreau@entreprise.com', Users::byId($ids['member'])['email'],
        'un administrateur a été effacé sans être rétrogradé');
});

Tests::run('les écrans de sécurité et de données personnelles rendent leurs données', function (): void {
    $ids = seed();
    Privacy::seedRecords();
    Audit::log('essai.affichage', 'users', $ids['member']);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $securite = visit('GET', '/securite');
    assertSame(200, $securite->status);
    assertContains('essai.affichage', $securite->body);
    assertContains('admin@demo.test', $securite->body);

    $rgpd = visit('GET', '/rgpd', [], ['personne' => (string) $ids['member']]);
    assertSame(200, $rgpd->status);
    assertContains('Gestion administrative du personnel', $rgpd->body);
    assertContains('Bulletins de paie', $rgpd->body);
});
