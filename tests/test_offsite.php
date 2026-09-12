<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Db;
use App\Core\Secrets;
use App\Core\Settings;
use App\Modules\Backup;
use App\Modules\Notifications;
use App\Modules\Offsite;
use App\Modules\Offsite\Drive;
use App\Modules\Offsite\Ftp;

function seedOffsite(): array
{
    $ids = seed();
    $dir = sys_get_temp_dir() . '/toutadmin-offsite-' . bin2hex(random_bytes(6));
    mkdir($dir, 0770, true);
    Config::set('data_dir', $dir);
    Ftp::$transport = null;
    Drive::$transport = null;
    Drive::forgetTokens();
    return $ids;
}

/** Une configuration FTP complète, telle que l'écran l'enverrait. */
function ftpConfig(): array
{
    return [
        'host' => 'nas.entreprise.test', 'port' => '21', 'mode' => 'ftps',
        'user' => 'sauvegarde', 'password' => 'motdepasse', 'directory' => '/sauvegardes',
    ];
}

Tests::run('un secret se chiffre, se relit, et ne ressort jamais en clair', function (): void {
    seedOffsite();
    $sealed = Secrets::encrypt('motdepasse-ftp');
    assertTrue(Secrets::isEncrypted($sealed));
    assertTrue(!str_contains($sealed, 'motdepasse-ftp'), 'le secret figure en clair dans la valeur stockée');
    assertSame('motdepasse-ftp', Secrets::decrypt($sealed));
    assertSame('•••••••• (défini)', Secrets::mask($sealed));
    assertSame('', Secrets::mask(''));

    // Une valeur altérée ne se devine pas : elle est perdue, pas approximée.
    assertSame('', Secrets::decrypt(substr($sealed, 0, -6) . 'AAAAAA'));
    // Une valeur écrite avant le chiffrement se relit telle quelle.
    assertSame('ancienne', Secrets::decrypt('ancienne'));
});

Tests::run('la configuration garde ses secrets et ne les rend pas à l\'écran', function (): void {
    seedOffsite();
    Offsite::setConfig('ftp', ftpConfig());

    assertSame('motdepasse', Offsite::config('ftp')['password']);
    assertSame('•••••••• (défini)', Offsite::displayConfig('ftp')['password']);
    assertSame('nas.entreprise.test', Offsite::displayConfig('ftp')['host']);
    // Le mot de passe n'est pas en clair dans la table des réglages.
    $stored = Settings::get('offsite.ftp.config');
    assertTrue(!str_contains($stored, 'motdepasse'), 'le mot de passe est en clair en base');

    // Un champ secret laissé vide conserve la valeur en place : l'écran ne
    // l'affiche pas, il ne peut donc pas le renvoyer.
    Offsite::setConfig('ftp', ftpConfig() + ['password' => '']);
    assertSame('motdepasse', Offsite::config('ftp')['password'], 'le secret a été effacé par un formulaire vide');
});

Tests::run('une destination incomplète ne s\'active pas', function (): void {
    seedOffsite();
    assertTrue(!Offsite::isConfigured('ftp'));
    assertTrue(!Offsite::setEnabled('ftp', true), 'une destination incomplète a été activée');
    assertTrue(!Offsite::isEnabled('ftp'));

    Offsite::setConfig('ftp', ftpConfig());
    assertTrue(Offsite::isConfigured('ftp'));
    assertTrue(Offsite::setEnabled('ftp', true));
    assertTrue(Offsite::isEnabled('ftp'));

    // Éteindre reste toujours possible.
    assertTrue(Offsite::setEnabled('ftp', false));
    assertTrue(!Offsite::isEnabled('ftp'));
    assertTrue(!Offsite::setEnabled('inconnue', true));
});

Tests::run('l\'URL FTP suit le mode, le port et le dossier', function (): void {
    seedOffsite();
    assertSame('ftp://nas.test:21/sauvegardes/archive.tar.gz',
        Ftp::url(['host' => 'nas.test', 'mode' => 'ftps', 'directory' => '/sauvegardes'], 'archive.tar.gz'));
    // Le FTPS implicite parle sur le port 990, avec son propre schéma.
    assertSame('ftps://nas.test:990/archive.tar.gz',
        Ftp::url(['host' => 'nas.test', 'mode' => 'ftps-implicite'], 'archive.tar.gz'));
    assertSame('ftp://nas.test:2121/depot/archive.tar.gz',
        Ftp::url(['host' => 'nas.test', 'mode' => 'ftp', 'port' => '2121', 'directory' => 'depot/'], 'archive.tar.gz'));

    assertSame('/sauvegardes', Ftp::normalizeDirectory('/sauvegardes/'));
    assertSame('/a/b', Ftp::normalizeDirectory('\\a\\\\b'));
    assertSame('', Ftp::normalizeDirectory('   '));
});

Tests::run('un envoi FTP réussi est consigné, un échec aussi', function (): void {
    seedOffsite();
    Offsite::setConfig('ftp', ftpConfig());

    $sent = [];
    Ftp::$transport = function (array $options) use (&$sent): array {
        $sent[] = $options[CURLOPT_URL] ?? '';
        return ['ok' => true, 'body' => ''];
    };
    $result = Offsite::send('ftp', 'sauvegarde-2026-01-01.tar.gz', 'contenu');
    assertTrue($result['ok'], $result['message'] ?? '');
    assertContains('sauvegarde-2026-01-01.tar.gz', $sent[0]);
    assertTrue(Offsite::status('ftp')['ok']);

    Ftp::$transport = static fn (array $options): array => ['ok' => false, 'message' => 'connexion refusée'];
    $failed = Offsite::send('ftp', 'sauvegarde-2026-01-02.tar.gz', 'contenu');
    assertTrue(!$failed['ok']);
    $status = (array) Offsite::status('ftp');
    assertTrue(!$status['ok'], "l'échec n'a pas été consigné");
    assertContains('connexion refusée', $status['message']);
});

Tests::run('un échec d\'externalisation alerte les administrateurs, une fois par jour', function (): void {
    $ids = seedOffsite();
    Offsite::setConfig('ftp', ftpConfig());
    Offsite::setEnabled('ftp', true);
    Ftp::$transport = static fn (array $options): array => ['ok' => false, 'message' => 'hôte injoignable'];

    Offsite::afterBackup('sauvegarde-2026-01-01.tar.gz', 'contenu');
    $notified = Notifications::forUser($ids['admin']);
    assertSame(1, count($notified), "l'administrateur n'a pas été prévenu");
    assertContains('Externalisation en échec', $notified[0]['title']);

    // Une même destination en échec n'alerte qu'une fois par jour.
    Offsite::afterBackup('sauvegarde-2026-01-02.tar.gz', 'contenu');
    assertSame(1, count(Notifications::forUser($ids['admin'])), "l'alerte s'est répétée le même jour");
    // Et l'échec figure au journal.
    assertTrue((int) Db::value(
        "SELECT COUNT(*) FROM audit_log WHERE action = 'sauvegarde.externalisation_echec'"
    ) >= 1);
});

Tests::run('la purge distante aligne le nombre d\'archives sur le réglage local', function (): void {
    seedOffsite();
    Offsite::setConfig('ftp', ftpConfig());

    $deleted = [];
    Ftp::$transport = function (array $options) use (&$deleted): array {
        if (isset($options[CURLOPT_QUOTE])) {
            $deleted[] = $options[CURLOPT_QUOTE][0];
            return ['ok' => true, 'body' => ''];
        }
        // Un listing FTP : une ligne par nom.
        return ['ok' => true, 'body' => implode("\n", [
            'sauvegarde-2026-01-01.tar.gz',
            'sauvegarde-2026-01-02.tar.gz',
            'sauvegarde-2026-01-03.tar.gz',
            'autre-fichier.txt',
        ])];
    };

    $listing = Offsite::remoteList('ftp');
    assertSame(3, count($listing['files']), 'seules les archives de sauvegarde comptent');
    // Le tri met la plus récente en tête : la purge garde les premières.
    assertSame('sauvegarde-2026-01-03.tar.gz', $listing['files'][0]['name']);

    $pruned = Offsite::prune('ftp', 2);
    assertSame(1, count($pruned['removed']));
    assertSame('sauvegarde-2026-01-01.tar.gz', $pruned['removed'][0]);
    assertContains('DELE /sauvegardes/sauvegarde-2026-01-01.tar.gz', $deleted[0]);
});

Tests::run('le jeton Drive est un JWT signé, réutilisé tant qu\'il vaut', function (): void {
    seedOffsite();
    // Une clé de service, engendrée pour le test.
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $config = ['clientEmail' => 'sauvegarde@projet.iam.gserviceaccount.com', 'privateKey' => $pem, 'folderId' => 'ABC'];

    $assertion = Drive::buildAssertion($config, 1767225600000);
    [$header, $claims, $signature] = explode('.', $assertion);
    assertSame(['alg' => 'RS256', 'typ' => 'JWT'], json_decode(base64_decode(strtr($header, '-_', '+/')), true));
    $payload = json_decode(base64_decode(strtr($claims, '-_', '+/')), true);
    assertSame('sauvegarde@projet.iam.gserviceaccount.com', $payload['iss']);
    assertSame(Drive::SCOPE, $payload['scope']);
    assertSame(1767225600, $payload['iat']);
    assertSame(1767229200, $payload['exp'], 'le jeton doit valoir une heure');
    // La signature est vérifiable avec la clé publique : c'est bien du RS256.
    assertSame(1, openssl_verify(
        "$header.$claims",
        base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($signature)) % 4)),
        openssl_pkey_get_public(openssl_pkey_get_details($key)['key']),
        OPENSSL_ALGO_SHA256
    ));

    // Un seul appel au service de jetons pour deux envois.
    $calls = ['token' => 0, 'upload' => 0];
    Drive::$transport = function (string $url, array $options) use (&$calls): array {
        if ($url === Drive::TOKEN_URL) {
            $calls['token']++;
            return ['ok' => true, 'status' => 200, 'body' => json_encode(['access_token' => 'jeton', 'expires_in' => 3600])];
        }
        $calls['upload']++;
        return ['ok' => true, 'status' => 200, 'body' => json_encode(['id' => 'fichier-1'])];
    };
    assertTrue(Drive::upload($config, 'sauvegarde-1.tar.gz', 'contenu')['ok']);
    assertTrue(Drive::upload($config, 'sauvegarde-2.tar.gz', 'contenu')['ok']);
    assertSame(1, $calls['token'], 'le jeton a été redemandé alors qu\'il valait encore');
    assertSame(2, $calls['upload']);

    // Un changement de configuration oublie le jeton.
    Drive::forgetTokens();
    Drive::upload($config, 'sauvegarde-3.tar.gz', 'contenu');
    assertSame(2, $calls['token']);
});

Tests::run('Drive dit ce que Google a refusé, et ce qu\'il ne peut pas signer', function (): void {
    seedOffsite();
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    Drive::$transport = static fn (string $url, array $options): array =>
        $url === Drive::TOKEN_URL
            ? ['ok' => false, 'status' => 401, 'body' => '{"error":"invalid_grant"}']
            : ['ok' => true, 'status' => 200, 'body' => '{}'];

    $refused = Drive::upload(['clientEmail' => 'x@y.z', 'privateKey' => $pem, 'folderId' => 'ABC'], 'a.tar.gz', 'x');
    assertTrue(!$refused['ok']);
    assertContains('invalid_grant', $refused['message']);

    // Une clé illisible se dit franchement, sans partir sur le réseau.
    Drive::forgetTokens();
    $unreadable = Drive::upload(['clientEmail' => 'x@y.z', 'privateKey' => 'pas une clé', 'folderId' => 'ABC'], 'a.tar.gz', 'x');
    assertTrue(!$unreadable['ok']);
    assertContains('private_key', $unreadable['message']);
});

Tests::run('l\'écran d\'externalisation n\'affiche aucun secret', function (): void {
    seedOffsite();
    Offsite::setConfig('ftp', ftpConfig());
    Offsite::setEnabled('ftp', true);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $page = visit('GET', '/sauvegardes');
    assertSame(200, $page->status);
    assertContains('nas.entreprise.test', $page->body);
    assertContains('Serveur FTP', $page->body);
    assertTrue(!str_contains($page->body, 'motdepasse'), 'le mot de passe FTP est affiché');
});

Tests::run('l\'externalisation est fermée aux non-administrateurs', function (): void {
    seedOffsite();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('POST', '/sauvegardes/destinations/ftp', ftpConfig())->status);
    assertSame(403, visit('POST', '/sauvegardes/destinations/ftp/tester')->status);
    assertSame('', Settings::get('offsite.ftp.config'));
});
