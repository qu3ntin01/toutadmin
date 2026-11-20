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
        $search = mb_substr($request->input('q'), 0, 80);
        // Filtres de navigation : par équipe ou par service.
        $teamFilter = (int) $request->input('equipe') ?: null;
        $departmentFilter = (int) $request->input('service') ?: null;

        $params = [];
        $where = "u.role = 'employee' AND u.active = 1 AND u.directory_hidden = 0";
        if ($search !== '') {
            $where .= " AND (lower(u.first_name) LIKE ? OR lower(u.last_name) LIKE ? OR lower(u.grade) LIKE ?
                             OR lower(u.email) LIKE ? OR lower(COALESCE(tm.name, '')) LIKE ?
                             OR lower(COALESCE(d.name, '')) LIKE ?)";
            $like = '%' . mb_strtolower($search) . '%';
            $params = [$like, $like, $like, $like, $like, $like];
        }
        if ($teamFilter !== null) {
            $where .= ' AND u.team_id = ?';
            $params[] = $teamFilter;
        }
        if ($departmentFilter !== null) {
            $where .= ' AND u.department_id = ?';
            $params[] = $departmentFilter;
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
            'teamFilter' => $teamFilter,
            'departmentFilter' => $departmentFilter,
            'teams' => \App\Modules\Org::teams(),
            'departments' => \App\Modules\Org::departments(),
        ]));
    }
}
