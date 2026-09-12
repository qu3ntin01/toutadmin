<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Db;
use App\Core\Tar;
use App\Modules\Backup;
use App\Modules\Exporter;
use App\Modules\Users;
use App\Modules\Vault;

/** Chaque test des sauvegardes travaille dans son propre dossier de données. */
function seedBackup(): array
{
    $ids = seed();
    $dir = sys_get_temp_dir() . '/toutadmin-backup-' . bin2hex(random_bytes(6));
    mkdir($dir, 0770, true);
    Config::set('data_dir', $dir);
    return $ids;
}

Tests::run('une archive tar se relit, et refuse ce qui sort de ses racines', function (): void {
    $archive = Tar::pack([
        ['name' => 'db/app.sqlite', 'bytes' => 'contenu de base'],
        ['name' => 'meta/manifeste.json', 'bytes' => '{"format":1}'],
    ]);
    $entries = Tar::unpack($archive);
    assertSame(2, count($entries));
    assertSame('contenu de base', $entries[0]['data']);

    assertSame('db/app.sqlite', Tar::safeName('db/app.sqlite', Backup::ARCHIVE_ROOTS));
    assertSame(null, Tar::safeName('../etc/passwd', Backup::ARCHIVE_ROOTS));
    assertSame(null, Tar::safeName('/etc/passwd', Backup::ARCHIVE_ROOTS));
    assertSame(null, Tar::safeName('ailleurs/fichier', Backup::ARCHIVE_ROOTS));

    // Une archive tronquée se signale plutôt que de rendre une liste partielle.
    $failed = false;
    try {
        Tar::unpack(substr($archive, 0, 800));
    } catch (\Throwable) {
        $failed = true;
    }
    assertTrue($failed, 'une archive tronquée est passée');
});

Tests::run('une sauvegarde embarque la base et les fichiers, et se vérifie', function (): void {
    $ids = seedBackup();
    Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Janvier',
        'file' => ['bytes' => "%PDF-1.4\n%%EOF\n", 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);

    $created = Backup::create('manuelle', 'essai');
    assertTrue($created['files'] >= 2, 'la base et le document du coffre doivent y être');
    assertTrue($created['bytes'] > 0);

    $verdict = Backup::inspectFile($created['fileName']);
    assertTrue($verdict['ok'], $verdict['message'] ?? '');
    $names = array_column($verdict['manifest']['files'], 'name');
    assertTrue(in_array('db/app.sqlite', $names, true));
    assertSame(1, count(array_filter($names, static fn (string $n): bool => str_starts_with($n, 'coffre/'))));
});

Tests::run('une archive altérée est refusée', function (): void {
    seedBackup();
    $created = Backup::create();
    $path = (string) Backup::pathOf($created['fileName']);

    // Un octet changé dans la charge utile : l'empreinte du manifeste ne suit plus.
    $plain = (string) gzdecode((string) file_get_contents($path));
    $entries = Tar::unpack($plain);
    $rebuilt = [];
    foreach ($entries as $entry) {
        $bytes = $entry['name'] === 'db/app.sqlite' ? $entry['data'] . ' ' : $entry['data'];
        $rebuilt[] = ['name' => $entry['name'], 'bytes' => $bytes];
    }
    file_put_contents($path, (string) gzencode(Tar::pack($rebuilt)));

    $verdict = Backup::inspectFile($created['fileName']);
    assertTrue(!$verdict['ok'], 'une archive altérée est passée pour intacte');
    assertContains('altéré', $verdict['message']);

    // Ce qui n'est pas une archive du tout, non plus.
    assertTrue(!Backup::inspect('nimporte quoi')['ok']);
});

Tests::run('restaurer remet la base dans l\'état de l\'archive', function (): void {
    $ids = seedBackup();
    Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $created = Backup::create();
    $before = Users::count();

    // On casse tout après la sauvegarde.
    Db::run("DELETE FROM users WHERE email = 'marc.leroy@entreprise.com'");
    Db::insert('INSERT INTO departments (name) VALUES (?)', ['Service créé après la sauvegarde']);
    assertSame($before - 1, Users::count());

    $verdict = Backup::restore((string) file_get_contents((string) Backup::pathOf($created['fileName'])));
    assertTrue($verdict['ok'], $verdict['message'] ?? '');
    assertSame($before, Users::count(), 'le compte supprimé n\'est pas revenu');
    assertSame(0, (int) Db::value("SELECT COUNT(*) FROM departments WHERE name = 'Service créé après la sauvegarde'"),
        'ce qui a été créé après la sauvegarde a survécu');
    assertTrue($verdict['report']['tables'] > 0);
});

Tests::run('les fichiers reviennent à l\'état de l\'archive', function (): void {
    $ids = seedBackup();
    $deposit = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Février',
        'file' => ['bytes' => "%PDF-1.4\nfevrier\n%%EOF\n", 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    $created = Backup::create();
    $document = (array) Vault::byId((int) $deposit['id']);

    // Le fichier disparaît du disque après la sauvegarde.
    unlink(Vault::pathOf($document));
    assertTrue(!Vault::verify($document)['ok']);

    Backup::restore((string) file_get_contents((string) Backup::pathOf($created['fileName'])));
    assertTrue(Vault::verify($document)['ok'], 'le document du coffre n\'a pas été remis en place');
});

Tests::run('la restauration passe par une confirmation exacte, et sauvegarde d\'abord', function (): void {
    seedBackup();
    $created = Backup::create();

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/sauvegardes/' . $created['fileName'] . '/restaurer', ['confirmation' => 'à peu près']);
    assertSame(1, count(Backup::list()), 'une restauration a eu lieu sans le nom exact');

    visit('POST', '/sauvegardes/' . $created['fileName'] . '/restaurer',
        ['confirmation' => $created['fileName']]);
    // L'état précédent a été sauvegardé avant la restauration.
    assertSame(2, count(Backup::list()), 'l\'état précédent n\'a pas été sauvegardé');
});

Tests::run('les sauvegardes sont fermées aux non-administrateurs', function (): void {
    seedBackup();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/sauvegardes')->status);
    assertSame(403, visit('POST', '/sauvegardes')->status);
    assertSame(403, visit('POST', '/sauvegardes/export')->status);
    assertSame(0, count(Backup::list()));
});

Tests::run('la purge garde le nombre d\'archives demandé', function (): void {
    seedBackup();
    Backup::setConfig(true, 60, 2);
    for ($i = 0; $i < 4; $i++) {
        Backup::create();
    }
    assertSame(4, count(Backup::list()));
    $removed = Backup::prune();
    assertSame(2, count($removed));
    assertSame(2, count(Backup::list()));
});

Tests::run('le balayage ne sauvegarde qu\'une fois l\'intervalle écoulé', function (): void {
    seedBackup();
    Backup::setConfig(true, 60, 10);
    assertTrue(Backup::isDue(), 'sans archive, une sauvegarde est due');
    assertTrue(Backup::runScheduled() !== null);
    assertTrue(!Backup::isDue(), 'une sauvegarde vient d\'être prise');
    assertSame(null, Backup::runScheduled());
    // Une heure plus tard, elle l'est de nouveau.
    assertTrue(Backup::isDue(time() + 3700));

    // Éteinte, elle ne l'est jamais.
    Backup::setConfig(false, 60, 10);
    assertTrue(!Backup::isDue(time() + 86400));
});

Tests::run('l\'export intégral omet ce qui n\'a pas à circuler', function (): void {
    $ids = seedBackup();
    $archive = Exporter::build();
    $entries = [];
    foreach (Tar::unpack((string) gzdecode($archive['buffer'])) as $entry) {
        $entries[$entry['name']] = $entry['data'];
    }

    assertTrue(isset($entries['donnees/users.json']), 'les comptes doivent figurer dans l\'export');
    assertTrue(isset($entries['LISEZMOI.txt']));
    assertTrue(isset($entries['meta/manifeste.json']));

    // Les empreintes de mots de passe n'en font pas partie.
    $users = json_decode($entries['donnees/users.json'], true);
    assertTrue(!array_key_exists('password_hash', $users[0]), 'une empreinte de mot de passe a été exportée');
    assertTrue(!array_key_exists('totp_secret', $users[0]));
    assertTrue(array_key_exists('email', $users[0]));
    // Ni les sessions.
    assertTrue(!isset($entries['donnees/sessions.json']), 'les sessions ont été exportées');
});

Tests::run('l\'écran des sauvegardes rend ses archives', function (): void {
    seedBackup();
    $created = Backup::create('manuelle', 'avant migration');

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $page = visit('GET', '/sauvegardes');
    assertSame(200, $page->status);
    assertContains($created['fileName'], $page->body);

    $download = visit('GET', '/sauvegardes/' . $created['fileName'] . '/telecharger');
    assertSame(200, $download->status);
    assertSame($created['bytes'], strlen($download->body));
});
