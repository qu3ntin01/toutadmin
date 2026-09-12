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
use App\Modules\Org;
use App\Modules\Projects;

/**
 * Projets.
 *
 * Trois cercles, et non deux. L'administration et la gestion voient et
 * conduisent tous les projets : c'est leur périmètre. Un manager peut en ouvrir
 * — son équipe en a besoin — mais ne conduit que ceux dont il est responsable :
 * encadrer une équipe ne donne aucun droit sur le projet d'une autre. Les
 * membres participent et saisissent leur temps.
 */
final class ProjectsController
{
    private static function user(): array
    {
        return (array) \App\Modules\Users::byId((int) Session::get('user')['id']);
    }

    private static function isSteward(array $user): bool
    {
        return $user['role'] === 'admin' || (int) ($user['is_finance'] ?? 0) === 1;
    }

    /** Peut ouvrir un projet : la gestion, et quiconque encadre un périmètre. */
    private static function canCreate(array $user): bool
    {
        return self::isSteward($user) || Org::isManager((int) $user['id']);
    }

    private static function canManage(array $user, ?array $project): bool
    {
        return self::isSteward($user) || ($project !== null && (int) $project['lead_id'] === (int) $user['id']);
    }

    /** Un projet n'est ouvert qu'à son équipe, son responsable et la gestion. */
    private static function visibleTo(array $user, array $project): bool
    {
        if (self::isSteward($user) || (int) $project['lead_id'] === (int) $user['id']) {
            return true;
        }
        return Db::get(
            'SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?',
            [$project['id'], $user['id']]
        ) !== null;
    }

    private static function refuse(string $message): Response
    {
        return Response::html(View::page('error', [
            'title' => t('err.notAccessible'),
            'headerTitle' => t('err.notAccessible'),
            'message' => $message,
        ]), 403);
    }

    private static function back(string $target, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($target);
    }

    private static function amount(string $raw, float $max = 1000000000): float|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], $trimmed);
        if (!is_numeric($value)) {
            return false;
        }
        $number = round((float) $value, 2);
        return $number < 0 || $number > $max ? false : $number;
    }

    private static function date(string $raw, bool $required = false): string|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return $required ? false : null;
        }
        return Validate::date($trimmed) ? $trimmed : false;
    }

    private static function people(): array
    {
        return Db::all(
            'SELECT id, first_name, last_name, grade FROM users WHERE active = 1
             ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE'
        );
    }

    // ---------- Vue d'ensemble ----------

    public static function index(Request $request): Response
    {
        $user = self::user();
        $steward = self::isSteward($user);
        $showArchived = $request->input('archives') === '1';
        $list = $steward ? Projects::list($showArchived) : Projects::forUser((int) $user['id']);

        return Response::html(View::page('projects/index', [
            'title' => t('nav.projects') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.projects'),
            'headerTitle' => t('nav.projects'),
            'headerSubtitle' => t('prj.headerSub'),
            'navItems' => [
                ['tab' => 'projets', 'label' => t('nav.projects')],
                ['tab' => 'mes-taches', 'label' => t('prj.tabMyTasks')],
                ['tab' => 'mon-temps', 'label' => t('prj.tabMyTime')],
            ],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'canManage' => self::canCreate($user),
            'showArchived' => $showArchived,
            'projectList' => array_map(
                static fn (array $p): array => $p + ['profit' => Projects::profitability($p)],
                $list
            ),
            'myTasks' => Projects::tasksOf((int) $user['id']),
            'myTime' => array_slice(Projects::timeOf((int) $user['id']), 0, 40),
            'summary' => Projects::summary(),
            'statuses' => Projects::STATUSES,
            'people' => self::people(),
            'departments' => Org::departments(),
            'teams' => Org::teams(),
        ]));
    }

    public static function create(Request $request): Response
    {
        $user = self::user();
        if (!self::canCreate($user)) {
            return self::refuse("L'ouverture d'un projet est réservée à la gestion et aux managers.");
        }
        $name = trim($request->input('name'));
        if ($name === '' || mb_strlen($name) > 160) {
            return self::back('/projets#projets', 'Intitulé de projet invalide.');
        }
        $start = self::date($request->input('start_date'));
        $due = self::date($request->input('due_date'));
        if ($start === false || $due === false) {
            return self::back('/projets#projets', 'Date invalide.');
        }
        if ($start !== null && $due !== null && $due < $start) {
            return self::back('/projets#projets', 'La date de fin précède la date de début.');
        }
        $budget = self::amount($request->input('budget_amount'));
        $rate = self::amount($request->input('hourly_rate'), 10000);
        if ($budget === false || $rate === false) {
            return self::back('/projets#projets', 'Montant invalide.');
        }
        $status = $request->input('status', 'Cadrage') ?: 'Cadrage';
        if (!in_array($status, Projects::STATUSES, true)) {
            return self::back('/projets#projets', 'Statut invalide.');
        }

        // Sans responsable désigné, c'est celui qui ouvre le projet : un manager
        // qui n'en désignerait pas perdrait la main sur ce qu'il vient de créer.
        $leadId = (int) $request->input('lead_id') ?: (self::isSteward($user) ? null : (int) $user['id']);

        $id = Projects::create([
            'code' => mb_substr($request->input('code'), 0, 20),
            'name' => $name,
            'partnerId' => (int) $request->input('partner_id') ?: null,
            'departmentId' => (int) $request->input('department_id') ?: null,
            'teamId' => (int) $request->input('team_id') ?: null,
            'leadId' => $leadId,
            'status' => $status,
            'startDate' => $start,
            'dueDate' => $due,
            'budgetAmount' => $budget,
            'hourlyRate' => $rate,
            'description' => mb_substr($request->input('description'), 0, 4000),
        ]);
        Flash::set('success', 'Projet créé.');
        return Response::redirect("/projets/$id#taches");
    }

    // ---------- Fiche projet ----------

    public static function show(Request $request, array $params): Response
    {
        $user = self::user();
        $project = Projects::byId((int) $params['id']);
        if ($project === null) {
            return self::refuse('Projet introuvable.');
        }
        if (!self::visibleTo($user, $project)) {
            return self::refuse("Ce projet n'est ouvert qu'à son équipe.");
        }

        return Response::html(View::page('projects/show', [
            'title' => $project['name'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.projects'),
            'headerTitle' => $project['name'],
            'headerSubtitle' => (string) $project['code'],
            'navItems' => [
                ['href' => '/projets', 'label' => t('nav.projects')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/confirm.js'],
            'project' => $project,
            'canManage' => self::canManage($user, $project),
            'board' => Projects::board((int) $project['id']),
            'taskList' => Projects::tasks((int) $project['id']),
            'milestoneList' => Projects::milestones((int) $project['id']),
            'memberList' => Projects::members((int) $project['id']),
            'entries' => Projects::timeEntries((int) $project['id']),
            'byMember' => Projects::timeByMember((int) $project['id']),
            'profit' => Projects::profitability($project),
            'statuses' => Projects::STATUSES,
            'taskStatuses' => Projects::TASK_STATUSES,
            'priorities' => Projects::PRIORITIES,
            'people' => self::people(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    private static function manageOr403(array $params): array|Response
    {
        $user = self::user();
        $project = Projects::byId((int) $params['id']);
        if ($project === null || !self::canManage($user, $project)) {
            return self::refuse("La conduite de ce projet est réservée à son responsable et à la gestion.");
        }
        return [$user, $project];
    }

    public static function update(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        $id = (int) $project['id'];

        $name = trim($request->input('name'));
        if ($name === '' || mb_strlen($name) > 160) {
            return self::back("/projets/$id", 'Intitulé de projet invalide.');
        }
        $start = self::date($request->input('start_date'));
        $due = self::date($request->input('due_date'));
        $budget = self::amount($request->input('budget_amount'));
        $rate = self::amount($request->input('hourly_rate'), 10000);
        $status = $request->input('status');
        if ($start === false || $due === false) {
            return self::back("/projets/$id", 'Date invalide.');
        }
        if ($budget === false || $rate === false) {
            return self::back("/projets/$id", 'Montant invalide.');
        }
        if (!in_array($status, Projects::STATUSES, true)) {
            return self::back("/projets/$id", 'Statut invalide.');
        }

        Projects::update($id, [
            'code' => mb_substr($request->input('code'), 0, 20),
            'name' => $name,
            'partnerId' => (int) $request->input('partner_id') ?: null,
            'departmentId' => (int) $request->input('department_id') ?: null,
            'teamId' => (int) $request->input('team_id') ?: null,
            'leadId' => (int) $request->input('lead_id') ?: null,
            'status' => $status,
            'startDate' => $start,
            'dueDate' => $due,
            'budgetAmount' => $budget,
            'hourlyRate' => $rate,
            'description' => mb_substr($request->input('description'), 0, 4000),
        ]);
        Flash::set('success', 'Projet mis à jour.');
        return Response::redirect("/projets/$id");
    }

    public static function archive(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        Projects::archive((int) $project['id'], (int) $project['archived'] === 0);
        Flash::set('success', (int) $project['archived'] === 0 ? 'Projet archivé.' : 'Projet rouvert.');
        return Response::redirect('/projets#projets');
    }

    public static function remove(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        Projects::remove((int) $project['id']);
        Flash::set('success', 'Projet supprimé.');
        return Response::redirect('/projets#projets');
    }

    public static function addMember(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        $userId = (int) $request->input('user_id');
        if (Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$userId]) === null) {
            return self::back('/projets/' . (int) $project['id'] . '#equipe', 'Membre introuvable.');
        }
        Projects::addMember((int) $project['id'], $userId, mb_substr($request->input('role'), 0, 60));
        Flash::set('success', 'Membre ajouté au projet.');
        return Response::redirect('/projets/' . (int) $project['id'] . '#equipe');
    }

    public static function removeMember(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        Projects::removeMember((int) $project['id'], (int) $params['userId']);
        Flash::set('success', 'Membre retiré du projet.');
        return Response::redirect('/projets/' . (int) $project['id'] . '#equipe');
    }

    public static function createMilestone(Request $request, array $params): Response
    {
        $found = self::manageOr403($params);
        if ($found instanceof Response) {
            return $found;
        }
        [, $project] = $found;
        $title = trim($request->input('title'));
        $due = self::date($request->input('due_date'));
        $anchor = '/projets/' . (int) $project['id'] . '#jalons';
        if ($title === '' || mb_strlen($title) > 160) {
            return self::back($anchor, 'Intitulé de jalon invalide.');
        }
        if ($due === false) {
            return self::back($anchor, 'Échéance invalide.');
        }
        Projects::createMilestone((int) $project['id'], $title, $due);
        Flash::set('success', 'Jalon ajouté.');
        return Response::redirect($anchor);
    }

    /** Les routes qui portent un jalon ou une tâche remontent à leur projet. */
    private static function manageOfProject(?int $projectId): array|Response
    {
        $user = self::user();
        $project = $projectId === null ? null : Projects::byId($projectId);
        if ($project === null || !self::canManage($user, $project)) {
            return self::refuse("La conduite de ce projet est réservée à son responsable et à la gestion.");
        }
        return [$user, $project];
    }

    public static function toggleMilestone(Request $request, array $params): Response
    {
        $row = Db::get('SELECT project_id FROM project_milestones WHERE id = ?', [(int) $params['id']]);
        $found = self::manageOfProject($row === null ? null : (int) $row['project_id']);
        if ($found instanceof Response) {
            return $found;
        }
        $projectId = Projects::toggleMilestone((int) $params['id']);
        return Response::redirect("/projets/$projectId#jalons");
    }

    public static function deleteMilestone(Request $request, array $params): Response
    {
        $row = Db::get('SELECT project_id FROM project_milestones WHERE id = ?', [(int) $params['id']]);
        $found = self::manageOfProject($row === null ? null : (int) $row['project_id']);
        if ($found instanceof Response) {
            return $found;
        }
        $projectId = Projects::deleteMilestone((int) $params['id']);
        Flash::set('success', 'Jalon supprimé.');
        return Response::redirect("/projets/$projectId#jalons");
    }

    // ---------- Tâches ----------

    public static function createTask(Request $request, array $params): Response
    {
        $user = self::user();
        $project = Projects::byId((int) $params['id']);
        if ($project === null || !self::visibleTo($user, $project)) {
            return self::back('/projets#projets', 'Projet introuvable.');
        }
        $id = (int) $project['id'];
        $anchor = "/projets/$id#taches";

        $title = trim($request->input('title'));
        if ($title === '' || mb_strlen($title) > 200) {
            return self::back($anchor, 'Intitulé de tâche invalide.');
        }
        $due = self::date($request->input('due_date'));
        if ($due === false) {
            return self::back($anchor, 'Échéance invalide.');
        }
        $priority = $request->input('priority', 'Normale') ?: 'Normale';
        if (!in_array($priority, Projects::PRIORITIES, true)) {
            return self::back($anchor, 'Priorité invalide.');
        }
        $estimate = self::amount($request->input('estimate_hours'), 10000);
        if ($estimate === false) {
            return self::back($anchor, 'Estimation invalide.');
        }

        Projects::createTask([
            'projectId' => $id,
            'milestoneId' => (int) $request->input('milestone_id') ?: null,
            'title' => $title,
            'description' => mb_substr($request->input('description'), 0, 2000),
            'assigneeId' => (int) $request->input('assignee_id') ?: null,
            'priority' => $priority,
            'estimateHours' => $estimate,
            'dueDate' => $due,
            'createdBy' => (int) $user['id'],
        ]);
        Flash::set('success', 'Tâche ajoutée.');
        return Response::redirect($anchor);
    }

    private static function taskInVisibleProject(int $taskId): array|Response
    {
        $user = self::user();
        $task = Projects::taskById($taskId);
        $project = $task === null ? null : Projects::byId((int) $task['project_id']);
        if ($task === null || $project === null || !self::visibleTo($user, $project)) {
            return self::back('/projets#projets', 'Tâche introuvable.');
        }
        return [$user, $task, $project];
    }

    public static function setTaskStatus(Request $request, array $params): Response
    {
        $found = self::taskInVisibleProject((int) $params['id']);
        if ($found instanceof Response) {
            return $found;
        }
        [, $task] = $found;
        if (Projects::setTaskStatus((int) $task['id'], $request->input('status')) === null) {
            return self::back('/projets/' . (int) $task['project_id'] . '#taches', 'Statut invalide.');
        }
        return Response::redirect('/projets/' . (int) $task['project_id'] . '#taches');
    }

    public static function assignTask(Request $request, array $params): Response
    {
        $found = self::taskInVisibleProject((int) $params['id']);
        if ($found instanceof Response) {
            return $found;
        }
        [, $task] = $found;
        Projects::assignTask((int) $task['id'], (int) $request->input('assignee_id') ?: null);
        return Response::redirect('/projets/' . (int) $task['project_id'] . '#taches');
    }

    public static function deleteTask(Request $request, array $params): Response
    {
        $task = Projects::taskById((int) $params['id']);
        $found = self::manageOfProject($task === null ? null : (int) $task['project_id']);
        if ($found instanceof Response) {
            return $found;
        }
        $projectId = Projects::deleteTask((int) $params['id']);
        Flash::set('success', 'Tâche supprimée.');
        return Response::redirect("/projets/$projectId#taches");
    }

    // ---------- Temps passé ----------

    public static function logTime(Request $request, array $params): Response
    {
        $user = self::user();
        $project = Projects::byId((int) $params['id']);
        if ($project === null || !self::visibleTo($user, $project)) {
            return self::back('/projets#projets', 'Projet introuvable.');
        }
        $id = (int) $project['id'];
        $anchor = "/projets/$id#temps";

        $spentOn = self::date($request->input('spent_on'), true);
        if ($spentOn === false) {
            return self::back($anchor, 'Date invalide.');
        }
        if ($spentOn > gmdate('Y-m-d')) {
            return self::back($anchor, "Une saisie de temps ne se fait pas à l'avance.");
        }
        $taskId = (int) $request->input('task_id') ?: null;
        if ($taskId !== null) {
            $task = Projects::taskById($taskId);
            if ($task === null || (int) $task['project_id'] !== $id) {
                return self::back($anchor, 'Tâche introuvable sur ce projet.');
            }
        }

        $hours = str_replace(',', '.', $request->input('hours'));
        $verdict = Projects::logTime([
            'projectId' => $id,
            'taskId' => $taskId,
            'userId' => (int) $user['id'],
            'spentOn' => $spentOn,
            'hours' => is_numeric($hours) ? (float) $hours : 0,
            'note' => mb_substr($request->input('note'), 0, 300),
            'billable' => $request->input('billable') !== '0',
        ]);
        if (!$verdict['ok']) {
            return self::back($anchor, $verdict['message']);
        }
        Flash::set('success', 'Temps enregistré.');
        return Response::redirect($anchor);
    }

    /** Chacun efface ses propres saisies ; la gestion corrige celles de tous. */
    public static function deleteTime(Request $request, array $params): Response
    {
        $user = self::user();
        $entry = Db::get('SELECT project_id FROM project_time WHERE id = ?', [(int) $params['id']]);
        $project = $entry === null ? null : Projects::byId((int) $entry['project_id']);
        $restrict = self::canManage($user, $project) ? null : (int) $user['id'];
        $projectId = Projects::deleteTimeEntry((int) $params['id'], $restrict);
        if ($projectId === null) {
            return self::back('/projets#mon-temps', 'Saisie introuvable, ou pas la vôtre.');
        }
        Flash::set('success', 'Saisie supprimée.');
        return Response::redirect("/projets/$projectId#temps");
    }
}
