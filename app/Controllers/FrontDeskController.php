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
use App\Modules\FrontDesk;

/**
 * Accueil : visiteurs et courrier.
 *
 * Les deux registres tiennent des données personnelles de tiers : ils restent
 * entre les mains de l'administration et des RH, comme les autres registres de
 * l'entreprise.
 */
final class FrontDeskController
{
    public static function canAccess(?array $user): bool
    {
        return HrController::canAccess($user);
    }

    private static function back(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/accueil#' . $anchor);
    }

    private static function text(Request $request, string $key, int $max): string
    {
        return mb_substr($request->input($key), 0, $max);
    }

    private static function isTime(string $value): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value);
    }

    public static function index(Request $request): Response
    {
        $filterDay = Validate::date($request->input('jour')) ? $request->input('jour') : null;
        $stats = FrontDesk::summary();

        return Response::html(View::page('frontdesk/index', [
            'title' => t('nav.frontDeskMail') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.frontDesk'),
            'headerTitle' => t('nav.frontDesk'),
            'headerSubtitle' => t('acc.headerSub'),
            'navItems' => [
                ['tab' => 'visiteurs', 'label' => t('acc.tabVisitors'), 'badge' => $stats['presentNow'] ?: null],
                ['tab' => 'courrier', 'label' => t('acc.tabMail'), 'badge' => $stats['pendingMail'] ?: null],
            ],
            'footLinks' => [
                ['href' => '/rh', 'label' => t('nav.hrSpace')],
                ['href' => '/salles', 'label' => t('nav.rooms')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'stats' => $stats,
            'presentList' => FrontDesk::present(),
            'visitorList' => FrontDesk::visitors($filterDay),
            'filterDay' => $filterDay,
            'incoming' => FrontDesk::mail('Entrant'),
            'outgoing' => FrontDesk::mail('Sortant'),
            'mailKinds' => FrontDesk::MAIL_KINDS,
            'mailDirections' => FrontDesk::MAIL_DIRECTIONS,
            'employees' => Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'),
            'today' => gmdate('Y-m-d'),
            'now' => gmdate('H:i'),
        ]));
    }

    // ---------- Visiteurs ----------

    public static function checkIn(Request $request): Response
    {
        $lastName = self::text($request, 'last_name', 120);
        if ($lastName === '') {
            return self::back('visiteurs', 'error', 'Le nom du visiteur est requis.');
        }

        $visitedOn = Validate::date($request->input('visited_on')) ? $request->input('visited_on') : gmdate('Y-m-d');
        $arrivedAt = self::isTime($request->input('arrived_at')) ? $request->input('arrived_at') : gmdate('H:i');
        $company = self::text($request, 'company', 160);

        $id = FrontDesk::checkIn([
            'firstName' => self::text($request, 'first_name', 120),
            'lastName' => $lastName,
            'company' => $company,
            'purpose' => self::text($request, 'purpose', 300),
            'hostId' => (int) $request->input('host_id') ?: null,
            'badge' => self::text($request, 'badge', 40),
            'notes' => self::text($request, 'notes', 500),
            'createdBy' => (int) Session::get('user')['id'],
            'visitedOn' => $visitedOn,
            'arrivedAt' => $arrivedAt,
        ]);
        Audit::log('accueil.visiteur_entre', 'visitors', $id, ['nom' => $lastName, 'societe' => $company]);
        return self::back('visiteurs', 'success', 'Visiteur inscrit au registre.');
    }

    public static function checkOut(Request $request, array $params): Response
    {
        $at = self::isTime($request->input('departed_at')) ? $request->input('departed_at') : gmdate('H:i');
        if (!FrontDesk::checkOut((int) $params['id'], $at)) {
            return self::back('visiteurs', 'error', "Ce visiteur est déjà sorti, ou n'existe pas.");
        }
        Audit::log('accueil.visiteur_sorti', 'visitors', (int) $params['id'], ['heure' => $at]);
        return self::back('visiteurs', 'success', 'Sortie enregistrée.');
    }

    public static function deleteVisitor(Request $request, array $params): Response
    {
        FrontDesk::deleteVisitor((int) $params['id']);
        Audit::log('accueil.visiteur_supprime', 'visitors', (int) $params['id']);
        return self::back('visiteurs', 'success', 'Visite retirée du registre.');
    }

    // ---------- Courrier ----------

    public static function logMail(Request $request): Response
    {
        if (!in_array($request->input('direction'), FrontDesk::MAIL_DIRECTIONS, true)) {
            return self::back('courrier', 'error', 'Sens inconnu.');
        }
        if (!in_array($request->input('kind'), FrontDesk::MAIL_KINDS, true)) {
            return self::back('courrier', 'error', 'Nature de courrier inconnue.');
        }

        $loggedOn = Validate::date($request->input('logged_on')) ? $request->input('logged_on') : gmdate('Y-m-d');
        $subject = self::text($request, 'subject', 300);
        $correspondent = self::text($request, 'correspondent', 200);
        if ($subject === '' && $correspondent === '') {
            return self::back('courrier', 'error', 'Indiquez au moins un objet ou un correspondant.');
        }

        $id = FrontDesk::logMail([
            'direction' => $request->input('direction'),
            'loggedOn' => $loggedOn,
            'kind' => $request->input('kind'),
            'correspondent' => $correspondent,
            'recipientId' => (int) $request->input('recipient_id') ?: null,
            'recipientLabel' => self::text($request, 'recipient_label', 200),
            'tracking' => self::text($request, 'tracking', 80),
            'subject' => $subject,
            'notes' => self::text($request, 'notes', 500),
        ]);
        Audit::log('accueil.courrier_enregistre', 'mail_items', $id, [
            'sens' => $request->input('direction'), 'nature' => $request->input('kind'),
        ]);
        return self::back('courrier', 'success', 'Courrier enregistré.');
    }

    public static function handOver(Request $request, array $params): Response
    {
        if (!FrontDesk::handOver((int) $params['id'], (int) Session::get('user')['id'])) {
            return self::back('courrier', 'error', 'Ce courrier a déjà été remis.');
        }
        Audit::log('accueil.courrier_remis', 'mail_items', (int) $params['id']);
        return self::back('courrier', 'success', 'Remise enregistrée, datée et signée.');
    }

    public static function archiveMail(Request $request, array $params): Response
    {
        FrontDesk::archiveMail((int) $params['id']);
        return self::back('courrier');
    }

    public static function deleteMail(Request $request, array $params): Response
    {
        FrontDesk::deleteMail((int) $params['id']);
        Audit::log('accueil.courrier_supprime', 'mail_items', (int) $params['id']);
        return self::back('courrier', 'success', 'Courrier retiré du registre.');
    }
}
