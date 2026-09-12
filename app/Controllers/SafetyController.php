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
use App\Modules\Safety;

/**
 * Santé et sécurité au travail.
 *
 * Le document unique, le registre des accidents et le suivi médical sont des
 * obligations de l'employeur : ils relèvent des RH et de l'administration.
 */
final class SafetyController
{
    public static function canAccess(?array $user): bool
    {
        return HrController::canAccess($user);
    }

    private static function back(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/sante-securite#' . $anchor);
    }

    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    public static function index(Request $request): Response
    {
        $upcoming = Safety::upcoming();
        $due = count($upcoming['visits']) + count($upcoming['ppe']) + count($upcoming['risks']);

        return Response::html(View::page('safety/index', [
            'title' => t('sst.title') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.healthSafety'),
            'headerTitle' => t('sst.title'),
            'headerSubtitle' => t('sst.subtitle'),
            'navItems' => [
                ['tab' => 'risques', 'label' => t('sst.singleDocument')],
                ['tab' => 'accidents', 'label' => t('sst.accidentRegister')],
                ['tab' => 'protections', 'label' => t('erp.assets')],
                ['tab' => 'visites', 'label' => t('sst.medicalVisits')],
                ['tab' => 'echeances', 'label' => t('sst.deadlines'), 'badge' => $due ?: null],
            ],
            'footLinks' => [['href' => '/rh', 'label' => t('nav.hrSpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'riskList' => Safety::risks(),
            'incidentList' => Safety::incidents(),
            'indicators' => Safety::indicators(),
            'ppeList' => Safety::ppeItems(),
            'ppeGiven' => Safety::ppeAssignments(),
            'visitList' => Safety::visits(),
            'upcoming' => $upcoming,
            'incidentKinds' => Safety::INCIDENT_KINDS,
            'visitKinds' => Safety::VISIT_KINDS,
            'severities' => Safety::SEVERITIES,
            'likelihoods' => Safety::LIKELIHOODS,
            'actionThreshold' => Safety::ACTION_THRESHOLD,
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Document unique ----------

    public static function createRisk(Request $request): Response
    {
        $unit = $request->input('unit');
        $hazard = $request->input('hazard');
        if ($unit === '' || $hazard === '') {
            return self::back('risques', 'error', 'Unité de travail et danger sont requis.');
        }

        $severity = (int) $request->input('severity');
        $likelihood = (int) $request->input('likelihood');
        if (!in_array($severity, Safety::SEVERITIES, true) || !in_array($likelihood, Safety::LIKELIHOODS, true)) {
            return self::back('risques', 'error', 'Cotation invalide.');
        }

        $reviewed = self::date($request, 'reviewed_on');
        $next = self::date($request, 'next_review');
        if (!$reviewed['ok'] || !$next['ok']) {
            return self::back('risques', 'error', 'Date invalide.');
        }

        $id = Safety::createRisk([
            'unit' => mb_substr($unit, 0, 120),
            'hazard' => mb_substr($hazard, 0, 200),
            'exposure' => mb_substr($request->input('exposure'), 0, 300),
            'severity' => $severity,
            'likelihood' => $likelihood,
            'measures' => mb_substr($request->input('measures'), 0, 2000),
            'reviewedOn' => $reviewed['value'],
            'nextReview' => $next['value'],
        ]);
        Audit::log('duerp.risque_ajoute', 'risk_assessments', $id, ['unite' => $unit, 'danger' => $hazard]);
        return self::back('risques', 'success', 'Risque consigné au document unique.');
    }

    public static function deleteRisk(Request $request, array $params): Response
    {
        $risk = Safety::riskById((int) $params['id']);
        if ($risk === null) {
            return self::back('risques', 'error', 'Risque introuvable.');
        }
        Safety::deleteRisk((int) $risk['id']);
        Audit::log('duerp.risque_supprime', 'risk_assessments', (int) $risk['id'], ['danger' => $risk['hazard']]);
        return self::back('risques', 'success', 'Risque retiré du document unique.');
    }

    // ---------- Registre des accidents ----------

    public static function createIncident(Request $request): Response
    {
        $occurred = self::date($request, 'occurred_on', true);
        if (!$occurred['ok']) {
            return self::back('accidents', 'error', "Date de l'accident invalide.");
        }
        if ($occurred['value'] > gmdate('Y-m-d')) {
            return self::back('accidents', 'error', "Un accident ne se consigne pas à l'avance.");
        }
        if (!in_array($request->input('kind'), Safety::INCIDENT_KINDS, true)) {
            return self::back('accidents', 'error', 'Nature invalide.');
        }

        $declared = self::date($request, 'declared_on');
        if (!$declared['ok']) {
            return self::back('accidents', 'error', 'Date de déclaration invalide.');
        }

        $rawDays = $request->input('days_off', '0');
        if ($rawDays === '') {
            $rawDays = '0';
        }
        if (!ctype_digit($rawDays) || (int) $rawDays > 3650) {
            return self::back('accidents', 'error', "Nombre de jours d'arrêt invalide.");
        }

        $id = Safety::createIncident([
            'occurredOn' => $occurred['value'],
            'userId' => (int) $request->input('user_id') ?: null,
            'kind' => $request->input('kind'),
            'location' => mb_substr($request->input('location'), 0, 160),
            'description' => mb_substr($request->input('description'), 0, 3000),
            'daysOff' => (int) $rawDays,
            'declaredOn' => $declared['value'],
            'followUp' => mb_substr($request->input('follow_up'), 0, 2000),
        ]);
        Audit::log('sst.accident_consigne', 'workplace_incidents', $id, [
            'nature' => $request->input('kind'),
            'jours_arret' => (int) $rawDays,
        ]);
        return self::back('accidents', 'success', 'Accident consigné au registre.');
    }

    public static function deleteIncident(Request $request, array $params): Response
    {
        Safety::deleteIncident((int) $params['id']);
        Audit::log('sst.accident_supprime', 'workplace_incidents', (int) $params['id']);
        return self::back('accidents', 'success', 'Entrée retirée du registre.');
    }

    // ---------- Équipements de protection ----------

    public static function createPpe(Request $request): Response
    {
        $name = $request->input('name');
        if ($name === '') {
            return self::back('protections', 'error', 'Intitulé invalide.');
        }

        $validity = $request->input('validity_months');
        $months = null;
        if ($validity !== '') {
            if (!ctype_digit($validity) || (int) $validity < 1 || (int) $validity > 600) {
                return self::back('protections', 'error', 'Durée de validité invalide.');
            }
            $months = (int) $validity;
        }

        Safety::createPpe(
            mb_substr($name, 0, 120),
            mb_substr($request->input('category', 'Protection') ?: 'Protection', 0, 60),
            $months
        );
        return self::back('protections', 'success', 'Équipement déclaré.');
    }

    public static function deletePpe(Request $request, array $params): Response
    {
        Safety::deletePpe((int) $params['id']);
        return self::back('protections', 'success', 'Équipement supprimé, avec ses remises.');
    }

    public static function issuePpe(Request $request): Response
    {
        $issued = self::date($request, 'issued_on', true);
        if (!$issued['ok']) {
            return self::back('protections', 'error', 'Date de remise invalide.');
        }

        $done = Safety::issuePpe(
            (int) $request->input('ppe_id'),
            (int) $request->input('user_id'),
            (string) $issued['value']
        );
        if ($done === null) {
            return self::back('protections', 'error', 'Équipement introuvable.');
        }

        return self::back('protections', 'success', "Remise consignée. L'échéance découle de la durée de validité.");
    }

    public static function returnPpe(Request $request, array $params): Response
    {
        Safety::returnPpe((int) $params['id']);
        return self::back('protections', 'success', 'Restitution consignée.');
    }

    // ---------- Visites médicales ----------

    public static function createVisit(Request $request): Response
    {
        $userId = (int) $request->input('user_id');
        if (Db::get('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
            return self::back('visites', 'error', 'Membre introuvable.');
        }
        if (!in_array($request->input('kind'), Safety::VISIT_KINDS, true)) {
            return self::back('visites', 'error', 'Type de visite invalide.');
        }

        $scheduled = self::date($request, 'scheduled_on');
        $done = self::date($request, 'done_on');
        $next = self::date($request, 'next_due');
        if (!$scheduled['ok'] || !$done['ok'] || !$next['ok']) {
            return self::back('visites', 'error', 'Date invalide.');
        }

        Safety::createVisit([
            'userId' => $userId,
            'kind' => $request->input('kind'),
            'scheduledOn' => $scheduled['value'],
            'doneOn' => $done['value'],
            // L'avis du médecin se note en clair ; aucune donnée de santé n'est demandée ici.
            'verdict' => mb_substr($request->input('verdict'), 0, 200),
            'nextDue' => $next['value'],
        ]);
        return self::back('visites', 'success', 'Visite consignée.');
    }

    public static function deleteVisit(Request $request, array $params): Response
    {
        Safety::deleteVisit((int) $params['id']);
        return self::back('visites', 'success', 'Visite retirée.');
    }
}
