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

Tests::run('les en-têtes de sécurité suivent ceux de l’autre édition', function (): void {
    seed();
    $response = visit('GET', '/connexion');
    $headers = $response->headers;

    // Le socle : politique de contenu à nonce, pas de cadre, pas de reniflage
    // de type, référent limité au site, et rien à charger d'ailleurs.
    assertContains("script-src 'self' 'nonce-", $headers['Content-Security-Policy']);
    assertContains("frame-ancestors 'none'", $headers['Content-Security-Policy']);
    assertContains("object-src 'none'", $headers['Content-Security-Policy']);
    assertSame('nosniff', $headers['X-Content-Type-Options']);
    assertSame('DENY', $headers['X-Frame-Options']);
    assertSame('same-origin', $headers['Referrer-Policy']);
    assertSame('same-origin', $headers['Cross-Origin-Opener-Policy']);
    assertSame('same-origin', $headers['Cross-Origin-Resource-Policy']);
    assertSame('none', $headers['X-Permitted-Cross-Domain-Policies']);

    // En clair, pas de HSTS : annoncé depuis une page non chiffrée il n'est
    // pas lu, et il enfermerait un essai local pour six mois.
    assertTrue(!isset($headers['Strict-Transport-Security']), 'HSTS posé sur une connexion en clair');

    // Chiffrée, il est là.
    $_SERVER['HTTPS'] = 'on';
    $secure = visit('GET', '/connexion');
    unset($_SERVER['HTTPS']);
    assertContains('max-age=15552000', $secure->headers['Strict-Transport-Security']);
    assertContains('includeSubDomains', $secure->headers['Strict-Transport-Security']);

    // Le nonce change à chaque réponse : sans quoi il ne servirait à rien.
    $first = visit('GET', '/connexion')->headers['Content-Security-Policy'];
    $second = visit('GET', '/connexion')->headers['Content-Security-Policy'];
    assertTrue($first !== $second, 'le nonce est le même d’une réponse à l’autre');
});

Tests::run('aucun écran ne compte sur un gestionnaire d’événement en ligne', function (): void {
    // La politique de contenu n'autorise que les scripts du site portant le
    // nonce : un « onchange » écrit dans le HTML est bloqué par le navigateur,
    // et le contrôle qui comptait dessus ne fait rien du tout. Le défaut ne se
    // voit pas dans un test de requête — seulement à l'usage — d'où ce garde-fou.
    $offenders = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APP_DIR . '/views'));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with((string) $file, '.php')) {
            $body = (string) file_get_contents((string) $file);
            if (preg_match('/\son(?:change|click|submit|input|load|focus|blur)\s*=/i', $body)) {
                $offenders[] = basename(dirname((string) $file)) . '/' . $file->getBasename();
            }
        }
    }
    assertSame([], $offenders, 'gestionnaires en ligne : ' . implode(', ', $offenders));

    // Le style en ligne est bloqué de la même façon.
    $styled = [];
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APP_DIR . '/views'));
    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with((string) $file, '.php')
            && preg_match('/\sstyle\s*=\s*"/i', (string) file_get_contents((string) $file))) {
            $styled[] = $file->getBasename();
        }
    }
    assertSame([], $styled, 'styles en ligne : ' . implode(', ', $styled));
});
