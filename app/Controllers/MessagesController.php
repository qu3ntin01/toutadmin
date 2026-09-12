<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Messages;
use App\Modules\Users;

/** Boîte de réception, boîte d'envoi, et le message ouvert. */
final class MessagesController
{
    public static function index(Request $request): Response
    {
        $userId = (int) Session::get('user')['id'];
        $box = $request->input('boite') === 'envoyes' ? 'envoyes' : 'reception';
        $opened = $request->input('message') !== '' ? Messages::open((int) $request->input('message'), $userId) : null;

        return Response::html(View::page('messages/index', [
            'title' => t('nav.messages') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('messages.title'),
            'headerSubtitle' => t('messages.subtitle'),
            'navItems' => MemberController::nav((array) Users::byId($userId)),
            'scripts' => ['/js/confirm.js'],
            'box' => $box,
            'inbox' => Messages::inbox($userId),
            'sent' => Messages::sent($userId),
            'opened' => $opened,
            'contacts' => Messages::contacts($userId),
            'unread' => Messages::unreadCount($userId),
            // Adresse et serveurs viennent de l'administration : ils s'affichent,
            // ils ne se règlent pas ici.
            'account' => Db::get('SELECT mail_address, mail_imap_host, mail_smtp_host FROM users WHERE id = ?', [$userId]),
        ]));
    }

    public static function send(Request $request): Response
    {
        $result = Messages::send(
            (int) Session::get('user')['id'],
            (int) $request->input('recipient_id'),
            $request->input('subject'),
            $request->input('body'),
            $request->input('parent_id') !== '' ? (int) $request->input('parent_id') : null
        );
        if (!$result['ok']) {
            Flash::set('error', $result['message']);
            return Response::redirect('/messagerie');
        }
        Flash::set('success', 'Message envoyé.');
        return Response::redirect('/messagerie?boite=envoyes');
    }

    public static function remove(Request $request, array $params): Response
    {
        Messages::remove((int) $params['id'], (int) Session::get('user')['id']);
        Flash::set('success', 'Message supprimé.');
        return Response::redirect('/messagerie');
    }
}
