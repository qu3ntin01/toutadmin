<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\AiConfidentialite;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Ai;
use App\Modules\Assistance;
use App\Modules\Org;
use App\Modules\Corbeille;
use App\Modules\CustomFields;
use App\Modules\Kanban;
use App\Modules\Support;
use App\Modules\Users;
use App\Modules\Societes;

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

    /**
     * Qui traite quoi : trois sources, réunies.
     *
     * Les rôles — l'administration et les RH voient tout, la gestion tout sauf
     * les demandes RH —, les groupes de support que l'administration compose, et
     * l'encadrement, qui traite son périmètre hors RH.
     *
     * Les groupes ajoutent, ils ne retirent pas. Créer un premier groupe ne doit
     * pas fermer la file aux RH ni à l'administration : l'entreprise le
     * découvrirait le jour même, en ne voyant plus personne répondre.
     */
    private static function agentCategories(array $user): array
    {
        $base = Support::categoriesFor($user);
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
            'groupId' => (int) $request->input('groupe'),
            'openOnly' => $request->input('tous') !== '1',
        ];
        $tickets = $agent
            ? Support::list($filters + ['categories' => $allowed])
            : Support::list($filters + ['requesterId' => (int) $user['id']]);

        /*
         * La vue : la même liste, le même filtrage, rangée autrement. Le kanban
         * n'est offert qu'à qui traite les demandes — déplacer une carte est une
         * action, et un demandeur n'en a aucune à faire sur sa propre demande.
         */
        $vue = $agent && $request->input('vue') === 'kanban' ? 'kanban' : 'liste';

        /* Les liens de bascule gardent la requête en cours : changer de vue ne
           doit pas perdre le filtre que la personne vient de poser. */
        $lienVue = static function (string $laquelle) use ($request): string {
            $parametres = [];
            foreach (['statut' => 'statut', 'categorie' => 'categorie', 'groupe' => 'groupe', 'tous' => 'tous'] as $cle) {
                $valeur = $request->input($cle);
                if ($valeur !== '') {
                    $parametres[$cle] = $valeur;
                }
            }
            $parametres['vue'] = $laquelle;
            return '/support?' . http_build_query($parametres);
        };

        return Response::html(View::page('support/index', [
            'vue' => $vue,
            'tableau' => $vue === 'kanban'
                ? Kanban::tableau('ticket', $filters + ['categories' => $allowed])
                : null,
            'kanbanColonnes' => Kanban::colonnesDe('ticket'),
            'lienVue' => $lienVue,
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
            // Les délais en vigueur sur cette instance, et non ceux d'origine :
            // l'écran qui les affiche doit dire ce que le serveur appliquera.
            'responseHours' => array_combine(
                Support::PRIORITIES,
                array_map(static fn (string $p): int => Support::responseHours($p), Support::PRIORITIES)
            ),
            'people' => $agent ? Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE') : [],
            // Un ticket peut concerner un client : l'équipe support le rattache
            // au tiers, le demandeur non.
            'partners' => $agent
                ? Db::all(
                    "SELECT id, name FROM partners WHERE company_id = ? AND active = 1 ORDER BY name COLLATE NOCASE",
                    [Societes::courant()],
                )
                : [],
            // Les files, pour filtrer et pour transférer. Un salarié qui n'est
            // pas agent n'en a pas besoin, et ne les reçoit pas.
            'groups' => $agent ? Support::groups() : [],
            'myGroups' => Support::groupsOf((int) $user['id']),
            'canManageGroups' => $user['role'] === 'admin',
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

        $garde = Session::get('assistance');
        $propose = is_array($garde) && (int) ($garde['ticketId'] ?? 0) === (int) $ticket['id'] ? $garde : [];
        if (is_array($garde)) {
            Session::forget('assistance');
        }

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
            /*
             * Ce que l'assistance vient de proposer, s'il y a lieu.
             *
             * La proposition vit dans la session, le temps d'un aller-retour, et
             * pas en base : ce n'est pas un document de l'entreprise, c'est un
             * brouillon. Elle se lit une fois — rouvrir le ticket demain ne doit
             * pas ressortir un résumé d'hier.
             */
            'resumeIa' => $propose['resume'] ?? null,
            'brouillonIa' => (string) ($propose['brouillon'] ?? ''),
            'resumeOffert' => $agent && Ai::fonctionPrete('resume'),
            'brouillonOffert' => $agent && Ai::fonctionPrete('redaction'),
            'overdue' => Support::isOverdue($ticket),
            // Une note interne ne se montre pas au demandeur.
            'messageList' => Support::messages((int) $ticket['id'], $agent),
            'statuses' => Support::STATUSES,
            'priorities' => Support::PRIORITIES,
            'people' => $agent ? Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE') : [],
            // Les files, pour transférer. Un demandeur ne les reçoit pas : savoir
            // quels groupes existent n'est pas son affaire.
            'groups' => $agent ? Support::groups() : [],
            // Les champs que l'entreprise a ajoutés aux tickets. Ils ne
            // s'affichent que pour qui traite la demande : c'est un classement
            // interne, pas une question posée au demandeur.
            'champsPerso' => $agent ? CustomFields::valeurs('ticket', (int) $ticket['id']) : [],
        ]));
    }

    /**
     * Deux coups de main sur un fil : le résumer, et proposer une réponse.
     *
     * Ni l'un ni l'autre n'écrit quoi que ce soit. Le résumé s'affiche une fois ;
     * le brouillon atterrit dans le champ de saisie, où il s'édite et d'où il faut
     * encore appuyer sur « Répondre ». Un service éteint, ou en panne, laisse
     * l'écran exactement tel qu'il était avant que ces boutons existent.
     */
    private static function coupDeMain(array $params, string $quoi): Response
    {
        $user = self::user();
        $ticket = Support::byId((int) $params['id']);
        if ($ticket === null) {
            Flash::set('error', 'Ticket introuvable.');
            return Response::redirect('/support#tickets');
        }

        $cible = '/support/tickets/' . (int) $ticket['id'];
        if (self::agentCategories($user) === []) {
            Flash::set('error', 'Réservé à qui traite les demandes.');
            return Response::redirect($cible);
        }

        $fil = [
            'titre' => (string) $ticket['subject'],
            'categorie' => (string) $ticket['category'],
            'priorite' => (string) $ticket['priority'],
            'demande' => (string) $ticket['body'],
            'echanges' => Support::messages((int) $ticket['id'], true),
        ];

        $verdict = $quoi === 'resume' ? Assistance::resumer($fil) : Assistance::brouillon($fil);
        if (empty($verdict['ok'])) {
            Flash::set('error', (string) ($verdict['message'] ?? ''));
            return Response::redirect($cible);
        }

        Session::set('assistance', $quoi === 'resume'
            ? ['ticketId' => (int) $ticket['id'], 'resume' => $verdict['resume']]
            : ['ticketId' => (int) $ticket['id'], 'brouillon' => $verdict['brouillon']]);

        // Ce que la porte a retiré se dit : c'est rassurant, et c'est vrai.
        $retires = AiConfidentialite::combien($verdict['retires'] ?? []);
        Flash::set('success', $retires > 0
            ? "Proposition de l'assistance. " . $retires . ' donnée(s) sensible(s) retirée(s) avant l’envoi.'
            : "Proposition de l'assistance. À relire avant de s'en servir.");
        return Response::redirect($cible);
    }

    public static function summarise(Request $request, array $params): Response
    {
        return self::coupDeMain($params, 'resume');
    }

    public static function draftReply(Request $request, array $params): Response
    {
        return self::coupDeMain($params, 'brouillon');
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
        Corbeille::jeterPuis('ticket', (int) $ticket['id'], (int) Session::get('user')['id'],
            static fn (): mixed => Support::remove((int) $ticket['id']));
        CustomFields::oublier('ticket', (int) $ticket['id']);
        Flash::set('success', 'Ticket supprimé.');
        return Response::redirect('/support#tickets');
    }

    /**
     * Les champs personnalisés du ticket, dans leur propre formulaire.
     *
     * Le fil n'a pas d'écran d'édition où les glisser — un ticket ne se réécrit
     * pas — donc ils ont le leur, qui n'enregistre qu'eux.
     */
    public static function saveFields(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;
        $cible = '/support/tickets/' . (int) $ticket['id'];

        $refus = CustomFields::refus('ticket', (int) $ticket['id'], $request->body);
        if ($refus !== null) {
            Flash::set('error', $refus);
            return Response::redirect($cible);
        }

        Flash::set('success', 'Champs enregistrés.');
        return Response::redirect($cible);
    }
/*
     * Transférer une demande d'un groupe à l'autre.
     *
     * C'est l'acte qui rend les files utiles : une demande mal orientée se
     * déplace au lieu d'être réécrite. Le groupe vide la retire de toute file et
     * la rend aux rôles — mieux que de la laisser dans une file où personne ne
     * la lit.
     */
    public static function setGroup(Request $request, array $params): Response
    {
        $found = self::agentFor($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $ticket] = $found;

        $voulu = (int) $request->input('group_id');
        if (!Support::setTicketGroup((int) $ticket['id'], $voulu)) {
            Flash::set('error', 'Groupe de support introuvable.');
            return Response::redirect('/support/tickets/' . (int) $ticket['id']);
        }
        $groupe = $voulu > 0 ? Support::groupById($voulu) : null;
        Audit::log('ticket.transfere', 'tickets', (int) $ticket['id'], ['groupe' => $groupe['name'] ?? null]);
        Flash::set('success', $groupe === null
            ? 'Demande retirée de sa file.'
            : 'Demande transférée au groupe « ' . $groupe['name'] . ' ».');
        return Response::redirect('/support/tickets/' . (int) $ticket['id']);
    }

    // ---------- Les groupes de support ----------

    /** Composer les groupes est une décision d'instance : elle appartient à l'administration. */
    private static function exigerAdmin(): ?Response
    {
        $user = self::user();
        return $user['role'] === 'admin'
            ? null
            : self::refuse('La composition des groupes de support relève de l’administration.');
    }

    private static function versGroupes(string $anchor): Response
    {
        return Response::redirect('/support/groupes#' . $anchor);
    }

    private static function echecGroupes(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::versGroupes($anchor);
    }

    /*
     * L'écran d'administration du support.
     *
     * Il répond à une question que trois écrans ne répondaient pas : si Claire
     * part demain, qui lit les demandes informatiques ? La carte des accès la
     * pose une fois, catégorie par catégorie, au lieu de la faire reconstituer
     * de tête.
     */
    public static function groups(Request $request): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }

        $groupes = array_map(
            static fn (array $g): array => $g + ['members' => Support::groupMembers((int) $g['id'])],
            Support::groups(),
        );

        return Response::html(View::page('support/groupes', [
            'title' => t('sup.grp.title') . ' — ' . t('app.name'),
            'panelLabel' => t('sup.grp.title'),
            'headerTitle' => t('sup.grp.title'),
            'headerSubtitle' => t('sup.grp.sub'),
            'navItems' => [
                ['tab' => 'acces', 'label' => t('sup.grp.tabAccess')],
                ['tab' => 'groupes', 'label' => t('sup.grp.tabGroups'), 'badge' => count($groupes) ?: null],
                ['tab' => 'nouveau', 'label' => t('sup.grp.tabNew')],
            ],
            'footLinks' => [['href' => '/support', 'label' => t('nav.support')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'groups' => $groupes,
            'access' => Support::accessMap(),
            'categories' => Support::CATEGORIES,
            'restricted' => Support::RESTRICTED_CATEGORY,
            'people' => Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'),
        ]));
    }

    public static function createGroup(Request $request): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }

        $nom = trim($request->input('name'));
        if ($nom === '' || mb_strlen($nom) > 120) {
            return self::echecGroupes('nouveau', 'Nom de groupe invalide.');
        }

        $id = Support::createGroup($nom, mb_substr(trim($request->input('description')), 0, 500));
        if ($id === null) {
            return self::echecGroupes('nouveau', 'Nom de groupe invalide.');
        }

        Support::setGroupCategories($id, $request->inputs('categories'));
        Audit::log('support.groupe_cree', 'support_groups', $id, ['nom' => $nom]);
        Flash::set('success', 'Groupe « ' . $nom . ' » créé.');
        return self::versGroupes('groupes');
    }

    public static function renameGroup(Request $request, array $params): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }

        $nom = trim($request->input('name'));
        if ($nom === '' || mb_strlen($nom) > 120) {
            return self::echecGroupes('groupes', 'Nom de groupe invalide.');
        }
        $id = (int) ($params['id'] ?? 0);
        if (!Support::renameGroup($id, $nom, mb_substr(trim($request->input('description')), 0, 500))) {
            return self::echecGroupes('groupes', 'Groupe introuvable.');
        }
        Audit::log('support.groupe_modifie', 'support_groups', $id, ['nom' => $nom]);
        Flash::set('success', 'Groupe enregistré.');
        return self::versGroupes('groupes');
    }

    /*
     * Les catégories d'un groupe, remplacées d'un bloc.
     *
     * Une case décochée n'est pas envoyée par le navigateur : l'absence doit
     * donc valoir « non ». Le contraire rendrait une catégorie impossible à
     * retirer depuis l'écran.
     */
    public static function setGroupCategories(Request $request, array $params): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $id = (int) ($params['id'] ?? 0);
        if (!Support::setGroupCategories($id, $request->inputs('categories'))) {
            return self::echecGroupes('groupes', 'Groupe introuvable.');
        }
        $groupe = Support::groupById($id);
        Audit::log('support.groupe_categories', 'support_groups', $id, ['categories' => $groupe['categories']]);
        Flash::set('success', 'Prise en charge du groupe « ' . $groupe['name'] . ' » enregistrée.');
        return self::versGroupes('groupes');
    }

    public static function addGroupMember(Request $request, array $params): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $id = (int) ($params['id'] ?? 0);
        if (!Support::addGroupMember($id, (int) $request->input('user_id'))) {
            return self::echecGroupes('groupes', 'Groupe ou personne introuvable.');
        }
        Audit::log('support.groupe_membre_ajoute', 'support_groups', $id, ['personne' => (int) $request->input('user_id')]);
        Flash::set('success', 'Personne ajoutée au groupe.');
        return self::versGroupes('groupes');
    }

    public static function removeGroupMember(Request $request, array $params): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $id = (int) ($params['id'] ?? 0);
        if (!Support::removeGroupMember($id, (int) $request->input('user_id'))) {
            return self::echecGroupes('groupes', 'Cette personne n’est pas dans ce groupe.');
        }
        Audit::log('support.groupe_membre_retire', 'support_groups', $id, ['personne' => (int) $request->input('user_id')]);
        Flash::set('success', 'Personne retirée du groupe.');
        return self::versGroupes('groupes');
    }

    public static function deleteGroup(Request $request, array $params): Response
    {
        $refus = self::exigerAdmin();
        if ($refus !== null) {
            return $refus;
        }
        $id = (int) ($params['id'] ?? 0);
        $groupe = Support::groupById($id);
        if ($groupe === null) {
            return self::echecGroupes('groupes', 'Groupe introuvable.');
        }
        Support::deleteGroup($id);
        Audit::log('support.groupe_supprime', 'support_groups', $id, ['nom' => $groupe['name']]);
        Flash::set('success', 'Groupe « ' . $groupe['name'] . ' » supprimé. Ses demandes restent visibles par les rôles.');
        return self::versGroupes('groupes');
    }
}
