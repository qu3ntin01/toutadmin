<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Calendar;
use App\Modules\Users;

/** L'agenda d'une personne : ce qu'elle saisit, et ce que le reste du site y pose. */
final class AgendaController
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function index(Request $request): Response
    {
        $user = (array) Users::byId((int) Session::get('user')['id']);
        $month = Calendar::normalizeMonth($request->input('mois'));
        $canShare = !empty($user['team_id']) || !empty($user['department_id']);
        $shared = $canShare && $request->input('vue') === 'equipe';

        return Response::html(View::page('agenda/index', [
            'title' => t('agenda.title') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('agenda.title'),
            'headerSubtitle' => t('agenda.subtitle'),
            'navItems' => MemberController::nav($user),
            'scripts' => ['/js/confirm.js'],
            'month' => $month,
            'previousMonth' => Calendar::shiftMonth($month, -1),
            'nextMonth' => Calendar::shiftMonth($month, 1),
            'weeks' => Calendar::buildGrid($month),
            'agenda' => Calendar::monthAgenda($user, $month, false, $shared),
            'upcoming' => Calendar::upcoming($user),
            'categories' => Calendar::EVENT_CATEGORIES,
            'visibilities' => Calendar::VISIBILITIES,
            'canShare' => $canShare,
            'shared' => $shared,
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function create(Request $request): Response
    {
        $month = Calendar::normalizeMonth($request->input('mois'));
        $back = "/agenda?mois=$month";
        $fail = static function (string $message) use ($back): Response {
            Flash::set('error', $message);
            return Response::redirect($back);
        };

        $title = mb_substr($request->input('title'), 0, 140);
        $start = $request->input('start_date');
        $end = $request->input('end_date') ?: $start;
        $allDay = $request->input('all_day') === 'on';
        $startTime = $request->input('start_time');
        $endTime = $request->input('end_time');
        $category = $request->input('category');
        $visibility = $request->input('visibility', 'Privé');

        if ($title === '') {
            return $fail("L'intitulé de l'événement est obligatoire.");
        }
        if (!Validate::date($start)) {
            return $fail('Date de début invalide.');
        }
        if (!Validate::date($end)) {
            return $fail('Date de fin invalide.');
        }
        if ($end < $start) {
            return $fail('La date de fin précède la date de début.');
        }
        if ($category !== '' && !in_array($category, Calendar::EVENT_CATEGORIES, true)) {
            return $fail('Catégorie invalide.');
        }
        if (!in_array($visibility, Calendar::VISIBILITIES, true)) {
            return $fail('Portée de partage invalide.');
        }
        // Un créneau horaire n'a de sens que sur un événement qui n'occupe pas
        // la journée entière.
        if (!$allDay) {
            if ($startTime !== '' && !preg_match(self::TIME, $startTime)) {
                return $fail('Heure de début invalide.');
            }
            if ($endTime !== '' && !preg_match(self::TIME, $endTime)) {
                return $fail('Heure de fin invalide.');
            }
            if ($startTime !== '' && $endTime !== '' && $start === $end && $endTime < $startTime) {
                return $fail("L'heure de fin précède l'heure de début.");
            }
        }

        Calendar::createEvent([
            'userId' => (int) Session::get('user')['id'],
            'title' => $title,
            'description' => mb_substr($request->input('description'), 0, 2000),
            'location' => mb_substr($request->input('location'), 0, 140),
            'startDate' => $start,
            'endDate' => $end,
            'startTime' => $allDay ? '' : $startTime,
            'endTime' => $allDay ? '' : $endTime,
            'allDay' => $allDay,
            'category' => $category,
            'visibility' => $visibility,
        ]);
        Flash::set('success', 'Événement ajouté à votre agenda.');
        return Response::redirect($back);
    }

    /** Ouvrir ou refermer le partage d'un événement déjà créé. */
    public static function share(Request $request, array $params): Response
    {
        $month = Calendar::normalizeMonth($request->input('mois'));
        $visibility = $request->input('visibility');
        $ok = Calendar::setVisibility((int) $params['id'], (int) Session::get('user')['id'], $visibility);
        Flash::set($ok ? 'success' : 'error', $ok
            ? ($visibility === 'Privé'
                ? 'Événement redevenu privé.'
                : 'Événement partagé avec votre ' . mb_strtolower($visibility) . '.')
            : 'Partage impossible : événement introuvable ou portée invalide.');
        return Response::redirect("/agenda?mois=$month");
    }

    public static function delete(Request $request, array $params): Response
    {
        $month = Calendar::normalizeMonth($request->input('mois'));
        $ok = Calendar::deleteEvent((int) $params['id'], (int) Session::get('user')['id']);
        Flash::set($ok ? 'success' : 'error', $ok ? 'Événement supprimé.' : 'Événement introuvable.');
        return Response::redirect("/agenda?mois=$month");
    }
}
