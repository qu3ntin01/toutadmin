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
use App\Modules\Announcements;
use App\Modules\OneOnOne;
use App\Modules\Org;

/**
 * Espace manager.
 *
 * Un manager voit son équipe, ses absences, publie sur ses périmètres et tient
 * ses points individuels. Il ne voit ni les fiches de paie ni les demandes du
 * reste de l'entreprise : encadrer n'est pas administrer.
 */
final class ManagerController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && Org::isManager((int) $user['id']);
    }

    public static function home(Request $request): Response
    {
        $managerId = (int) Session::get('user')['id'];
        $scopes = Org::scopesManagedBy($managerId);
        $team = Org::membersManagedBy($managerId);
        $ids = array_map('intval', array_column($team, 'id'));

        // Aucun collaborateur : pas de requête « IN () » invalide.
        $requests = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $requests = Db::all(
                "SELECT r.*, u.first_name, u.last_name
                 FROM hr_requests r JOIN users u ON u.id = r.employee_id
                 WHERE r.employee_id IN ($placeholders)
                 ORDER BY r.created_at DESC LIMIT 50",
                $ids
            );
        }

        $today = gmdate('Y-m-d');
        $upcoming = array_values(array_filter(
            $requests,
            static fn (array $r): bool => $r['status'] === 'Approuvée' && $r['end_date'] >= $today
        ));

        // Les périmètres encadrés, nommés : ils servent de destinataires d'actualité.
        $managedScopes = [];
        foreach ($scopes['departments'] as $id) {
            $department = Org::departmentById($id);
            if ($department !== null) {
                $managedScopes[] = ['scope' => 'department', 'id' => $id, 'name' => $department['name']];
            }
        }
        foreach ($scopes['teams'] as $id) {
            $team_ = Org::teamById($id);
            if ($team_ !== null) {
                $managedScopes[] = ['scope' => 'team', 'id' => $id, 'name' => $team_['name']];
            }
        }

        return Response::html(View::page('manager/index', [
            'title' => t('nav.team') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.team'),
            'headerTitle' => t('team.title'),
            'headerSubtitle' => t('team.subtitle'),
            'navItems' => [
                ['tab' => 'equipe', 'label' => t('nav.team')],
                ['tab' => 'absences', 'label' => t('nav.requests')],
                ['tab' => 'points', 'label' => t('oto.title')],
                ['tab' => 'actualites', 'label' => t('home.companyNews')],
            ],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'team' => $team,
            'requests' => $requests,
            'upcoming' => $upcoming,
            'managedScopes' => $managedScopes,
            'teamNews' => Announcements::forScopes($scopes),
            'points' => OneOnOne::forManager($managerId),
            'cadence' => OneOnOne::cadence($managerId),
            'pointStatuses' => OneOnOne::STATUSES,
            'pointStats' => OneOnOne::summary($managerId),
            'today' => $today,
            'stats' => [
                'teamSize' => count($team),
                'pending' => count(array_filter($requests, static fn (array $r): bool => $r['status'] === 'En attente')),
                'upcoming' => count($upcoming),
                'leaveTotal' => array_sum(array_map(static fn (array $m): float => (float) ($m['leave_balance'] ?? 0), $team)),
            ],
        ]));
    }

    // ---------- Actualités de périmètre ----------

    public static function publish(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 150);
        $body = mb_substr($request->input('body'), 0, 2000);
        [$scope, $rawId] = array_pad(explode(':', $request->input('target'), 2), 2, null);
        $scopeId = (int) $rawId;

        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/mon-equipe#actualites');
        };
        if ($title === '') {
            return $fail("Le titre de l'actualité est obligatoire.");
        }

        // Un manager ne publie que sur un périmètre qu'il encadre effectivement.
        $scopes = Org::scopesManagedBy((int) Session::get('user')['id']);
        $allowed = match ($scope) {
            'team' => $scopes['teams'],
            'department' => $scopes['departments'],
            default => [],
        };
        if (!in_array($scopeId, $allowed, true)) {
            return $fail("Vous n'encadrez pas ce périmètre.");
        }

        Announcements::create((int) Session::get('user')['id'], (string) $scope, $scopeId, $title, $body);
        Flash::set('success', 'Actualité publiée.');
        return Response::redirect('/mon-equipe#actualites');
    }

    public static function deleteNews(Request $request, array $params): Response
    {
        $scopes = Org::scopesManagedBy((int) Session::get('user')['id']);
        $ok = Announcements::removeWithinScopes((int) $params['id'], $scopes);
        Flash::set($ok ? 'success' : 'error', $ok
            ? 'Actualité supprimée.'
            : "Cette actualité ne relève pas d'un périmètre que vous encadrez.");
        return Response::redirect('/mon-equipe#actualites');
    }

    // ---------- Points individuels ----------

    /** null si vide, false si la date est invalide. */
    private static function date(string $raw, bool $required = false): string|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $required ? false : null;
        }
        return Validate::date($trimmed) ? $trimmed : false;
    }

    private static function mood(string $raw): int|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $value = filter_var($trimmed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 5]]);
        return $value === false ? false : $value;
    }

    public static function createPoint(Request $request): Response
    {
        $scheduled = self::date($request->input('scheduled_on'), true);
        if ($scheduled === false) {
            Flash::set('error', 'Date invalide.');
            return Response::redirect('/mon-equipe#points');
        }
        $result = OneOnOne::create(
            (int) Session::get('user')['id'],
            (int) $request->input('employee_id'),
            (string) $scheduled,
            $request->input('topics')
        );
        Flash::set($result['ok'] ? 'success' : 'error', $result['ok']
            ? 'Point individuel planifié.'
            : "Vous n'encadrez pas cette personne.");
        return Response::redirect('/mon-equipe#points');
    }

    public static function updatePoint(Request $request, array $params): Response
    {
        $scheduled = self::date($request->input('scheduled_on'), true);
        $held = self::date($request->input('held_on'));
        $next = self::date($request->input('next_on'));
        $mood = self::mood($request->input('mood'));
        $status = $request->input('status');

        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/mon-equipe#points');
        };
        if ($scheduled === false || $held === false || $next === false || $mood === false) {
            return $fail('Saisie invalide.');
        }
        if (!in_array($status, OneOnOne::STATUSES, true)) {
            return $fail('Statut invalide.');
        }
        // Un point « tenu » sans date de tenue ne compte dans aucune cadence :
        // il disparaîtrait du suivi tout en paraissant fait.
        if ($status === 'Tenu' && $held === null) {
            return $fail('Renseignez la date à laquelle le point a été tenu.');
        }

        $done = OneOnOne::update((int) $params['id'], (int) Session::get('user')['id'], [
            'scheduledOn' => $scheduled,
            'heldOn' => $held,
            'topics' => $request->input('topics'),
            'sharedNote' => $request->input('shared_note'),
            'privateNote' => $request->input('private_note'),
            'mood' => $mood,
            'nextOn' => $next,
            'status' => $status,
        ]);
        if (!$done) {
            return $fail("Ce point ne vous appartient pas, ou vous n'encadrez plus cette personne.");
        }
        Flash::set('success', 'Point individuel enregistré.');
        return Response::redirect('/mon-equipe#points');
    }

    public static function deletePoint(Request $request, array $params): Response
    {
        $ok = OneOnOne::remove((int) $params['id'], (int) Session::get('user')['id']);
        Flash::set($ok ? 'success' : 'error', $ok ? 'Point individuel supprimé.' : 'Ce point ne vous appartient pas.');
        return Response::redirect('/mon-equipe#points');
    }
}
