<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

/**
 * Annuaire.
 *
 * Une personne peut être retirée de l'annuaire, mais **elle ne s'y retire pas
 * elle-même** : le retrait est une décision de l'administration, comme
 * l'inscription. Le filtre est donc posé ici, en base, pas dans un réglage de
 * profil.
 */
final class DirectoryController
{
    public static function index(Request $request): Response
    {
        $search = $request->input('q');
        $params = [];
        $where = "u.active = 1 AND u.directory_hidden = 0";
        if ($search !== '') {
            $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.grade LIKE ? OR u.email LIKE ? OR d.name LIKE ?)";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like, $like];
        }

        $people = Db::all(
            "SELECT u.id, u.first_name, u.last_name, u.grade, u.email, u.phone, u.avatar_file,
                    d.name AS department, tm.name AS team
             FROM users u
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN teams tm ON tm.id = u.team_id
             WHERE $where
             ORDER BY u.last_name, u.first_name",
            $params
        );

        return Response::html(View::page('directory/index', [
            'title' => t('directory.title') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('directory.title'),
            'headerSubtitle' => t('directory.subtitle'),
            'people' => $people,
            'search' => $search,
        ]));
    }
}
