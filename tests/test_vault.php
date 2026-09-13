<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Users;
use App\Modules\Vault;

/** Un PDF minimal mais authentique : la signature du fichier doit passer. */
function pdfBytes(string $marker = 'x'): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\n% $marker\n%%EOF\n";
}

function fileUpload(string $bytes, string $mime = 'application/pdf', string $name = 'bulletin.pdf'): array
{
    return ['document' => ['bytes' => $bytes, 'mime' => $mime, 'name' => $name]];
}

function seedVault(): array
{
    $ids = seed();
    $ids['hr'] = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['hr'], 'is_hr', true);
    return $ids;
}

Tests::run('le dépôt est réservé aux RH, le retrait à l\'administration', function (): void {
    $ids = seedVault();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/coffre-fort/gestion')->status);
    assertSame(403, visit('POST', '/coffre-fort/gestion/depots', ['user_id' => (string) $ids['member']])->status);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    assertSame(200, visit('GET', '/coffre-fort/gestion')->status);
    visit('POST', '/coffre-fort/gestion/depots', [
        'user_id' => (string) $ids['member'], 'title' => 'Bulletin janvier', 'category' => 'Bulletin de paie',
        'period' => '2026-01',
    ], [], fileUpload(pdfBytes()));
    assertSame(1, count(Vault::documentsFor($ids['member'])));

    // Les RH déposent, mais ne retirent pas : le retrait engage.
    $document = Vault::documentsFor($ids['member'])[0];
    assertSame(403, visit('POST', '/coffre-fort/gestion/documents/' . $document['id'] . '/retirer',
        ['reason' => 'Doublon de janvier'])->status);
    assertSame(null, Vault::byId((int) $document['id'])['removed_at']);
});

Tests::run('un fichier qui n\'est pas du type annoncé est refusé', function (): void {
    $ids = seedVault();
    // Un exécutable renommé en PDF : la signature ne correspond pas.
    $verdict = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Faux',
        'file' => ['bytes' => "MZ\x90\x00 binaire", 'mime' => 'application/pdf', 'name' => 'faux.pdf'],
    ]);
    assertTrue(!$verdict['ok']);
    assertContains("n'est pas du type annoncé", $verdict['message']);
    assertSame(0, count(Vault::documentsFor($ids['member'])));

    // Un type non accepté non plus.
    $exe = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Tableur',
        'file' => ['bytes' => pdfBytes(), 'mime' => 'application/x-msdownload', 'name' => 'x.exe'],
    ]);
    assertSame(false, $exe['ok']);
});

Tests::run('le même document ne se dépose pas deux fois', function (): void {
    $ids = seedVault();
    $file = ['bytes' => pdfBytes(), 'mime' => 'application/pdf', 'name' => 'bulletin.pdf'];
    assertTrue(Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Janvier', 'file' => $file,
    ])['ok']);
    $again = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Janvier bis', 'file' => $file,
    ]);
    assertTrue(!$again['ok']);
    assertTrue(isset($again['duplicate']));
    assertSame(1, count(Vault::documentsFor($ids['member'])));
});

Tests::run('un document altéré n\'est pas servi', function (): void {
    $ids = seedVault();
    $verdict = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Février',
        'file' => ['bytes' => pdfBytes('fev'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    $document = (array) Vault::byId((int) $verdict['id']);
    assertTrue(Vault::verify($document)['ok']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/coffre-fort/documents/' . $document['id'])->status);

    // Quelqu'un change le fichier sur le disque : l'empreinte ne suit plus.
    file_put_contents(Vault::pathOf($document), pdfBytes('bidouille'));
    assertTrue(!Vault::verify($document)['ok']);
    assertSame(500, visit('GET', '/coffre-fort/documents/' . $document['id'])->status);
    assertSame(1, count(Vault::integrityAudit()['broken']));
});

Tests::run('le coffre d\'un autre ne se télécharge pas', function (): void {
    $ids = seedVault();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $verdict = Vault::deposit([
        'userId' => $autre, 'category' => 'Contrat de travail', 'title' => 'Contrat de Marc',
        'file' => ['bytes' => pdfBytes('marc'), 'mime' => 'application/pdf', 'name' => 'c.pdf'],
    ]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/coffre-fort/documents/' . $verdict['id'])->status);

    // Les RH, eux, y accèdent : c'est leur travail.
    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    assertSame(200, visit('GET', '/coffre-fort/documents/' . $verdict['id'])->status);
});

Tests::run('un retrait laisse sa trace, son auteur et son motif', function (): void {
    $ids = seedVault();
    $verdict = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Mars',
        'file' => ['bytes' => pdfBytes('mars'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    $id = (int) $verdict['id'];

    // Un motif trop court est refusé.
    assertTrue(!Vault::remove($id, $ids['admin'], 'oups')['ok']);

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', "/coffre-fort/gestion/documents/$id/retirer", ['reason' => 'Déposé sur le mauvais compte']);

    $document = (array) Vault::byId($id);
    assertTrue($document['removed_at'] !== null, 'le retrait n\'a pas été consigné');
    assertSame('Déposé sur le mauvais compte', $document['removal_reason']);
    assertSame($ids['admin'], (int) $document['removed_by']);
    // La ligne reste, le fichier non.
    assertSame(0, count(Vault::documentsFor($ids['member'])));
    assertSame(1, count(Vault::documentsFor($ids['member'], true)));
    assertTrue(!is_file(Vault::pathOf($document)));
});

Tests::run('un code ouvre une session qui ne voit que le coffre', function (): void {
    $ids = seedVault();
    Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Avril',
        'file' => ['bytes' => pdfBytes('avr'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    // Le compte est fermé : la personne est partie.
    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['member']]);

    $grant = Vault::issueGrant($ids['member'], 90, $ids['admin']);
    visit('POST', '/coffre-fort/acces', ['email' => 'claire.moreau@entreprise.com', 'code' => $grant['code']]);
    assertSame(200, visit('GET', '/coffre-fort')->status, 'le code n\'a pas ouvert le coffre');

    // Hors du coffre, rien : une page se solde par une redirection, une écriture par un refus.
    assertSame(302, visit('GET', '/mon-espace')->status);
    assertSame(302, visit('GET', '/annuaire')->status);
    assertSame(403, visit('POST', '/mon-profil', ['first_name' => 'Pirate'])->status);
    // Et la gestion du coffre reste fermée.
    assertSame(302, visit('GET', '/coffre-fort/gestion')->status);
});

Tests::run('un code faux, expiré ou révoqué n\'ouvre rien', function (): void {
    $ids = seedVault();
    Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Mai',
        'file' => ['bytes' => pdfBytes('mai'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    $grant = Vault::issueGrant($ids['member'], 90, $ids['admin']);

    assertSame(null, Vault::redeem('claire.moreau@entreprise.com', 'AAAAA-BBBBB-CCCCC'));
    assertSame(null, Vault::redeem('inconnu@entreprise.com', $grant['code']));

    // Émettre un nouveau code révoque le précédent.
    $second = Vault::issueGrant($ids['member'], 90, $ids['admin']);
    assertSame(null, Vault::redeem('claire.moreau@entreprise.com', $grant['code']));
    assertTrue(Vault::redeem('claire.moreau@entreprise.com', $second['code']) !== null);

    // Révoquer ferme tout.
    Vault::revokeGrants($ids['member']);
    assertSame(null, Vault::redeem('claire.moreau@entreprise.com', $second['code']));

    // Un code expiré non plus.
    $third = Vault::issueGrant($ids['member'], 1, $ids['admin']);
    Db::run("UPDATE vault_access_grants SET expires_at = ? WHERE user_id = ? AND revoked_at IS NULL",
        [gmdate('c', time() - 3600), $ids['member']]);
    assertSame(null, Vault::redeem('claire.moreau@entreprise.com', $third['code']));
});

Tests::run('un code n\'est émis que pour un coffre qui contient quelque chose', function (): void {
    $ids = seedVault();
    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    visit('POST', '/coffre-fort/gestion/acces/' . $ids['member'], ['days' => '90']);
    assertSame(0, count(Vault::grantsFor($ids['member'])), 'un code a été émis sur un coffre vide');

    Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Juin',
        'file' => ['bytes' => pdfBytes('juin'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    visit('POST', '/coffre-fort/gestion/acces/' . $ids['member'], ['days' => '90']);
    assertSame(1, count(Vault::grantsFor($ids['member'])));
    // Le code n'est montré qu'une fois : il est affiché puis retiré de la session.
    $page = visit('GET', '/coffre-fort/gestion', [], ['personne' => (string) $ids['member']]);
    assertContains('affiché une seule fois', $page->body);
    assertTrue(!str_contains(visit('GET', '/coffre-fort/gestion', [], ['personne' => (string) $ids['member']])->body,
        'affiché une seule fois'), 'le code est resté affiché');
});

Tests::run('la conservation court sur cinquante ans', function (): void {
    $ids = seedVault();
    $verdict = Vault::deposit([
        'userId' => $ids['member'], 'category' => 'Bulletin de paie', 'title' => 'Juillet',
        'file' => ['bytes' => pdfBytes('juil'), 'mime' => 'application/pdf', 'name' => 'b.pdf'],
    ]);
    $document = (array) Vault::byId((int) $verdict['id']);
    assertSame((int) gmdate('Y') + 50, (int) substr((string) $document['retention_until'], 0, 4));
    assertSame('2076-01-15', Vault::retentionFrom('2026-01-15'));
});

Tests::run("l'écran de connexion dit aux anciens salariés par où passer", function (): void {
    seed();
    $page = visit('GET', '/connexion')->body;
    // Le coffre reste atteignable sans compte : encore faut-il que la porte se
    // voie, sinon la fonction existe sans servir.
    assertContains('/coffre-fort/acces', $page);
    assertSame(200, visit('GET', '/coffre-fort/acces')->status);
});
