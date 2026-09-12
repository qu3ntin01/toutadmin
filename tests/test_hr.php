<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Hr;
use App\Modules\Users;

function rh(): void
{
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
}

function salariee(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run('les jours ouvrés excluent samedi et dimanche', function (): void {
    // Du lundi 5 au vendredi 9 janvier 2026 : cinq jours.
    assertSame(5, Hr::countBusinessDays('2026-01-05', '2026-01-09'));
    // Une semaine entière, week-end compris : toujours cinq.
    assertSame(5, Hr::countBusinessDays('2026-01-05', '2026-01-11'));
    // Un samedi seul : zéro.
    assertSame(0, Hr::countBusinessDays('2026-01-10', '2026-01-10'));
    // Fin avant début : refusé.
    assertSame(null, Hr::countBusinessDays('2026-01-09', '2026-01-05'));
});

Tests::run('un freelance n\'a ni congés ni fiche de paie', function (): void {
    $ids = seed();
    Db::run("UPDATE users SET contract_type = 'Freelance' WHERE id = ?", [$ids['member']]);
    salariee();
    $response = visit('GET', '/mon-espace');
    assertTrue(!str_contains($response->body, 'action="/mon-espace/demandes"'),
        'un formulaire de congés est proposé à un freelance');

    visit('POST', '/mon-espace/demandes', [
        'type' => 'Congés payés', 'start_date' => '2026-01-05', 'end_date' => '2026-01-09',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM hr_requests'), 'une demande a été créée pour un freelance');
});

Tests::run('une demande se dépose, se compte en jours ouvrés et s\'annule', function (): void {
    $ids = seed();
    salariee();
    visit('POST', '/mon-espace/demandes', [
        'type' => 'Congés payés', 'start_date' => '2026-01-05', 'end_date' => '2026-01-11', 'reason' => 'Vacances',
    ]);
    $requests = Hr::requestsFor($ids['member']);
    assertSame(1, count($requests));
    assertSame(5, (int) $requests[0]['days'], 'le week-end a été compté');
    assertSame('En attente', $requests[0]['status']);

    visit('POST', '/mon-espace/demandes/' . (int) $requests[0]['id'] . '/annuler');
    assertSame('Annulée', Hr::requestById((int) $requests[0]['id'])['status']);
});

Tests::run('une demande d\'un tiers ne s\'annule pas', function (): void {
    $ids = seed();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $id = Hr::createRequest($autre, 'Congés payés', '2026-02-02', '2026-02-06', 5, '');
    salariee();
    visit('POST', "/mon-espace/demandes/$id/annuler");
    assertSame('En attente', Hr::requestById($id)['status'], 'une demande d\'autrui a été annulée');
});

Tests::run('une date invalide ne crée pas de demande', function (): void {
    seed();
    salariee();
    visit('POST', '/mon-espace/demandes', ['type' => 'Congés payés', 'start_date' => '2026-13-45', 'end_date' => '2026-01-09']);
    visit('POST', '/mon-espace/demandes', ['type' => 'Vacances aux Bahamas', 'start_date' => '2026-01-05', 'end_date' => '2026-01-09']);
    visit('POST', '/mon-espace/demandes', ['type' => 'Congés payés', 'start_date' => '2026-01-10', 'end_date' => '2026-01-10']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM hr_requests'));
});

Tests::run('l\'espace RH est fermé à qui n\'a pas l\'accès', function (): void {
    seed();
    salariee();
    assertSame(403, visit('GET', '/rh')->status);
});

Tests::run('l\'accès RH s\'ouvre quand l\'administration le donne', function (): void {
    $ids = seed();
    Users::setRoleFlag($ids['member'], 'is_hr', true);
    salariee();
    assertSame(200, visit('GET', '/rh')->status);
});

Tests::run('encadrer une équipe n\'ouvre pas l\'espace RH', function (): void {
    $ids = seed();
    \App\Modules\Org::createDepartment('Production');
    $departmentId = (int) \App\Modules\Org::departments()[0]['id'];
    \App\Modules\Org::addManager('department', $departmentId, $ids['member']);
    salariee();
    assertSame(403, visit('GET', '/rh')->status, 'un manager a atteint l\'espace RH');
});

Tests::run('approuver des congés payés décompte le solde', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-09', 5, '');

    rh();
    visit('POST', "/rh/demandes/$id/approuver", ['note' => 'Bon congé']);
    assertSame('Approuvée', Hr::requestById($id)['status']);
    assertSame(20.0, (float) Users::byId($ids['member'])['leave_balance']);
});

Tests::run('un télétravail approuvé ne touche pas au solde', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    $id = Hr::createRequest($ids['member'], 'Télétravail', '2026-01-05', '2026-01-09', 5, '');
    rh();
    visit('POST', "/rh/demandes/$id/approuver");
    assertSame(25.0, (float) Users::byId($ids['member'])['leave_balance']);
});

Tests::run('annuler une demande approuvée recrédite le solde', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-09', 5, '');
    rh();
    visit('POST', "/rh/demandes/$id/approuver");
    visit('POST', "/rh/demandes/$id/annuler", ['note' => 'Projet reporté']);
    assertSame('Annulée', Hr::requestById($id)['status']);
    assertSame(25.0, (float) Users::byId($ids['member'])['leave_balance'], 'solde non recrédité');
});

Tests::run('une demande refusée ne touche jamais au solde', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-09', 5, '');
    rh();
    visit('POST', "/rh/demandes/$id/refuser", ['note' => 'Période chargée']);
    assertSame('Refusée', Hr::requestById($id)['status']);
    assertSame(25.0, (float) Users::byId($ids['member'])['leave_balance']);
});

Tests::run('une demande déjà décidée ne se décide pas deux fois', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-09', 5, '');
    rh();
    visit('POST', "/rh/demandes/$id/approuver");
    visit('POST', "/rh/demandes/$id/approuver");
    // Le solde n'a été débité qu'une fois.
    assertSame(20.0, (float) Users::byId($ids['member'])['leave_balance']);
});

Tests::run('le solde s\'ajuste dans les deux sens, et jamais de façon absurde', function (): void {
    $ids = seed();
    Db::run('UPDATE users SET leave_balance = 25 WHERE id = ?', [$ids['member']]);
    rh();
    visit('POST', "/rh/solde/{$ids['member']}/ajuster", ['amount' => '2,5', 'reason' => 'Report']);
    assertSame(27.5, (float) Users::byId($ids['member'])['leave_balance']);

    visit('POST', "/rh/solde/{$ids['member']}/ajuster", ['amount' => '-1', 'reason' => 'Correction']);
    assertSame(26.5, (float) Users::byId($ids['member'])['leave_balance']);

    visit('POST', "/rh/solde/{$ids['member']}/ajuster", ['amount' => '9000']);
    visit('POST', "/rh/solde/{$ids['member']}/ajuster", ['amount' => '0']);
    visit('POST', "/rh/solde/{$ids['member']}/ajuster", ['amount' => 'beaucoup']);
    assertSame(26.5, (float) Users::byId($ids['member'])['leave_balance'], 'un ajustement absurde est passé');
    assertSame(2, count(Hr::adjustments($ids['member'])));
});

Tests::run('une fiche de paie refuse un net supérieur au brut', function (): void {
    $ids = seed();
    rh();
    visit('POST', '/rh/paie', [
        'employee_id' => (string) $ids['member'], 'period' => '2026-01',
        'gross_amount' => '3000', 'net_amount' => '3500',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM payslips'));

    visit('POST', '/rh/paie', [
        'employee_id' => (string) $ids['member'], 'period' => 'janvier',
        'gross_amount' => '3000', 'net_amount' => '2400',
    ]);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM payslips'), 'une période mal formée est passée');

    visit('POST', '/rh/paie', [
        'employee_id' => (string) $ids['member'], 'period' => '2026-01',
        'gross_amount' => '3000', 'net_amount' => '2 400',
    ]);
    visit('POST', '/rh/paie', [
        'employee_id' => (string) $ids['member'], 'period' => '2026-01',
        'gross_amount' => '3000', 'net_amount' => '2400,50',
    ]);
    $payslips = Hr::payslipsFor($ids['member']);
    assertSame(1, count($payslips));
    assertSame(2400.5, (float) $payslips[0]['net_amount']);
});

Tests::run('une fiche de paie se marque payée puis se supprime', function (): void {
    $ids = seed();
    $id = Hr::createPayslip($ids['member'], '2026-01', 3000, 2400, '', $ids['admin']);
    rh();
    visit('POST', "/rh/paie/$id/marquer-payee");
    assertSame('Payée', Hr::payslipsFor($ids['member'])[0]['status']);
    visit('POST', "/rh/paie/$id/supprimer");
    assertSame([], Hr::payslipsFor($ids['member']));
});

Tests::run('le salarié voit ses demandes et ses fiches, pas celles des autres', function (): void {
    $ids = seed();
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    Hr::createRequest($ids['member'], 'RTT', '2026-03-02', '2026-03-02', 1, 'Déménagement');
    Hr::createRequest($autre, 'Congés payés', '2026-03-09', '2026-03-13', 5, 'Ski');
    Hr::createPayslip($ids['member'], '2026-02', 3000, 2400, '', $ids['admin']);

    salariee();
    $body = visit('GET', '/mon-espace')->body;
    assertContains('Déménagement', $body);
    assertTrue(!str_contains($body, 'Ski'), 'la demande d\'un collègue est visible');
    assertContains('2026-02', $body);
});

Tests::run('la décision laisse une trace et un commentaire', function (): void {
    $ids = seed();
    $id = Hr::createRequest($ids['member'], 'Congés payés', '2026-01-05', '2026-01-09', 5, '');
    rh();
    visit('POST', "/rh/demandes/$id/refuser", ['note' => 'Période chargée']);
    assertSame('Période chargée', Hr::requestById($id)['review_note']);
    $actions = array_column(Db::all('SELECT action FROM audit_log'), 'action');
    assertTrue(in_array('demande.refusee', $actions, true));
    assertSame(null, \App\Core\Audit::verify());
});
