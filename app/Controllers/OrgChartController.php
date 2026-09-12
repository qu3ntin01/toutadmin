<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Org;

/**
 * Organigramme.
 *
 * Il dit trois choses que des listes séparées ne disent pas : qui encadre quoi,
 * où se trouve chacun, et qui n'est rattaché nulle part. L'administration et
 * les RH voient l'effectif entier ; les autres voient l'annuaire, c'est-à-dire
 * sans les personnes que l'administration en a retirées.
 */
final class OrgChartController
{
    public static function index(Request $request): Response
    {
        $session = Session::get('user');
        $seesAll = is_array($session) && ($session['role'] === 'admin' || !empty($session['isHr']));
        $chart = Org::chart($seesAll);

        return Response::html(View::page('org/chart', [
            'title' => t('nav.orgChart') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('nav.orgChart'),
            'headerSubtitle' => t('org.headerSub'),
            'chart' => $chart,
            'seesAll' => $seesAll,
        ]));
    }
}
