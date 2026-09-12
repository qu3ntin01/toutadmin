<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Org;
use App\Modules\Planning;

/**
 * Planning d'équipe.
 *
 * Planifier engage les journées d'autrui : c'est réservé à ceux qui encadrent,
 * aux RH et à l'administration. Consulter, en revanche, est ouvert à tous —
 * un planning que l'on ne peut pas lire ne sert à personne.
 */
final class PlanningController
{
    public static function canPlan(?array $user): bool
    {
        return $user !== null && (
            $user['role'] === 'admin'
            || (int) ($user['is_hr'] ?? 0) === 1
            || Org::isManager((int) $user['id'])
        );
    }

    private static function back(string $start, string $anchor = 'semaine'): string
    {
        return '/planning?semaine=' . $start . '#' . $anchor;
    }

    private static function fail(string $start, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect(self::back($start));
    }

    private static function text(Request $request, string $key, int $max): string
    {
        return mb_substr($request->input($key), 0, $max);
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    /** « 2026-09-07T09:00 » : la minute suffit, la seconde n'apporte rien ici. */
    private static function moment(string $day, string $time): ?string
    {
        if (!Validate::date($day) || !self::isTime($time)) {
            return null;
        }
        return $day . 'T' . $time;
    }

    private static function weekOf(Request $request, string $key = 'week'): string
    {
        $raw = $request->input($key);
        return Planning::weekStart(Validate::date($raw) ? $raw : gmdate('Y-m-d'));
    }

    /** Qui l'on peut planifier : un manager ne planifie que les siens. */
    private static function plannablePeople(array $user): array
    {
        if ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1) {
            return Db::all(
                "SELECT id, first_name, last_name, team_id FROM users
                 WHERE active = 1 AND role = 'employee' ORDER BY last_name COLLATE NOCASE"
            );
        }
        return Org::membersManagedBy((int) $user['id']);
    }

    private static function isPlannable(array $user, int $userId): bool
    {
        foreach (self::plannablePeople($user) as $person) {
            if ((int) $person['id'] === $userId) {
                return true;
            }
        }
        return false;
    }

    public static function index(Request $request): Response
    {
        $user = \App\Modules\Users::byId((int) Session::get('user')['id']);
        $requested = Validate::date($request->input('semaine')) ? $request->input('semaine') : gmdate('Y-m-d');
        $start = Planning::weekStart($requested);
        $end = Planning::addDays($start, 6);
        $planner = self::canPlan($user);
        $teamId = (int) $request->input('equipe') ?: null;

        // Sans droit de planification, on ne voit que son propre planning publié —
        // et le sien en entier, brouillon compris, puisqu'il le concerne.
        $view = $planner
            ? Planning::grid($start, ['teamId' => $teamId])
            : Planning::grid($start, ['userIds' => [(int) $user['id']]]);

        return Response::html(View::page('planning/index', [
            'title' => t('nav.planning') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.planning'),
            'headerTitle' => t('pln.title'),
            'headerSubtitle' => $planner
                ? 'Postes, roulements et astreintes de votre périmètre'
                : 'Vos créneaux planifiés',
            'navItems' => [
                ['tab' => 'semaine', 'label' => t('pln.tabWeek')],
                ['tab' => 'astreintes', 'label' => t('pln.tabOnCall'), 'badge' => count(Planning::onCall($start, $end)) ?: null],
                ['tab' => 'roulements', 'label' => t('pln.tabRotations')],
                ['tab' => 'charge', 'label' => t('pln.tabLoad')],
            ],
            'footLinks' => [
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
                ['href' => '/agenda', 'label' => t('nav.agenda')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/meter.js'],
            'planner' => $planner,
            'week' => $view,
            'start' => $start,
            'end' => $end,
            'previousWeek' => Planning::addDays($start, -7),
            'nextWeek' => Planning::addDays($start, 7),
            'teamId' => $teamId,
            'teams' => Org::teams(),
            'kinds' => Planning::KINDS,
            'weekdays' => Planning::WEEKDAYS,
            'templateList' => Planning::templates(),
            'onCallList' => Planning::onCall($start, $end),
            'onCallNow' => Planning::whoIsOnCall(),
            'loadList' => $planner ? Planning::load($start, $end, $teamId) : [],
            'people' => $planner ? self::plannablePeople($user) : [],
            'mine' => Planning::between($start, $end, ['userId' => (int) $user['id']]),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Créneaux ----------

    public static function createShift(Request $request): Response
    {
        $user = \App\Modules\Users::byId((int) Session::get('user')['id']);
        $start = self::weekOf($request);
        $userId = (int) $request->input('user_id');

        if (!self::isPlannable($user, $userId)) {
            return self::fail($start, "Cette personne n'est pas dans votre périmètre.");
        }
        if (!in_array($request->input('kind'), Planning::KINDS, true)) {
            return self::fail($start, 'Type de créneau inconnu.');
        }

        $day = $request->input('day');
        $endDay = $request->input('end_day') !== '' ? $request->input('end_day') : $day;
        $startsAt = self::moment($day, $request->input('start_time'));
        $endsAt = self::moment($endDay, $request->input('end_time'));
        if ($startsAt === null || $endsAt === null) {
            return self::fail($start, 'Date ou horaire invalide.');
        }
        if ($endsAt <= $startsAt) {
            return self::fail($start, 'Le créneau finit avant de commencer.');
        }

        $clash = Planning::conflicts($userId, $startsAt, $endsAt);
        if ($clash['blocked']) {
            return self::fail($start, $clash['leave'] !== []
                ? 'Absence déjà accordée sur ce créneau : le planning ne peut pas la contredire.'
                : 'Cette personne a déjà un créneau qui chevauche celui-ci.');
        }

        $id = Planning::create([
            'userId' => $userId,
            'startsAt' => $startsAt,
            'endsAt' => $endsAt,
            'kind' => $request->input('kind'),
            'label' => self::text($request, 'label', 160),
            'location' => self::text($request, 'location', 160),
            'teamId' => (int) $request->input('team_id') ?: null,
            'published' => $request->input('published') === '1',
            'createdBy' => (int) $user['id'],
        ]);
        Audit::log('planning.creneau_ajoute', 'shifts', $id, [
            'personne' => $userId, 'debut' => $startsAt, 'type' => $request->input('kind'),
        ]);
        Flash::set('success', 'Créneau posé.');
        return Response::redirect(self::back($start));
    }

    public static function deleteShift(Request $request, array $params): Response
    {
        $user = \App\Modules\Users::byId((int) Session::get('user')['id']);
        $shift = Planning::byId((int) $params['id']);
        $start = Planning::weekStart($shift !== null ? substr($shift['starts_at'], 0, 10) : gmdate('Y-m-d'));

        if ($shift === null) {
            return self::fail($start, 'Créneau introuvable.');
        }
        if (!self::isPlannable($user, (int) $shift['user_id'])) {
            return self::fail($start, 'Hors de votre périmètre.');
        }

        Planning::remove((int) $shift['id']);
        Audit::log('planning.creneau_retire', 'shifts', (int) $shift['id'], [
            'personne' => (int) $shift['user_id'], 'debut' => $shift['starts_at'],
        ]);
        Flash::set('success', 'Créneau retiré.');
        return Response::redirect(self::back($start));
    }

    public static function publish(Request $request): Response
    {
        $start = self::weekOf($request);
        $teamId = (int) $request->input('team_id') ?: null;

        $published = Planning::publishWeek($start, $teamId);
        Audit::log('planning.publie', 'shifts', null, ['semaine' => $start, 'creneaux' => $published]);
        Flash::set($published ? 'success' : 'error', $published
            ? "$published créneau(x) publié(s) : la semaine est annoncée."
            : 'Rien à publier sur cette semaine.');
        return Response::redirect(self::back($start));
    }

    // ---------- Roulements ----------

    public static function createTemplate(Request $request): Response
    {
        $start = self::weekOf($request);
        $name = self::text($request, 'name', 120);
        if ($name === '') {
            return self::fail($start, 'Un nom est requis.');
        }
        if (!in_array($request->input('kind'), Planning::KINDS, true)) {
            return self::fail($start, 'Type inconnu.');
        }

        $weekdays = [];
        foreach ($request->inputs('weekdays') as $raw) {
            $day = (int) $raw;
            if ($day >= 1 && $day <= 7) {
                $weekdays[] = $day;
            }
        }
        if ($weekdays === []) {
            return self::fail($start, 'Choisissez au moins un jour.');
        }
        if (!self::isTime($request->input('start_time')) || !self::isTime($request->input('end_time'))) {
            return self::fail($start, 'Horaire invalide.');
        }

        $id = Planning::createTemplate([
            'name' => $name,
            'kind' => $request->input('kind'),
            'startTime' => $request->input('start_time'),
            'endTime' => $request->input('end_time'),
            'weekdays' => $weekdays,
            'location' => self::text($request, 'location', 160),
        ]);
        Audit::log('planning.roulement_cree', 'shift_templates', $id, ['nom' => $name]);
        Flash::set('success', 'Roulement enregistré.');
        return Response::redirect(self::back($start, 'roulements'));
    }

    public static function applyTemplate(Request $request, array $params): Response
    {
        $user = \App\Modules\Users::byId((int) Session::get('user')['id']);
        $start = self::weekOf($request);
        $userId = (int) $request->input('user_id');
        if (!self::isPlannable($user, $userId)) {
            return self::fail($start, "Cette personne n'est pas dans votre périmètre.");
        }

        $from = $request->input('from');
        $to = $request->input('to');
        if (!Validate::date($from) || !Validate::date($to)) {
            return self::fail($start, 'Période invalide.');
        }
        // Un roulement appliqué sur deux ans remplirait la base sans que personne
        // ne l'ait voulu : on borne à un trimestre par application.
        if (Planning::addDays($from, 92) < $to) {
            return self::fail($start, 'Appliquez un roulement sur trois mois au plus.');
        }

        $verdict = Planning::applyTemplate((int) $params['id'], [
            'userId' => $userId,
            'from' => $from,
            'to' => $to,
            'createdBy' => (int) $user['id'],
            'published' => $request->input('published') === '1',
        ]);
        if (!$verdict['ok']) {
            return self::fail($start, $verdict['message']);
        }

        Audit::log('planning.roulement_applique', 'shift_templates', (int) $params['id'], [
            'personne' => $userId, 'du' => $from, 'au' => $to,
            'poses' => count($verdict['created']), 'sautes' => count($verdict['skipped']),
        ]);

        $skipped = array_map(
            static fn (array $row): string => $row['day'] . ' (' . $row['reason'] . ')',
            $verdict['skipped']
        );
        Flash::set('success', $skipped !== []
            ? count($verdict['created']) . ' créneau(x) posé(s), ' . count($skipped)
              . ' jour(s) sauté(s) : ' . implode(', ', $skipped) . '.'
            : count($verdict['created']) . ' créneau(x) posé(s).');
        return Response::redirect(self::back($start, 'roulements'));
    }

    public static function deleteTemplate(Request $request, array $params): Response
    {
        $start = self::weekOf($request);
        Planning::deleteTemplate((int) $params['id']);
        Flash::set('success', 'Roulement supprimé. Les créneaux déjà posés restent.');
        return Response::redirect(self::back($start, 'roulements'));
    }
}
