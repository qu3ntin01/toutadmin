<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Search;
use App\Modules\Users;

/**
 * Recherche globale.
 *
 * Une seule barre pour tout le CMS. Le tri des droits est fait par le module,
 * source par source : l'écran ne reçoit que ce que la personne pouvait déjà
 * voir ailleurs.
 */
final class SearchController
{
    public static function index(Request $request): Response
    {
        $user = (array) Users::byId((int) Session::get('user')['id']);
        $query = mb_substr(trim($request->input('q')), 0, 100);

        return Response::html(View::page('search/index', [
            'title' => t('nav.search') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.search'),
            'headerTitle' => t('nav.search'),
            'headerSubtitle' => t('rch.headerSub'),
            'navItems' => [
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
                ['href' => '/annuaire', 'label' => t('nav.directory')],
                ['href' => '/base-de-connaissances', 'label' => t('nav.knowledge')],
            ],
            'footLinks' => [['href' => '/mon-profil', 'label' => t('nav.profile')]],
            'query' => $query,
            'groups' => $query === '' ? [] : Search::search($query, $user),
        ]));
    }
}
