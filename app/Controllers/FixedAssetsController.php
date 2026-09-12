<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Treasury;

/**
 * Immobilisations.
 *
 * Module optionnel, réservé à la gestion. Le registre et les tableaux
 * d'amortissement sont un outil de suivi : le rattachement comptable, les
 * composants et les dérogatoires restent l'affaire de l'expert-comptable.
 */
final class FixedAssetsController
{
    public static function canAccess(?array $user): bool
    {
        return FinanceController::canAccess($user);
    }

    private static function back(string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/immobilisations#registre');
    }

    public static function index(Request $request): Response
    {
        return Response::html(View::page('assets/index', [
            'title' => t('imm.panel') . ' — ' . t('app.name'),
            'panelLabel' => t('imm.panel'),
            'headerTitle' => t('imm.panel'),
            'headerSubtitle' => t('imm.headerSub'),
            'navItems' => [['tab' => 'registre', 'label' => t('imm.inService')]],
            'footLinks' => [
                ['href' => '/gestion', 'label' => t('nav.gestion')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'assetList' => Treasury::assets(),
            'summary' => Treasury::assetSummary(),
            'methods' => Treasury::DEPRECIATION_METHODS,
            'today' => gmdate('Y-m-d'),
            'currentYear' => (int) gmdate('Y'),
        ]));
    }

    public static function create(Request $request): Response
    {
        $label = $request->input('label');
        if ($label === '' || mb_strlen($label) > 160) {
            return self::back('error', 'Intitulé invalide.');
        }
        $acquired = $request->input('acquired_on');
        if (!Validate::date($acquired)) {
            return self::back('error', "Date d'acquisition invalide.");
        }

        $raw = str_replace([' ', ','], ['', '.'], $request->input('amount'));
        if (!is_numeric($raw)) {
            return self::back('error', 'Montant invalide.');
        }
        $amount = round((float) $raw, 2);
        if ($amount <= 0 || $amount > 1000000000) {
            return self::back('error', 'Montant invalide.');
        }

        $duration = (int) $request->input('duration_years');
        if ($duration < 1 || $duration > 50) {
            return self::back('error', "La durée d'amortissement doit tenir entre 1 et 50 ans.");
        }
        $method = $request->input('method');
        if (!in_array($method, Treasury::DEPRECIATION_METHODS, true)) {
            return self::back('error', 'Mode invalide.');
        }

        $id = Treasury::createAsset([
            'label' => $label,
            'category' => mb_substr($request->input('category', 'Matériel') ?: 'Matériel', 0, 60),
            'acquiredOn' => $acquired,
            'amount' => $amount,
            'durationYears' => $duration,
            'method' => $method,
            'note' => mb_substr($request->input('note'), 0, 500),
        ]);
        Audit::log('immobilisation.enregistree', 'fixed_assets', $id, ['libelle' => $label, 'montant' => $amount]);
        return self::back('success', "Immobilisation enregistrée, avec son tableau d'amortissement.");
    }

    public static function dispose(Request $request, array $params): Response
    {
        $asset = Treasury::assetById((int) $params['id']);
        if ($asset === null) {
            return self::back('error', 'Immobilisation introuvable.');
        }
        $on = $request->input('disposed_on');
        if (!Validate::date($on)) {
            return self::back('error', 'Date de cession invalide.');
        }
        if ($on < $asset['acquired_on']) {
            return self::back('error', "Une cession ne précède pas l'acquisition.");
        }
        Treasury::disposeAsset((int) $asset['id'], $on);
        Audit::log('immobilisation.cedee', 'fixed_assets', (int) $asset['id'], ['le' => $on]);
        return self::back('success', 'Cession enregistrée.');
    }

    public static function remove(Request $request, array $params): Response
    {
        Treasury::deleteAsset((int) $params['id']);
        return self::back('success', 'Immobilisation supprimée.');
    }
}
