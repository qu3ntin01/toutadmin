<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Users;

/** L'espace du salarié : ce qu'une personne voit en arrivant. */
final class MemberController
{
    public static function home(Request $request): Response
    {
        $session = Session::get('user');
        $user = Users::byId((int) $session['id']);
        if ($user === null) {
            return Response::redirect('/connexion');
        }

        $department = $user['department_id'] === null ? null
            : Db::get('SELECT * FROM departments WHERE id = ?', [$user['department_id']]);
        $team = $user['team_id'] === null ? null
            : Db::get('SELECT * FROM teams WHERE id = ?', [$user['team_id']]);

        $colleagues = Db::all(
            'SELECT id, first_name, last_name, grade, email FROM users
             WHERE active = 1 AND directory_hidden = 0 AND id != ?
             ORDER BY last_name, first_name LIMIT 6',
            [$user['id']]
        );

        return Response::html(View::page('member/espace', [
            'title' => t('nav.mySpace') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('home.greeting', ['name' => $user['first_name'] ?: $user['email']]),
            'headerSubtitle' => t('home.subtitle'),
            'member' => $user,
            'department' => $department,
            'team' => $team,
            'colleagues' => $colleagues,
        ]));
    }
}
