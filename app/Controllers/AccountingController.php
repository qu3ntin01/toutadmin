<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Accounting;
use App\Modules\Finance;

/**
 * Comptabilité.
 *
 * Module optionnel : l'écran n'existe que si l'administration l'a activé. Les
 * droits sont ceux de la gestion — qui tient les comptes tient l'argent.
 */
final class AccountingController
{
    public static function canAccess(?array $user): bool
    {
        return FinanceController::canAccess($user);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/comptabilite#' . $anchor);
    }

    /** Un montant de formulaire : virgule décimale acceptée. */
    private static function amount(string $raw): float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }

    public static function index(Request $request): Response
    {
        $year = (int) ($request->input('annee') ?: gmdate('Y'));
        $from = "$year-01-01";
        $to = "$year-12-31";
        $accountId = (int) $request->input('compte') ?: null;
        $accounts = Accounting::accounts();

        $posted = array_filter(array_column(Accounting::entries(5000), 'invoice_id'), static fn ($id): bool => $id !== null);
        $unposted = array_values(array_filter(
            Finance::invoices(),
            static fn (array $invoice): bool => $invoice['status'] !== 'Annulée'
                && !in_array((int) $invoice['id'], array_map('intval', $posted), true)
        ));

        $selected = null;
        foreach ($accounts as $account) {
            if ((int) $account['id'] === $accountId) {
                $selected = $account;
            }
        }

        return Response::html(View::page('accounting/index', [
            'title' => t('pcs.tabAccounting') . ' — ' . t('app.name'),
            'panelLabel' => t('pcs.tabAccounting'),
            'headerTitle' => t('pcs.tabAccounting'),
            'headerSubtitle' => t('cpt.headerSub', ['year' => $year]),
            'navItems' => [
                ['tab' => 'plan', 'label' => t('cpt.tabChart')],
                ['tab' => 'ecritures', 'label' => t('cpt.tabEntries'), 'badge' => count($unposted) ?: null],
                ['tab' => 'balance', 'label' => t('cpt.tabBalance')],
                ['tab' => 'grandlivre', 'label' => t('cpt.tabLedger')],
            ],
            'footLinks' => [
                ['href' => '/gestion', 'label' => t('nav.gestion')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'year' => $year,
            'years' => [$year + 1, $year, $year - 1, $year - 2],
            'today' => gmdate('Y-m-d'),
            'accounts' => $accounts,
            'accountKinds' => Accounting::ACCOUNT_KINDS,
            'journals' => Accounting::journals(),
            'entries' => Accounting::entries(),
            'balance' => Accounting::balance($from, $to),
            'income' => Accounting::income($from, $to),
            'selectedAccount' => $selected,
            'ledger' => $selected === null ? [] : Accounting::ledger($accountId, $from, $to),
            // Les factures pas encore passées en écriture : le lien entre gestion et compta.
            'unposted' => $unposted,
        ]));
    }

    // ---------- Plan comptable ----------

    public static function createAccount(Request $request): Response
    {
        $code = mb_substr($request->input('code'), 0, 20);
        $label = mb_substr($request->input('label'), 0, 160);
        $kind = $request->input('kind');

        if (preg_match('/^\d{2,10}$/', $code) !== 1) {
            return self::back('plan', 'error', 'Le numéro de compte doit être composé de 2 à 10 chiffres.');
        }
        if ($label === '') {
            return self::back('plan', 'error', "L'intitulé du compte est obligatoire.");
        }

        $result = Accounting::createAccount($code, $label, $kind);
        if (!$result['ok']) {
            $messages = ['bad-kind' => 'Type de compte invalide.', 'duplicate' => 'Ce numéro de compte existe déjà.'];
            return self::back('plan', 'error', $messages[$result['reason']] ?? 'Création impossible.');
        }
        return self::back('plan', 'success', "Compte $code créé.");
    }

    public static function deleteAccount(Request $request, array $params): Response
    {
        $result = Accounting::deleteAccount((int) $params['id']);
        return self::back('plan', 'success', $result['deactivated']
            ? 'Compte mouvementé : il a été désactivé plutôt que supprimé.'
            : 'Compte supprimé.');
    }

    public static function createJournal(Request $request): Response
    {
        $code = mb_substr(strtoupper($request->input('code')), 0, 6);
        $label = mb_substr($request->input('label'), 0, 120);

        if (preg_match('/^[A-Z0-9]{2,6}$/', $code) !== 1) {
            return self::back('plan', 'error', 'Code journal invalide (2 à 6 caractères).');
        }
        if ($label === '') {
            return self::back('plan', 'error', "L'intitulé du journal est obligatoire.");
        }
        if (!Accounting::createJournal($code, $label)['ok']) {
            return self::back('plan', 'error', 'Ce code journal existe déjà.');
        }
        return self::back('plan', 'success', "Journal $code créé.");
    }

    // ---------- Écritures ----------

    public static function createEntry(Request $request): Response
    {
        $journalId = (int) $request->input('journal_id');
        $entryDate = $request->input('entry_date');
        $label = mb_substr($request->input('label'), 0, 160);

        if (!Validate::date($entryDate)) {
            return self::back('ecritures', 'error', "Date d'écriture invalide.");
        }
        if ($label === '') {
            return self::back('ecritures', 'error', "L'intitulé de l'écriture est obligatoire.");
        }

        // Le formulaire envoie des colonnes parallèles : on les recompose en lignes.
        $accountIds = $request->inputs('account_id');
        $debits = $request->inputs('debit');
        $credits = $request->inputs('credit');
        $labels = $request->inputs('line_label');

        $lines = [];
        foreach ($accountIds as $index => $accountId) {
            $lines[] = [
                'accountId' => (int) $accountId,
                'label' => (string) ($labels[$index] ?? ''),
                'debit' => self::amount((string) ($debits[$index] ?? '0')),
                'credit' => self::amount((string) ($credits[$index] ?? '0')),
            ];
        }

        $result = Accounting::createEntry([
            'journalId' => $journalId,
            'entryDate' => $entryDate,
            'label' => $label,
            'reference' => mb_substr($request->input('reference'), 0, 60),
            'lines' => $lines,
            'createdBy' => (int) Session::get('user')['id'],
        ]);

        if (!$result['ok']) {
            if ($result['reason'] === 'unbalanced') {
                return self::back('ecritures', 'error', sprintf(
                    'Écriture déséquilibrée : %.2f au débit contre %.2f au crédit.',
                    $result['totalDebit'],
                    $result['totalCredit']
                ));
            }
            $messages = [
                'no-journal' => 'Journal introuvable.',
                'too-few-lines' => 'Une écriture demande au moins deux lignes mouvementées.',
                'both-sides' => 'Une ligne porte un débit ou un crédit, pas les deux.',
                'negative' => 'Les montants ne peuvent pas être négatifs.',
                'no-account' => 'Compte introuvable sur une des lignes.',
                'empty' => 'Une écriture à zéro ne veut rien dire.',
            ];
            return self::back('ecritures', 'error', $messages[$result['reason']] ?? 'Écriture refusée.');
        }
        return self::back('ecritures', 'success', 'Écriture enregistrée.');
    }

    public static function deleteEntry(Request $request, array $params): Response
    {
        Accounting::deleteEntry((int) $params['id']);
        return self::back('ecritures', 'success', 'Écriture supprimée.');
    }

    /** Passe une facture de la gestion en écriture comptable. */
    public static function postInvoice(Request $request, array $params): Response
    {
        $invoice = Finance::invoiceById((int) $params['id']);
        if ($invoice === null) {
            return self::back('ecritures', 'error', 'Facture introuvable.');
        }
        $result = Accounting::entryFromInvoice($invoice, (int) Session::get('user')['id']);
        if (!$result['ok']) {
            $messages = [
                'already-posted' => 'Cette facture est déjà comptabilisée.',
                'no-journal' => 'Journal des ventes ou des achats manquant.',
                'missing-accounts' => 'Comptes 401/411, 606/706 ou TVA manquants dans le plan.',
            ];
            return self::back('ecritures', 'error', $messages[$result['reason']] ?? 'Comptabilisation impossible.');
        }
        return self::back('ecritures', 'success', 'Facture comptabilisée.');
    }

    // ---------- Export ----------

    public static function balanceCsv(Request $request): Response
    {
        $year = (int) ($request->input('annee') ?: gmdate('Y'));
        $csv = Accounting::balanceCsv("$year-01-01", "$year-12-31");
        // Le BOM évite qu'un tableur ouvre les accents de travers.
        return Response::text("\u{FEFF}" . $csv)->withHeaders([
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"balance-$year.csv\"",
        ]);
    }
}
