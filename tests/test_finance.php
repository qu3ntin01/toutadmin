<?php

declare(strict_types=1);

use App\Core\Db;
use App\Modules\Assets;
use App\Modules\Billing;
use App\Modules\Currency;
use App\Modules\Dunning;
use App\Modules\Finance;
use App\Modules\Org;
use App\Modules\Users;
use App\Modules\Vat;

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

// ---------- Abonnements ----------

Tests::run('un abonnement émet sa facture à l\'échéance, et pas avant', function (): void {
    seedFinance();
    $partner = Finance::createPartner(['kind' => 'Client', 'name' => 'Cabinet Vasseur']);
    Billing::create([
        'direction' => 'Client', 'partnerId' => $partner, 'label' => 'Maintenance annuelle',
        'amountHt' => 1200, 'vatRate' => 20, 'period' => 'Mensuelle',
        'startDate' => gmdate('Y-m-d', strtotime('+3 days')), 'paymentDays' => 30,
    ]);
    assertSame(0, count(Billing::due()), 'une échéance future est déjà due');
    assertSame(0, count(Billing::run()['issued']));

    // À l'échéance, la facture part et l'abonnement avance d'un mois.
    $result = Billing::run(gmdate('Y-m-d', strtotime('+3 days')));
    assertSame(1, count($result['issued']));
    $invoices = Finance::invoices();
    assertSame(1, count($invoices));
    assertSame(1440.0, $invoices[0]['amount_ttc']);
    assertSame(
        Billing::addMonths(gmdate('Y-m-d', strtotime('+3 days')), 1),
        Billing::byId((int) Billing::list()[0]['id'])['next_issue']
    );
});

Tests::run('la même échéance ne se facture pas deux fois', function (): void {
    seedFinance();
    $id = Billing::create([
        'direction' => 'Client', 'label' => 'Abonnement', 'amountHt' => 100, 'vatRate' => 20,
        'period' => 'Mensuelle', 'startDate' => gmdate('Y-m-d'), 'paymentDays' => 30,
    ]);
    assertSame(1, count(Billing::run()['issued']));
    // On remet la date d'échéance en arrière : l'index unique doit tenir.
    Db::run('UPDATE subscriptions SET next_issue = ?, active = 1 WHERE id = ?', [gmdate('Y-m-d'), $id]);
    $again = Billing::run();
    assertSame(0, count($again['issued']));
    assertSame('déjà facturée', $again['skipped'][0]['reason']);
});

Tests::run('un abonnement suspendu ou terminé n\'émet plus rien', function (): void {
    seedFinance();
    $suspendu = Billing::create([
        'direction' => 'Client', 'label' => 'Suspendu', 'amountHt' => 100, 'vatRate' => 20,
        'period' => 'Mensuelle', 'startDate' => gmdate('Y-m-d'), 'paymentDays' => 30,
    ]);
    Billing::setActive($suspendu, false);

    // Terme atteint : la dernière échéance part, puis l'abonnement s'éteint.
    $fini = Billing::create([
        'direction' => 'Client', 'label' => 'Dernier mois', 'amountHt' => 100, 'vatRate' => 20,
        'period' => 'Mensuelle', 'startDate' => gmdate('Y-m-d'), 'endDate' => gmdate('Y-m-d'), 'paymentDays' => 30,
    ]);
    assertSame(1, count(Billing::run()['issued']));
    assertSame(0, (int) Billing::byId($fini)['active'], 'un abonnement au terme dépassé reste actif');
    assertSame(0, count(Billing::run()['issued']));
});

Tests::run('supprimer un abonnement laisse ses factures dues', function (): void {
    seedFinance();
    $id = Billing::create([
        'direction' => 'Client', 'label' => 'Abonnement', 'amountHt' => 100, 'vatRate' => 20,
        'period' => 'Mensuelle', 'startDate' => gmdate('Y-m-d'), 'paymentDays' => 30,
    ]);
    Billing::run();
    Billing::remove($id);
    assertSame(1, count(Finance::invoices()), 'les factures ont disparu avec le moule');
    assertSame(null, Billing::byId($id));
});

Tests::run('un abonnement en devise sans taux est refusé', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    visit('POST', '/gestion/abonnements', [
        'label' => 'Licence', 'direction' => 'Fournisseur', 'period' => 'Mensuelle',
        'start_date' => gmdate('Y-m-d'), 'amount_ht' => '80', 'vat_rate' => '20',
        'payment_days' => '30', 'currency' => 'USD',
    ]);
    assertSame(0, count(Billing::list()));
});

// ---------- TVA ----------

Tests::run('la TVA collectée et déductible se calcule sur la période', function (): void {
    seedFinance();
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Vente', 'issueDate' => '2026-03-10',
        'amountHt' => 1000, 'vatRate' => 20,
    ]);
    Finance::createInvoice([
        'direction' => 'Fournisseur', 'label' => 'Achat', 'issueDate' => '2026-03-20',
        'amountHt' => 500, 'vatRate' => 20,
    ]);
    // Hors période : ne compte pas.
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Avril', 'issueDate' => '2026-04-02',
        'amountHt' => 999, 'vatRate' => 20,
    ]);
    // Annulée : n'a pas généré de TVA.
    $annulee = Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Annulée', 'issueDate' => '2026-03-15',
        'amountHt' => 2000, 'vatRate' => 20,
    ]);
    Finance::setInvoiceStatus((int) $annulee, 'Annulée');

    $totals = Vat::compute('2026-03-01', '2026-03-31');
    assertSame(200.0, $totals['collected']);
    assertSame(100.0, $totals['deductible']);
    assertSame(100.0, $totals['due']);
    assertSame(0.0, $totals['credit']);
    assertSame(2, $totals['invoices']);
});

Tests::run('une TVA négative est un crédit reportable, pas une dette', function (): void {
    seedFinance();
    Finance::createInvoice([
        'direction' => 'Fournisseur', 'label' => 'Gros achat', 'issueDate' => '2026-03-05',
        'amountHt' => 1000, 'vatRate' => 20,
    ]);
    $totals = Vat::compute('2026-03-01', '2026-03-31');
    assertSame(0.0, $totals['due']);
    assertSame(200.0, $totals['credit']);
});

Tests::run('une période ne se saisit pas à la main, elle se choisit', function (): void {
    seedFinance();
    assertSame(null, Vat::periodByKey('Mensuel:2026-03-03:2026-03-28'), 'une période bricolée a été acceptée');
    $period = Vat::periods(2026, 'Mensuel')[2];
    assertSame('2026-03-01', $period['start']);
    assertSame('2026-03-31', $period['end']);
    assertTrue(Vat::periodByKey($period['key']) !== null);
    assertSame(4, count(Vat::periods(2026, 'Trimestriel')));
});

Tests::run('une déclaration déposée ne se recalcule plus', function (): void {
    seedFinance();
    visit('POST', '/connexion', ['email' => 'admin@demo.test', 'password' => 'Administration-2026!']);
    $period = Vat::periods((int) gmdate('Y'), 'Mensuel')[0];

    visit('POST', '/gestion/tva', ['periode' => $period['key']]);
    assertSame(1, count(Vat::list()));
    $id = (int) Vat::list()[0]['id'];

    visit('POST', "/gestion/tva/$id/statut", ['status' => 'Déclarée']);
    assertSame('Déclarée', Vat::byId($id)['status']);
    assertSame(gmdate('Y-m-d'), Vat::byId($id)['filed_on']);

    // Une facture arrive après coup : la déclaration déposée ne bouge pas.
    Finance::createInvoice([
        'direction' => 'Client', 'label' => 'Tardive', 'issueDate' => $period['start'],
        'amountHt' => 1000, 'vatRate' => 20,
    ]);
    $verdict = Vat::save([
        'regime' => $period['regime'], 'label' => $period['label'],
        'from' => $period['start'], 'to' => $period['end'],
    ]);
    assertTrue(!$verdict['ok'], 'une déclaration déposée a été réécrite');
    assertSame(0.0, (float) Vat::byId($id)['collected']);
});

// ---------- Recouvrement ----------

/** Une facture client échue depuis N jours. */
function lateInvoice(int $days, float $amount = 1000): int
{
    return (int) Finance::createInvoice([
        'direction' => 'Client', 'label' => "Facture $days jours",
        'issueDate' => gmdate('Y-m-d', strtotime('-' . ($days + 30) . ' days')),
        'dueDate' => gmdate('Y-m-d', strtotime("-$days days")),
        'amountHt' => $amount, 'vatRate' => 0,
    ]);
}

Tests::run('le palier de relance se déduit du retard et de ce qui a été envoyé', function (): void {
    $ids = seedFinance();
    lateInvoice(3);
    assertSame(0, count(Dunning::due()), 'une facture en retard de trois jours appelle déjà un rappel');

    $id = lateInvoice(50);
    $due = Dunning::due();
    assertSame(1, count($due));
    assertSame(1, $due[0]['level']['level'], 'le premier palier n\'est pas le rappel');

    // Un rappel envoyé aujourd'hui : le palier suivant attend dix jours.
    Dunning::record(['invoiceId' => $id, 'level' => 1, 'sentOn' => gmdate('Y-m-d'), 'createdBy' => $ids['admin']]);
    assertSame(0, count(Dunning::due()));

    Db::run("UPDATE dunning_notices SET sent_on = date('now', '-11 days') WHERE invoice_id = ?", [$id]);
    $after = Dunning::due();
    assertSame(1, count($after));
    assertSame(2, $after[0]['level']['level']);
});

Tests::run('une mise en demeure ne saute pas les rappels', function (): void {
    $ids = seedFinance();
    $id = lateInvoice(90);
    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    visit('POST', '/gestion/relances', ['invoice_id' => $id, 'level' => '3', 'sent_on' => gmdate('Y-m-d')]);
    assertSame(0, count(Dunning::noticesFor($id)), 'une mise en demeure est partie sans rappel');

    visit('POST', '/gestion/relances', ['invoice_id' => $id, 'level' => '1', 'sent_on' => gmdate('Y-m-d')]);
    assertSame(1, count(Dunning::noticesFor($id)));
});

Tests::run('une facture réglée ne se relance plus', function (): void {
    $ids = seedFinance();
    $id = lateInvoice(60);
    Finance::setInvoiceStatus($id, 'Payée');
    $result = Dunning::record(['invoiceId' => $id, 'level' => 1, 'sentOn' => gmdate('Y-m-d')]);
    assertTrue(!$result['ok']);
    assertSame('reglee', $result['reason']);
    assertSame(0, count(Dunning::outstanding()), 'une facture payée reste dans l\'encours');
});

Tests::run('la balance âgée répartit l\'encours par tranche de retard', function (): void {
    seedFinance();
    lateInvoice(-5, 100);   // Pas encore échue.
    lateInvoice(20, 200);
    lateInvoice(45, 300);
    lateInvoice(120, 400);

    $balance = Dunning::agedBalance();
    assertSame(100.0, $balance['buckets']['courant']['amount']);
    assertSame(200.0, $balance['buckets']['j30']['amount']);
    assertSame(300.0, $balance['buckets']['j60']['amount']);
    assertSame(400.0, $balance['buckets']['plus']['amount']);
    assertSame(1000.0, $balance['total']);
    assertSame(900.0, $balance['overdue'], 'une facture non échue compte comme en retard');
});

// ---------- Parc matériel ----------

Tests::run('« Affecté » découle d\'une affectation, il ne se déclare pas', function (): void {
    $ids = seedFinance();
    $asset = Assets::create(['name' => 'Portable Latitude', 'category' => 'Informatique']);

    $refus = Assets::setStatus($asset, 'Affecté');
    assertTrue(!$refus['ok']);
    assertSame('assign-instead', $refus['reason']);

    assertTrue(Assets::assign($asset, $ids['member'])['ok']);
    assertSame('Affecté', Assets::byId($asset)['status']);
    assertSame('already-assigned', Assets::assign($asset, $ids['member'])['reason']);
    assertSame('return-first', Assets::setStatus($asset, 'En maintenance')['reason']);

    assertTrue(Assets::takeBack($asset)['ok']);
    assertSame('Disponible', Assets::byId($asset)['status']);
    assertSame(0, count(Assets::of($ids['member'])));
    // La reprise ne fait pas disparaître l'histoire du matériel.
    assertSame(1, count(Assets::history($asset)));
});

Tests::run('un équipement réformé ne s\'affecte pas', function (): void {
    $ids = seedFinance();
    $asset = Assets::create(['name' => 'Vieux poste']);
    Assets::setStatus($asset, 'Réformé');
    assertSame('retired', Assets::assign($asset, $ids['member'])['reason']);
});

Tests::run('les écrans de gestion portent les nouveaux onglets', function (): void {
    seedFinance();
    Billing::create([
        'direction' => 'Client', 'label' => 'Hébergement mutualisé', 'amountHt' => 90, 'vatRate' => 20,
        'period' => 'Mensuelle', 'startDate' => gmdate('Y-m-d'), 'paymentDays' => 30,
    ]);
    Assets::create(['name' => 'Écran Dell 27', 'category' => 'Informatique']);
    lateInvoice(40, 800);

    visit('POST', '/connexion', ['email' => 'gestion@entreprise.com', 'password' => 'Gestion-Demo-2026!']);
    $page = visit('GET', '/gestion');
    assertSame(200, $page->status);
    assertContains('Hébergement mutualisé', $page->body);
    assertContains('Écran Dell 27', $page->body);
    assertContains('Facture 40 jours', $page->body);
});
