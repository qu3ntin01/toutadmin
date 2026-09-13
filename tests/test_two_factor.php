<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\QrCode;
use App\Core\Settings;
use App\Core\Totp;
use App\Modules\TwoFactor;
use App\Modules\Users;

function loginMember(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

/** Le code valable à cet instant pour le secret enregistré. */
function codeOf(int $userId): string
{
    return Totp::currentCode((string) Db::value('SELECT totp_secret FROM users WHERE id = ?', [$userId]));
}

// ---------- Le code QR ----------

Tests::run('le code QR se lit : structure, repères et taille', function (): void {
    // Version 1 pour une donnée courte, version 8 pour une adresse otpauth.
    assertSame(21, count(QrCode::matrix('HELLO')));
    $uri = Totp::otpauthUri('JBSWY3DPEHPK3PXPJBSWY3DP', 'claire.moreau@entreprise.com', 'Vertane Industries');
    $grid = QrCode::matrix($uri);
    $size = count($grid);
    // Une adresse otpauth réclame une version moyenne : la taille suit la règle
    // 17 + 4 × version, et elle reste dans ce que ce codeur couvre.
    assertSame(0, ($size - 17) % 4);
    assertTrue($size >= 21 && $size <= 57, 'version 1 à 10');

    // Les trois repères de position, aux trois coins.
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
        assertSame(true, $grid[$top][$left], 'coin sombre');
        assertSame(false, $grid[$top + 1][$left + 1], 'anneau clair');
        assertSame(true, $grid[$top + 3][$left + 3], 'cœur sombre');
    }
    // La ligne de synchronisation alterne.
    assertSame(true, $grid[6][8]);
    assertSame(false, $grid[6][9]);
    // Le module toujours sombre.
    assertSame(true, $grid[$size - 8][8]);

    // Le même texte rend toujours la même trame : le masque est choisi, pas tiré.
    assertSame($grid, QrCode::matrix($uri));

    // Au-delà de la version 10, on refuse plutôt que de rendre un code illisible.
    $failed = false;
    try {
        QrCode::matrix(str_repeat('x', 300));
    } catch (\RuntimeException) {
        $failed = true;
    }
    assertSame(true, $failed);
});

Tests::run('la trame produite est celle de la norme, module pour module', function (): void {
    // Ces deux empreintes ont été comparées, au moment de l'écriture, à une
    // implémentation indépendante de la norme : les trames sont identiques
    // module pour module. Elles tiennent lieu de garde-fou, pour qu'une
    // retouche du codeur se voie tout de suite.
    $print = static function (string $text): string {
        $out = '';
        foreach (QrCode::matrix($text) as $row) {
            $out .= implode('', array_map(static fn (bool $b): string => $b ? '#' : '.', $row)) . "\n";
        }
        return hash('sha256', $out);
    };

    assertSame('97850d9ee6b6f28e82651ed61f4e9e8d13d7902695ecee97635b47bd26604c3e', $print('HELLO'));

    // Version 8 : la seule taille qui porte aussi une information de version.
    $uri = 'otpauth://totp/Vertane%20Industries:admin@demo.test'
        . '?secret=JBSWY3DPEHPK3PXPJBSWY3DP&issuer=Vertane%20Industries&digits=6&period=30';
    assertSame(49, count(QrCode::matrix($uri)));
    assertSame('b70400b9673f13c8a34dbc327f97a6ccf2a3d8da54039fd19710db1f95419932', $print($uri));
});

Tests::run('le code QR sort en SVG, dessiné dans la page', function (): void {
    $svg = QrCode::svg('otpauth://totp/Test:a@b.c?secret=JBSWY3DPEHPK3PXP', 220, 'Mise en service');

    assertContains('<svg', $svg);
    assertContains('width="220"', $svg);
    assertContains('aria-label="Mise en service"', $svg);
    // Une marge de quatre modules de chaque côté, sans quoi rien ne se lit.
    $size = count(QrCode::matrix('otpauth://totp/Test:a@b.c?secret=JBSWY3DPEHPK3PXP'));
    assertContains('viewBox="0 0 ' . ($size + 8) . ' ' . ($size + 8) . '"', $svg);
    // Pas d'image à charger : le dessin est dans la page, et la seule adresse
    // qu'il porte est l'espace de noms SVG, que rien ne va chercher.
    assertTrue(!str_contains($svg, '<img'), 'aucune image externe');
    assertTrue(!str_contains($svg, 'data:'), 'aucune donnée encodée à part');
    assertSame(1, substr_count($svg, 'http'), 'seulement xmlns');
});

// ---------- Mise en service ----------

Tests::run("le secret n'est actif qu'une fois un premier code validé", function (): void {
    $ids = seed();
    $user = (array) Users::byId($ids['member']);
    assertSame(false, TwoFactor::stateOf($user)['enabled']);

    TwoFactor::beginEnrolment($ids['member']);
    $user = (array) Users::byId($ids['member']);
    $state = TwoFactor::stateOf($user);
    // En attente : le secret existe, mais il n'ouvre encore rien.
    assertSame(true, $state['pending']);
    assertSame(false, $state['enabled']);
    assertSame(0, $state['remainingCodes']);

    // Un code faux ne met rien en service : une application mal réglée
    // enfermerait sinon la personne dehors.
    $refused = TwoFactor::confirmEnrolment($ids['member'], '000000');
    assertSame(false, $refused['ok']);
    assertSame(0, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    $verdict = TwoFactor::confirmEnrolment($ids['member'], codeOf($ids['member']));
    assertSame(true, $verdict['ok']);
    assertSame(8, count($verdict['recoveryCodes']));
    assertSame(1, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    // Les codes de secours sont conservés hachés, jamais en clair.
    $stored = Db::all('SELECT code_hash FROM totp_recovery_codes WHERE user_id = ?', [$ids['member']]);
    assertSame(8, count($stored));
    foreach ($verdict['recoveryCodes'] as $code) {
        assertTrue(!str_contains((string) json_encode($stored), $code), 'aucun code en clair en base');
    }
    assertSame(Totp::hashRecoveryCode($verdict['recoveryCodes'][0]), $stored[0]['code_hash']);
});

Tests::run("un code de secours ne sert qu'une fois, et les régénérer annule les anciens", function (): void {
    $ids = seed();
    TwoFactor::beginEnrolment($ids['member']);
    $codes = TwoFactor::confirmEnrolment($ids['member'], codeOf($ids['member']))['recoveryCodes'];
    $user = (array) Users::byId($ids['member']);

    $first = TwoFactor::verifyLogin($user, $codes[0]);
    assertSame(true, $first['ok']);
    assertSame(true, $first['usedRecovery']);
    // Le même code présenté deux fois ne vaut plus.
    assertSame(false, TwoFactor::verifyLogin($user, $codes[0])['ok']);
    assertSame(7, TwoFactor::stateOf($user)['remainingCodes']);

    // Le code du téléphone, lui, passe sans consommer quoi que ce soit.
    $totp = TwoFactor::verifyLogin($user, codeOf($ids['member']));
    assertSame(true, $totp['ok']);
    assertSame(false, $totp['usedRecovery']);

    $renewed = TwoFactor::regenerateRecoveryCodes($ids['member']);
    assertSame(8, TwoFactor::stateOf($user)['remainingCodes']);
    assertSame(false, TwoFactor::verifyLogin($user, $codes[1])['ok'], "les anciens codes ne valent plus");
    assertSame(true, TwoFactor::verifyLogin($user, $renewed[0])['ok']);
});

Tests::run("retirer la double authentification efface le secret et ses codes", function (): void {
    $ids = seed();
    TwoFactor::beginEnrolment($ids['member']);
    TwoFactor::confirmEnrolment($ids['member'], codeOf($ids['member']));

    TwoFactor::disable($ids['member']);
    $row = Db::get('SELECT * FROM users WHERE id = ?', [$ids['member']]);
    assertSame(null, $row['totp_secret']);
    assertSame(0, (int) $row['totp_enabled']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM totp_recovery_codes WHERE user_id = ?', [$ids['member']]));
});

Tests::run("l'entreprise peut exiger la double authentification", function (): void {
    $ids = seed();
    $admin = (array) Users::byId($ids['admin']);
    $member = (array) Users::byId($ids['member']);

    assertSame(false, TwoFactor::requiredFor($admin));
    Settings::setMany(['require_2fa_admin' => '1']);
    assertSame(true, TwoFactor::requiredFor($admin), "l'administration est la cible qui vaut la peine");
    assertSame(false, TwoFactor::requiredFor($member));

    Settings::setMany(['require_2fa_admin' => '0', 'require_2fa_all' => '1']);
    assertSame(true, TwoFactor::requiredFor($member));
});

// ---------- Les écrans ----------

Tests::run('la mise en service se fait depuis son profil, QR compris', function (): void {
    $ids = seed();
    loginMember();

    $page = visit('GET', '/mon-profil')->body;
    assertContains('/mon-profil/2fa/preparer', $page);
    assertTrue(!str_contains($page, '<svg class="totp-qr"'), 'pas de QR tant que rien n’est préparé');

    assertSame(302, visit('POST', '/mon-profil/2fa/preparer')->status);
    $page = visit('GET', '/mon-profil')->body;
    assertContains('<svg class="totp-qr"', $page, 'le QR est dessiné dans la page');
    assertContains('/mon-profil/2fa/activer', $page);
    // La saisie manuelle du secret reste possible, pour qui ne peut pas scanner.
    assertContains((string) Db::value('SELECT totp_secret FROM users WHERE id = ?', [$ids['member']]), $page);

    // Un code faux laisse tout en l'état.
    visit('POST', '/mon-profil/2fa/activer', ['code' => '123456']);
    assertSame(0, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    visit('POST', '/mon-profil/2fa/activer', ['code' => codeOf($ids['member'])]);
    assertSame(1, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    // Les codes de secours s'affichent une fois, puis plus jamais.
    $page = visit('GET', '/mon-profil')->body;
    assertContains('code-list', $page);
    $again = visit('GET', '/mon-profil')->body;
    assertTrue(!str_contains($again, 'code-list'), 'affichés une seule fois');
    assertContains('/mon-profil/2fa/codes', $again);
});

Tests::run("le retrait de la double authentification redemande le mot de passe", function (): void {
    $ids = seed();
    loginMember();
    visit('POST', '/mon-profil/2fa/preparer');
    visit('POST', '/mon-profil/2fa/activer', ['code' => codeOf($ids['member'])]);

    // Sans le mot de passe, un écran resté déverrouillé suffirait.
    visit('POST', '/mon-profil/2fa/desactiver', ['current_password' => 'au-hasard']);
    assertSame(1, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    // Exigée par l'entreprise, elle ne se retire pas non plus.
    Settings::setMany(['require_2fa_all' => '1']);
    visit('POST', '/mon-profil/2fa/desactiver', ['current_password' => 'Salariee-Demo-2026!']);
    assertSame(1, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    Settings::setMany(['require_2fa_all' => '0']);
    visit('POST', '/mon-profil/2fa/desactiver', ['current_password' => 'Salariee-Demo-2026!']);
    assertSame(0, (int) Db::value('SELECT totp_enabled FROM users WHERE id = ?', [$ids['member']]));

    // Chaque geste est au journal.
    $actions = array_column(Db::all("SELECT action FROM audit_log WHERE action LIKE '2fa.%'"), 'action');
    assertTrue(in_array('2fa.activee', $actions, true));
    assertTrue(in_array('2fa.desactivee', $actions, true));
});

Tests::run("la connexion demande le second facteur une fois qu'il est en service", function (): void {
    $ids = seed();
    loginMember();
    visit('POST', '/mon-profil/2fa/preparer');
    visit('POST', '/mon-profil/2fa/activer', ['code' => codeOf($ids['member'])]);
    visit('POST', '/deconnexion');

    // Le mot de passe seul n'ouvre plus de session : il mène à l'écran du code.
    $response = visit('POST', '/connexion', [
        'email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!',
    ]);
    assertSame(302, $response->status);
    assertSame('/connexion/code', $response->headers['Location']);
    // Et l'espace reste fermé tant que le code n'est pas donné.
    assertSame(302, visit('GET', '/mon-espace')->status);

    visit('POST', '/connexion/code', ['code' => '000000']);
    assertSame(302, visit('GET', '/mon-espace')->status, 'un code faux n’ouvre rien');

    visit('POST', '/connexion/code', ['code' => codeOf($ids['member'])]);
    assertSame(200, visit('GET', '/mon-espace')->status);
});

Tests::run('fermer toutes ses sessions les ferme vraiment, la sienne comprise', function (): void {
    $ids = seed();
    loginMember();
    // Une autre session du même compte, ouverte ailleurs.
    Db::run(
        'INSERT INTO sessions (sid, user_id, data, expires_at) VALUES (?, ?, ?, ?)',
        ['ailleurs', $ids['member'], '{}', time() + 3600]
    );
    assertSame(2, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$ids['member']]));

    $response = visit('POST', '/sessions/fermer');
    assertSame(302, $response->status);
    assertSame('/connexion', $response->headers['Location']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM sessions WHERE user_id = ?', [$ids['member']]));

    // La session courante est tombée avec les autres : l'espace se referme.
    assertSame(302, visit('GET', '/mon-espace')->status);
    assertTrue(Db::get("SELECT * FROM audit_log WHERE action = 'sessions.revoquees'") !== null);
});
