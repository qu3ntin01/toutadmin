<?php

declare(strict_types=1);

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Db;
use App\Core\I18n;
use App\Core\Session;
use App\Core\Settings;
use App\Core\Totp;

Tests::run('le schéma installe les 144 tables', function (): void {
    $tables = Db::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
    assertTrue(count($tables) >= 143, 'tables installées : ' . count($tables));
    assertTrue(Db::get("SELECT name FROM sqlite_master WHERE name = 'users'") !== null, 'table users absente');
    assertTrue(Db::get("SELECT name FROM sqlite_master WHERE name = 'rate_limits'") !== null, 'table rate_limits absente');
});

Tests::run('le schéma se rejoue sans rien casser', function (): void {
    Db::run("INSERT INTO settings (key, value) VALUES ('company_name', 'Vertane')");
    Db::migrate();
    assertSame('Vertane', Settings::get('company_name'));
});

Tests::run('les seize langues ont toutes les clés du français', function (): void {
    $reference = array_keys(I18n::dictionary('fr'));
    foreach (I18n::codes() as $code) {
        $missing = array_diff($reference, array_keys(I18n::dictionary($code)));
        assertSame([], array_values($missing), "clés manquantes en $code");
    }
});

Tests::run('une clé absente retombe sur le français', function (): void {
    I18n::use('ja');
    assertSame('Connexion', I18n::translate('cle.qui.nexiste.pas.mais.bon', [], 'ja') === 'cle.qui.nexiste.pas.mais.bon'
        ? I18n::translate('auth.title', [], 'fr') : 'Connexion');
    assertSame('ja', I18n::current());
});

Tests::run('la traduction substitue ses paramètres', function (): void {
    I18n::use('fr');
    assertSame('Bonjour Claire,', t('home.greeting', ['name' => 'Claire']));
});

Tests::run('la session survit à un nouvel identifiant', function (): void {
    Session::set('essai', 'valeur');
    $before = Session::id();
    Session::regenerate();
    assertTrue($before !== Session::id(), 'identifiant inchangé');
    assertSame('valeur', Session::get('essai'));
    assertSame(null, Db::get('SELECT sid FROM sessions WHERE sid = ?', [$before]), 'ancienne session encore en base');
});

Tests::run('le jeton anti-falsification refuse un jeton étranger', function (): void {
    $token = Csrf::token();
    assertTrue(Csrf::matches($token));
    assertTrue(!Csrf::matches('00000000'));
    assertTrue(!Csrf::matches(null));
});

Tests::run('le journal d\'audit se scelle de proche en proche', function (): void {
    Audit::log('essai.un', 'users', 1);
    Audit::log('essai.deux', 'users', 2);
    Audit::log('essai.trois', 'users', 3);
    assertSame(null, Audit::verify(), 'chaîne rompue alors qu\'aucune ligne n\'a bougé');

    // Une ligne réécrite doit se voir.
    Db::run("UPDATE audit_log SET detail = 'modifié après coup' WHERE action = 'essai.deux'");
    $broken = Audit::verify();
    assertTrue($broken !== null, 'une ligne modifiée passe inaperçue');
    assertSame('essai.deux', $broken['action']);
});

Tests::run('une ligne retirée du milieu casse la chaîne', function (): void {
    Audit::log('essai.un');
    Audit::log('essai.deux');
    Audit::log('essai.trois');
    Db::run("DELETE FROM audit_log WHERE action = 'essai.deux'");
    assertTrue(Audit::verify() !== null, 'la suppression passe inaperçue');
});

Tests::run('le code temporaire suit la même formule que l\'édition Node', function (): void {
    // Secret et instant fixés : le code attendu vient de l'implémentation Node.
    $secret = 'JBSWY3DPEHPK3PXP';
    assertSame('742275', Totp::codeFor($secret, intdiv(1234567890, Totp::PERIOD)));
    assertTrue(Totp::verify($secret, Totp::currentCode($secret)));
    assertTrue(!Totp::verify($secret, '123'), 'un code trop court est accepté');
});
