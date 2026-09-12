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
use App\Modules\Finance;
use App\Modules\It;

/**
 * Service informatique.
 *
 * Le parc logiciel et les accès applicatifs relèvent du service informatique,
 * désigné par l'administration — pas de la gestion financière, qui paie les
 * factures sans répondre des habilitations qu'elles ouvrent.
 */
final class ItController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_it'] ?? 0) === 1);
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
        return '/informatique#' . $anchor;
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
     * Un horodatage de formulaire (« 2026-04-12T09:30 ») devient « 2026-04-12 09:30 »,
     * la forme que SQLite compare et trie comme du texte.
     */
    private static function moment(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})$/', $raw, $match)) {
            return ['ok' => false, 'value' => null];
        }
        if (!Validate::date($match[1]) || (int) $match[2] > 23 || (int) $match[3] > 59) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $match[1] . ' ' . $match[2] . ':' . $match[3]];
    }

    private static function seats(string $raw): array
    {
        if (trim($raw) === '') {
            return ['ok' => true, 'value' => 0];
        }
        if (!ctype_digit(trim($raw)) || (int) $raw > 100000) {
            return ['ok' => false, 'value' => 0];
        }
        return ['ok' => true, 'value' => (int) $raw];
    }

    private static function cost(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => null];
        }
        $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], $trimmed);
        if (!is_numeric($value)) {
            return ['ok' => false, 'value' => null];
        }
        $amount = round((float) $value, 2);
        if ($amount < 0 || $amount > 1e7) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $amount];
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    private static function licenceFields(Request $request): array
    {
        $name = mb_substr($request->input('name'), 0, 120);
        if ($name === '') {
            return ['ok' => false, 'message' => 'Nom du logiciel obligatoire.'];
        }
        if (!in_array($request->input('kind'), It::LICENCE_KINDS, true)) {
            return ['ok' => false, 'message' => 'Type de licence invalide.'];
        }
        if (!in_array($request->input('criticality'), It::CRITICALITIES, true)) {
            return ['ok' => false, 'message' => 'Criticité invalide.'];
        }
        if (!in_array($request->input('billing_period'), Finance::BILLING_PERIODS, true)) {
            return ['ok' => false, 'message' => 'Périodicité invalide.'];
        }

        $seats = self::seats($request->input('seats'));
        if (!$seats['ok']) {
            return ['ok' => false, 'message' => 'Nombre de sièges invalide.'];
        }
        $cost = self::cost($request->input('unit_cost'));
        if (!$cost['ok']) {
            return ['ok' => false, 'message' => 'Coût invalide.'];
        }
        $renewal = self::date($request, 'renewal_date');
        if (!$renewal['ok']) {
            return ['ok' => false, 'message' => 'Date de renouvellement invalide.'];
        }

        return [
            'ok' => true,
            'fields' => [
                'name' => $name,
                'publisher' => mb_substr($request->input('publisher'), 0, 120),
                'kind' => $request->input('kind'),
                'seats' => $seats['value'],
                'unitCost' => $cost['value'],
                'billingPeriod' => $request->input('billing_period'),
                'renewalDate' => $renewal['value'],
                'ownerId' => (int) $request->input('owner_id') ?: null,
                'criticality' => $request->input('criticality'),
                'personalData' => $request->input('personal_data') === '1',
                'notes' => mb_substr($request->input('notes'), 0, 1000),
            ],
        ];
    }

    // ---------------------------------------------------------------- écrans

    public static function index(Request $request): Response
    {
        $showRetired = $request->input('retires') === '1';
        $summary = It::summary();

        return Response::html(View::page('it/index', [
            'title' => t('nav.it') . ' — ' . t('app.name'),
            'panelLabel' => t('inf.panel'),
            'headerTitle' => t('nav.it'),
            'headerSubtitle' => t('inf.headerSub'),
            'navItems' => [
                ['tab' => 'logiciels', 'label' => t('inf.tabSoftware')],
                ['tab' => 'acces', 'label' => t('inf.tabAccess'), 'badge' => $summary['flagged'] ?: null],
                ['tab' => 'incidents', 'label' => t('inf.tabIncidents'), 'badge' => $summary['incidents']['open'] ?: null],
                ['tab' => 'nouveau', 'label' => t('inf.addSoftware')],
            ],
            'footLinks' => [
                ['href' => '/developpement', 'label' => t('nav.development')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'licenceList' => It::licences($showRetired),
            'showRetired' => $showRetired,
            'review' => It::accessReview(),
            'incidentList' => It::incidents(true, 100),
            'serviceList' => Dev::services(true),
            'summary' => $summary,
            'kinds' => It::LICENCE_KINDS,
            'criticalities' => It::CRITICALITIES,
            'severities' => It::SEVERITIES,
            'incidentStatuses' => It::INCIDENT_STATUSES,
            'billingPeriods' => Finance::BILLING_PERIODS,
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function showLicence(Request $request, array $params): Response
    {
        $licence = It::licenceById((int) $params['id']);
        if ($licence === null) {
            return Response::html(View::page('error', ['message' => 'Logiciel introuvable.', 'title' => 'Logiciel introuvable.']), 404);
        }

        $open = array_map(
            static fn (array $a): int => (int) $a['user_id'],
            It::accesses((int) $licence['id'], false)
        );
        $employees = self::employees();

        return Response::html(View::page('it/licence', [
            'title' => $licence['name'] . ' — ' . t('app.name'),
            'panelLabel' => t('inf.panel'),
            'headerTitle' => $licence['name'],
            'headerSubtitle' => t('inf.sheetSub'),
            'navItems' => [
                ['tab' => 'fiche', 'label' => t('inf.sheet')],
                ['tab' => 'acces', 'label' => t('inf.tabAccess'),
                 'badge' => count(It::accesses((int) $licence['id'], false)) ?: null],
            ],
            'footLinks' => [['href' => '/informatique#logiciels', 'label' => t('nav.it')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'licence' => $licence,
            'accessList' => It::accesses((int) $licence['id']),
            'grantable' => array_values(array_filter(
                $employees,
                static fn (array $u): bool => !in_array((int) $u['id'], $open, true)
            )),
            'yearlyCost' => It::yearlyCost($licence),
            'kinds' => It::LICENCE_KINDS,
            'criticalities' => It::CRITICALITIES,
            'statuses' => It::LICENCE_STATUSES,
            'levels' => It::ACCESS_LEVELS,
            'billingPeriods' => Finance::BILLING_PERIODS,
            'employees' => $employees,
        ]));
    }

    // ---------------------------------------------------------------- logiciels

    public static function createLicence(Request $request): Response
    {
        $parsed = self::licenceFields($request);
        if (!$parsed['ok']) {
            return self::fail(self::back('nouveau'), $parsed['message']);
        }

        $id = It::createLicence($parsed['fields'] + ['status' => 'Actif']);
        Audit::log('informatique.logiciel_ajoute', 'software_licences', $id, ['nom' => $parsed['fields']['name']]);
        return self::done('/informatique/logiciels/' . $id, 'Logiciel enregistré.');
    }

    public static function updateLicence(Request $request, array $params): Response
    {
        $licence = It::licenceById((int) $params['id']);
        if ($licence === null) {
            return self::fail(self::back('logiciels'), 'Logiciel introuvable.');
        }

        $target = '/informatique/logiciels/' . (int) $licence['id'];
        $parsed = self::licenceFields($request);
        if (!$parsed['ok']) {
            return self::fail($target, $parsed['message']);
        }
        if (!in_array($request->input('status'), It::LICENCE_STATUSES, true)) {
            return self::fail($target, 'Statut invalide.');
        }
        // Réduire les sièges sous le nombre d'accès ouverts mettrait l'instance en
        // défaut de licence sans que rien ne le signale : la saisie est refusée.
        $used = (int) $licence['seats_used'];
        if ($parsed['fields']['seats'] > 0 && $parsed['fields']['seats'] < $used) {
            return self::fail($target, "$used accès sont ouverts : retirez-en avant de descendre à "
                . $parsed['fields']['seats'] . ' sièges.');
        }

        It::updateLicence((int) $licence['id'], $parsed['fields'] + ['status' => $request->input('status')]);
        return self::done($target, 'Logiciel mis à jour.');
    }

    public static function deleteLicence(Request $request, array $params): Response
    {
        $licence = It::licenceById((int) $params['id']);
        if ($licence === null) {
            return self::fail(self::back('logiciels'), 'Logiciel introuvable.');
        }

        It::removeLicence((int) $licence['id']);
        Audit::log('informatique.logiciel_supprime', 'software_licences', (int) $licence['id'], ['nom' => $licence['name']]);
        return self::done(self::back('logiciels'), 'Logiciel supprimé, avec ses accès.');
    }

    // ---------------------------------------------------------------- accès

    public static function grantAccess(Request $request, array $params): Response
    {
        $licence = It::licenceById((int) $params['id']);
        if ($licence === null) {
            return self::fail(self::back('logiciels'), 'Logiciel introuvable.');
        }

        $target = '/informatique/logiciels/' . (int) $licence['id'];
        if (!in_array($request->input('level'), It::ACCESS_LEVELS, true)) {
            return self::fail($target, "Niveau d'accès invalide.");
        }

        $userId = (int) $request->input('user_id');
        if (Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$userId]) === null) {
            return self::fail($target, 'Membre introuvable.');
        }

        $result = It::grantAccess([
            'licenceId' => (int) $licence['id'],
            'userId' => $userId,
            'level' => $request->input('level'),
            'grantedBy' => (int) Session::get('user')['id'],
            'note' => $request->input('note'),
        ]);
        if (!$result['ok']) {
            return self::fail($target, $result['reason'] === 'complet'
                ? 'Les ' . $result['seats'] . ' sièges de cette licence sont attribués.'
                : 'Cet accès est déjà ouvert.');
        }

        Audit::log('informatique.acces_ouvert', 'software_accesses', $result['id'], [
            'logiciel' => $licence['name'], 'beneficiaire' => $userId, 'niveau' => $request->input('level'),
        ]);
        return self::done($target, 'Accès ouvert.');
    }

    public static function revokeAccess(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $row = Db::get('SELECT licence_id, user_id FROM software_accesses WHERE id = ?', [$id]);
        if ($row === null) {
            return self::fail(self::back('acces'), 'Accès introuvable.');
        }

        It::revokeAccess($id);
        Audit::log('informatique.acces_revoque', 'software_accesses', $id, ['beneficiaire' => (int) $row['user_id']]);
        return self::done(
            $request->input('retour') === 'revue' ? self::back('acces') : '/informatique/logiciels/' . (int) $row['licence_id'],
            'Accès révoqué.'
        );
    }

    public static function markReviewed(Request $request, array $params): Response
    {
        if (!It::markReviewed((int) $params['id'])) {
            return self::fail(self::back('acces'), 'Accès introuvable.');
        }
        return self::done(self::back('acces'), 'Accès marqué comme revu.');
    }

    // ---------------------------------------------------------------- incidents

    public static function createIncident(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 150);
        if ($title === '') {
            return self::fail(self::back('incidents'), "Intitulé de l'incident obligatoire.");
        }
        if (!in_array($request->input('severity'), It::SEVERITIES, true)) {
            return self::fail(self::back('incidents'), 'Gravité invalide.');
        }

        $started = self::moment($request, 'started_at', true);
        if (!$started['ok']) {
            return self::fail(self::back('incidents'), 'Date de début invalide.');
        }
        $detected = self::moment($request, 'detected_at');
        if (!$detected['ok']) {
            return self::fail(self::back('incidents'), 'Date de détection invalide.');
        }

        $id = It::createIncident([
            'reference' => mb_substr($request->input('reference'), 0, 40),
            'title' => $title,
            'serviceId' => (int) $request->input('service_id') ?: null,
            'severity' => $request->input('severity'),
            'startedAt' => $started['value'],
            'detectedAt' => $detected['value'],
            'impact' => mb_substr($request->input('impact'), 0, 1000),
            'declaredBy' => (int) Session::get('user')['id'],
        ]);
        Audit::log('informatique.incident_declare', 'it_incidents', $id, [
            'titre' => $title, 'gravite' => $request->input('severity'),
        ]);
        return self::done(self::back('incidents'), 'Incident déclaré.');
    }

    public static function updateIncident(Request $request, array $params): Response
    {
        $incident = It::incidentById((int) $params['id']);
        if ($incident === null) {
            return self::fail(self::back('incidents'), 'Incident introuvable.');
        }
        if (!in_array($request->input('severity'), It::SEVERITIES, true)) {
            return self::fail(self::back('incidents'), 'Gravité invalide.');
        }
        if (!in_array($request->input('status'), It::INCIDENT_STATUSES, true)) {
            return self::fail(self::back('incidents'), 'Statut invalide.');
        }

        $started = self::moment($request, 'started_at', true);
        $detected = self::moment($request, 'detected_at');
        $resolved = self::moment($request, 'resolved_at');
        if (!$started['ok'] || !$detected['ok'] || !$resolved['ok']) {
            return self::fail(self::back('incidents'), 'Date invalide.');
        }
        // Un rétablissement antérieur au début fausserait le délai moyen sans bruit.
        if ($resolved['value'] !== null && $resolved['value'] < $started['value']) {
            return self::fail(self::back('incidents'), 'Le rétablissement ne peut pas précéder le début de la panne.');
        }
        // Un incident déclaré résolu sans heure de rétablissement ne se mesure pas.
        $closing = in_array($request->input('status'), ['Résolu', 'Clos'], true);
        if ($closing && $resolved['value'] === null) {
            return self::fail(self::back('incidents'), "Renseignez l'heure de rétablissement pour clore l'incident.");
        }

        It::updateIncident((int) $incident['id'], [
            'title' => mb_substr($request->input('title'), 0, 150) ?: $incident['title'],
            'serviceId' => (int) $request->input('service_id') ?: null,
            'severity' => $request->input('severity'),
            'startedAt' => $started['value'],
            'detectedAt' => $detected['value'],
            'resolvedAt' => $resolved['value'],
            'impact' => mb_substr($request->input('impact'), 0, 1000),
            'cause' => mb_substr($request->input('cause'), 0, 1000),
            'remediation' => mb_substr($request->input('remediation'), 0, 1000),
            'status' => $request->input('status'),
        ]);
        return self::done(self::back('incidents'), 'Incident mis à jour.');
    }

    public static function deleteIncident(Request $request, array $params): Response
    {
        $incident = It::incidentById((int) $params['id']);
        if ($incident === null) {
            return self::fail(self::back('incidents'), 'Incident introuvable.');
        }

        It::removeIncident((int) $incident['id']);
        Audit::log('informatique.incident_supprime', 'it_incidents', (int) $incident['id'], ['titre' => $incident['title']]);
        return self::done(self::back('incidents'), 'Incident supprimé.');
    }
}
