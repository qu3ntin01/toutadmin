<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Org;
use App\Modules\Support;
use App\Modules\Users;

/**
 * Support.
 *
 * Qui traite quoi : l'administration et les RH voient tout ; la gestion et les
 * managers voient tout sauf les demandes RH, qui parlent de paie, de contrat,
 * parfois de santé. Tout le monde peut ouvrir un ticket et suivre les siens.
 */
final class SupportController
{
    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function agentCategories(array $user): array
    {
        $base = Support::agentCategories($user);
        if ($base !== []) {
            return $base;
        }
        // Un manager encadre : il traite les demandes de son périmètre, hors RH.
        return Org::isManager((int) $user['id'])
            ? array_values(array_filter(Support::CATEGORIES, static fn (string $c): bool => $c !== Support::RESTRICTED_CATEGORY))
            : [];
    }

    private static function refuse(string $message, int $status = 403): Response
    {
        return Response::html(View::page('error', [
            'title' => t('err.notAccessible'),
            'headerTitle' => t('err.notAccessible'),
            'message' => $message,
        ]), $status);
    }

    public static function index(Request $request): Response
    {
        $user = self::user();
        $allowed = self::agentCategories($user);
        $agent = $allowed !== [];
        $filters = [
            'status' => $request->input('statut'),
            'category' => $request->input('categorie'),
            'openOnly' => $request->input('tous') !== '1',
        ];
        $tickets = $agent
            ? Support::list($filters + ['categories' => $allowed])
            : Support::list($filters + ['requesterId' => (int) $user['id']]);

        return Response::html(View::page('support/index', [
            'title' => t('nav.support') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.support'),
            'headerTitle' => t('nav.support'),
            'headerSubtitle' => t('sup.headerSub'),
            'navItems' => array_values(array_filter([
                ['tab' => 'nouveau', 'label' => t('sup.tabOpen')],
                ['tab' => 'tickets', 'label' => t('nav.support')],
                $agent ? ['tab' => 'a-moi', 'label' => t('sup.tabAssigned')] : null,
            ])),
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'isAgent' => $agent,
            'tickets' => array_map(
                static fn (array $tk): array => $tk + ['overdue' => Support::isOverdue($tk)],
                $tickets
            ),
            'mine' => Support::list(['requesterId' => (int) $user['id']]),
            'assigned' => $agent ? Support::list(['assigneeId' => (int) $user['id'], 'openOnly' => true, 'categories' => $allowed]) : [],
            'filters' => $filters,
            'summary' => Support::summary(),
            'categories' => $agent ? Support::CATEGORIES : array_values(array_filter(
                Support::CATEGORIES,
                static fn (string $c): bool => $c !== 'Client'
            )),
            'priorities' => Support::PRIORITIES,
            'statuses' => Support::STATUSES,
            'origins' => Support::ORIGINS,
            'responseHours' => Support::RESPONSE_HOURS,
            'people' => $agent ? Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE') : [],
            // Un ticket peut concerner un client : l'équipe support le rattache
            // au tiers, le demandeur non.
            'partners' => $agent
                ? Db::all("SELECT id, name FROM partners WHERE active = 1 ORDER BY name COLLATE NOCASE")
                : [],
        ]));
    }

    public static function create(Request $request): Response
    {
        $user = self::user();
        $subject = trim($request->input('subject'));
        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/support#nouveau');
        };
        if ($subject === '' || mb_strlen($subject) > 200) {
            return $fail('Objet du ticket invalide.');
        }
        if (!in_array($request->input('category'), Support::CATEGORIES, true)) {
            return $fail('Catégorie invalide.');
        }
        if (!in_array($request->input('priority'), Support::PRIORITIES, true)) {
            return $fail('Priorité invalide.');
        }

        // L'origine « Client » et le rattachement à un partenaire ne sont
        // ouverts qu'aux équipes support : un salarié ouvre un ticket interne.
        $agent = self::agentCategories($user) !== [];
        $origin = $agent && in_array($request->input('origin'), Support::ORIGINS, true) ? $request->input('origin') : 'Interne';

        $id = Support::create([
            'subject' => $subject,
            'body' => mb_substr($request->input('body'), 0, 5000),
            'category' => $request->input('category'),
            'priority' => $request->input('priority'),
            'origin' => $origin,
            'requesterId' => (int) $user['id'],
            'partnerId' => $agent ? ((int) $request->input('partner_id') ?: null) : null,
        ]);
        Flash::set('success', 'Ticket ' . Support::reference($id) . ' ouvert.');
        return Response::redirect("/support/tickets/$id");
    }

    /** Un demandeur voit ses tickets ; un agent, ceux des catégories qu'il traite. */
    private static function visibleTo(array $user, array $ticket): bool
    {
        return (int) $ticket['requester_id'] === (int) $user['id']
            || in_array($ticket['category'], self::agentCategories($user), true);
    }

    public static function show(Request $request, array $params): Response
    {
        $user = self::user();
        $ticket = Support::byId((int) $params['id']);
        if ($ticket === null) {
            return self::refuse('Ticket introuvable.', 404);
        }
        if (!self::visibleTo($user, $ticket)) {
            return self::refuse("Ce ticket n'est pas le vôtre.");
        }
        $agent = self::agentCategories($user) !== [];

        return Response::html(View::page('support/ticket', [
            'title' => $ticket['reference'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.support'),
            'headerTitle' => $ticket['subject'],
            'headerSubtitle' => (string) $ticket['reference'],
            'navItems' => [
                ['href' => '/support', 'label' => t('nav.support')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/confirm.js'],
            'ticket' => $ticket,
            'isAgent' => $agent,
            'overdue' => Support::isOverdue($ticket),
            // Une note interne ne se montre pas au demandeur.
            'messageList' => Support::messages((int) $ticket['id'], $agent),
            'statuses' => Support::STATUSES,
            'priorities' => Support::PRIORITIES,
            'people' => $agent ? Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE') : [],
        ]));
    }

    public static function reply(Request $request, array $params): Response
    {
        $user = self::user();
        $ticket = Support::byId((int) $params['id']);
        if ($ticket === null || !self::visibleTo($user, $ticket)) {
            Flash::set('error', 'Ticket introuvable.');
            return Response::redirect('/support#tickets');
        }
        $body = trim($request->input('body'));
        if ($body === '') {
            Flash::set('error', 'Message vide.');
            return Response::redirect('/support/tickets/' . (int) $ticket['id']);
        }
        $agent = self::agentCategories($user) !== [];
        Support::reply(
            (int) $ticket['id'],
            (int) $user['id'],
            mb_substr($body, 0, 5000),
            $agent && $request->input('internal') === '1'
        );
        return Response::redirect('/support/tickets/' . (int) $ticket['id']);
    }

    /** Les actions de traitement exigent d'avoir la charge de la catégorie. */
    private static function agentFor(array $params): array|Response
    {
        $user = self::user();
        $ticket = Support::byId((int) $params['id']);
        if ($ticket === null || !in_array($ticket['category'], self::agentCategories($user), true)) {
            return self::refuse('Le traitement de ce ticket est réservé aux équipes qui en ont la charge.');
        }
        return [$user, $ticket];
    }

    public static function setStatus(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;
        if (!Support::setStatus((int) $ticket['id'], $request->input('status'))) {
            Flash::set('error', 'Statut invalide.');
        }
        return Response::redirect('/support/tickets/' . (int) $ticket['id']);
    }

    public static function setPriority(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;
        if (!Support::setPriority((int) $ticket['id'], $request->input('priority'))) {
            Flash::set('error', 'Priorité invalide.');
        }
        return Response::redirect('/support/tickets/' . (int) $ticket['id']);
    }

    public static function assign(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;
        Support::assign((int) $ticket['id'], (int) $request->input('assignee_id') ?: null);
        return Response::redirect('/support/tickets/' . (int) $ticket['id']);
    }

    public static function remove(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;
        Support::remove((int) $ticket['id']);
        Flash::set('success', 'Ticket supprimé.');
        return Response::redirect('/support#tickets');
    }
}
