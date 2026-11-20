<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\MemberMail;
use App\Modules\Messages;
use App\Modules\Users;

/** Boîte de réception, boîte d'envoi, et le message ouvert. */
final class MessagesController
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
        $box = $request->input('boite') === 'envoyes' ? 'envoyes' : 'reception';
        $opened = $request->input('message') !== '' ? Messages::open((int) $request->input('message'), $userId) : null;

        $account = Db::get(
            'SELECT mail_address, mail_imap_host, mail_smtp_host, mail_sync_enabled, mail_synced_at
             FROM users WHERE id = ?',
            [$userId]
        );
        $external = ($account !== null && (int) $account['mail_sync_enabled'] === 1)
            ? MemberMail::inbox($userId)
            : [];

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
            'account' => $account,
            // Messagerie externe : ce que la relève a rapporté de la boîte du membre.
            // Personne d'autre que lui ne peut le lire — pas même l'administration,
            // qui a pourtant posé les identifiants.
            'external' => $external,
            'openedExternal' => $request->input('externe') !== ''
                ? MemberMail::message((int) $request->input('externe'), $userId)
                : null,
        ]));
    }

    /**
     * Le membre efface la copie locale de sa boîte. Le serveur de courriel, lui,
     * n'est pas touché : ses messages y sont toujours, et la relève suivante peut
     * les rapporter. C'est une copie de lecture, pas une archive.
     */
    public static function forgetExternal(Request $request): Response
    {
        $removed = MemberMail::forget((int) Session::get('user')['id']);
        Flash::set('success', $removed . ' message(s) relevé(s) effacé(s) de la copie locale.');
        return Response::redirect('/messagerie');
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
        if (!Messages::remove((int) $params['id'], (int) Session::get('user')['id'])) {
            return self::absent('/messagerie', 'Message introuvable.');
        }

        Flash::set('success', 'Message supprimé.');
        return Response::redirect('/messagerie');
    }
}
