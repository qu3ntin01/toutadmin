<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Signing;
use App\Modules\Users;

function seedSigning(): array
{
    $ids = seed();
    $ids['hr'] = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['hr'], 'is_hr', true);
    $ids['second'] = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    return $ids;
}

/** Un circuit à deux signataires : le salarié, puis l'employeur. */
function openFlow(array $ids, string $body = "Contrat de travail à durée indéterminée."): int
{
    $verdict = Signing::create([
        'title' => 'Contrat de Claire Moreau', 'kind' => 'Contrat de travail', 'body' => $body,
        'createdBy' => $ids['admin'],
        'signers' => [
            ['userId' => $ids['member'], 'roleLabel' => 'Salariée'],
            ['userId' => $ids['hr'], 'roleLabel' => 'Employeur'],
        ],
    ]);
    assertTrue($verdict['ok'], $verdict['message'] ?? '');
    return (int) $verdict['id'];
}

Tests::run('mettre à la signature est réservé à l\'administration et aux RH', function (): void {
    $ids = seedSigning();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', '/parapheur')->status, 'chacun doit pouvoir voir ses documents');
    assertSame(403, visit('POST', '/parapheur', [
        'title' => 'Auto-contrat', 'kind' => 'Contrat de travail', 'body' => 'Texte',
        'signer_ids' => [(string) $ids['member']],
    ])->status);
    assertSame(0, count(Signing::list()));
});

Tests::run('un circuit se crée, fige son empreinte et prévient le premier signataire', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    $request = (array) Signing::decorate(Signing::byId($id));

    assertSame(2, $request['total']);
    assertSame(0, $request['signedCount']);
    assertSame($ids['member'], (int) $request['next']['user_id'], 'le premier signataire n\'est pas le bon');
    assertSame(
        hash('sha256', 'Contrat de travail à durée indéterminée.'),
        $request['sha256'],
        "l'empreinte ne porte pas sur le texte signé"
    );
    assertTrue(Signing::verify($request)['ok']);
    assertSame(1, Signing::pendingCountFor($ids['member']));
    assertSame(0, Signing::pendingCountFor($ids['hr']), 'le second signataire est prévenu trop tôt');
});

Tests::run('le parapheur circule dans l\'ordre', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);

    // Le second ne peut pas signer avant le premier.
    $tooEarly = Signing::canSign(Signing::byId($id), $ids['hr']);
    assertTrue(!$tooEarly['ok']);
    assertContains('au tour de', $tooEarly['message']);

    // Un tiers non plus.
    assertContains('ne figurez pas', Signing::canSign(Signing::byId($id), $ids['second'])['message']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/parapheur/$id/signer", ['password' => 'Salariee-Demo-2026!', 'consent' => '1']);
    $request = (array) Signing::decorate(Signing::byId($id));
    assertSame(1, $request['signedCount']);
    assertSame('En cours', $request['status']);
    assertSame($ids['hr'], (int) $request['next']['user_id']);
    assertSame(1, Signing::pendingCountFor($ids['hr']), 'le suivant n\'a pas été prévenu');

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    visit('POST', "/parapheur/$id/signer", ['password' => 'Rh-Demo-2026!', 'consent' => '1']);
    $signed = (array) Signing::decorate(Signing::byId($id));
    assertSame('Signé', $signed['status']);
    assertSame(2, $signed['signedCount']);
});

Tests::run('signer demande le mot de passe et le consentement', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);

    // Sans consentement.
    visit('POST', "/parapheur/$id/signer", ['password' => 'Salariee-Demo-2026!']);
    assertSame(0, (int) Signing::decorate(Signing::byId($id))['signedCount']);

    // Avec un mauvais mot de passe.
    visit('POST', "/parapheur/$id/signer", ['password' => 'PasLeBon-2026!', 'consent' => '1']);
    assertSame(0, (int) Signing::decorate(Signing::byId($id))['signedCount']);

    visit('POST', "/parapheur/$id/signer", ['password' => 'Salariee-Demo-2026!', 'consent' => '1']);
    assertSame(1, (int) Signing::decorate(Signing::byId($id))['signedCount']);
});

Tests::run('le sceau ne se recopie pas à la main', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/parapheur/$id/signer", ['password' => 'Salariee-Demo-2026!', 'consent' => '1']);

    $request = (array) Signing::byId($id);
    assertTrue(Signing::verify($request)['ok']);

    // Une signature écrite directement en base, sans sceau valable.
    Db::run(
        "UPDATE signature_signers SET status = 'Signé', signed_at = ?, seal = 'faux-sceau'
         WHERE request_id = ? AND user_id = ?",
        [gmdate('c'), $id, $ids['hr']]
    );
    $verification = Signing::verify($request);
    assertTrue(!$verification['ok'], 'un sceau inventé est passé');
    assertSame(['Inès Garnier'], $verification['broken']);
});

Tests::run('un document altéré ne se signe plus', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    // Quelqu'un retouche le texte après la mise à la signature.
    Db::run('UPDATE signature_requests SET body = ? WHERE id = ?', ['Texte remplacé.', $id]);

    $result = Signing::sign($id, $ids['member'], 'Salariee-Demo-2026!', true);
    assertTrue(!$result['ok']);
    assertContains('altéré', $result['message']);
    assertSame(0, (int) Signing::decorate(Signing::byId($id))['signedCount']);
});

Tests::run('un refus motivé interrompt le circuit', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);

    // Un refus sans motif n'en est pas un.
    assertTrue(!Signing::refuse($id, $ids['member'], '  ')['ok']);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', "/parapheur/$id/refuser", ['reason' => "La durée du préavis ne correspond pas à ce qui a été convenu."]);

    $request = (array) Signing::decorate(Signing::byId($id));
    assertSame('Refusé', $request['status']);
    assertContains('préavis', (string) $request['closing_reason']);
    // Le suivant n'a plus à se prononcer.
    assertSame(0, Signing::pendingCountFor($ids['hr']));
    assertTrue(!Signing::canSign(Signing::byId($id), $ids['hr'])['ok']);
});

Tests::run('un document signé ne se supprime pas', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);

    // Tant qu'il circule, il s'annule avant de s'effacer.
    assertContains('Annulez', Signing::remove($id)['message']);

    Signing::sign($id, $ids['member'], 'Salariee-Demo-2026!', true);
    Signing::cancel($id, 'Erreur de destinataire');
    // Annulé, mais une signature a été apposée : il fait preuve.
    $verdict = Signing::remove($id);
    assertTrue(!$verdict['ok']);
    assertContains('fait preuve', $verdict['message']);
    assertTrue(Signing::byId($id) !== null);
});

Tests::run('un document sans aucune signature s\'efface une fois annulé', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    Signing::cancel($id, 'Document erroné');
    assertTrue(Signing::remove($id)['ok']);
    assertSame(null, Signing::byId($id));
});

Tests::run('un document au parapheur ne se lit que par ses parties', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(403, visit('GET', "/parapheur/$id")->status);
    assertSame(403, visit('GET', "/parapheur/$id/attestation")->status);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', "/parapheur/$id")->status);
});

Tests::run('les signataires se contrôlent à l\'ouverture du circuit', function (): void {
    $ids = seedSigning();
    $doubles = Signing::create([
        'title' => 'Deux fois', 'kind' => 'Avenant', 'body' => 'Texte', 'createdBy' => $ids['admin'],
        'signers' => [['userId' => $ids['member']], ['userId' => $ids['member']]],
    ]);
    assertContains('deux fois', $doubles['message']);

    $none = Signing::create([
        'title' => 'Personne', 'kind' => 'Avenant', 'body' => 'Texte', 'createdBy' => $ids['admin'], 'signers' => [],
    ]);
    assertContains('au moins un signataire', $none['message']);

    $empty = Signing::create([
        'title' => 'Vide', 'kind' => 'Avenant', 'body' => '   ', 'createdBy' => $ids['admin'],
        'signers' => [['userId' => $ids['member']]],
    ]);
    assertContains('Déposez un fichier', $empty['message']);

    Db::run('UPDATE users SET active = 0 WHERE id = ?', [$ids['second']]);
    $closed = Signing::create([
        'title' => 'Compte fermé', 'kind' => 'Avenant', 'body' => 'Texte', 'createdBy' => $ids['admin'],
        'signers' => [['userId' => $ids['second']]],
    ]);
    assertContains('désactivé', $closed['message']);
    assertSame(0, count(Signing::list()));
});

Tests::run('l\'attestation porte l\'empreinte et les sceaux', function (): void {
    $ids = seedSigning();
    $id = openFlow($ids);
    Signing::sign($id, $ids['member'], 'Salariee-Demo-2026!', true);
    Signing::sign($id, $ids['hr'], 'Rh-Demo-2026!', true);

    visit('POST', '/connexion', ['email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!']);
    $page = visit('GET', "/parapheur/$id/attestation");
    assertSame(200, $page->status);
    assertContains(hash('sha256', 'Contrat de travail à durée indéterminée.'), $page->body);
    assertContains('Moreau', $page->body);
    assertContains('Garnier', $page->body);
});
