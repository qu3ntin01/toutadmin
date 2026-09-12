<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Rooms;
use App\Modules\Users;

/** Réserver une salle est ouvert à tout le personnel ; la gestion tient le parc. */
final class RoomsController
{
    public static function index(Request $request): Response
    {
        $day = $request->input('jour');
        $date = Validate::date($day) ? $day : gmdate('Y-m-d');
        $rooms = Rooms::all(true);
        $dayBookings = Rooms::bookings($date, $date);
        $userId = (int) Session::get('user')['id'];

        $byRoom = [];
        foreach ($rooms as $room) {
            $byRoom[(int) $room['id']] = array_values(array_filter(
                $dayBookings,
                static fn (array $b): bool => (int) $b['room_id'] === (int) $room['id']
            ));
        }
        $shift = static fn (int $days): string => gmdate('Y-m-d', strtotime("$date $days days"));

        return Response::html(View::page('rooms/index', [
            'title' => t('nav.rooms') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('nav.rooms'),
            'headerSubtitle' => t('erp.bookings'),
            'navItems' => MemberController::nav((array) Users::byId($userId)),
            'scripts' => ['/js/confirm.js'],
            'date' => $date,
            'previousDay' => $shift(-1),
            'nextDay' => $shift(1),
            'today' => gmdate('Y-m-d'),
            'rooms' => $rooms,
            'bookingsByRoom' => $byRoom,
            'myBookings' => array_values(array_filter(
                Rooms::bookings(gmdate('Y-m-d')),
                static fn (array $b): bool => (int) $b['user_id'] === $userId
            )),
        ]));
    }

    public static function book(Request $request): Response
    {
        $date = $request->input('booking_date');
        $back = '/salles?jour=' . (Validate::date($date) ? $date : '');
        $fail = static function (string $message) use ($back): Response {
            Flash::set('error', $message);
            return Response::redirect($back);
        };

        $title = mb_substr($request->input('title'), 0, 140);
        if ($title === '') {
            return $fail("L'objet de la réservation est obligatoire.");
        }
        if (!Validate::date($date)) {
            return $fail('Date invalide.');
        }

        $result = Rooms::book(
            (int) $request->input('room_id'),
            (int) Session::get('user')['id'],
            $title,
            $date,
            $request->input('start_time'),
            $request->input('end_time')
        );
        if (!$result['ok']) {
            if ($result['reason'] === 'clash') {
                $clash = $result['clash'];
                return $fail(sprintf(
                    'Créneau déjà pris par %s %s (%s – %s).',
                    $clash['first_name'], $clash['last_name'], $clash['start_time'], $clash['end_time']
                ));
            }
            $messages = [
                'not-found' => 'Salle introuvable.',
                'inactive' => "Cette salle n'est pas réservable.",
                'bad-time' => 'Horaires invalides.',
                'bad-range' => "L'heure de fin précède l'heure de début.",
            ];
            return $fail($messages[$result['reason']] ?? 'Réservation impossible.');
        }

        Flash::set('success', 'Salle réservée.');
        return Response::redirect($back);
    }

    public static function cancel(Request $request, array $params): Response
    {
        $day = $request->input('jour');
        $ok = Rooms::cancel((int) $params['id'], (int) Session::get('user')['id']);
        Flash::set($ok ? 'success' : 'error', $ok
            ? 'Réservation annulée.'
            : 'Vous ne pouvez annuler que vos propres réservations.');
        return Response::redirect('/salles?jour=' . (Validate::date($day) ? $day : ''));
    }
}
