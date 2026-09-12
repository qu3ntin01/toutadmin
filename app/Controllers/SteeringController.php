<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\Deadlines;
use App\Modules\Org;
use App\Modules\Steering;

/**
 * Pilotage.
 *
 * Le pilotage regarde toute l'entreprise : administration, gestion et RH. Il
 * n'écrit rien d'autre que les objectifs — tout le reste est lu chez ceux qui
 * en répondent.
 */
final class SteeringController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && (
            $user['role'] === 'admin'
            || (int) ($user['is_finance'] ?? 0) === 1
            || (int) ($user['is_hr'] ?? 0) === 1
        );
    }

    private static function back(string $anchor): Response
    {
        return Response::redirect('/pilotage#' . $anchor);
    }

    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::back($anchor);
    }

    /** Un nombre saisi : « 1 234,5 » vaut 1234.5 ; le reste est refusé. */
    private static function number(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        return $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    public static function index(Request $request): Response
    {
        $year = (int) $request->input('annee') ?: (int) gmdate('Y');
        $deadlines = Deadlines::summary();

        return Response::html(View::page('steering/index', [
            'title' => t('nav.steering') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.steering'),
            'headerTitle' => t('nav.steering'),
            'headerSubtitle' => t('pil.headerSub'),
            'navItems' => [
                ['tab' => 'tableau', 'label' => t('common.dashboard')],
                ['tab' => 'echeances', 'label' => t('sst.deadlines'), 'badge' => $deadlines['overdue'] ?: null],
                ['tab' => 'projets', 'label' => t('pil.tabProjectHealth')],
                ['tab' => 'objectifs', 'label' => t('common.objectives')],
            ],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/meter.js', '/js/confirm.js'],
            'overview' => Steering::overview($year),
            'deadlines' => $deadlines,
            'objectiveList' => Steering::objectives(),
            'scopes' => Steering::SCOPES,
            'statuses' => Steering::OBJECTIVE_STATUSES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
            'people' => Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE'),
            'year' => $year,
        ]));
    }

    // ---------- Objectifs ----------

    public static function createObjective(Request $request): Response
    {
        $title = trim($request->input('title'));
        if ($title === '' || mb_strlen($title) > 200) {
            return self::fail('objectifs', 'Intitulé invalide.');
        }
        $scope = $request->input('scope');
        if (!in_array($scope, Steering::SCOPES, true)) {
            return self::fail('objectifs', 'Portée invalide.');
        }

        $scopeId = $scope === 'Entreprise' ? null : ((int) $request->input('scope_id') ?: null);
        if ($scope !== 'Entreprise' && $scopeId === null) {
            return self::fail('objectifs', "Une portée de service ou d'équipe demande de choisir laquelle.");
        }

        $id = Steering::createObjective([
            'title' => $title,
            'description' => mb_substr(trim($request->input('description')), 0, 2000),
            'scope' => $scope,
            'scopeId' => $scopeId,
            'ownerId' => (int) $request->input('owner_id') ?: null,
            'period' => mb_substr(trim($request->input('period')), 0, 20),
        ]);
        Audit::log('objectif.cree', 'objectives', $id, ['titre' => $title]);
        Flash::set('success', 'Objectif créé. Ajoutez-lui des résultats clés mesurables.');
        return self::back('objectifs');
    }

    public static function setObjectiveStatus(Request $request, array $params): Response
    {
        if (!Steering::setObjectiveStatus((int) $params['id'], $request->input('status'))) {
            return self::fail('objectifs', 'Statut invalide.');
        }
        return self::back('objectifs');
    }

    public static function deleteObjective(Request $request, array $params): Response
    {
        Steering::deleteObjective((int) $params['id']);
        Flash::set('success', 'Objectif supprimé, avec ses résultats clés.');
        return self::back('objectifs');
    }

    public static function addKeyResult(Request $request, array $params): Response
    {
        $objective = Steering::objectiveById((int) $params['id']);
        if ($objective === null) {
            return self::fail('objectifs', 'Objectif introuvable.');
        }

        $title = trim($request->input('title'));
        if ($title === '') {
            return self::fail('objectifs', 'Intitulé du résultat clé invalide.');
        }

        $start = self::number($request->input('start_value') ?: '0');
        $target = self::number($request->input('target_value'));
        $current = self::number($request->input('current_value') ?: ($request->input('start_value') ?: '0'));
        if ($start === null || $target === null || $current === null) {
            return self::fail('objectifs', 'Valeurs invalides.');
        }
        if ($start === $target) {
            return self::fail('objectifs', "Départ et cible identiques : il n'y a rien à mesurer.");
        }

        Steering::addKeyResult([
            'objectiveId' => (int) $objective['id'],
            'title' => mb_substr($title, 0, 200),
            'startValue' => $start,
            'targetValue' => $target,
            'currentValue' => $current,
            'unit' => mb_substr(trim($request->input('unit')), 0, 20),
        ]);
        Flash::set('success', 'Résultat clé ajouté.');
        return self::back('objectifs');
    }

    public static function updateKeyResult(Request $request, array $params): Response
    {
        $value = self::number($request->input('current_value'));
        if ($value === null) {
            return self::fail('objectifs', 'Valeur invalide.');
        }
        if (Steering::updateKeyResult((int) $params['id'], $value) === null) {
            return self::fail('objectifs', 'Résultat clé introuvable.');
        }
        return self::back('objectifs');
    }

    public static function deleteKeyResult(Request $request, array $params): Response
    {
        Steering::deleteKeyResult((int) $params['id']);
        Flash::set('success', 'Résultat clé retiré.');
        return self::back('objectifs');
    }
}
