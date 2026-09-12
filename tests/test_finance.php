<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Currency;
use App\Modules\Finance;
use App\Modules\Org;
use App\Modules\Users;

/** Un compte gestion : salarié à qui l'administration a ouvert l'accès. */
function seedFinance(): array
{
    $ids = seed();
    $ids['finance'] = Users::create([
        'role' => 'employee', 'email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!',
        'first_name' => 'Inès', 'last_name' => 'Garnier',
    ]);
    Users::setRoleFlag($ids['finance'], 'is_finance', true);
    return $ids;
}

Tests::run('la gestion est fermée à qui n\'y a pas accès', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    assertSame(403, visit('GET', '/gestion')->status);
    assertSame(403, visit('POST', '/gestion/tiers', ['name' => 'Intrus', 'kind' => 'Client'])->status);
    assertSame(0, count(Finance::partners()));

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    assertSame(200, visit('GET', '/gestion')->status);
});

Tests::run('un tiers se crée, se désactive et s\'efface avec ses contrats', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/gestion/tiers', [
        'name' => 'Papeterie Lemoine', 'kind' => 'Fournisseur', 'email' => 'contact@lemoine.test',
    ]);
    $partners = Finance::partners();
    assertSame(1, count($partners));
    $id = (int) $partners[0]['id'];

    visit('POST', "/gestion/contrats", [
        'partner_id' => $id, 'title' => 'Fournitures de bureau', 'billing_period' => 'Annuel', 'notice_days' => '30',
    ]);
    assertSame(1, count(Finance::contracts()));

    visit('POST', "/gestion/tiers/$id/statut");
    assertSame(0, (int) Finance::partnerById($id)['active']);

    visit('POST', "/gestion/tiers/$id/supprimer");
    assertSame(null, Finance::partnerById($id));
    assertSame(0, count(Finance::contracts()), 'les contrats du tiers survivent à sa suppression');
});

Tests::run('un email de tiers invalide est refusé', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/gestion/tiers', ['name' => 'Test', 'kind' => 'Client', 'email' => 'pas-une-adresse']);
    assertSame(0, count(Finance::partners()));
});

Tests::run('le préavis d\'un contrat calcule sa date limite de dénonciation', function (): void {
    seedFinance();
    $partner = Finance::createPartner(['kind' => 'Fournisseur', 'name' => 'Hébergeur Nord']);
    // Fin dans 100 jours, préavis de 90 : la dénonciation est déjà à dix jours.
    Finance::createContract([
        'partnerId' => $partner, 'title' => 'Hébergement', 'endDate' => gmdate('Y-m-d', strtotime('+100 days')),
        'noticeDays' => 90, 'billingPeriod' => 'Annuel',
    ]);
    $renewals = Finance::contractsToRenew();
    assertSame(1, count($renewals));
    assertSame(gmdate('Y-m-d', strtotime('+10 days')), $renewals[0]['noticeDeadline']);
    assertTrue(!$renewals[0]['noticeElapsed'], 'le préavis ne devrait pas être écoulé');

    // Un contrat qui finit dans deux ans n'alerte pas.
    Finance::createContract([
        'partnerId' => $partner, 'title' => 'Maintenance', 'endDate' => gmdate('Y-m-d', strtotime('+730 days')),
        'noticeDays' => 30, 'billingPeriod' => 'Annuel',
    ]);
    assertSame(1, count(Finance::contractsToRenew()));
});

Tests::run('une facture en devise sans taux connu est refusée', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    assertSame(null, Currency::rateOf('USD'), 'le dollar ne devrait pas avoir de taux au départ');

    visit('POST', '/gestion/factures', [
        'direction' => 'Client', 'label' => 'Prestation', 'issue_date' => gmdate('Y-m-d'),
        'amount_ht' => '1000', 'vat_rate' => '20', 'currency' => 'USD',
    ]);
    assertSame(0, count(Finance::invoices()), 'une facture a été émise sans taux connu');

    visit('POST', '/gestion/devises/taux', ['code' => 'USD', 'rate' => '0,90']);
    visit('POST', '/gestion/factures', [
        'direction' => 'Client', 'label' => 'Prestation', 'issue_date' => gmdate('Y-m-d'),
        'amount_ht' => '1000', 'vat_rate' => '20', 'currency' => 'USD',
    ]);
    $invoices = Finance::invoices();
    assertSame(1, count($invoices));
    assertSame(1200.0, $invoices[0]['amount_ttc']);
    assertSame(1080.0, $invoices[0]['amount_base_ttc'], 'le montant en devise de référence est faux');
});

Tests::run('le taux d\'une facture émise ne bouge plus', function (): void {
    seedFinance();
    Currency::setRate('USD', 0.90);
    $id = Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Prestation', 'issueDate' => gmdate('Y-m-d'),
        'amountHt' => 1000, 'vatRate' => 0, 'currency' => 'USD',
    ]);
    assertSame(900.0, Finance::invoiceById((int) $id)['amount_base_ht']);

    // Le taux du jour change : le chiffre d'affaires de la facture, non.
    Currency::setRate('USD', 0.50);
    assertSame(900.0, Finance::invoiceById((int) $id)['amount_base_ht'], 'le taux figé a été réécrit');
});

Tests::run('le retard d\'une facture se déduit de son échéance', function (): void {
    seedFinance();
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'En retard', 'issueDate' => gmdate('Y-m-d', strtotime('-60 days')),
        'dueDate' => gmdate('Y-m-d', strtotime('-30 days')), 'amountHt' => 100, 'vatRate' => 20,
    ]);
    $payee = Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Payée', 'issueDate' => gmdate('Y-m-d', strtotime('-60 days')),
        'dueDate' => gmdate('Y-m-d', strtotime('-30 days')), 'amountHt' => 100, 'vatRate' => 20,
    ]);
    Finance::setInvoiceStatus((int) $payee, 'Payée');

    $summary = Finance::financialSummary((int) gmdate('Y', strtotime('-60 days')));
    assertSame(1, $summary['overdue'], 'une facture payée compte encore comme en retard');
    assertSame(120.0, $summary['unpaidIncome']);
});

Tests::run('une note de frais suit son circuit et s\'impute au budget du service', function (): void {
    $ids = seedFinance();
    $service = Org::createDepartment('Qualité');
    Db::run('UPDATE users SET department_id = ? WHERE id = ?', [$service, $ids['member']]);
    Finance::setBudget($service, (int) gmdate('Y'), 1000);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/mon-espace/frais', [
        'spent_on' => gmdate('Y-m-d'), 'category' => 'Transport', 'amount' => '42,50', 'description' => 'Billet de train',
    ]);
    $claims = Finance::claimsFor($ids['member']);
    assertSame(1, count($claims));
    $id = (int) $claims[0]['id'];

    // Tant qu'elle est en attente, elle ne consomme rien.
    assertSame(0.0, Finance::budgets((int) gmdate('Y'))[0]['consumed']);

    // Un salarié ne décide pas de sa propre note.
    assertSame(403, visit('POST', "/gestion/frais/$id/statut", ['status' => 'Approuvée'])->status);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    // Rembourser sans approuver : refusé.
    visit('POST', "/gestion/frais/$id/statut", ['status' => 'Remboursée']);
    assertSame('En attente', Finance::claimById($id)['status']);

    visit('POST', "/gestion/frais/$id/statut", ['status' => 'Approuvée']);
    assertSame('Approuvée', Finance::claimById($id)['status']);
    assertSame(42.5, Finance::budgets((int) gmdate('Y'))[0]['consumed']);

    visit('POST', "/gestion/frais/$id/statut", ['status' => 'Remboursée']);
    assertSame('Remboursée', Finance::claimById($id)['status']);
});

Tests::run('une note examinée ne se retire plus', function (): void {
    $ids = seedFinance();
    $id = Finance::createClaim($ids['member'], gmdate('Y-m-d'), 'Repas', 'Déjeuner client', 30);

    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    // Une note d'un collègue ne se retire pas non plus.
    $autre = Users::create([
        'role' => 'employee', 'email' => 'marc.leroy@entreprise.com', 'password' => 'Salarie-Demo-2026!',
        'first_name' => 'Marc', 'last_name' => 'Leroy',
    ]);
    $sienne = Finance::createClaim($autre, gmdate('Y-m-d'), 'Repas', 'Dîner', 40);
    visit('POST', "/mon-espace/frais/$sienne/annuler");
    assertSame(1, count(Finance::claimsFor($autre)), 'la note d\'un collègue a été retirée');

    Finance::reviewClaim($id, 'Approuvée', $ids['admin']);
    visit('POST', "/mon-espace/frais/$id/annuler");
    assertSame(1, count(Finance::claimsFor($ids['member'])), 'une note approuvée a été retirée');
});

Tests::run('une dépense ne se déclare pas à l\'avance', function (): void {
    $ids = seedFinance();
    visit('POST', '/connexion', ['email' => 'claire.moreau@entreprise.com', 'password' => 'Salariee-Demo-2026!']);
    visit('POST', '/mon-espace/frais', [
        'spent_on' => gmdate('Y-m-d', strtotime('+2 days')), 'category' => 'Repas', 'amount' => '20',
    ]);
    assertSame(0, count(Finance::claimsFor($ids['member'])));
});

Tests::run('la devise de référence vaut 1 et refuse un taux', function (): void {
    seedFinance();
    assertSame('EUR', Currency::base());
    assertSame(1.0, (float) Currency::rateOf('EUR'));
    assertTrue(!Currency::setRate('EUR', 1.2)['ok'], 'la devise de référence a accepté un taux');
    assertTrue(!Currency::setRate('USD', -1)['ok'], 'un taux négatif a été accepté');

    // Une devise sans taux n'est pas proposée à la facturation.
    $codes = array_column(Currency::usable(), 'code');
    assertTrue(in_array('EUR', $codes, true));
    assertTrue(!in_array('USD', $codes, true), 'une devise sans taux est proposée');
    Currency::setRate('USD', 0.9);
    assertTrue(in_array('USD', array_column(Currency::usable(), 'code'), true));
});

Tests::run('la page de gestion affiche tiers, factures et budgets', function (): void {
    $ids = seedFinance();
    $partner = Finance::createPartner(['kind' => 'Client', 'name' => 'Ateliers Duvals']);
    Finance::createInvoice([
        'direction' => 'Client', 'partnerId' => $partner, 'label' => 'Étude de faisabilité',
        'issueDate' => gmdate('Y-m-d'), 'amountHt' => 2500, 'vatRate' => 20,
    ]);
    $service = Org::createDepartment('Études');
    Finance::setBudget($service, (int) gmdate('Y'), 50000);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $page = visit('GET', '/gestion');
    assertSame(200, $page->status);
    assertContains('Ateliers Duvals', $page->body);
    assertContains('Étude de faisabilité', $page->body);
    assertContains('Études', $page->body);
});
