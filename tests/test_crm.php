<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Catalogue;
use App\Modules\Crm;
use App\Modules\Users;

/** Une instance avec le CRM activé, la gestion, et un client. */
function seedCrm(): array
{
    $ids = seed();
    Catalogue::setEnabled('crm', true);
    Users::setRoleFlag($ids['member'], 'is_finance', true);
    $ids['client'] = Db::insert("INSERT INTO partners (kind, name) VALUES ('Client', 'Vertane Industries')");
    return $ids;
}

function loginCrm(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run("un module éteint n'a pas d'écran, et le CRM reste à la gestion", function (): void {
    $ids = seed();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(404, visit('GET', '/crm')->status);

    Catalogue::setEnabled('crm', true);
    assertSame(200, visit('GET', '/crm')->status);

    // Un salarié sans la gestion n'entre pas.
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/crm')->status);
});

Tests::run('le pipeline pondère le montant par la probabilité', function (): void {
    $ids = seedCrm();

    Crm::createOpportunity(['partnerId' => $ids['client'], 'title' => 'Marché A', 'amount' => 10000.0, 'probability' => 25]);
    Crm::createOpportunity(['partnerId' => $ids['client'], 'title' => 'Marché B', 'amount' => 20000.0, 'probability' => 75]);

    $pipeline = Crm::pipeline();
    assertSame(30000.0, $pipeline['total']);
    // 10 000 × 0,25 + 20 000 × 0,75 = 17 500.
    assertSame(17500.0, $pipeline['weighted']);
    assertSame(0.0, $pipeline['winRate'], 'sans affaire décidée, le taux de réussite ne se calcule pas');
});

Tests::run('gagner ou perdre une affaire la ferme et fixe sa probabilité', function (): void {
    $ids = seedCrm();
    Crm::createOpportunity(['partnerId' => $ids['client'], 'title' => 'Marché A', 'amount' => 10000.0, 'probability' => 40]);
    Crm::createOpportunity(['partnerId' => $ids['client'], 'title' => 'Marché B', 'amount' => 20000.0, 'probability' => 60]);
    $rows = Crm::opportunities();

    assertSame(true, Crm::setStage((int) $rows[0]['id'], 'Gagnée')['ok']);
    assertSame(true, Crm::setStage((int) $rows[1]['id'], 'Perdue')['ok']);

    $won = Crm::opportunityById((int) $rows[0]['id']);
    assertSame(100, (int) $won['probability']);
    assertTrue($won['closed_at'] !== null, 'une affaire gagnée doit être datée');
    assertSame(0, (int) Crm::opportunityById((int) $rows[1]['id'])['probability']);

    $pipeline = Crm::pipeline();
    assertSame(0.0, $pipeline['total'], 'une affaire close sort du pipeline ouvert');
    assertSame(50.0, $pipeline['winRate']);
    assertSame('bad-stage', Crm::setStage((int) $rows[0]['id'], 'Peut-être')['reason']);
});

Tests::run("un devis se contrôle avant d'exister", function (): void {
    $ids = seedCrm();
    loginCrm();

    // Client inconnu, date de validité antérieure à l'émission, taux hors bornes.
    visit('POST', '/crm/devis', ['partner_id' => '9999', 'label' => 'Devis', 'issue_date' => '2026-03-01', 'vat_rate' => '20']);
    visit('POST', '/crm/devis', [
        'partner_id' => (string) $ids['client'], 'label' => 'Devis', 'issue_date' => '2026-03-01',
        'valid_until' => '2026-02-01', 'vat_rate' => '20',
    ]);
    visit('POST', '/crm/devis', [
        'partner_id' => (string) $ids['client'], 'label' => 'Devis', 'issue_date' => '2026-03-01', 'vat_rate' => '300',
    ]);
    assertSame(0, count(Crm::quotes()));

    visit('POST', '/crm/devis', [
        'partner_id' => (string) $ids['client'], 'label' => 'Refonte du site', 'reference' => 'D-2026-001',
        'issue_date' => '2026-03-01', 'valid_until' => '2026-04-01', 'amount_ht' => '1 000', 'vat_rate' => '20',
    ]);
    $quote = Crm::quotes()[0];
    assertSame('Brouillon', $quote['status']);
    assertSame(1200.0, $quote['amount_ttc']);
});

Tests::run("un devis expiré se voit, sans changer de statut en base", function (): void {
    $ids = seedCrm();
    Crm::createQuote([
        'partnerId' => $ids['client'], 'label' => 'Devis', 'issueDate' => '2020-01-01',
        'validUntil' => '2020-02-01', 'amountHt' => 100.0, 'vatRate' => 20.0, 'status' => 'Envoyé',
    ]);

    $quote = Crm::quotes()[0];
    assertSame(true, $quote['expired']);
    assertSame('Envoyé', $quote['status'], "l'expiration est lue, pas écrite");
});

Tests::run("seul un devis accepté devient une facture, et une seule fois", function (): void {
    $ids = seedCrm();
    loginCrm();
    Crm::createQuote([
        'partnerId' => $ids['client'], 'label' => 'Refonte du site', 'reference' => 'D-2026-001',
        'issueDate' => '2026-03-01', 'amountHt' => 1000.0, 'vatRate' => 20.0,
    ]);
    $quote = Crm::quotes()[0];

    assertSame('not-accepted', Crm::convertToInvoice((int) $quote['id'], $ids['member'])['reason']);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM invoices'));

    Crm::setQuoteStatus((int) $quote['id'], 'Accepté');
    visit('POST', '/crm/devis/' . $quote['id'] . '/facturer');

    $invoice = Db::get('SELECT * FROM invoices');
    assertSame('Client', $invoice['direction']);
    assertSame('D-2026-001', $invoice['reference']);
    assertSame(1000.0, (float) $invoice['amount_ht']);
    assertSame('Émise', $invoice['status']);
    assertContains('Issue du devis D-2026-001', $invoice['notes']);
    assertSame((int) $invoice['id'], (int) Crm::quoteById((int) $quote['id'])['invoice_id']);

    // Deux fois, non.
    assertSame('already-invoiced', Crm::convertToInvoice((int) $quote['id'], $ids['member'])['reason']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM invoices'));
});

Tests::run('une relance vise un client ou une affaire, jamais rien', function (): void {
    $ids = seedCrm();
    loginCrm();

    visit('POST', '/crm/relances', ['due_on' => '2026-04-01', 'note' => 'Sans cible']);
    assertSame(0, count(Crm::activities()));

    visit('POST', '/crm/relances', [
        'partner_id' => (string) $ids['client'], 'kind' => 'Appel', 'due_on' => '2020-01-01', 'note' => 'Rappeler',
    ]);
    $activity = Crm::activities()[0];
    assertSame(true, $activity['overdue']);
    assertSame($ids['member'], (int) $activity['owner_id'], 'à défaut de porteur, la relance est à celui qui la pose');

    visit('POST', '/crm/relances/' . $activity['id'] . '/faite');
    $done = Crm::activities()[0];
    assertTrue($done['done_at'] !== null);
    assertSame(false, $done['overdue'], 'une relance faite n\'est plus en retard');
    assertSame(false, Crm::completeActivity((int) $activity['id']), 'une relance faite ne se refait pas');

    visit('POST', '/crm/relances/' . $activity['id'] . '/supprimer');
    assertSame(0, count(Crm::activities()));
});

Tests::run("un contact commercial se rattache à un tiers connu", function (): void {
    $ids = seedCrm();
    loginCrm();

    visit('POST', '/crm/contacts', ['partner_id' => '9999', 'first_name' => 'Alice', 'last_name' => 'Martin']);
    visit('POST', '/crm/contacts', ['partner_id' => (string) $ids['client'], 'first_name' => 'Alice']);
    visit('POST', '/crm/contacts', [
        'partner_id' => (string) $ids['client'], 'first_name' => 'Alice', 'last_name' => 'Martin',
        'email' => 'pas-une-adresse',
    ]);
    assertSame(0, count(Crm::contacts()));

    visit('POST', '/crm/contacts', [
        'partner_id' => (string) $ids['client'], 'first_name' => 'Alice', 'last_name' => 'Martin',
        'role' => 'Directrice achats', 'email' => 'alice@vertane.test',
    ]);
    $contacts = Crm::contacts();
    assertSame(1, count($contacts));
    assertSame('Vertane Industries', $contacts[0]['partner_name']);

    $page = visit('GET', '/crm');
    assertSame(200, $page->status);
    assertContains('Alice', $page->body);
});
