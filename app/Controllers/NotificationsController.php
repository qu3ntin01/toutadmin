<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Notifications;

/** Les notifications d'une personne, et elles seules. */
final class NotificationsController
{

    /**
     * « Rien n'a été effacé » : le message d'échec et le retour, en une ligne.
     *
     * Une suppression qui ne trouve pas sa ligne annonçait le succès quand même.
     * C'est l'onglet resté ouvert sur une ligne qu'un collègue vient d'effacer :
     * le produit répondait « Supprimé. » et la personne repartait en croyant
     * avoir fait quelque chose.
     */
    private static function absent(string $destination, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($destination);
    }
    public static function index(Request $request): Response
    {
        $userId = (int) Session::get('user')['id'];
        return Response::html(View::page('notifications/index', [
            'title' => t('nav.notifications') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('nav.notifications'),
            'headerSubtitle' => t('ntf.headerSub'),
            'navItems' => MemberController::nav((array) \App\Modules\Users::byId($userId)),
            'scripts' => ['/js/confirm.js'],
            'notifications' => Notifications::forUser($userId),
            'unread' => Notifications::unreadCount($userId),
        ]));
    }

    public static function markRead(Request $request, array $params): Response
    {
        Notifications::markRead((int) $params['id'], (int) Session::get('user')['id']);
        return Response::redirect('/notifications');
    }

    public static function markAllRead(Request $request): Response
    {
        $count = Notifications::markAllRead((int) Session::get('user')['id']);
        Flash::set('success', $count . ' ' . t('ntf.markRead'));
        return Response::redirect('/notifications');
    }

    public static function remove(Request $request, array $params): Response
    {
        if (!Notifications::remove((int) $params['id'], (int) Session::get('user')['id'])) {
            return self::absent('/notifications', 'Notification introuvable.');
        }

        return Response::redirect('/notifications');
    }
}
