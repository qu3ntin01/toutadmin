<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Fleet;

/**
 * Flotte de véhicules.
 *
 * Elle relève des moyens généraux, tenus par la gestion.
 */
final class FleetController
{
    public static function canAccess(?array $user): bool
    {
        return FinanceController::canAccess($user);
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

    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    private static function mileage(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => 0];
        }
        if (!ctype_digit($trimmed) || (int) $trimmed > 5000000) {
            return ['ok' => false, 'value' => 0];
        }
        return ['ok' => true, 'value' => (int) $trimmed];
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    public static function index(Request $request): Response
    {
        $showDisposed = $request->input('cedes') === '1';
        $deadlines = Fleet::deadlines();

        return Response::html(View::page('fleet/index', [
            'title' => t('nav.fleet') . ' — ' . t('app.name'),
            'panelLabel' => t('flo.panel'),
            'headerTitle' => t('nav.fleet'),
            'headerSubtitle' => t('flo.headerSub'),
            'navItems' => [
                ['tab' => 'flotte', 'label' => t('flo.tabVehicles')],
                ['tab' => 'echeances', 'label' => t('sst.deadlines'), 'badge' => count($deadlines) ?: null],
                ['tab' => 'nouveau', 'label' => t('common.add')],
            ],
            'footLinks' => [['href' => '/gestion', 'label' => t('nav.gestion')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'vehicleList' => Fleet::vehicles($showDisposed),
            'showDisposed' => $showDisposed,
            'deadlines' => $deadlines,
            'summary' => Fleet::summary(),
            'kinds' => Fleet::KINDS,
            'statuses' => Fleet::STATUSES,
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function show(Request $request, array $params): Response
    {
        $vehicle = Fleet::byId((int) $params['id']);
        if ($vehicle === null) {
            return Response::html(View::page('error', ['message' => 'Véhicule introuvable.', 'title' => 'Véhicule introuvable.']), 404);
        }

        return Response::html(View::page('fleet/vehicle', [
            'title' => $vehicle['registration'] . ' — ' . t('app.name'),
            'panelLabel' => t('flo.panel'),
            'headerTitle' => $vehicle['registration'],
            'headerSubtitle' => trim(trim($vehicle['brand'] . ' ' . $vehicle['model']) . ' · ' . $vehicle['status'], ' ·'),
            'navItems' => [
                ['tab' => 'historique', 'label' => t('flo.tabHistory')],
                ['tab' => 'fiche', 'label' => t('common.form')],
            ],
            'footLinks' => [['href' => '/flotte', 'label' => t('flo.tabWholeFleet')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'vehicle' => $vehicle,
            'eventList' => Fleet::events((int) $vehicle['id']),
            'kinds' => Fleet::KINDS,
            'statuses' => Fleet::STATUSES,
            'eventKinds' => Fleet::EVENT_KINDS,
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function create(Request $request): Response
    {
        $back = '/flotte#flotte';
        $registration = mb_strtoupper($request->input('registration'));
        if ($registration === '' || mb_strlen($registration) > 20) {
            return self::fail($back, 'Immatriculation invalide.');
        }
        if (Db::get('SELECT id FROM vehicles WHERE registration = ?', [$registration]) !== null) {
            return self::fail($back, 'Ce véhicule est déjà enregistré.');
        }
        if (!in_array($request->input('kind'), Fleet::KINDS, true)) {
            return self::fail($back, 'Type invalide.');
        }

        $acquired = self::date($request, 'acquired_on');
        $insurance = self::date($request, 'insurance_due');
        $inspection = self::date($request, 'inspection_due');
        $service = self::date($request, 'service_due');
        if (!$acquired['ok'] || !$insurance['ok'] || !$inspection['ok'] || !$service['ok']) {
            return self::fail($back, 'Date invalide.');
        }

        $mileage = self::mileage($request->input('mileage'));
        if (!$mileage['ok']) {
            return self::fail($back, 'Kilométrage invalide.');
        }

        $id = Fleet::create([
            'registration' => $registration,
            'brand' => mb_substr($request->input('brand'), 0, 60),
            'model' => mb_substr($request->input('model'), 0, 60),
            'kind' => $request->input('kind'),
            'acquiredOn' => $acquired['value'],
            'mileage' => $mileage['value'],
            'assignedTo' => (int) $request->input('assigned_to') ?: null,
            'insuranceDue' => $insurance['value'],
            'inspectionDue' => $inspection['value'],
            'serviceDue' => $service['value'],
        ]);
        Audit::log('flotte.vehicule_ajoute', 'vehicles', $id, ['immatriculation' => $registration]);
        return self::done('/flotte/' . $id, 'Véhicule enregistré.');
    }

    public static function update(Request $request, array $params): Response
    {
        $vehicle = Fleet::byId((int) $params['id']);
        if ($vehicle === null) {
            return self::fail('/flotte#flotte', 'Véhicule introuvable.');
        }

        $target = '/flotte/' . (int) $vehicle['id'];
        if (!in_array($request->input('kind'), Fleet::KINDS, true)) {
            return self::fail($target, 'Type invalide.');
        }
        if (!in_array($request->input('status'), Fleet::STATUSES, true)) {
            return self::fail($target, 'Statut invalide.');
        }

        $acquired = self::date($request, 'acquired_on');
        $insurance = self::date($request, 'insurance_due');
        $inspection = self::date($request, 'inspection_due');
        $service = self::date($request, 'service_due');
        if (!$acquired['ok'] || !$insurance['ok'] || !$inspection['ok'] || !$service['ok']) {
            return self::fail($target, 'Date invalide.');
        }

        $mileage = self::mileage($request->input('mileage'));
        if (!$mileage['ok']) {
            return self::fail($target, 'Kilométrage invalide.');
        }
        // Un compteur ne recule pas : la saisie est refusée plutôt que silencieusement ignorée.
        if ($mileage['value'] < (int) $vehicle['mileage']) {
            return self::fail($target, 'Le compteur ne recule pas : il est déjà à ' . (int) $vehicle['mileage'] . ' km.');
        }

        Fleet::update((int) $vehicle['id'], [
            'brand' => mb_substr($request->input('brand'), 0, 60),
            'model' => mb_substr($request->input('model'), 0, 60),
            'kind' => $request->input('kind'),
            'acquiredOn' => $acquired['value'],
            'mileage' => $mileage['value'],
            'assignedTo' => (int) $request->input('assigned_to') ?: null,
            'insuranceDue' => $insurance['value'],
            'inspectionDue' => $inspection['value'],
            'serviceDue' => $service['value'],
            'status' => $request->input('status'),
        ]);
        return self::done($target, 'Véhicule mis à jour.');
    }

    public static function remove(Request $request, array $params): Response
    {
        $vehicle = Fleet::byId((int) $params['id']);
        if ($vehicle === null) {
            return self::fail('/flotte#flotte', 'Véhicule introuvable.');
        }

        Fleet::remove((int) $vehicle['id']);
        Audit::log('flotte.vehicule_supprime', 'vehicles', (int) $vehicle['id'], [
            'immatriculation' => $vehicle['registration'],
        ]);
        return self::done('/flotte#flotte', 'Véhicule supprimé, avec son historique.');
    }

    public static function addEvent(Request $request, array $params): Response
    {
        $vehicle = Fleet::byId((int) $params['id']);
        if ($vehicle === null) {
            return self::fail('/flotte#flotte', 'Véhicule introuvable.');
        }

        $target = '/flotte/' . (int) $vehicle['id'];
        if (!in_array($request->input('kind'), Fleet::EVENT_KINDS, true)) {
            return self::fail($target, 'Nature invalide.');
        }

        $on = self::date($request, 'occurred_on', true);
        if (!$on['ok']) {
            return self::fail($target, 'Date invalide.');
        }

        $mileage = self::mileage($request->input('mileage'));
        if (!$mileage['ok']) {
            return self::fail($target, 'Kilométrage invalide.');
        }

        $rawCost = $request->input('cost');
        $cost = null;
        if ($rawCost !== '') {
            $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], $rawCost);
            if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1e7) {
                return self::fail($target, 'Coût invalide.');
            }
            $cost = round((float) $value, 2);
        }

        Fleet::addEvent([
            'vehicleId' => (int) $vehicle['id'],
            'kind' => $request->input('kind'),
            'occurredOn' => $on['value'],
            'mileage' => $mileage['value'],
            'cost' => $cost,
            'note' => mb_substr($request->input('note'), 0, 500),
        ]);
        return self::done($target, 'Événement consigné.');
    }

    public static function deleteEvent(Request $request, array $params): Response
    {
        $vehicleId = Fleet::deleteEvent((int) $params['id']);
        if ($vehicleId === null) {
            return self::fail('/flotte#flotte', 'Événement introuvable.');
        }
        return self::done('/flotte/' . $vehicleId, 'Événement retiré.');
    }
}
