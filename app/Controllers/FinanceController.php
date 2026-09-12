<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Currency;
use App\Modules\Finance;
use App\Modules\Org;
use App\Modules\Users;

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
                ['tab' => 'budgets', 'label' => t('erp.budgets')],
                ['tab' => 'frais', 'label' => t('erp.claims'),
                 'badge' => count(array_filter($claims, static fn (array $c): bool => $c['status'] === 'En attente')) ?: null],
                ['tab' => 'devises', 'label' => t('nav.currencies')],
            ],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'year' => $year,
            'years' => [$year + 1, $year, $year - 1, $year - 2],
            'today' => gmdate('Y-m-d'),
            'summary' => Finance::financialSummary($year),
            'partners' => $partners,
            'partnerKinds' => Finance::PARTNER_KINDS,
            'contracts' => Finance::contracts(),
            'contractStatuses' => Finance::CONTRACT_STATUSES,
            'billingPeriods' => Finance::BILLING_PERIODS,
            'renewals' => $renewals,
            'invoices' => Finance::invoices(),
            'invoiceDirections' => Finance::INVOICE_DIRECTIONS,
            'invoiceStatuses' => Finance::INVOICE_STATUSES,
            'budgets' => Finance::budgets($year),
            'claims' => $claims,
            'expenseStatuses' => Finance::EXPENSE_STATUSES,
            'currencies' => Currency::rates(),
            'usableCurrencies' => Currency::usable(),
            'baseCurrency' => Currency::base(),
            'departments' => Org::departments(),
            'employees' => Users::employees(),
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
        Finance::deletePartner((int) $partner['id']);
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
        Finance::deleteContract((int) $params['id']);
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

        Finance::createInvoice([
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
        ]);
        return self::back('factures', 'success', 'Facture enregistrée.');
    }

    public static function setInvoiceStatus(Request $request, array $params): Response
    {
        if (!Finance::setInvoiceStatus((int) $params['id'], $request->input('status'))) {
            return self::back('factures', 'error', 'Statut invalide ou facture introuvable.');
        }
        return self::back('factures', 'success', 'Statut de la facture mis à jour.');
    }

    public static function deleteInvoice(Request $request, array $params): Response
    {
        Finance::deleteInvoice((int) $params['id']);
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
        Finance::deleteBudget((int) $params['id']);
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
}
