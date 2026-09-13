<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Notifications;
use App\Modules\Org;
use App\Modules\Users;
use App\Modules\Workflows;

function formulaire(?array $fields = null, string $amountField = 'montant', ?int $by = null): int
{
    $fields ??= [
        ['name' => 'motif', 'label' => 'Motif', 'type' => 'texte', 'required' => true],
        ['name' => 'montant', 'label' => 'Montant', 'type' => 'montant', 'required' => true],
    ];
    $verdict = Workflows::createForm('Note de frais', 'Remboursement', $fields, $amountField, $by);
    assertTrue($verdict['ok'], $verdict['message'] ?? '');
    return (int) $verdict['id'];
}

Tests::run('un type de demande exige un intitulé et des champs', function (): void {
    seed();
    assertTrue(!Workflows::createForm('', '', [['name' => 'a', 'type' => 'texte']], '', null)['ok']);
    assertTrue(!Workflows::createForm('Sans champ', '', [], '', null)['ok']);
    assertTrue(!Workflows::createForm('Type inconnu', '', [['name' => 'a', 'type' => 'martien']], '', null)['ok']);
    // Deux champs de même nom : refusé, sinon l'un écraserait l'autre.
    assertTrue(!Workflows::createForm('Doublon', '', [
        ['name' => 'a', 'type' => 'texte'], ['name' => 'a', 'type' => 'texte'],
    ], '', null)['ok']);
    // Un champ de montant qui n'existe pas rendrait les seuils inopérants.
    assertTrue(!Workflows::createForm('Montant fantôme', '', [['name' => 'a', 'type' => 'texte']], 'prix', null)['ok']);
});

Tests::run('une étape désigne une fonction, pas une personne', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    assertTrue(Workflows::addStep($formId, 'manager', null, '', 0)['ok']);
    assertTrue(Workflows::addStep($formId, 'admin', null, '', 500)['ok']);
    assertTrue(!Workflows::addStep($formId, 'martien', null, '', 0)['ok']);
    assertTrue(!Workflows::addStep($formId, 'user', 9999, '', 0)['ok'], 'une personne inconnue est acceptée');
    assertSame(2, count(Workflows::formById($formId)['steps']));
});

Tests::run('une demande sans étape applicable est approuvée d\'emblée', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    // Une seule étape : le manager. La salariée n'en a pas → étape sautée.
    Workflows::addStep($formId, 'manager', null, '', 0);

    $verdict = Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Taxi', 'champ_montant' => '32']);
    assertTrue($verdict['ok']);
    assertSame(0, $verdict['steps']);
    assertSame('Approuvée', Workflows::byId((int) $verdict['id'])['status']);
});

Tests::run('le seuil n\'appelle la direction qu\'au-delà du montant', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 500);

    $petite = Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Taxi', 'champ_montant' => '32']);
    assertSame('Approuvée', Workflows::byId((int) $petite['id'])['status'], 'une petite dépense a été envoyée en validation');

    $grosse = Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Salon', 'champ_montant' => '1 200,50']);
    assertSame('En cours', Workflows::byId((int) $grosse['id'])['status']);
    assertSame(1200.5, (float) Workflows::byId((int) $grosse['id'])['amount']);
});

Tests::run('la saisie est contrôlée contre la description du formulaire', function (): void {
    $ids = seed();
    $formId = formulaire([
        ['name' => 'motif', 'label' => 'Motif', 'type' => 'texte', 'required' => true],
        ['name' => 'jour', 'label' => 'Jour', 'type' => 'date', 'required' => false],
        ['name' => 'moyen', 'label' => 'Moyen', 'type' => 'choix', 'required' => false, 'options' => ['Train', 'Voiture']],
    ], '', $ids['admin']);
    // Pas de champ de montant ici : ce type ne porte aucun seuil.

    assertTrue(!Workflows::submit($formId, $ids['member'], [])['ok'], 'un champ obligatoire vide est passé');
    assertTrue(!Workflows::submit($formId, $ids['member'], ['champ_motif' => 'x', 'champ_jour' => '32/13'])['ok']);
    assertTrue(!Workflows::submit($formId, $ids['member'], ['champ_motif' => 'x', 'champ_moyen' => 'Fusée'])['ok']);
    assertTrue(Workflows::submit($formId, $ids['member'], ['champ_motif' => 'x', 'champ_moyen' => 'Train'])['ok']);
});

Tests::run('personne ne valide sa propre demande', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 0);

    // L'administrateur dépose lui-même : l'étape « administration » ne peut pas
    // être tenue par lui, elle est donc sautée.
    $verdict = Workflows::submit($formId, $ids['admin'], ['champ_motif' => 'Congrès', 'champ_montant' => '900']);
    assertSame('Approuvée', Workflows::byId((int) $verdict['id'])['status']);
});

Tests::run('le circuit avance étape par étape', function (): void {
    $ids = seed();
    $hr = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($hr, 'is_hr', true);

    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'hr', null, '', 0);
    Workflows::addStep($formId, 'admin', null, '', 0);

    $verdict = Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Formation', 'champ_montant' => '800']);
    $id = (int) $verdict['id'];
    assertSame(2, $verdict['steps']);

    // Un salarié sans rôle ne tranche rien, même en envoyant la requête à la main.
    $tiers = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    assertTrue(!Workflows::decide($id, $tiers, 'Approuvée')['ok'], 'une étape a été décidée par le mauvais validateur');

    // L'administration fait partie des RH comme de l'administration : c'est la
    // règle de l'édition Node, un compte admin supplée les deux fonctions.
    assertSame('En cours', Workflows::decide($id, $hr, 'Approuvée', 'Vu')['status']);
    assertSame('Approuvée', Workflows::decide($id, $ids['admin'], 'Approuvée')['status']);
    assertSame('Approuvée', Workflows::byId($id)['status']);
    assertSame(2, count(Workflows::byId($id)['decisions']));
});

Tests::run('un refus referme la demande et se motive', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 0);
    $id = (int) Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Congrès', 'champ_montant' => '900'])['id'];

    assertTrue(!Workflows::decide($id, $ids['admin'], 'Refusée', '  ')['ok'], 'un refus sans motif est passé');
    assertSame('Refusée', Workflows::decide($id, $ids['admin'], 'Refusée', 'Hors budget')['status']);
    assertSame('Refusée', Workflows::byId($id)['status']);
});

Tests::run('le demandeur retire sa demande tant que rien n\'est décidé', function (): void {
    $ids = seed();
    $hr = Users::create([
        'role' => 'employee', 'email' => 'rh@entreprise.com', 'password' => 'Rh-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($hr, 'is_hr', true);
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'hr', null, '', 0);
    Workflows::addStep($formId, 'admin', null, '', 0);
    $id = (int) Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Formation', 'champ_montant' => '800'])['id'];

    assertTrue(!Workflows::cancel($id, $ids['admin'])['ok'], 'un tiers a retiré la demande');
    Workflows::decide($id, $hr, 'Approuvée');
    assertTrue(!Workflows::cancel($id, $ids['member'])['ok'], 'une demande déjà examinée a été retirée');
});

Tests::run('le validateur est prévenu, puis le demandeur à la clôture', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 0);
    $id = (int) Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Congrès', 'champ_montant' => '900'])['id'];

    assertSame(1, Notifications::unreadCount($ids['admin']), 'le validateur n\'a pas été prévenu');
    Workflows::decide($id, $ids['admin'], 'Refusée', 'Hors budget');
    assertSame(1, Notifications::unreadCount($ids['member']), 'le demandeur n\'a pas été prévenu');

    // Même étape, même validateur : pas de doublon.
    Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Autre', 'champ_montant' => '900']);
    assertSame(2, Notifications::unreadCount($ids['admin']));
});

Tests::run('une demande ne se lit que par ceux qu\'elle concerne', function (): void {
    $ids = seed();
    $tiers = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 0);
    $id = (int) Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Congrès', 'champ_montant' => '900'])['id'];

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(404, visit('GET', "/demandes/$id")->status, 'un tiers a pu lire la demande');

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(200, visit('GET', "/demandes/$id")->status, 'le demandeur ne peut pas lire sa demande');
});

Tests::run('le paramétrage des circuits est fermé aux salariés', function (): void {
    seed();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('POST', '/demandes/types', ['label' => 'Tentative'])->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM request_forms'));
});

Tests::run('un type avec des demandes en cours se suspend au lieu de s\'effacer', function (): void {
    $ids = seed();
    $formId = formulaire(null, 'montant', $ids['admin']);
    Workflows::addStep($formId, 'admin', null, '', 0);
    Workflows::submit($formId, $ids['member'], ['champ_motif' => 'Congrès', 'champ_montant' => '900']);

    $verdict = Workflows::deleteForm($formId);
    assertTrue(!$verdict['ok'], 'un type avec des demandes en cours a été effacé');
    assertTrue(Workflows::setFormActive($formId, false));
    assertSame(0, (int) Workflows::formById($formId)['active']);
});

Tests::run('le circuit complet passe par les écrans', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/demandes/types', [
        'label' => 'Note de frais', 'amount_field' => 'montant',
        'field_names' => ['motif', 'montant'],
        'field_labels' => ['Motif', 'Montant'],
        'field_types' => ['texte', 'montant'],
        'field_required' => ['1', '1'],
        'field_options' => ['', ''],
    ]);
    $forms = Workflows::forms();
    assertSame(1, count($forms), 'aucun type créé depuis l\'écran');
    $formId = (int) $forms[0]['id'];
    visit('POST', "/demandes/types/$formId/etapes", ['approver' => 'admin', 'threshold' => '0']);

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/demandes', ['form_id' => (string) $formId, 'champ_motif' => 'Taxi', 'champ_montant' => '32']);
    $requests = Workflows::list($ids['member']);
    assertSame(1, count($requests));
    $id = (int) $requests[0]['id'];

    \App\Core\Session::destroy();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $page = visit('GET', '/demandes');
    assertContains('Taxi', $page->body);
    visit('POST', "/demandes/$id/decision", ['decision' => 'Approuvée', 'note' => 'Accord']);
    assertSame('Approuvée', Workflows::byId($id)['status']);
});

Tests::run('une étape porte le nom qu’on lui donne, et le circuit le reprend', function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/demandes/types', [
        'label' => 'Achat de matériel', 'amount_field' => 'montant',
        'field_names' => ['montant'], 'field_labels' => ['Montant'],
        'field_types' => ['montant'], 'field_required' => ['1'], 'field_options' => [''],
    ]);
    $form = Db::get('SELECT * FROM request_forms ORDER BY id DESC LIMIT 1');
    assertTrue($form !== null, 'le type de demande n’a pas été créé');

    // Le formulaire d'étape porte bien de quoi la nommer.
    assertContains('name="label"', visit('GET', '/demandes')->body);

    visit('POST', '/demandes/types/' . $form['id'] . '/etapes', [
        'approver' => 'manager', 'label' => 'Visa du responsable direct', 'threshold' => '0',
    ]);
    $step = Db::get('SELECT * FROM request_steps WHERE form_id = ?', [$form['id']]);
    assertSame('Visa du responsable direct', $step['label']);

    // L'écran montre ce nom, et rappelle tout de même qui décide.
    $page = visit('GET', '/demandes')->body;
    assertContains('Visa du responsable direct', $page);
    assertContains('Le manager du demandeur', $page, 'le rôle qui décide reste rappelé');

    // Sans nom, l'étape reste lisible : c'est le rôle qui la nomme.
    visit('POST', '/demandes/types/' . $form['id'] . '/etapes', ['approver' => 'admin', 'threshold' => '0']);
    $steps = Db::all('SELECT * FROM request_steps WHERE form_id = ? ORDER BY position', [$form['id']]);
    assertSame(2, count($steps));
    assertSame('', (string) $steps[1]['label']);
});
