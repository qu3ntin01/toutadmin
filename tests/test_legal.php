<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Corporate;

Tests::run("le juridique est à l'administration, la déclaration à chacun", function (): void {
    seed();

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    // Chacun voit l'espace et y dépose ses déclarations.
    assertSame(200, visit('GET', '/juridique')->status);
    visit('POST', '/juridique/interets', [
        'kind' => 'Mandat externe', 'entity' => 'Association du quartier', 'declared_on' => gmdate('Y-m-d'),
    ]);
    assertSame(1, count(Corporate::declarations()));

    // Mais les registres de la société lui sont fermés.
    assertSame(403, visit('POST', '/juridique/associes', ['name' => 'Moi', 'kind' => 'Personne physique'])->status);
    assertSame(403, visit('POST', '/juridique/mandats', ['holder_name' => 'Moi', 'role' => 'Président'])->status);
    assertSame(403, visit('POST', '/juridique/assemblees', ['kind' => 'Assemblée générale ordinaire'])->status);
    assertSame(403, visit('POST', '/juridique/delegations', ['scope' => 'Tout'])->status);
    assertSame(403, visit('GET', '/juridique/assemblees/1')->status);
    // Ni l'examen des déclarations, qui appartient à l'administration.
    assertSame(403, visit('POST', '/juridique/interets/1/examen', ['status' => 'Clos'])->status);
    assertSame(0, count(Corporate::shareholders()));
});

Tests::run('un salarié ne voit que ses propres déclarations', function (): void {
    $ids = seed();
    Corporate::declareInterest([
        'userId' => $ids['admin'], 'kind' => 'Intérêt financier', 'entity' => 'Holding du président',
        'declaredOn' => gmdate('Y-m-d'),
    ]);
    Corporate::declareInterest([
        'userId' => $ids['member'], 'kind' => 'Lien familial', 'entity' => 'Entreprise du conjoint',
        'declaredOn' => gmdate('Y-m-d'),
    ]);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    $page = visit('GET', '/juridique');
    assertContains('Entreprise du conjoint', $page->body);
    assertTrue(!str_contains($page->body, 'Holding du président'), "un salarié lit les déclarations des autres");

    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $console = visit('GET', '/juridique');
    assertContains('Holding du président', $console->body);
    assertContains('Entreprise du conjoint', $console->body);
});

Tests::run('la détention est la somme des mouvements, jamais une colonne', function (): void {
    seed();
    $alice = Corporate::createShareholder(['name' => 'Alice Fontaine', 'kind' => 'Personne physique']);
    $capitalCo = Corporate::createShareholder(['name' => 'Capital Invest', 'kind' => 'Personne morale']);

    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 700]);
    Corporate::recordMovement(['shareholderId' => $capitalCo, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 300]);

    $capital = Corporate::capital();
    assertSame(1000.0, (float) $capital['total']);
    assertSame(70.0, $capital['holders'][0]['share']);
    assertSame(['Alice Fontaine'], $capital['majority'], "la majorité simple n'est pas repérée");

    // Une réduction retire, et le pourcentage suit.
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Réduction',
        'movedOn' => '2026-02-01', 'shares' => 200]);
    assertSame(800.0, (float) Corporate::capital()['total']);
    assertSame(500.0, (float) Corporate::shareholderById($alice)['shares']);
});

Tests::run('une cession écrit les deux côtés du registre', function (): void {
    seed();
    $alice = Corporate::createShareholder(['name' => 'Alice', 'kind' => 'Personne physique']);
    $bob = Corporate::createShareholder(['name' => 'Bob', 'kind' => 'Personne physique']);
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 500]);

    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Cession', 'movedOn' => '2026-03-01',
        'shares' => 120, 'unitPrice' => 15.5, 'counterpartyId' => $bob]);

    assertSame(380.0, (float) Corporate::shareholderById($alice)['shares']);
    assertSame(120.0, (float) Corporate::shareholderById($bob)['shares'], "les titres cédés ne sont arrivés nulle part");
    // Le capital total n'a pas bougé : une cession déplace, elle ne crée pas.
    assertSame(500.0, (float) Corporate::capital()['total']);
    assertSame(3, count(Corporate::movements()));
});

Tests::run("on ne cède pas plus qu'on ne détient", function (): void {
    seed();
    $alice = Corporate::createShareholder(['name' => 'Alice', 'kind' => 'Personne physique']);
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 100]);

    $refused = Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Cession',
        'movedOn' => '2026-02-01', 'shares' => 150]);
    assertTrue(!$refused['ok']);
    assertSame('insuffisant', $refused['reason']);
    assertSame(100.0, (float) $refused['held']);
    assertSame(100.0, (float) Corporate::shareholderById($alice)['shares']);

    assertSame('introuvable', Corporate::recordMovement(['shareholderId' => 9999, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 10])['reason']);
});

Tests::run("un associé qui détient encore des titres ne s'efface pas", function (): void {
    seed();
    $alice = Corporate::createShareholder(['name' => 'Alice', 'kind' => 'Personne physique']);
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 10]);

    assertTrue(!Corporate::removeShareholder($alice), 'un associé part avec ses titres');
    assertSame(1, count(Corporate::shareholders()));

    // Une fois les titres réduits à zéro, le registre peut le retirer.
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Réduction',
        'movedOn' => '2026-02-01', 'shares' => 10]);
    assertTrue(Corporate::removeShareholder($alice));
    assertSame(0, count(Corporate::shareholders()));
});

Tests::run('la majorité se calcule sur les voix exprimées, abstentions écartées', function (): void {
    seed();
    $meeting = Corporate::createMeeting(['kind' => 'Assemblée générale ordinaire', 'heldOn' => '2026-06-15']);
    $simple = Corporate::addResolution($meeting, "Approbation des comptes", 50);
    $reinforced = Corporate::addResolution($meeting, 'Modification des statuts', 66.67);

    // 60 pour, 40 contre, 500 abstentions : 60 % des exprimées, adoptée.
    Corporate::recordVote($simple, 60, 40, 500);
    $rows = Corporate::resolutions($meeting);
    assertSame('Adoptée', $rows[0]['outcome']);

    // Les mêmes voix ne suffisent pas aux deux tiers.
    Corporate::recordVote($reinforced, 60, 40, 0);
    assertSame('Rejetée', Corporate::resolutions($meeting)[1]['outcome']);

    // Sans voix exprimée, la résolution reste en attente.
    Corporate::recordVote($reinforced, 0, 0, 120);
    assertSame('En attente', Corporate::resolutions($meeting)[1]['outcome']);
    assertTrue(!Corporate::recordVote(9999, 1, 0, 0));
});

Tests::run("le quorum se lit du capital, et dit ce qui manque", function (): void {
    seed();
    $alice = Corporate::createShareholder(['name' => 'Alice', 'kind' => 'Personne physique']);
    Corporate::recordMovement(['shareholderId' => $alice, 'kind' => 'Souscription',
        'movedOn' => '2026-01-10', 'shares' => 1000]);
    $meeting = Corporate::createMeeting(['kind' => 'Assemblée générale extraordinaire',
        'heldOn' => '2026-06-15', 'quorumRequired' => 500]);

    $short = Corporate::quorum(Corporate::meetingById($meeting));
    assertSame(1000.0, (float) $short['total']);
    assertTrue(!$short['reached']);
    assertSame(500.0, $short['missing']);

    Corporate::updateMeeting($meeting, ['kind' => 'Assemblée générale extraordinaire', 'heldOn' => '2026-06-15',
        'quorumRequired' => 500, 'sharesPresent' => 620, 'status' => 'Tenue']);
    $reached = Corporate::quorum(Corporate::meetingById($meeting));
    assertTrue($reached['reached']);
    assertSame(0.0, $reached['missing']);
    assertSame(62.0, $reached['share']);
});

Tests::run("le procès-verbal se modifie sans toucher au reste de la fiche", function (): void {
    seed();
    $meeting = Corporate::createMeeting(['kind' => 'Assemblée générale ordinaire', 'heldOn' => '2026-06-15',
        'location' => 'Siège social', 'quorumRequired' => 100]);
    Corporate::updateMeeting($meeting, ['kind' => 'Assemblée générale ordinaire', 'heldOn' => '2026-06-15',
        'quorumRequired' => 100, 'sharesPresent' => 150, 'status' => 'Tenue', 'location' => 'Siège social']);

    Corporate::updateMinutes($meeting, "L'assemblée approuve les comptes à l'unanimité.");
    $after = Corporate::meetingById($meeting);
    assertContains('approuve les comptes', $after['minutes']);
    assertSame('Tenue', $after['status'], 'le procès-verbal a écrasé le statut');
    assertSame(150.0, (float) $after['shares_present'], 'le procès-verbal a écrasé les présents');
    assertSame('Siège social', $after['location']);
});

Tests::run("les assemblées portent une référence séquentielle", function (): void {
    seed();
    $year = (int) gmdate('Y');
    $first = Corporate::createMeeting(['kind' => 'Assemblée générale ordinaire', 'heldOn' => '2026-06-15']);
    $second = Corporate::createMeeting(['kind' => 'Assemblée générale mixte', 'heldOn' => '2026-11-20']);

    assertSame("AG-$year-001", Corporate::meetingById($first)['reference']);
    assertSame("AG-$year-002", Corporate::meetingById($second)['reference']);

    // Supprimer une assemblée emporte ses résolutions.
    Corporate::addResolution($first, 'Quitus aux dirigeants', 50);
    Corporate::removeMeeting($first);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM meeting_resolutions'));
});

Tests::run("un cadeau au-delà du seuil appelle un examen", function (): void {
    $ids = seed();
    Corporate::declareGift(['userId' => $ids['member'], 'direction' => 'Reçu', 'kind' => 'Invitation',
        'thirdParty' => 'Fournisseur X', 'occurredOn' => gmdate('Y-m-d'), 'value' => 40]);
    $big = Corporate::declareGift(['userId' => $ids['member'], 'direction' => 'Reçu', 'kind' => 'Voyage',
        'thirdParty' => 'Fournisseur Y', 'occurredOn' => gmdate('Y-m-d'), 'value' => 900]);

    assertSame(1, count(Corporate::giftsToReview()), 'le seuil de 150 € ne trie rien');
    assertSame(1, Corporate::summary()['giftsToReview']);

    // Examiné, il sort de la file, quelle que soit sa valeur.
    assertTrue(Corporate::reviewGift($big, 'Restitué', $ids['admin']));
    assertSame(0, count(Corporate::giftsToReview()));
    assertTrue(!Corporate::reviewGift($big, 'Avalé', $ids['admin']));
});

Tests::run("une délégation ne s'achève pas avant de commencer", function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    visit('POST', '/juridique/delegations', [
        'holder_id' => (string) $ids['member'], 'scope' => 'Engager les achats',
        'starts_on' => '2026-06-01', 'ends_on' => '2026-05-01',
    ]);
    assertSame(0, count(Corporate::delegations()));

    // Un délégataire inconnu non plus.
    visit('POST', '/juridique/delegations', ['holder_id' => '9999', 'scope' => 'Tout',
        'starts_on' => '2026-06-01']);
    assertSame(0, count(Corporate::delegations()));

    visit('POST', '/juridique/delegations', [
        'holder_id' => (string) $ids['member'], 'scope' => 'Engager les achats jusqu\'à 5 000 €',
        'amount_limit' => '5 000', 'starts_on' => '2026-06-01', 'ends_on' => '2027-05-31',
    ]);
    $list = Corporate::delegations();
    assertSame(1, count($list));
    assertSame(5000.0, (float) $list[0]['amount_limit']);
    assertSame('En vigueur', $list[0]['status']);
    assertSame(1, Corporate::summary()['delegations']);

    // Révoquée, elle ne compte plus parmi celles en vigueur.
    assertTrue(Corporate::setDelegationStatus((int) $list[0]['id'], 'Révoquée'));
    assertSame(0, Corporate::summary()['delegations']);
});

Tests::run("les écrans du juridique tiennent debout", function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);

    // Un associé au registre, puis un mouvement, puis une assemblée.
    visit('POST', '/juridique/associes', ['name' => 'Capital Invest', 'kind' => 'Personne morale',
        'registration' => '512 345 678', 'email' => 'contact@capital-invest.test']);
    $holder = Corporate::shareholders()[0];
    visit('POST', '/juridique/mouvements', ['shareholder_id' => (string) $holder['id'],
        'kind' => 'Souscription', 'moved_on' => '2026-01-10', 'shares' => '1 200', 'unit_price' => '12,50']);
    assertSame(1200.0, (float) Corporate::capital()['total'], 'le nombre tapé à la française est refusé');

    // Une adresse électronique invalide ne passe pas.
    visit('POST', '/juridique/associes', ['name' => 'Autre', 'kind' => 'Personne physique', 'email' => 'pas-une-adresse']);
    assertSame(1, count(Corporate::shareholders()));

    $page = visit('GET', '/juridique');
    assertSame(200, $page->status);
    assertContains('Capital Invest', $page->body);

    visit('POST', '/juridique/assemblees', ['kind' => 'Assemblée générale ordinaire',
        'held_on' => '2026-06-15', 'location' => 'Siège', 'quorum_required' => '600']);
    $meeting = Corporate::meetings()[0];
    $sheet = visit('GET', '/juridique/assemblees/' . (int) $meeting['id']);
    assertSame(200, $sheet->status);
    assertContains($meeting['reference'], $sheet->body);
    assertSame(404, visit('GET', '/juridique/assemblees/9999')->status);

    // Une résolution, un vote, et le sort qui en découle.
    visit('POST', '/juridique/assemblees/' . (int) $meeting['id'] . '/resolutions',
        ['label' => 'Approbation des comptes', 'majority_required' => '50']);
    $resolution = Corporate::resolutions((int) $meeting['id'])[0];
    visit('POST', '/juridique/resolutions/' . (int) $resolution['id'] . '/vote',
        ['votes_for' => '800', 'votes_against' => '400', 'votes_abstain' => '0']);
    assertSame('Adoptée', Corporate::resolutions((int) $meeting['id'])[0]['outcome']);

    // Une majorité hors bornes est refusée.
    visit('POST', '/juridique/assemblees/' . (int) $meeting['id'] . '/resolutions',
        ['label' => 'Résolution folle', 'majority_required' => '250']);
    assertSame(1, count(Corporate::resolutions((int) $meeting['id'])));
});
