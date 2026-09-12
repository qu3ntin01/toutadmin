<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Validate;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Announcements;
use App\Modules\Hr;
use App\Modules\Notifications;
use App\Modules\Workflows;
use App\Modules\Org;
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

        $eligible = Hr::isEligible($user);
        return Response::html(View::page('member/espace', [
            'title' => t('nav.mySpace') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('home.greeting', ['name' => $user['first_name'] ?: $user['email']]),
            'headerSubtitle' => t('home.subtitle'),
            'member' => $user,
            'department' => $department,
            'team' => $team,
            'colleagues' => $colleagues,
            'managers' => Org::managersFor($user),
            'news' => Announcements::forEmployee($user),
            'eligible' => $eligible,
            'requestTypes' => Hr::REQUEST_TYPES,
            'requests' => $eligible ? Hr::requestsFor((int) $user['id'], 20) : [],
            'payslips' => $eligible ? Hr::payslipsFor((int) $user['id'], 12) : [],
            'navItems' => self::nav($user),
            'scripts' => ['/js/confirm.js'],
        ]));
    }

    /** La barre de gauche d'un salarié : ce qui lui est ouvert, et rien d'autre. */
    public static function nav(array $user): array
    {
        $unread = \App\Modules\Notifications::unreadCount((int) $user['id']);
        $awaiting = count(\App\Modules\Workflows::awaiting((int) $user['id']));
        $items = [
            ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ['href' => '/demandes', 'label' => t('nav.internalRequests'), 'badge' => $awaiting ?: null],
            ['href' => '/notifications', 'label' => t('nav.notifications'), 'badge' => $unread ?: null],
            ['href' => '/annuaire', 'label' => t('nav.directory')],
            ['href' => '/organigramme', 'label' => t('nav.orgChart')],
            ['href' => '/mon-profil', 'label' => t('nav.profile')],
        ];
        if ($user['role'] === 'admin') {
            array_unshift($items, ['href' => '/admin', 'label' => t('admin.title')]);
        }
        if ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1) {
            $items[] = ['href' => '/rh', 'label' => t('nav.hrSpace')];
        }
        return $items;
    }

    // ---------- Demandes de congés et d'absence ----------

    public static function createRequest(Request $request): Response
    {
        $session = Session::get('user');
        $employee = Users::byId((int) $session['id']);
        if ($employee === null || !Hr::isEligible($employee)) {
            return Response::redirect('/mon-espace');
        }

        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/mon-espace');
        };
        $type = $request->input('type');
        $start = $request->input('start_date');
        $end = $request->input('end_date');

        if (!in_array($type, Hr::REQUEST_TYPES, true)) {
            return $fail('Type de demande invalide.');
        }
        if (!Validate::date($start) || !Validate::date($end)) {
            return $fail('Dates invalides.');
        }
        $days = Hr::countBusinessDays($start, $end);
        if ($days === null || $days === 0) {
            return $fail('La période sélectionnée est invalide (la date de fin doit suivre la date de début, jours ouvrés).');
        }

        Hr::createRequest((int) $employee['id'], $type, $start, $end, $days, mb_substr($request->input('reason'), 0, 500));
        Flash::set('success', 'Demande envoyée (' . $days . ' jour' . ($days > 1 ? 's' : '') . ' ouvré' . ($days > 1 ? 's' : '') . ').');
        return Response::redirect('/mon-espace');
    }

    public static function cancelRequest(Request $request, array $params): Response
    {
        $session = Session::get('user');
        $result = Hr::cancelOwn((int) $params['id'], (int) $session['id']);
        Flash::set($result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Demande annulée.' : 'Cette demande ne peut plus être annulée.');
        return Response::redirect('/mon-espace');
    }
}
