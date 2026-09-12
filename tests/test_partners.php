<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Partners;
use App\Modules\Users;

/** Une instance avec la gestion, et un fournisseur à sa fiche. */
function seedPartner(): array
{
    $ids = seed();
    Users::setRoleFlag($ids['member'], 'is_finance', true);
    $ids['partner'] = Db::insert(
        "INSERT INTO partners (kind, name, registration, email) VALUES ('Fournisseur', 'Aciers du Nord', '73282932000074', 'compta@aciers.test')"
    );
    return $ids;
}

function loginFinancePartners(): void
{
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
}

Tests::run('la fiche tiers relève de la gestion', function (): void {
    $ids = seedPartner();
    $other = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);

    visit('POST', '/connexion', ['email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!']);
    assertSame(403, visit('GET', '/partenaires/' . $ids['partner'])->status);
    assertSame(403, visit('POST', '/partenaires/' . $ids['partner'] . '/contacts', ['name' => 'Pirate'])->status);
    assertSame(0, (int) Db::value('SELECT COUNT(*) FROM partner_contacts'));

    loginFinancePartners();
    assertSame(200, visit('GET', '/partenaires/' . $ids['partner'])->status);
    assertSame(404, visit('GET', '/partenaires/9999')->status);
});

Tests::run("un seul interlocuteur principal : désigner le nouveau retire l'ancien", function (): void {
    $ids = seedPartner();
    loginFinancePartners();

    visit('POST', '/partenaires/' . $ids['partner'] . '/contacts', [
        'name' => 'Alice Martin', 'role' => 'Commerciale', 'email' => 'alice@aciers.test', 'is_primary' => '1',
    ]);
    visit('POST', '/partenaires/' . $ids['partner'] . '/contacts', [
        'name' => 'Bruno Sanchez', 'role' => 'Comptabilité', 'is_primary' => '1',
    ]);

    $contacts = Partners::contacts($ids['partner']);
    assertSame(2, count($contacts));
    assertSame('Bruno Sanchez', $contacts[0]['name']);
    assertSame(1, (int) Db::value('SELECT COUNT(*) FROM partner_contacts WHERE is_primary = 1'));

    // Un contact sans nom, ou avec une adresse invalide, n'est pas enregistré.
    visit('POST', '/partenaires/' . $ids['partner'] . '/contacts', ['name' => '']);
    visit('POST', '/partenaires/' . $ids['partner'] . '/contacts', ['name' => 'Carole', 'email' => 'pas-une-adresse']);
    assertSame(2, count(Partners::contacts($ids['partner'])));

    $alice = null;
    foreach (Partners::contacts($ids['partner']) as $contact) {
        if ($contact['name'] === 'Alice Martin') {
            $alice = $contact;
        }
    }
    visit('POST', '/partenaires/contacts/' . $alice['id'] . '/principal', ['partner_id' => (string) $ids['partner']]);
    assertSame(1, (int) Db::value('SELECT is_primary FROM partner_contacts WHERE id = ?', [(int) $alice['id']]));

    visit('POST', '/partenaires/contacts/' . $alice['id'] . '/supprimer');
    assertSame(1, count(Partners::contacts($ids['partner'])));
});

Tests::run("l'état d'une pièce de conformité se déduit de sa date", function (): void {
    seedPartner();
    $today = '2026-09-12';

    assertSame('sans_date', Partners::documentState(['expires_on' => null], $today));
    assertSame('perime', Partners::documentState(['expires_on' => '2026-09-11'], $today));
    // Quarante-cinq jours : le temps d'en redemander une.
    assertSame('bientot', Partners::documentState(['expires_on' => '2026-10-20'], $today));
    assertSame('valable', Partners::documentState(['expires_on' => '2026-12-31'], $today));
});

Tests::run('une pièce périmée avant son émission est refusée', function (): void {
    $ids = seedPartner();
    loginFinancePartners();

    visit('POST', '/partenaires/' . $ids['partner'] . '/pieces', [
        'kind' => 'Attestation de vigilance', 'issued_on' => '2026-06-01', 'expires_on' => '2026-01-01',
    ]);
    assertSame(0, count(Partners::documents($ids['partner'])));

    visit('POST', '/partenaires/' . $ids['partner'] . '/pieces', ['kind' => 'Passeport', 'expires_on' => '2027-01-01']);
    assertSame(0, count(Partners::documents($ids['partner'])));

    visit('POST', '/partenaires/' . $ids['partner'] . '/pieces', [
        'kind' => 'Attestation de vigilance', 'reference' => 'AV-2026', 'issued_on' => '2026-01-01',
        'expires_on' => '2026-07-01',
    ]);
    $documents = Partners::documents($ids['partner']);
    assertSame(1, count($documents));
    assertSame('AV-2026', $documents[0]['reference']);

    // La fiche compte ce qui est périmé et ce qui va l'être.
    $sheet = Partners::sheet($ids['partner']);
    assertSame(1, $sheet['compliance']['total']);
    assertSame('perime', $sheet['documents'][0]['state']);
    assertSame(1, $sheet['compliance']['expired']);

    visit('POST', '/partenaires/pieces/' . $documents[0]['id'] . '/supprimer');
    assertSame(0, count(Partners::documents($ids['partner'])));
});

Tests::run("la note d'une évaluation est la moyenne des critères renseignés", function (): void {
    assertSame(4.0, Partners::reviewScore(['quality' => 5, 'lead_time' => 3, 'price' => 4]));
    assertSame(4.5, Partners::reviewScore(['quality' => 5, 'lead_time' => 4, 'price' => null]));
    assertSame(null, Partners::reviewScore(['quality' => null, 'lead_time' => null, 'price' => null]));
});

Tests::run('une évaluation demande au moins une note, et la dernière fait foi', function (): void {
    $ids = seedPartner();
    loginFinancePartners();

    visit('POST', '/partenaires/' . $ids['partner'] . '/evaluations', ['reviewed_on' => '2026-03-01']);
    assertSame(0, count(Partners::reviews($ids['partner'])));

    visit('POST', '/partenaires/' . $ids['partner'] . '/evaluations', [
        'reviewed_on' => '2026-03-01', 'quality' => '9',
    ]);
    assertSame(0, count(Partners::reviews($ids['partner'])), 'une note hors barème est passée');

    visit('POST', '/partenaires/' . $ids['partner'] . '/evaluations', [
        'reviewed_on' => '2025-03-01', 'quality' => '5', 'lead_time' => '5', 'price' => '5',
        'comment' => 'Excellente année.',
    ]);
    visit('POST', '/partenaires/' . $ids['partner'] . '/evaluations', [
        'reviewed_on' => '2026-03-01', 'quality' => '2', 'lead_time' => '2', 'price' => '2',
        'next_review' => '2027-03-01',
    ]);

    $sheet = Partners::sheet($ids['partner']);
    // Une moyenne de tout l'historique lisserait la dégradation : c'est la
    // dernière évaluation qui est retenue.
    assertSame(2.0, $sheet['lastScore']);
    assertSame('2027-03-01', $sheet['nextReview']);
    assertSame(2, count($sheet['reviews']));

    $latest = $sheet['reviews'][0];
    visit('POST', '/partenaires/evaluations/' . $latest['id'] . '/supprimer');
    assertSame(5.0, Partners::sheet($ids['partner'])['lastScore']);
});

Tests::run('la fiche rassemble contrats et factures du tiers', function (): void {
    $ids = seedPartner();
    Db::run(
        "INSERT INTO partner_contracts (partner_id, title, status, start_date, end_date, amount)
         VALUES (?, 'Fourniture acier', 'Actif', '2026-01-01', '2026-12-31', 50000)",
        [$ids['partner']]
    );
    \App\Modules\Finance::createInvoice([
        'direction' => 'Fournisseur', 'partnerId' => $ids['partner'], 'label' => 'Tôles mars',
        'issueDate' => '2026-03-12', 'amountHt' => 950.0, 'vatRate' => 20.0, 'reference' => 'F2026-0147',
    ]);

    $sheet = Partners::sheet($ids['partner']);
    assertSame(1, count($sheet['contracts']));
    assertSame(1, count($sheet['invoices']));
    assertSame('F2026-0147', $sheet['invoices'][0]['reference']);

    loginFinancePartners();
    $page = visit('GET', '/partenaires/' . $ids['partner']);
    assertContains('Fourniture acier', $page->body);
    assertContains('F2026-0147', $page->body);
    // Et la liste des tiers mène à la fiche.
    assertContains('/partenaires/' . $ids['partner'], visit('GET', '/gestion')->body);
});
