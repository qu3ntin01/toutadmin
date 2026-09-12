<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Workflows;

/**
 * Demandes internes.
 *
 * L'écran est le même pour tout le monde ; ce qu'on y voit dépend de la place
 * qu'on occupe : ses propres demandes, celles qui attendent une décision de
 * soi, et — pour l'administration seule — le paramétrage des circuits.
 */
final class RequestsController
{
    private static function isAdmin(): bool
    {
        $user = Session::get('user');
        return is_array($user) && $user['role'] === 'admin';
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/demandes#' . $anchor);
    }

    public static function index(Request $request): Response
    {
        $user = Session::get('user');
        $userId = (int) $user['id'];
        $admin = self::isAdmin();
        $toDecide = Workflows::awaiting($userId);

        return Response::html(View::page('requests/index', [
            'title' => t('nav.requests') . ' — ' . t('app.name'),
            'panelLabel' => t('req.panel'),
            'headerTitle' => t('nav.requests'),
            'headerSubtitle' => t('dmd.headerSub'),
            'navItems' => array_values(array_filter([
                ['tab' => 'nouvelle', 'label' => t('leave.newRequest')],
                ['tab' => 'mes-demandes', 'label' => t('leave.myRequests')],
                ['tab' => 'a-decider', 'label' => t('dmd.awaitingYou'), 'badge' => count($toDecide) ?: null],
                $admin ? ['tab' => 'types', 'label' => t('dmd.tabFlows')] : null,
            ])),
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'admin' => $admin,
            'openForms' => Workflows::forms(true),
            'allForms' => $admin ? Workflows::forms() : [],
            'mine' => Workflows::list($userId),
            'toDecide' => $toDecide,
            'stats' => Workflows::summary(),
            'fieldTypes' => Workflows::FIELD_TYPES,
            'approvers' => Workflows::APPROVERS,
            'employees' => Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'),
        ]));
    }

    // ---------- Paramétrage (administration) ----------

    public static function createForm(Request $request): Response
    {
        // Les champs arrivent en colonnes parallèles : on les rassemble avant de
        // les valider, pour qu'une ligne laissée vide n'en décale pas une autre.
        $names = (array) ($request->body['field_names'] ?? []);
        $labels = (array) ($request->body['field_labels'] ?? []);
        $types = (array) ($request->body['field_types'] ?? []);
        $required = (array) ($request->body['field_required'] ?? []);
        $options = (array) ($request->body['field_options'] ?? []);

        $fields = [];
        foreach ($names as $index => $name) {
            if (trim((string) $name) === '') {
                continue;
            }
            $fields[] = [
                'name' => $name,
                'label' => $labels[$index] ?? $name,
                'type' => $types[$index] ?? 'texte',
                'required' => (string) ($required[$index] ?? '') === '1',
                'options' => array_values(array_filter(array_map('trim', explode('|', (string) ($options[$index] ?? ''))))),
            ];
        }

        $verdict = Workflows::createForm(
            $request->input('label'),
            $request->input('description'),
            $fields,
            $request->input('amount_field'),
            (int) Session::get('user')['id']
        );
        if (!$verdict['ok']) {
            return self::back('types', 'error', $verdict['message']);
        }
        return self::back('types', 'success', 'Type de demande créé. Ajoutez maintenant les étapes de son circuit.');
    }

    public static function addStep(Request $request, array $params): Response
    {
        $verdict = Workflows::addStep(
            (int) $params['id'],
            $request->input('approver'),
            (int) $request->input('approver_id') ?: null,
            $request->input('label'),
            (float) str_replace(',', '.', $request->input('threshold'))
        );
        if (!$verdict['ok']) {
            return self::back('types', 'error', $verdict['message']);
        }
        Audit::log('demande_type.etape_ajoutee', 'request_steps', (int) $params['id'], ['validateur' => $request->input('approver')]);
        return self::back('types', 'success', 'Étape ajoutée au circuit.');
    }

    public static function deleteStep(Request $request, array $params): Response
    {
        Workflows::deleteStep((int) $params['id'], (int) $params['stepId']);
        return self::back('types', 'success', 'Étape retirée.');
    }

    public static function setFormActive(Request $request, array $params): Response
    {
        $active = $request->input('active') === '1';
        Workflows::setFormActive((int) $params['id'], $active);
        Audit::log('demande_type.etat', 'request_forms', (int) $params['id'], ['actif' => $active]);
        return self::back('types', 'success', $active
            ? 'Type ouvert aux demandes.'
            : 'Type fermé : les demandes en cours suivent leur circuit.');
    }

    public static function deleteForm(Request $request, array $params): Response
    {
        $verdict = Workflows::deleteForm((int) $params['id']);
        if (!$verdict['ok']) {
            return self::back('types', 'error', $verdict['message']);
        }
        return self::back('types', 'success', 'Type de demande supprimé.');
    }

    // ---------- Demandes ----------

    public static function submit(Request $request): Response
    {
        $verdict = Workflows::submit((int) $request->input('form_id'), (int) Session::get('user')['id'], $request->body);
        if (!$verdict['ok']) {
            return self::back('nouvelle', 'error', $verdict['message']);
        }
        Flash::set('success', $verdict['steps'] > 0
            ? 'Demande déposée : elle suit son circuit de validation.'
            : "Demande déposée et approuvée d'emblée : aucune validation n'est requise pour ce cas.");
        return Response::redirect('/demandes/' . $verdict['id']);
    }

    /** Une demande ne se lit que par ceux qu'elle concerne. */
    private static function load(int $id): ?array
    {
        $request = Workflows::byId($id);
        if ($request === null) {
            return null;
        }
        $userId = (int) Session::get('user')['id'];
        $involved = (int) $request['requester_id'] === $userId
            || in_array($userId, array_map('intval', array_column($request['pendingApprovers'], 'id')), true)
            || in_array($userId, array_map('intval', array_column($request['decisions'], 'approver_id')), true);

        return $involved || self::isAdmin() ? $request : null;
    }

    public static function show(Request $request, array $params): Response
    {
        $found = self::load((int) $params['id']);
        if ($found === null) {
            return Response::html(View::page('error', [
                'title' => t('err.notAccessible'),
                'headerTitle' => t('err.notAccessible'),
                'message' => t('err.notAccessible'),
            ]), 404);
        }
        $userId = (int) Session::get('user')['id'];
        return Response::html(View::page('requests/show', [
            'title' => $found['form']['label'] . ' — ' . t('app.name'),
            'panelLabel' => t('req.panel'),
            'headerTitle' => $found['form']['label'],
            'headerSubtitle' => $found['requesterName'],
            'navItems' => [['href' => '/demandes', 'label' => t('req.allRequests')], ['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/confirm.js'],
            'request' => $found,
            'canDecide' => Workflows::canDecide($found, $userId)['ok'],
            'isRequester' => (int) $found['requester_id'] === $userId,
        ]));
    }

    public static function decide(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $verdict = Workflows::decide(
            $id,
            (int) Session::get('user')['id'],
            $request->input('decision'),
            mb_substr($request->input('note'), 0, 1000)
        );
        Flash::set($verdict['ok'] ? 'success' : 'error', $verdict['ok']
            ? 'Décision enregistrée : ' . mb_strtolower($verdict['status']) . '.'
            : $verdict['message']);
        return Response::redirect("/demandes/$id");
    }

    public static function cancel(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $verdict = Workflows::cancel($id, (int) Session::get('user')['id']);
        Flash::set($verdict['ok'] ? 'success' : 'error', $verdict['ok'] ? 'Demande retirée.' : $verdict['message']);
        return Response::redirect("/demandes/$id");
    }
}
