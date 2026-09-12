<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Org;
use App\Modules\Support;
use App\Modules\Users;

function loginKb(string $email, string $password): void
{
    visit('POST', '/connexion', ['email' => $email, 'password' => $password]);
}

Tests::run('un article ne se lit que dans sa portée', function (): void {
    $ids = seed();
    $production = Org::createDepartment('Production');
    $commerce = Org::createDepartment('Commerce');
    Org::assignMembership($ids['member'], $production, null);

    $company = Support::createArticle([
        'title' => 'Règlement intérieur', 'category' => 'Général', 'body' => 'Pour tous.',
        'visibility' => 'Entreprise', 'scopeId' => null, 'authorId' => $ids['admin'],
    ]);
    $mine = Support::createArticle([
        'title' => 'Consignes ligne 2', 'category' => 'Production', 'body' => 'Pour la production.',
        'visibility' => 'Service', 'scopeId' => $production, 'authorId' => $ids['admin'],
    ]);
    $other = Support::createArticle([
        'title' => 'Argumentaire', 'category' => 'Commerce', 'body' => 'Pour le commerce.',
        'visibility' => 'Service', 'scopeId' => $commerce, 'authorId' => $ids['admin'],
    ]);
    $internal = Support::createArticle([
        'title' => 'Procédure de licenciement', 'category' => 'RH', 'body' => 'Interne.',
        'visibility' => 'Administration', 'scopeId' => null, 'authorId' => $ids['admin'],
    ]);

    loginKb('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    $page = visit('GET', '/base-de-connaissances');
    assertSame(200, $page->status);
    assertContains('Règlement intérieur', $page->body);
    assertContains('Consignes ligne 2', $page->body);
    assertTrue(!str_contains($page->body, 'Argumentaire'), 'un article d\'un autre service est listé');
    assertTrue(!str_contains($page->body, 'Procédure de licenciement'), 'un article d\'administration est listé');

    assertSame(200, visit('GET', '/base-de-connaissances/' . $company)->status);
    assertSame(200, visit('GET', '/base-de-connaissances/' . $mine)->status);
    assertSame(403, visit('GET', '/base-de-connaissances/' . $other)->status);
    assertSame(403, visit('GET', '/base-de-connaissances/' . $internal)->status);

    // L'administration lit tout.
    loginKb('admin@demo.test', 'Administration-2026!');
    assertSame(200, visit('GET', '/base-de-connaissances/' . $internal)->status);
});

Tests::run('la rédaction est réservée à l\'encadrement', function (): void {
    $ids = seed();

    loginKb('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    assertSame(403, visit('POST', '/base-de-connaissances', [
        'title' => 'Pirate', 'category' => 'Général', 'body' => 'x', 'visibility' => 'Entreprise',
    ])->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM kb_articles'));

    // Un manager écrit.
    $department = Org::createDepartment('Production');
    $team = Org::createTeam('Ligne 2', $department);
    Org::addManager('team', $team, $ids['member']);
    loginKb('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    visit('POST', '/base-de-connaissances', [
        'title' => 'Consignes', 'category' => 'Production', 'body' => 'Texte', 'visibility' => 'Entreprise',
    ]);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM kb_articles'));
});

Tests::run('une portée de service demande de choisir lequel', function (): void {
    seed();
    loginKb('admin@demo.test', 'Administration-2026!');

    visit('POST', '/base-de-connaissances', [
        'title' => 'Sans périmètre', 'body' => 'x', 'visibility' => 'Service',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM kb_articles'));

    visit('POST', '/base-de-connaissances', ['title' => 'Portée inventée', 'body' => 'x', 'visibility' => 'Monde']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM kb_articles'));

    $department = Org::createDepartment('Production');
    visit('POST', '/base-de-connaissances', [
        'title' => 'Consignes', 'body' => 'x', 'visibility' => 'Service', 'scope_id' => (string) $department,
    ]);
    $article = Db::get('SELECT * FROM kb_articles');
    assertSame('Service', $article['visibility']);
    assertSame($department, (int) $article['scope_id']);
});

Tests::run('un brouillon ne se lit pas, une lecture se compte', function (): void {
    $ids = seed();
    $id = Support::createArticle([
        'title' => 'Article', 'category' => 'Général', 'body' => 'Texte',
        'visibility' => 'Entreprise', 'scopeId' => null, 'authorId' => $ids['admin'],
    ]);
    Db::run('UPDATE kb_articles SET published = 0 WHERE id = ?', [$id]);

    loginKb('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    assertSame(404, visit('GET', '/base-de-connaissances/' . $id)->status);

    // Un rédacteur voit son brouillon, et le publie.
    loginKb('admin@demo.test', 'Administration-2026!');
    assertSame(200, visit('GET', '/base-de-connaissances/' . $id)->status);
    visit('POST', '/base-de-connaissances/' . $id . '/modifier', [
        'title' => 'Article', 'category' => 'Général', 'body' => 'Texte revu',
        'visibility' => 'Entreprise', 'published' => '1',
    ]);
    assertSame(1, (int) Db::value('SELECT published FROM kb_articles WHERE id = ?', [$id]));

    $before = (int) Db::value('SELECT views FROM kb_articles WHERE id = ?', [$id]);
    loginKb('claire.moreau@entreprise.com', 'Salariee-Demo-2026!');
    visit('GET', '/base-de-connaissances/' . $id);
    assertSame($before + 1, (int) Db::value('SELECT views FROM kb_articles WHERE id = ?', [$id]));
});

Tests::run('la recherche filtre par mots-clés et par catégorie', function (): void {
    $ids = seed();
    foreach ([
        ['Congés payés', 'RH', 'Poser ses congés dans le CMS.'],
        ['Machine à café', 'Vie de bureau', 'Détartrage mensuel.'],
    ] as [$title, $category, $body]) {
        Support::createArticle([
            'title' => $title, 'category' => $category, 'body' => $body,
            'visibility' => 'Entreprise', 'scopeId' => null, 'authorId' => $ids['admin'],
        ]);
    }

    $user = (array) Users::byId($ids['member']);
    assertSame(1, count(Support::articles('', 'détartrage', $user)));
    assertSame(1, count(Support::articles('RH', '', $user)));
    assertSame(0, count(Support::articles('RH', 'café', $user)));
    assertSame(['RH', 'Vie de bureau'], Support::categories());
});
