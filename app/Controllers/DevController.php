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
use App\Modules\Dev;
use App\Modules\It;

/**
 * Espace développement.
 *
 * Même périmètre que le service informatique : ceux qui livrent et ceux qui
 * exploitent regardent le même référentiel, sinon il en existe deux.
 */
final class DevController
{
    public static function canAccess(?array $user): bool
    {
        return ItController::canAccess($user);
    }

    private static function fail(string $target, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($target);
    }

    private static function done(string $target, string $message): Response
    {
        Flash::set('success', $message);
        return Response::redirect($target);
    }

    private static function back(string $anchor): string
    {
        return '/developpement#' . $anchor;
    }

    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    /**
     * Une adresse de dépôt ou de documentation est facultative, mais si elle est
     * donnée elle doit être cliquable sans risque : ni javascript:, ni data:.
     */
    private static function link(Request $request, string $key): array
    {
        $raw = mb_substr($request->input($key), 0, 300);
        if ($raw === '') {
            return ['ok' => true, 'value' => ''];
        }
        return ['ok' => Validate::url($raw), 'value' => $raw];
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    private static function projects(): array
    {
        return Db::all('SELECT id, name FROM projects WHERE archived = 0 ORDER BY name COLLATE NOCASE');
    }

    private static function serviceFields(Request $request): array
    {
        $name = mb_substr($request->input('name'), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Nom du service obligatoire.'];
        }
        if (!in_array($request->input('criticality'), Dev::CRITICALITIES, true)) {
            return ['ok' => false, 'message' => 'Criticité invalide.'];
        }

        $repository = self::link($request, 'repository');
        $documentation = self::link($request, 'documentation');
        if (!$repository['ok'] || !$documentation['ok']) {
            return ['ok' => false, 'message' => 'Adresse invalide : elle doit commencer par http:// ou https://.'];
        }

        return [
            'ok' => true,
            'fields' => [
                'name' => $name,
                'code' => mb_substr($request->input('code'), 0, 30),
                'description' => mb_substr($request->input('description'), 0, 1000),
                'repository' => $repository['value'],
                'documentation' => $documentation['value'],
                'stack' => mb_substr($request->input('stack'), 0, 120),
                'criticality' => $request->input('criticality'),
                'leadId' => (int) $request->input('lead_id') ?: null,
                'projectId' => (int) $request->input('project_id') ?: null,
            ],
        ];
    }

    // ---------------------------------------------------------------- écrans

    public static function index(Request $request): Response
    {
        $showRetired = $request->input('retires') === '1';
        $summary = Dev::summary();

        return Response::html(View::page('dev/index', [
            'title' => t('nav.development') . ' — ' . t('app.name'),
            'panelLabel' => t('dvp.panel'),
            'headerTitle' => t('nav.development'),
            'headerSubtitle' => t('dvp.headerSub'),
            'navItems' => [
                ['tab' => 'services', 'label' => t('dvp.tabServices')],
                ['tab' => 'livraisons', 'label' => t('dvp.tabReleases'),
                 'badge' => $summary['delivery']['planned'] ?: null],
                ['tab' => 'indicateurs', 'label' => t('dvp.tabMetrics')],
                ['tab' => 'nouveau', 'label' => t('dvp.addService')],
            ],
            'footLinks' => [
                ['href' => '/informatique', 'label' => t('nav.it')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'serviceList' => Dev::services($showRetired),
            'showRetired' => $showRetired,
            'releaseList' => Dev::releases(null, 60),
            'summary' => $summary,
            'restore' => It::incidentStats(),
            'criticalities' => Dev::CRITICALITIES,
            'employees' => self::employees(),
            'projectList' => self::projects(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function showService(Request $request, array $params): Response
    {
        $service = Dev::serviceById((int) $params['id']);
        if ($service === null) {
            return Response::html(View::page('error', [
                'message' => 'Service applicatif introuvable.',
                'title' => 'Service applicatif introuvable.',
            ]), 404);
        }

        $incidents = array_values(array_filter(
            It::incidents(true, 200),
            static fn (array $i): bool => (int) $i['service_id'] === (int) $service['id']
        ));

        return Response::html(View::page('dev/service', [
            'title' => $service['name'] . ' — ' . t('app.name'),
            'panelLabel' => t('dvp.panel'),
            'headerTitle' => $service['name'],
            'headerSubtitle' => t('dvp.sheetSub'),
            'navItems' => [
                ['tab' => 'fiche', 'label' => t('dvp.sheet')],
                ['tab' => 'livraisons', 'label' => t('dvp.tabReleases')],
                ['tab' => 'incidents', 'label' => t('inf.tabIncidents'), 'badge' => count($incidents) ?: null],
            ],
            'footLinks' => [['href' => '/developpement#services', 'label' => t('nav.development')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'service' => $service,
            'releaseList' => Dev::releases((int) $service['id'], 100),
            'incidentList' => $incidents,
            'criticalities' => Dev::CRITICALITIES,
            'statuses' => Dev::SERVICE_STATUSES,
            'environments' => Dev::ENVIRONMENTS,
            'releaseStatuses' => Dev::RELEASE_STATUSES,
            'employees' => self::employees(),
            'projectList' => self::projects(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------------------------------------------------------------- services

    public static function createService(Request $request): Response
    {
        $parsed = self::serviceFields($request);
        if (!$parsed['ok']) {
            return self::fail(self::back('nouveau'), $parsed['message']);
        }

        $id = Dev::createService($parsed['fields'] + ['status' => 'En service']);
        Audit::log('developpement.service_ajoute', 'app_services', $id, ['nom' => $parsed['fields']['name']]);
        return self::done('/developpement/services/' . $id, 'Service applicatif enregistré.');
    }

    public static function updateService(Request $request, array $params): Response
    {
        $service = Dev::serviceById((int) $params['id']);
        if ($service === null) {
            return self::fail(self::back('services'), 'Service applicatif introuvable.');
        }

        $target = '/developpement/services/' . (int) $service['id'];
        $parsed = self::serviceFields($request);
        if (!$parsed['ok']) {
            return self::fail($target, $parsed['message']);
        }
        if (!in_array($request->input('status'), Dev::SERVICE_STATUSES, true)) {
            return self::fail($target, 'Statut invalide.');
        }

        Dev::updateService((int) $service['id'], $parsed['fields'] + ['status' => $request->input('status')]);
        return self::done($target, 'Service applicatif mis à jour.');
    }

    public static function deleteService(Request $request, array $params): Response
    {
        $service = Dev::serviceById((int) $params['id']);
        if ($service === null) {
            return self::fail(self::back('services'), 'Service applicatif introuvable.');
        }

        Dev::removeService((int) $service['id']);
        Audit::log('developpement.service_supprime', 'app_services', (int) $service['id'], ['nom' => $service['name']]);
        return self::done(self::back('services'), 'Service applicatif supprimé, avec ses livraisons.');
    }

    // ---------------------------------------------------------------- livraisons

    public static function createRelease(Request $request, array $params): Response
    {
        $service = Dev::serviceById((int) $params['id']);
        if ($service === null) {
            return self::fail(self::back('services'), 'Service applicatif introuvable.');
        }

        $target = '/developpement/services/' . (int) $service['id'];
        $version = mb_substr($request->input('version'), 0, 40);
        if ($version === '') {
            return self::fail($target, 'Numéro de version obligatoire.');
        }
        if (!in_array($request->input('environment'), Dev::ENVIRONMENTS, true)) {
            return self::fail($target, 'Environnement invalide.');
        }
        if (!in_array($request->input('status'), Dev::RELEASE_STATUSES, true)) {
            return self::fail($target, 'Statut invalide.');
        }

        $planned = self::date($request, 'planned_on');
        $released = self::date($request, 'released_on');
        if (!$planned['ok'] || !$released['ok']) {
            return self::fail($target, 'Date invalide.');
        }
        // Une livraison sortie du champ « prévue » sans date de mise en production ne
        // compte dans aucun indicateur : elle disparaîtrait des statistiques.
        if ($request->input('status') !== 'Planifiée' && $released['value'] === null) {
            return self::fail($target, 'Renseignez la date de mise en production.');
        }

        $id = Dev::createRelease([
            'serviceId' => (int) $service['id'],
            'version' => $version,
            'environment' => $request->input('environment'),
            'plannedOn' => $planned['value'],
            'releasedOn' => $released['value'],
            'status' => $request->input('status'),
            'changelog' => mb_substr($request->input('changelog'), 0, 2000),
            'authorId' => (int) Session::get('user')['id'],
        ]);
        Audit::log('developpement.livraison_ajoutee', 'releases', $id, [
            'service' => $service['name'], 'version' => $version, 'environnement' => $request->input('environment'),
        ]);
        return self::done($target, 'Livraison consignée.');
    }

    public static function updateRelease(Request $request, array $params): Response
    {
        $release = Dev::releaseById((int) $params['id']);
        if ($release === null) {
            return self::fail(self::back('livraisons'), 'Livraison introuvable.');
        }

        $target = '/developpement/services/' . (int) $release['service_id'];
        if (!in_array($request->input('environment'), Dev::ENVIRONMENTS, true)) {
            return self::fail($target, 'Environnement invalide.');
        }
        if (!in_array($request->input('status'), Dev::RELEASE_STATUSES, true)) {
            return self::fail($target, 'Statut invalide.');
        }

        $planned = self::date($request, 'planned_on');
        $released = self::date($request, 'released_on');
        if (!$planned['ok'] || !$released['ok']) {
            return self::fail($target, 'Date invalide.');
        }
        if ($request->input('status') !== 'Planifiée' && $released['value'] === null) {
            return self::fail($target, 'Renseignez la date de mise en production.');
        }

        $incidentId = (int) $request->input('incident_id') ?: null;
        if ($incidentId !== null && It::incidentById($incidentId) === null) {
            return self::fail($target, 'Incident introuvable.');
        }

        Dev::updateRelease((int) $release['id'], [
            'version' => mb_substr($request->input('version'), 0, 40) ?: $release['version'],
            'environment' => $request->input('environment'),
            'plannedOn' => $planned['value'],
            'releasedOn' => $released['value'],
            'status' => $request->input('status'),
            'changelog' => mb_substr($request->input('changelog'), 0, 2000),
            'incidentId' => $incidentId,
        ]);
        return self::done($target, 'Livraison mise à jour.');
    }

    public static function deleteRelease(Request $request, array $params): Response
    {
        $release = Dev::releaseById((int) $params['id']);
        if ($release === null) {
            return self::fail(self::back('livraisons'), 'Livraison introuvable.');
        }

        Dev::removeRelease((int) $release['id']);
        Audit::log('developpement.livraison_supprimee', 'releases', (int) $release['id'], ['version' => $release['version']]);
        return self::done('/developpement/services/' . (int) $release['service_id'], 'Livraison retirée du registre.');
    }
}
