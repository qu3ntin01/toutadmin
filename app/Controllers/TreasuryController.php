<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Treasury;

/**
 * Trésorerie.
 *
 * Module optionnel, réservé à la gestion. Ce qui est en banque, ce qui va en
 * sortir, et le rapprochement qui distingue une facture émise d'une facture
 * encaissée.
 */
final class TreasuryController
{
    public static function canAccess(?array $user): bool
    {
        return FinanceController::canAccess($user);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/tresorerie#' . $anchor);
    }

    /**
     * Un montant signé : négatif pour une sortie, et jamais nul — un mouvement
     * à zéro ne dit rien et fausserait un rapprochement.
     */
    private static function signedAmount(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        $number = round((float) $value, 2);
        return $number === 0.0 || abs($number) > 1000000000 ? null : $number;
    }

    public static function index(Request $request): Response
    {
        $accountId = (int) $request->input('compte') ?: null;
        $projection = Treasury::projection();
        $pending = Treasury::unreconciled();

        return Response::html(View::page('treasury/index', [
            'title' => t('tre.panel') . ' — ' . t('app.name'),
            'panelLabel' => t('tre.panel'),
            'headerTitle' => t('tre.panel'),
            'headerSubtitle' => t('tre.headerSub'),
            'navItems' => [
                ['tab' => 'comptes', 'label' => t('tre.tabAccounts')],
                ['tab' => 'mouvements', 'label' => t('common.movements')],
                ['tab' => 'rapprochement', 'label' => t('tre.tabReconcile'), 'badge' => count($pending) ?: null],
                ['tab' => 'previsions', 'label' => t('tre.tabForecast')],
            ],
            'footLinks' => [
                ['href' => '/gestion', 'label' => t('nav.gestion')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'accountList' => Treasury::accounts(),
            'total' => Treasury::totalBalance(),
            'movements' => Treasury::transactions($accountId),
            'pending' => $pending,
            'openInvoices' => Db::all("SELECT * FROM invoices WHERE status != 'Payée' ORDER BY due_date IS NULL, due_date"),
            'forecastList' => Treasury::forecasts(),
            'projection' => $projection,
            'certainties' => Treasury::CERTAINTIES,
            'selectedAccount' => $accountId,
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Comptes ----------

    public static function createAccount(Request $request): Response
    {
        $label = $request->input('label');
        if ($label === '' || mb_strlen($label) > 120) {
            return self::back('comptes', 'error', 'Intitulé de compte invalide.');
        }
        $opening = $request->input('opening_balance');
        $balance = $opening === '' ? 0.0 : self::signedAmount($opening);
        if ($opening !== '' && $balance === null) {
            return self::back('comptes', 'error', 'Solde initial invalide.');
        }

        // Seuls les quatre derniers chiffres de l'IBAN sont conservés : le reste
        // ne sert à rien ici et n'a pas à traîner en base.
        $digits = preg_replace('/\D/', '', $request->input('iban_last4')) ?? '';
        $last4 = substr($digits, -4);

        Treasury::createAccount(
            $label,
            mb_substr($request->input('bank'), 0, 80),
            $last4,
            $balance ?? 0.0
        );
        return self::back('comptes', 'success', 'Compte ajouté.');
    }

    public static function closeAccount(Request $request, array $params): Response
    {
        Treasury::closeAccount((int) $params['id']);
        return self::back('comptes', 'success', 'Compte clôturé : il sort des soldes sans perdre son historique.');
    }

    public static function deleteAccount(Request $request, array $params): Response
    {
        $account = Treasury::accountById((int) $params['id']);
        if ($account === null) {
            return self::back('comptes', 'error', 'Compte introuvable.');
        }
        Treasury::deleteAccount((int) $account['id']);
        Audit::log('tresorerie.compte_supprime', 'bank_accounts', (int) $account['id'], ['libelle' => $account['label']]);
        return self::back('comptes', 'success', 'Compte supprimé, avec ses mouvements.');
    }

    // ---------- Mouvements ----------

    public static function addTransaction(Request $request): Response
    {
        $account = Treasury::accountById((int) $request->input('account_id'));
        if ($account === null) {
            return self::back('mouvements', 'error', 'Compte introuvable.');
        }
        $date = $request->input('value_date');
        if (!Validate::date($date)) {
            return self::back('mouvements', 'error', 'Date de valeur invalide.');
        }
        $label = $request->input('label');
        if ($label === '') {
            return self::back('mouvements', 'error', 'Libellé requis.');
        }
        $amount = self::signedAmount($request->input('amount'));
        if ($amount === null) {
            return self::back('mouvements', 'error',
                'Montant invalide : il doit être non nul, négatif pour une sortie.');
        }

        Treasury::addTransaction([
            'accountId' => (int) $account['id'], 'valueDate' => $date, 'label' => mb_substr($label, 0, 160),
            'amount' => $amount, 'category' => mb_substr($request->input('category'), 0, 60),
        ]);
        return self::back('mouvements', 'success', 'Mouvement enregistré.');
    }

    public static function deleteTransaction(Request $request, array $params): Response
    {
        Treasury::deleteTransaction((int) $params['id']);
        return self::back('mouvements', 'success', 'Mouvement supprimé.');
    }

    public static function reconcile(Request $request, array $params): Response
    {
        $verdict = Treasury::reconcile((int) $params['id'], (int) $request->input('invoice_id'));
        if (!$verdict['ok']) {
            return self::back('rapprochement', 'error', $verdict['message']);
        }
        Audit::log('tresorerie.rapprochement', 'bank_transactions', (int) $params['id'],
            ['facture' => (int) $request->input('invoice_id')]);
        return self::back('rapprochement', 'success', 'Mouvement rapproché : la facture passe en payée.');
    }

    // ---------- Prévisionnel ----------

    public static function addForecast(Request $request): Response
    {
        $label = $request->input('label');
        $date = $request->input('expected_on');
        $amount = self::signedAmount($request->input('amount'));
        $certainty = $request->input('certainty');

        if ($label === '') {
            return self::back('previsions', 'error', 'Libellé requis.');
        }
        if (!Validate::date($date)) {
            return self::back('previsions', 'error', 'Date invalide.');
        }
        if ($amount === null) {
            return self::back('previsions', 'error', 'Montant invalide.');
        }
        if (!in_array($certainty, Treasury::CERTAINTIES, true)) {
            return self::back('previsions', 'error', 'Degré de certitude invalide.');
        }

        Treasury::addForecast(
            mb_substr($label, 0, 160),
            $date,
            $amount,
            $certainty,
            mb_substr($request->input('note'), 0, 300)
        );
        return self::back('previsions', 'success', 'Échéance prévue enregistrée.');
    }

    public static function deleteForecast(Request $request, array $params): Response
    {
        Treasury::deleteForecast((int) $params['id']);
        return self::back('previsions', 'success', 'Prévision retirée.');
    }
}
