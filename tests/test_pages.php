<?php

declare(strict_types=1);

use App\Core\Db;
use App\Core\Session;
use App\Modules\Users;

function connecte(string $email = 'admin@demo.test', string $password = 'Administration-2026!'): void
{
    visit('POST', '/connexion', ['email' => $email, 'password' => $password]);
}

Tests::run('l\'écran de connexion s\'affiche dans la langue demandée', function (): void {
    seed();
    $response = visit('GET', '/connexion');
    assertSame(200, $response->status);
    assertContains('Connexion', $response->body);
    assertContains('name="_csrf"', $response->body);

    visit('POST', '/langue', ['locale' => 'ja', 'returnTo' => '/connexion']);
    $response = visit('GET', '/connexion');
    assertContains('lang="ja"', $response->body);
});

Tests::run('la langue arabe passe la page en écriture droite-à-gauche', function (): void {
    seed();
    visit('POST', '/langue', ['locale' => 'ar', 'returnTo' => '/connexion']);
    $response = visit('GET', '/connexion');
    assertContains('dir="rtl"', $response->body);
});

Tests::run('l\'espace du salarié affiche son titulaire', function (): void {
    seed();
    connecte('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    $response = visit('GET', '/mon-espace');
    assertSame(200, $response->status);
    assertContains('Bonjour Claire,', $response->body);
    assertContains('claire.moreau@entreprise.com', $response->body);
});

Tests::run('l\'annuaire cherche et compte', function (): void {
    seed();
    connecte();
    $response = visit('GET', '/annuaire');
    assertContains('Moreau', $response->body);

    $response = visit('GET', '/annuaire', [], ['q' => 'introuvable']);
    assertContains('Aucun collaborateur', $response->body);
});

Tests::run('une personne retirée de l\'annuaire n\'y figure plus', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET directory_hidden = 1 WHERE id = ?', [$ids['member']]);
    connecte();
    $response = visit('GET', '/annuaire');
    assertTrue(!str_contains($response->body, 'Moreau'), 'une personne masquée apparaît encore');
});

Tests::run('le profil ne propose pas les réglages de messagerie', function (): void {
    seed();
    connecte('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    $response = visit('GET', '/mon-profil');
    assertSame(200, $response->status);
    // Ils sont affichés, mais en lecture seule : aucun champ de saisie.
    assertTrue(!str_contains($response->body, 'name="mail_address"'), 'champ d\'adresse de messagerie proposé');
    assertTrue(!str_contains($response->body, 'name="mail_imap_host"'), 'champ de serveur IMAP proposé');
    assertContains('ne sont modifiables que par l', $response->body);
});

Tests::run('un membre ne peut pas se changer d\'adresse ni de rôle', function (): void {
    $ids = seed();
    connecte('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    visit('POST', '/mon-profil', [
        'first_name' => 'Claire', 'last_name' => 'Moreau', 'phone' => '0102030405',
        // Champs glissés à la main dans la requête : ils doivent être ignorés.
        'email' => 'claire@ailleurs.test', 'role' => 'admin', 'mail_address' => 'claire@interne',
        'is_hr' => '1', 'active' => '0',
    ]);
    $fresh = Users::byId($ids['member']);
    assertSame('claire.moreau@entreprise.com', $fresh['email'], 'adresse modifiée par le membre');
    assertSame('employee', $fresh['role'], 'rôle modifié par le membre');
    assertSame('', (string) $fresh['mail_address'], 'adresse de messagerie modifiée par le membre');
    assertSame(0, (int) $fresh['is_hr'], 'droit RH accordé par le membre');
    assertSame('0102030405', $fresh['phone'], 'le téléphone, lui, devait passer');
});

Tests::run('aucune route n\'ouvre la création de compte', function (): void {
    seed();
    connecte('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    foreach (['/inscription', '/creer-un-compte', '/register'] as $path) {
        assertSame(404, visit('GET', $path)->status, "la route $path existe");
    }
});

Tests::run('une page inconnue répond 404 sans détail', function (): void {
    seed();
    connecte();
    $response = visit('GET', '/pas-de-page-ici');
    assertSame(404, $response->status);
    assertContains('pas accessible', $response->body);
});

Tests::run('chaque réponse porte ses en-têtes de sécurité', function (): void {
    seed();
    $response = visit('GET', '/connexion');
    foreach (['Content-Security-Policy', 'X-Content-Type-Options', 'X-Frame-Options', 'Referrer-Policy'] as $header) {
        assertTrue(isset($response->headers[$header]), "en-tête $header absent");
    }
    assertContains("frame-ancestors 'none'", $response->headers['Content-Security-Policy']);
    assertTrue(!str_contains($response->headers['Content-Security-Policy'], "'unsafe-inline'"),
        'la politique autorise le script en ligne');
});

Tests::run('le nom de l\'entreprise s\'échappe au lieu de s\'exécuter', function (): void {
    seed();
    \App\Core\Settings::set('company_name', '<script>alert(1)</script>');
    $response = visit('GET', '/connexion');
    assertTrue(!str_contains($response->body, '<script>alert(1)</script>'), 'balise injectée telle quelle');
    assertContains('&lt;script&gt;', $response->body);
});
