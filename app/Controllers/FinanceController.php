<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Assets;
use App\Modules\Billing;
use App\Modules\Currency;
use App\Modules\Dunning;
use App\Modules\Corbeille;
use App\Modules\CustomFields;
use App\Modules\Finance;
use App\Modules\Org;
use App\Modules\Purchasing;
use App\Modules\Rooms;
use App\Modules\Sepa;
use App\Modules\Users;
use App\Modules\Vat;

/**
 * Gestion administrative et financière.
 *
 * Ouverte à l'administration et à qui elle a donné l'accès gestion. Tiers,
 * contrats, factures, budgets, notes de frais et devises : ce qui engage
 * l'argent de l'entreprise.
 */
final class FinanceController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_finance'] ?? 0) === 1);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/gestion#' . $anchor);
    }

    /** Un montant de formulaire : virgule décimale acceptée, bornes explicites. */
    private static function amount(string $raw, bool $required = true, float $max = 1000000000): float|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $required ? false : null;
        }
        $value = str_replace([' ', ','], ['', '.'], $trimmed);
        if (!is_numeric($value)) {
            return false;
        }
        $number = round((float) $value, 2);
        return $number < 0 || $number > $max ? false : $number;
    }

    public static function index(Request $request): Response
    {
        $year = (int) ($request->input('annee') ?: gmdate('Y'));
        $claims = Finance::allClaims();
        $renewals = Finance::contractsToRenew();
        $partners = Finance::partners();
        $dunningSummary = Dunning::summary();
        $vatSummary = Vat::summary();
        $invoiceList = Finance::invoices();
        $assetList = Assets::all();

        return Response::html(View::page('finance/index', [
            'title' => t('nav.gestion') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.gestion'),
            'headerTitle' => t('nav.gestion'),
            'headerSubtitle' => t('erp.partnersSub') . ' · ' . mb_strtolower(t('erp.contracts'))
                . ' · ' . mb_strtolower(t('erp.invoices')) . ' · ' . mb_strtolower(t('erp.budgets')),
            'navItems' => [
                ['tab' => 'tiers', 'label' => t('erp.partners')],
                ['tab' => 'contrats', 'label' => t('erp.contracts'), 'badge' => count($renewals) ?: null],
                ['tab' => 'factures', 'label' => t('erp.invoices')],
                ['tab' => 'recouvrement', 'label' => t('rec.outstanding'), 'badge' => $dunningSummary['toSend'] ?: null],
                ['tab' => 'abonnements', 'label' => t('nav.subscriptions')],
                ['tab' => 'tva', 'label' => t('nav.vat'), 'badge' => $vatSummary['pending'] ?: null],
                ['tab' => 'sepa', 'label' => t('sepa.tab')],
                ['tab' => 'budgets', 'label' => t('erp.budgets')],
                ['tab' => 'frais', 'label' => t('erp.claims'),
                 'badge' => count(array_filter($claims, static fn (array $c): bool => $c['status'] === 'En attente')) ?: null],
                ['tab' => 'devises', 'label' => t('nav.currencies')],
                ['tab' => 'equipements', 'label' => t('erp.assets')],
                ['tab' => 'salles', 'label' => t('nav.rooms')],
            ],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'year' => $year,
            'years' => [$year + 1, $year, $year - 1, $year - 2],
            'today' => gmdate('Y-m-d'),
            // Les paiements SEPA : ce qui se règle par virement, ce qui se prélève,
            // et ce qui manque à la société pour émettre l'un ou l'autre.
            'sepaSociete' => Sepa::societe(),
            'sepaVirer' => Sepa::aVirer(),
            'sepaPrelever' => Sepa::aPrelever(),
            'sepaManquesVir' => Sepa::manquesSociete(),
            'sepaManquesPrl' => Sepa::manquesSociete(true),
            // Le parc de salles se tient ici : réserver est ouvert à tous, mais
            // ouvrir, fermer ou supprimer une salle relève de la gestion.
            'rooms' => Rooms::all(),
            'roomBookings' => Rooms::bookings(gmdate('Y-m-d')),
            'summary' => Finance::financialSummary($year),
            'partners' => $partners,
            'partnerKinds' => Finance::PARTNER_KINDS,
            'contracts' => Finance::contracts(),
            'contractStatuses' => Finance::CONTRACT_STATUSES,
            'billingPeriods' => Finance::BILLING_PERIODS,
            'renewals' => $renewals,
            'invoices' => $invoiceList,
            'stripePret' => \App\Modules\Stripe::pret(),
            'stripeLiens' => (static function () use ($invoiceList): array {
                $liens = [];
                foreach ($invoiceList as $i) {
                    if ($i['direction'] === 'Client' && $i['status'] === 'Émise') {
                        $lien = \App\Modules\Stripe::lienDe((int) $i['id']);
                        if ($lien !== null && $lien['status'] === 'open') {
                            $liens[(int) $i['id']] = $lien;
                        }
                    }
                }
                return $liens;
            })(),
            'invoiceDirections' => Finance::INVOICE_DIRECTIONS,
            'invoiceStatuses' => Finance::INVOICE_STATUSES,
            'budgets' => Finance::budgets($year),
            'claims' => $claims,
            'expenseStatuses' => Finance::EXPENSE_STATUSES,
            'currencies' => Currency::rates(),
            'usableCurrencies' => Currency::usable(),
            'baseCurrency' => Currency::base(),
            'subscriptions' => Billing::list(),
            'subscriptionPeriods' => Billing::periodKeys(),
            'subscriptionDirections' => Billing::DIRECTIONS,
            'subscriptionDue' => Billing::due(),
            'subscriptionValue' => Billing::annualValue(),
            'subscriptionMetrics' => Billing::metriques(),
            'vatReturns' => Vat::list(),
            'vatPeriods' => array_merge(Vat::periods($year, 'Mensuel'), Vat::periods($year, 'Trimestriel')),
            'vatStatuses' => Vat::STATUSES,
            'vatSummary' => $vatSummary,
            'dunningSummary' => $dunningSummary,
            'dunningLevels' => Dunning::LEVELS,
            'dunningDue' => Dunning::due(),
            'dunningOutstanding' => Dunning::outstanding(),
            'agedBalance' => Dunning::agedBalance(),
            'assets' => $assetList,
            'assetStatuses' => Assets::STATUSES,
            'assetCategories' => Assets::CATEGORIES,
            // Sans le module Stock, il n'y a pas de bon de commande à proposer.
            'openOrders' => \App\Modules\Catalogue::isEnabled('stock') ? Purchasing::orders(false) : [],
            'departments' => Org::departments(),
            'employees' => Users::employees(),
            /*
             * Les champs personnalisés des factures et des équipements.
             *
             * « valeurs(objet, 0) » donne les définitions avec des valeurs
             * vides : c'est ce que porte le formulaire de création.
             * « valeursEnLot » lit en une requête celles de toutes les lignes
             * affichées, au lieu d'une requête par ligne. Les deux ne coûtent
             * rien tant que l'entreprise n'a rien ajouté.
             */
            'champsFactureVides' => CustomFields::valeurs('facture', 0),
            'champsParFacture' => CustomFields::valeursEnLot(
                'facture',
                array_map(static fn (array $f): int => (int) $f['id'], $invoiceList),
            ),
            'champsEquipementVides' => CustomFields::valeurs('equipement', 0),
            'champsParEquipement' => CustomFields::valeursEnLot(
                'equipement',
                array_map(static fn (array $e): int => (int) $e['id'], $assetList),
            ),
            'stats' => [
                'partnerCount' => count($partners),
                'renewalCount' => count($renewals),
                'pendingClaims' => count(array_filter($claims, static fn (array $c): bool => $c['status'] === 'En attente')),
                'overdue' => Finance::financialSummary($year)['overdue'],
            ],
        ]));
    }

    // ---------- Tiers ----------

    public static function createPartner(Request $request): Response
    {
        $name = mb_substr($request->input('name'), 0, 160);
        $kind = $request->input('kind');
        $email = mb_substr($request->input('email'), 0, 254);

        if ($name === '') {
            return self::back('tiers', 'error', 'Le nom du tiers est obligatoire.');
        }
        if (!in_array($kind, Finance::PARTNER_KINDS, true)) {
            return self::back('tiers', 'error', 'Type de tiers invalide.');
        }
        if ($email !== '' && !Validate::email($email)) {
            return self::back('tiers', 'error', 'Adresse email invalide.');
        }

        Finance::createPartner([
            'kind' => $kind,
            'name' => $name,
            'registration' => mb_substr($request->input('registration'), 0, 60),
            'contactName' => mb_substr($request->input('contact_name'), 0, 120),
            'email' => $email,
            'phone' => mb_substr($request->input('phone'), 0, 40),
            'address' => mb_substr($request->input('address'), 0, 300),
            'notes' => mb_substr($request->input('notes'), 0, 1000),
        ]);
        return self::back('tiers', 'success', "Tiers « $name » créé.");
    }

    public static function togglePartner(Request $request, array $params): Response
    {
        $partner = Finance::partnerById((int) $params['id']);
        if ($partner === null) {
            return self::back('tiers', 'error', 'Tiers introuvable.');
        }
        Finance::updatePartner((int) $partner['id'], [
            'kind' => $partner['kind'],
            'name' => $partner['name'],
            'registration' => $partner['registration'],
            'contactName' => $partner['contact_name'],
            'email' => $partner['email'],
            'phone' => $partner['phone'],
            'address' => $partner['address'],
            'notes' => $partner['notes'],
            'active' => (int) $partner['active'] !== 1,
        ]);
        return self::back('tiers', 'success', (int) $partner['active'] === 1 ? 'Tiers désactivé.' : 'Tiers réactivé.');
    }

    public static function deletePartner(Request $request, array $params): Response
    {
        $partner = Finance::partnerById((int) $params['id']);
        if ($partner === null) {
            return self::back('tiers', 'error', 'Tiers introuvable.');
        }
        // Supprimer un tiers emporte ses contrats : mieux vaut le désactiver
        // s'il a une histoire.
        Corbeille::jeterPuis('partenaire', (int) $partner['id'], (int) Session::get('user')['id'],
            static fn (): mixed => Finance::deletePartner((int) $partner['id']));
        CustomFields::oublier('partenaire', (int) $partner['id']);
        return self::back('tiers', 'success', 'Tiers supprimé, avec ses contrats.');
    }

    // ---------- Contrats ----------

    public static function createContract(Request $request): Response
    {
        $partnerId = (int) $request->input('partner_id');
        $title = mb_substr($request->input('title'), 0, 160);
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $noticeDays = (int) $request->input('notice_days');
        $amount = self::amount($request->input('amount'), false);
        $period = $request->input('billing_period');

        if (Finance::partnerById($partnerId) === null) {
            return self::back('contrats', 'error', 'Tiers introuvable.');
        }
        if ($title === '') {
            return self::back('contrats', 'error', "L'intitulé du contrat est obligatoire.");
        }
        if ($start !== '' && !Validate::date($start)) {
            return self::back('contrats', 'error', 'Date de début invalide.');
        }
        if ($end !== '' && !Validate::date($end)) {
            return self::back('contrats', 'error', 'Date de fin invalide.');
        }
        if ($start !== '' && $end !== '' && $end < $start) {
            return self::back('contrats', 'error', 'La fin précède le début.');
        }
        if ($noticeDays < 0 || $noticeDays > 365) {
            return self::back('contrats', 'error', 'Préavis invalide (0 à 365 jours).');
        }
        if ($amount === false) {
            return self::back('contrats', 'error', 'Montant invalide.');
        }
        if (!in_array($period, Finance::BILLING_PERIODS, true)) {
            return self::back('contrats', 'error', 'Périodicité invalide.');
        }

        Finance::createContract([
            'partnerId' => $partnerId,
            'title' => $title,
            'startDate' => $start ?: null,
            'endDate' => $end ?: null,
            'noticeDays' => $noticeDays,
            'amount' => $amount,
            'billingPeriod' => $period,
            'ownerId' => (int) $request->input('owner_id') ?: null,
            'reference' => mb_substr($request->input('reference'), 0, 60),
            'notes' => mb_substr($request->input('notes'), 0, 1000),
        ]);
        return self::back('contrats', 'success', 'Contrat enregistré.');
    }

    public static function setContractStatus(Request $request, array $params): Response
    {
        if (!Finance::setContractStatus((int) $params['id'], $request->input('status'))) {
            return self::back('contrats', 'error', 'Statut invalide ou contrat introuvable.');
        }
        return self::back('contrats', 'success', 'Statut du contrat mis à jour.');
    }

    public static function deleteContract(Request $request, array $params): Response
    {
        if (!Finance::deleteContract((int) $params['id'])) {
            return self::back('contrats', 'error', 'Contrat introuvable.');
        }

        return self::back('contrats', 'success', 'Contrat supprimé.');
    }

    // ---------- Factures ----------

    public static function createInvoice(Request $request): Response
    {
        $direction = $request->input('direction');
        $label = mb_substr($request->input('label'), 0, 160);
        $issue = $request->input('issue_date');
        $due = $request->input('due_date');
        $amountHt = self::amount($request->input('amount_ht'));
        $vatRaw = str_replace(',', '.', $request->input('vat_rate'));
        $partnerId = (int) $request->input('partner_id') ?: null;
        $departmentId = (int) $request->input('department_id') ?: null;
        $status = $request->input('status', 'Émise') ?: 'Émise';
        $code = strtoupper($request->input('currency') ?: Currency::base());
        $purchaseOrderId = (int) $request->input('purchase_order_id') ?: null;

        if (!in_array($direction, Finance::INVOICE_DIRECTIONS, true)) {
            return self::back('factures', 'error', 'Sens de facture invalide.');
        }
        if ($label === '') {
            return self::back('factures', 'error', "L'intitulé de la facture est obligatoire.");
        }
        if (!Validate::date($issue)) {
            return self::back('factures', 'error', "Date d'émission invalide.");
        }
        if ($due !== '' && !Validate::date($due)) {
            return self::back('factures', 'error', "Date d'échéance invalide.");
        }
        if ($due !== '' && $due < $issue) {
            return self::back('factures', 'error', "L'échéance précède l'émission.");
        }

        /*
         * Une échéance laissée vide n'est pas une facture sans terme : c'est
         * une facture au délai habituel de la maison, réglé dans la section
         * « Gestion ». Sans elle, la facture n'entrait ni dans les impayés ni
         * dans les relances — elle disparaissait du recouvrement sans que
         * personne l'ait décidé.
         *
         * Posé après le contrôle de cohérence, et non avant : ce qu'on calcule
         * soi-même depuis une date déjà validée n'a pas à être revalidé, et le
         * faire ferait dépendre l'ordre des contrôles du résultat.
         */
        if ($due === '') {
            $jours = \App\Core\SectionsReglages::nombre('gestion', 'delai_paiement');
            if ($jours > 0) {
                $due = gmdate('Y-m-d', (int) strtotime($issue . ' +' . $jours . ' days'));
            }
        }
        if ($amountHt === false || $amountHt === null) {
            return self::back('factures', 'error', 'Montant HT invalide.');
        }
        if (!is_numeric($vatRaw) || (float) $vatRaw < 0 || (float) $vatRaw > 100) {
            return self::back('factures', 'error', 'Taux de TVA invalide.');
        }
        if (!in_array($status, Finance::INVOICE_STATUSES, true)) {
            return self::back('factures', 'error', 'Statut invalide.');
        }
        if ($partnerId !== null && Finance::partnerById($partnerId) === null) {
            return self::back('factures', 'error', 'Tiers introuvable.');
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return self::back('factures', 'error', 'Service introuvable.');
        }
        if (!Currency::isKnown($code)) {
            return self::back('factures', 'error', 'Devise inconnue.');
        }
        // Facturer dans une devise dont le taux n'est pas connu produirait un
        // total faux : mieux vaut refuser et demander le taux.
        if (Currency::rateOf($code) === null) {
            return self::back('factures', 'error',
                "Aucun taux connu pour $code : renseignez-le dans l'onglet Devises avant de facturer.");
        }

        // Le rattachement au bon de commande est ce qui donne au rapprochement à
        // trois de quoi comparer. Il ne vaut que pour un achat : le bon de
        // commande est celui que nous avons passé, pas celui d'un client.
        $order = null;
        if ($purchaseOrderId !== null) {
            $order = Purchasing::orderById($purchaseOrderId);
            if ($order === null) {
                return self::back('factures', 'error', 'Bon de commande introuvable.');
            }
            if ($direction !== 'Fournisseur') {
                return self::back('factures', 'error',
                    "Un bon de commande ne se rattache qu'à une facture fournisseur.");
            }
            if ($partnerId !== null && $partnerId !== (int) $order['partner_id']) {
                return self::back('factures', 'error', 'Ce bon de commande est passé chez un autre fournisseur.');
            }
        }

        /*
         * Les champs personnalisés se contrôlent avant la création, et
         * s'écrivent après. Dans cet ordre, un champ refusé n'enregistre pas de
         * facture — alors que créer d'abord laisserait une facture nue, qu'il
         * faudrait supprimer à la main, et dont le numéro serait déjà pris.
         */
        $refusChamps = CustomFields::verifier('facture', $request->body);
        if ($refusChamps !== null) {
            return self::back('factures', 'error', $refusChamps);
        }

        $invoiceId = Finance::createInvoice([
            'direction' => $direction,
            'partnerId' => $partnerId,
            'departmentId' => $departmentId,
            'label' => $label,
            'issueDate' => $issue,
            'dueDate' => $due ?: null,
            'amountHt' => $amountHt,
            'vatRate' => round((float) $vatRaw, 2),
            'status' => $status,
            'currency' => $code,
            'reference' => mb_substr($request->input('reference'), 0, 60),
            'notes' => mb_substr($request->input('notes'), 0, 1000),
            'createdBy' => (int) Session::get('user')['id'],
            'purchaseOrderId' => $order === null ? null : (int) $order['id'],
        ]);
        if ($invoiceId !== null) {
            CustomFields::enregistrer('facture', $invoiceId, $request->body);
        }
        return self::back('factures', 'success', 'Facture enregistrée.');
    }

    /**
     * Une page de paiement Stripe pour une facture client émise. Le lien se
     * donne au client ; la facture passe d'elle-même à « Payée » quand Stripe
     * l'annonce (voir app/Modules/Stripe.php).
     */
    public static function payOnline(Request $request, array $params): Response
    {
        $facture = Finance::invoiceById((int) $params['id']);
        try {
            $origine = \App\Modules\Webauthn::site($request)['origine'];
            $lien = \App\Modules\Stripe::creerLien($facture, $origine);
        } catch (\RuntimeException) {
            return self::back('factures', 'error', 'Stripe ne répond pas. Réessayez dans un instant.');
        }
        if (!$lien['ok']) {
            return self::back('factures', 'error', match ($lien['reason']) {
                'disabled' => "Le paiement en ligne n'est pas configuré : voyez Intégrations, onglet Paiement en ligne.",
                'not-found' => 'Facture client introuvable.',
                'status' => 'Seule une facture client émise, ni payée ni annulée, se paie en ligne.',
                'amount' => 'Le montant de cette facture ne se paie pas en ligne.',
                'stripe' => 'Stripe a refusé la demande' . (($lien['detail'] ?? '') !== '' ? ' (' . $lien['detail'] . ')' : '') . '. Vérifiez la clé dans Intégrations.',
                default => 'Lien de paiement refusé.',
            });
        }
        Audit::log('facture.lien_paiement', 'invoices', (int) $facture['id'], ['session' => $lien['sessionId']]);
        return self::back('factures', 'success', 'Lien de paiement créé : copiez-le depuis la liste des factures pour l’envoyer au client.');
    }

    public static function setInvoiceStatus(Request $request, array $params): Response
    {
        if (!Finance::setInvoiceStatus((int) $params['id'], $request->input('status'))) {
            return self::back('factures', 'error', 'Statut invalide ou facture introuvable.');
        }
        return self::back('factures', 'success', 'Statut de la facture mis à jour.');
    }

    /**
     * Reprendre les champs personnalisés d'une facture déjà émise. Son montant
     * et son numéro ne se touchent pas ; ce qui l'entoure, si.
     */
    public static function saveInvoiceFields(Request $request, array $params): Response
    {
        $invoice = Finance::invoiceById((int) $params['id']);
        if ($invoice === null) {
            return self::back('factures', 'error', 'Facture introuvable.');
        }

        $refus = CustomFields::refus('facture', (int) $invoice['id'], $request->body);
        if ($refus !== null) {
            return self::back('factures', 'error', $refus);
        }
        return self::back('factures', 'success', 'Champs enregistrés.');
    }

    public static function deleteInvoice(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (!Corbeille::jeterPuis('facture', $id, (int) Session::get('user')['id'],
            static fn (): bool => Finance::deleteInvoice($id))) {
            return self::back('factures', 'error', 'Facture introuvable.');
        }

        CustomFields::oublier('facture', $id);
        return self::back('factures', 'success', 'Facture supprimée.');
    }

    // ---------- Budgets ----------

    public static function setBudget(Request $request): Response
    {
        $departmentId = (int) $request->input('department_id');
        $year = (int) $request->input('year');
        $amount = self::amount($request->input('amount'));

        if (Org::departmentById($departmentId) === null) {
            return self::back('budgets', 'error', 'Service introuvable.');
        }
        if ($year < 2000 || $year > 2100) {
            return self::back('budgets', 'error', 'Exercice invalide.');
        }
        if ($amount === false || $amount === null) {
            return self::back('budgets', 'error', 'Montant de budget invalide.');
        }
        Finance::setBudget($departmentId, $year, $amount, mb_substr($request->input('notes'), 0, 500));
        return self::back('budgets', 'success', 'Budget enregistré.');
    }

    public static function deleteBudget(Request $request, array $params): Response
    {
        if (!Finance::deleteBudget((int) $params['id'])) {
            return self::back('budgets', 'error', 'Budget introuvable.');
        }

        return self::back('budgets', 'success', 'Budget supprimé.');
    }

    // ---------- Notes de frais ----------

    public static function reviewClaim(Request $request, array $params): Response
    {
        $status = $request->input('status');
        $result = Finance::reviewClaim(
            (int) $params['id'],
            $status,
            (int) Session::get('user')['id'],
            mb_substr($request->input('review_note'), 0, 500)
        );
        if (!$result['ok']) {
            $messages = [
                'not-found' => 'Note de frais introuvable.',
                'not-pending' => "Cette note n'est plus en attente.",
                'not-approved' => "Une note doit être approuvée avant d'être remboursée.",
                'bad-status' => 'Décision invalide.',
            ];
            return self::back('frais', 'error', $messages[$result['reason']] ?? 'Décision impossible.');
        }
        return self::back('frais', 'success', 'Note de frais : ' . mb_strtolower($status) . '.');
    }

    // ---------- Devises ----------

    public static function setBaseCurrency(Request $request): Response
    {
        if (!Currency::setBase($request->input('code'))) {
            return self::back('devises', 'error', 'Devise inconnue.');
        }
        return self::back('devises', 'success', 'Devise de référence mise à jour.');
    }

    public static function setRate(Request $request): Response
    {
        $result = Currency::setRate(
            $request->input('code'),
            str_replace(',', '.', $request->input('rate')),
            (int) Session::get('user')['id']
        );
        if (!$result['ok']) {
            return self::back('devises', 'error', $result['message']);
        }
        return self::back('devises', 'success', 'Taux enregistré.');
    }

    // ---------- Notes de frais, côté salarié ----------

    public static function createClaim(Request $request): Response
    {
        $user = (array) Users::byId((int) Session::get('user')['id']);
        $spentOn = $request->input('spent_on');
        $category = $request->input('category');
        $amount = self::amount($request->input('amount'), true, 100000);

        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/mon-espace#frais');
        };
        if (!Validate::date($spentOn)) {
            return $fail('Date invalide.');
        }
        if ($spentOn > gmdate('Y-m-d')) {
            return $fail("Une dépense ne se déclare pas à l'avance.");
        }
        if (!in_array($category, Finance::EXPENSE_CATEGORIES, true)) {
            return $fail('Catégorie invalide.');
        }
        // Une indemnité kilométrique ne se déclare pas en euros : elle se
        // calcule au barème, sur la distance et la puissance fiscale. Le
        // montant saisi, s'il y en a un, est ignoré.
        if ($category === 'Kilométrique') {
            $km = str_replace(',', '.', trim($request->input('distance_km')));
            $cv = (int) $request->input('fiscal_hp');
            if (!is_numeric($km) || (float) $km <= 0 || (float) $km > 5000) {
                return $fail('Distance invalide : de 0,1 à 5 000 km par note.');
            }
            if (!in_array($cv, Finance::PUISSANCES_FISCALES, true)) {
                return $fail('Puissance fiscale invalide.');
            }
            $electrique = $request->input('electric') === 'on';
            $distance = round((float) $km, 1);
            $indemnite = Finance::indemniteKilometrique((int) $user['id'], $spentOn, $distance, $cv, $electrique);
            Finance::createClaim((int) $user['id'], $spentOn, $category, mb_substr($request->input('description'), 0, 300),
                $indemnite, $distance, $cv, $electrique);
            Flash::set('success', 'Indemnité kilométrique déposée : ' . rtrim(rtrim(number_format($distance, 1, '.', ''), '0'), '.')
                . ' km, ' . number_format($indemnite, 2, '.', '') . ' € au barème.');
            return Response::redirect('/mon-espace#frais');
        }
        if ($amount === false || $amount === null || $amount <= 0) {
            return $fail('Montant invalide.');
        }

        Finance::createClaim(
            (int) $user['id'],
            $spentOn,
            $category,
            mb_substr($request->input('description'), 0, 300),
            $amount
        );
        Flash::set('success', 'Note de frais déposée.');
        return Response::redirect('/mon-espace#frais');
    }

    public static function cancelClaim(Request $request, array $params): Response
    {
        $ok = Finance::cancelOwnClaim((int) $params['id'], (int) Session::get('user')['id']);
        Flash::set($ok ? 'success' : 'error', $ok
            ? 'Note de frais retirée.'
            : "Cette note n'est plus retirable : elle a déjà été examinée.");
        return Response::redirect('/mon-espace#frais');
    }

    // ---------- Abonnements ----------

    public static function createSubscription(Request $request): Response
    {
        $label = mb_substr($request->input('label'), 0, 160);
        $direction = $request->input('direction');
        $period = $request->input('period');
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $amountHt = self::amount($request->input('amount_ht'));
        $vatRaw = str_replace(',', '.', $request->input('vat_rate'));
        $paymentDays = $request->input('payment_days');
        $code = strtoupper($request->input('currency') ?: Currency::base());
        $partnerId = (int) $request->input('partner_id') ?: null;
        $departmentId = (int) $request->input('department_id') ?: null;

        if ($label === '') {
            return self::back('abonnements', 'error', "L'intitulé est obligatoire.");
        }
        if (!in_array($direction, Billing::DIRECTIONS, true)) {
            return self::back('abonnements', 'error', 'Sens invalide.');
        }
        if (!in_array($period, Billing::periodKeys(), true)) {
            return self::back('abonnements', 'error', 'Périodicité invalide.');
        }
        if (!Validate::date($start)) {
            return self::back('abonnements', 'error', 'Date de début invalide.');
        }
        if ($end !== '' && (!Validate::date($end) || $end < $start)) {
            return self::back('abonnements', 'error', 'Date de fin invalide.');
        }
        if ($amountHt === false || $amountHt === null) {
            return self::back('abonnements', 'error', 'Montant HT invalide.');
        }
        if (!is_numeric($vatRaw) || (float) $vatRaw < 0 || (float) $vatRaw > 100) {
            return self::back('abonnements', 'error', 'Taux de TVA invalide.');
        }
        if (!ctype_digit((string) $paymentDays) || (int) $paymentDays > 180) {
            return self::back('abonnements', 'error', 'Délai de paiement invalide.');
        }
        // Un abonnement facture tout seul : accepter une devise sans taux
        // reviendrait à programmer une facture fausse pour dans un mois.
        if (!Currency::isKnown($code) || Currency::rateOf($code) === null) {
            return self::back('abonnements', 'error', "Aucun taux connu pour $code.");
        }
        if ($partnerId !== null && Finance::partnerById($partnerId) === null) {
            return self::back('abonnements', 'error', 'Tiers introuvable.');
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return self::back('abonnements', 'error', 'Service introuvable.');
        }

        $id = Billing::create([
            'direction' => $direction, 'partnerId' => $partnerId, 'departmentId' => $departmentId,
            'label' => $label, 'amountHt' => $amountHt, 'vatRate' => round((float) $vatRaw, 2),
            'currency' => $code, 'period' => $period, 'startDate' => $start, 'endDate' => $end ?: null,
            'paymentDays' => (int) $paymentDays, 'notes' => mb_substr($request->input('notes'), 0, 1000),
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        Audit::log('abonnement.cree', 'subscriptions', $id, ['intitule' => $label, 'periodicite' => $period]);
        return self::back('abonnements', 'success',
            "Abonnement enregistré. La première facture partira à sa date d'échéance.");
    }

    public static function setSubscriptionState(Request $request, array $params): Response
    {
        $subscription = Billing::byId((int) $params['id']);
        if ($subscription === null) {
            return self::back('abonnements', 'error', 'Abonnement introuvable.');
        }
        $active = $request->input('active') === '1';
        Billing::setActive((int) $subscription['id'], $active);
        Audit::log('abonnement.etat', 'subscriptions', (int) $subscription['id'], ['actif' => $active]);
        return self::back('abonnements', 'success', $active
            ? 'Abonnement réactivé.'
            : "Abonnement suspendu : plus aucune facture n'en sortira.");
    }

    public static function deleteSubscription(Request $request, array $params): Response
    {
        $subscription = Billing::byId((int) $params['id']);
        if ($subscription === null) {
            return self::back('abonnements', 'error', 'Abonnement introuvable.');
        }
        Billing::remove((int) $subscription['id']);
        Audit::log('abonnement.supprime', 'subscriptions', (int) $subscription['id'], ['intitule' => $subscription['label']]);
        return self::back('abonnements', 'success', 'Abonnement supprimé. Les factures déjà émises restent dues.');
    }

    public static function issueSubscriptions(Request $request): Response
    {
        $result = Billing::run(null, (int) Session::get('user')['id']);
        Audit::log('abonnement.emission', 'invoices', null, [
            'emises' => count($result['issued']), 'sautees' => count($result['skipped']),
        ]);

        if ($result['issued'] === [] && $result['skipped'] === []) {
            return self::back('abonnements', 'error', "Aucune échéance à facturer aujourd'hui.");
        }
        $message = count($result['issued']) . ' facture(s) émise(s).';
        if ($result['skipped'] === []) {
            return self::back('abonnements', 'success', $message);
        }
        $detail = implode(', ', array_map(
            static fn (array $row): string => $row['label'] . ' (' . $row['reason'] . ')',
            $result['skipped']
        ));
        return self::back('abonnements', 'error',
            $message . ' ' . count($result['skipped']) . ' écartée(s) : ' . $detail . '.');
    }

    // ---------- TVA ----------

    public static function saveVatReturn(Request $request): Response
    {
        $period = Vat::periodByKey($request->input('periode'));
        if ($period === null) {
            return self::back('tva', 'error', 'Période inconnue.');
        }
        $verdict = Vat::save([
            'regime' => $period['regime'], 'label' => $period['label'],
            'from' => $period['start'], 'to' => $period['end'],
            'notes' => mb_substr($request->input('notes'), 0, 1000),
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$verdict['ok']) {
            return self::back('tva', 'error', $verdict['message']);
        }

        $totals = $verdict['totals'];
        Audit::log('tva.calculee', 'vat_returns', $verdict['id'], ['periode' => $period['label'], 'due' => $totals['due']]);
        return self::back('tva', 'success', $totals['credit'] > 0
            ? $period['label'] . ' : crédit de TVA de ' . $totals['credit'] . ' ' . $totals['currency'] . ', reportable.'
            : $period['label'] . ' : ' . $totals['due'] . ' ' . $totals['currency']
              . ' dus sur ' . $totals['invoices'] . ' facture(s).');
    }

    public static function setVatStatus(Request $request, array $params): Response
    {
        $verdict = Vat::setStatus((int) $params['id'], $request->input('status'));
        if (!$verdict['ok']) {
            return self::back('tva', 'error', $verdict['message']);
        }
        Audit::log('tva.statut', 'vat_returns', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::back('tva', 'success', 'Déclaration mise à jour.');
    }

    public static function deleteVatReturn(Request $request, array $params): Response
    {
        if (!Vat::remove((int) $params['id'])) {
            return self::back('tva', 'error', 'Déclaration introuvable.');
        }

        Audit::log('tva.supprimee', 'vat_returns', (int) $params['id']);
        return self::back('tva', 'success', 'Déclaration supprimée.');
    }

    // ---------- Recouvrement ----------

    public static function recordNotice(Request $request): Response
    {
        $sentOn = $request->input('sent_on');
        if (!Validate::date($sentOn)) {
            return self::back('recouvrement', 'error', 'Date invalide.');
        }
        $result = Dunning::record([
            'invoiceId' => (int) $request->input('invoice_id'),
            'level' => (int) $request->input('level'),
            'sentOn' => $sentOn,
            'note' => $request->input('note'),
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$result['ok']) {
            $messages = [
                'introuvable' => 'Facture introuvable.',
                'reglee' => 'Cette facture est réglée ou annulée : elle ne se relance plus.',
                'niveau' => 'Niveau de relance invalide.',
                'saut' => 'Le niveau ' . ($result['expected'] ?? 1) . ' doit être envoyé avant celui-ci :'
                    . ' une mise en demeure suppose des rappels restés sans effet.',
            ];
            return self::back('recouvrement', 'error', $messages[$result['reason']] ?? 'Relance impossible.');
        }
        Audit::log('recouvrement.relance', 'invoices', (int) $request->input('invoice_id'),
            ['niveau' => (int) $request->input('level')]);
        return self::back('recouvrement', 'success', 'Relance consignée.');
    }

    public static function deleteNotice(Request $request, array $params): Response
    {
        return Dunning::remove((int) $params['id'])
            ? self::back('recouvrement', 'success', 'Relance retirée.')
            : self::back('recouvrement', 'error', 'Relance introuvable.');
    }

    // ---------- Parc matériel ----------

    public static function createAsset(Request $request): Response
    {
        $name = mb_substr($request->input('name'), 0, 160);
        $category = $request->input('category');
        $purchaseDate = $request->input('purchase_date');
        $warrantyEnd = $request->input('warranty_end');
        $value = self::amount($request->input('value'), false);

        if ($name === '') {
            return self::back('equipements', 'error', "Le nom de l'équipement est obligatoire.");
        }
        if ($category !== '' && !in_array($category, Assets::CATEGORIES, true)) {
            return self::back('equipements', 'error', 'Catégorie invalide.');
        }
        if ($purchaseDate !== '' && !Validate::date($purchaseDate)) {
            return self::back('equipements', 'error', "Date d'achat invalide.");
        }
        if ($warrantyEnd !== '' && !Validate::date($warrantyEnd)) {
            return self::back('equipements', 'error', 'Fin de garantie invalide.');
        }
        if ($value === false) {
            return self::back('equipements', 'error', 'Valeur invalide.');
        }

        $refusChamps = CustomFields::verifier('equipement', $request->body);
        if ($refusChamps !== null) {
            return self::back('equipements', 'error', $refusChamps);
        }

        $assetId = Assets::create([
            'name' => $name, 'category' => $category,
            'purchaseDate' => $purchaseDate ?: null, 'warrantyEnd' => $warrantyEnd ?: null, 'value' => $value,
            'reference' => mb_substr($request->input('reference'), 0, 60),
            'serialNumber' => mb_substr($request->input('serial_number'), 0, 80),
            'notes' => mb_substr($request->input('notes'), 0, 1000),
        ]);
        CustomFields::enregistrer('equipement', $assetId, $request->body);
        return self::back('equipements', 'success', 'Équipement ajouté au parc.');
    }

    public static function assignAsset(Request $request, array $params): Response
    {
        $result = Assets::assign(
            (int) $params['id'],
            (int) $request->input('employee_id'),
            mb_substr($request->input('note'), 0, 300)
        );
        if (!$result['ok']) {
            $messages = [
                'not-found' => 'Équipement introuvable.',
                'retired' => 'Un équipement réformé ne peut pas être affecté.',
                'already-assigned' => "Cet équipement est déjà affecté : reprenez-le d'abord.",
                'no-employee' => 'Membre introuvable.',
            ];
            return self::back('equipements', 'error', $messages[$result['reason']] ?? 'Affectation impossible.');
        }
        return self::back('equipements', 'success', 'Équipement affecté.');
    }

    public static function takeBackAsset(Request $request, array $params): Response
    {
        if (!Assets::takeBack((int) $params['id'])['ok']) {
            return self::back('equipements', 'error', "Cet équipement n'est affecté à personne.");
        }
        return self::back('equipements', 'success', 'Équipement repris et rendu disponible.');
    }

    public static function setAssetStatus(Request $request, array $params): Response
    {
        $result = Assets::setStatus((int) $params['id'], $request->input('status'));
        if (!$result['ok']) {
            $messages = [
                'not-found' => 'Équipement introuvable.',
                'bad-status' => 'Statut invalide.',
                'assign-instead' => "« Affecté » découle d'une affectation : passez par le bouton Affecter.",
                'return-first' => "Reprenez d'abord cet équipement à son détenteur.",
            ];
            return self::back('equipements', 'error', $messages[$result['reason']] ?? 'Changement impossible.');
        }
        return self::back('equipements', 'success', 'Statut mis à jour.');
    }

    public static function saveAssetFields(Request $request, array $params): Response
    {
        $asset = Assets::byId((int) $params['id']);
        if ($asset === null) {
            return self::back('equipements', 'error', 'Équipement introuvable.');
        }

        $refus = CustomFields::refus('equipement', (int) $asset['id'], $request->body);
        if ($refus !== null) {
            return self::back('equipements', 'error', $refus);
        }
        return self::back('equipements', 'success', 'Champs enregistrés.');
    }

    public static function deleteAsset(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        if (!Corbeille::jeterPuis('equipement', $id, (int) Session::get('user')['id'],
            static fn (): bool => Assets::remove($id))) {
            return self::back('equipements', 'error', 'Équipement introuvable.');
        }

        CustomFields::oublier('equipement', $id);
        return self::back('equipements', 'success', 'Équipement retiré du parc.');
    }

    // ---------- Parc de salles ----------

    public static function createRoom(Request $request): Response
    {
        $name = mb_substr(trim($request->input('name')), 0, 120);
        $capacity = (int) $request->input('capacity');

        if ($name === '') {
            return self::back('salles', 'error', 'Le nom de la salle est obligatoire.');
        }
        if ($capacity < 0 || $capacity > 10000) {
            return self::back('salles', 'error', 'Capacité invalide.');
        }
        foreach (Rooms::all() as $room) {
            if (mb_strtolower((string) $room['name']) === mb_strtolower($name)) {
                return self::back('salles', 'error', 'Une salle porte déjà ce nom.');
            }
        }

        $id = Rooms::create([
            'name' => $name,
            'location' => mb_substr(trim($request->input('location')), 0, 140),
            'capacity' => $capacity,
            'equipment' => mb_substr(trim($request->input('equipment')), 0, 300),
        ]);
        Audit::log('salle.creee', 'rooms', $id, ['nom' => $name]);
        return self::back('salles', 'success', 'Salle ajoutée.');
    }

    /**
     * Fermer une salle ne touche pas aux réservations déjà posées : elle cesse
     * simplement d'être proposée.
     */
    public static function toggleRoom(Request $request, array $params): Response
    {
        if (!Rooms::toggle((int) $params['id'])) {
            return self::back('salles', 'error', 'Salle introuvable.');
        }
        Audit::log('salle.statut', 'rooms', (int) $params['id']);
        return self::back('salles', 'success', 'Disponibilité de la salle mise à jour.');
    }

    public static function deleteRoom(Request $request, array $params): Response
    {
        if (!Rooms::remove((int) $params['id'])) {
            return self::back('salles', 'error', 'Salle introuvable.');
        }

        Audit::log('salle.supprimee', 'rooms', (int) $params['id']);
        return self::back('salles', 'success', 'Salle supprimée, avec ses réservations.');
    }

    /** La gestion peut libérer n'importe quelle réservation, pas seulement les siennes. */
    public static function cancelBooking(Request $request, array $params): Response
    {
        if (!Rooms::cancel((int) $params['id'], (int) Session::get('user')['id'], true)) {
            return self::back('salles', 'error', 'Réservation introuvable.');
        }
        Audit::log('reservation.annulee', 'room_bookings', (int) $params['id'], ['par' => 'gestion']);
        return self::back('salles', 'success', 'Réservation annulée.');
    }

    // ---------- Paiements SEPA ----------

    private const SEPA_REFUS = [
        'company' => "Renseignez d'abord l'IBAN de la société — et, pour prélever, son identifiant créancier SEPA (ICS).",
        'date' => "Date d'exécution invalide : au plus tôt aujourd'hui pour un virement, demain pour un prélèvement.",
        'none' => 'Cochez au moins une facture.',
        'iban' => 'IBAN invalide : sa clé de contrôle ne correspond pas.',
        'bic' => 'BIC invalide.',
        'ics' => 'Identifiant créancier SEPA (ICS) invalide.',
    ];
    private const SEPA_OBSTACLES = [
        'currency' => "elle n'est pas en euros",
        'no-partner' => "elle n'a pas de tiers",
        'no-iban' => "le tiers n'a pas d'IBAN valide",
        'no-mandate' => "le tiers n'a pas de mandat de prélèvement actif",
        'amount' => 'son montant est nul',
    ];

    private static function refusSepa(array $verdict): Response
    {
        $message = $verdict['reason'] === 'blocked'
            ? 'La facture ' . $verdict['reference'] . ' ne peut pas partir : ' . (self::SEPA_OBSTACLES[$verdict['obstacle']] ?? 'elle est incomplète') . '.'
            : (self::SEPA_REFUS[$verdict['reason']] ?? 'Fichier impossible.');
        return self::back('sepa', 'error', $message);
    }

    public static function sepaCoordinates(Request $request): Response
    {
        $verdict = Sepa::poserCoordonneesSociete($request->input('iban'), $request->input('bic'), $request->input('ics'));
        if (!$verdict['ok']) {
            return self::refusSepa($verdict);
        }
        Audit::log('sepa.coordonnees', 'companies', null, []);
        return self::back('sepa', 'success', 'Coordonnées bancaires de la société enregistrées.');
    }

    private static function envoyerSepa(array $verdict, string $action): Response
    {
        if (!$verdict['ok']) {
            return self::refusSepa($verdict);
        }
        Audit::log($action, 'invoices', null, ['factures' => $verdict['nombre'], 'total' => $verdict['total']]);
        return Response::text($verdict['xml'])->withHeaders([
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $verdict['nom'] . '"',
        ]);
    }

    public static function sepaTransfers(Request $request): Response
    {
        return self::envoyerSepa(Sepa::virements($request->inputs('factures'), $request->input('date')), 'sepa.virements');
    }

    public static function sepaDebits(Request $request): Response
    {
        return self::envoyerSepa(Sepa::prelevements($request->inputs('factures'), $request->input('date')), 'sepa.prelevements');
    }
}
