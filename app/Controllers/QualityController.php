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
use App\Modules\Org;
use App\Modules\Quality;

/**
 * Espace qualité.
 *
 * La qualité se pilote au plus près du terrain : l'encadrement et
 * l'administration y ont accès, sans créer un rôle de plus à administrer.
 */
final class QualityController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || Org::isManager((int) $user['id']));
    }

    private static function back(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/qualite#' . $anchor);
    }

    private static function text(Request $request, string $key, int $max): string
    {
        return mb_substr($request->input($key), 0, $max);
    }

    /** Une date de formulaire : vide acceptée, sauf si elle est exigée. */
    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    private static function people(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    public static function index(Request $request): Response
    {
        $stats = Quality::summary();
        $audits = Quality::audits();
        $findings = [];
        foreach ($audits as $audit) {
            $findings[(int) $audit['id']] = Quality::findings((int) $audit['id']);
        }

        return Response::html(View::page('quality/index', [
            'title' => t('nav.quality') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.quality'),
            'headerTitle' => t('nav.quality'),
            'headerSubtitle' => t('qua.headerSub'),
            'navItems' => [
                ['tab' => 'non-conformites', 'label' => t('qua.tabNc'), 'badge' => $stats['open'] ?: null],
                ['tab' => 'actions', 'label' => t('common.actions'), 'badge' => $stats['openActions'] ?: null],
                ['tab' => 'audits', 'label' => t('qua.tabAudits')],
            ],
            'footLinks' => [
                ['href' => '/sante-securite', 'label' => t('nav.healthSafety')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'stats' => $stats,
            'ncList' => Quality::nonconformities(),
            'actionList' => Quality::actions(),
            'auditList' => $audits,
            'findingsByAudit' => $findings,
            'sources' => Quality::SOURCES,
            'severities' => Quality::SEVERITIES,
            'ncStatuses' => Quality::NC_STATUSES,
            'actionKinds' => Quality::ACTION_KINDS,
            'actionStatuses' => Quality::ACTION_STATUSES,
            'effectiveness' => Quality::EFFECTIVENESS,
            'findingKinds' => Quality::FINDING_KINDS,
            'employees' => self::people(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Non-conformités ----------

    public static function createNonconformity(Request $request): Response
    {
        $title = self::text($request, 'title', 200);
        if ($title === '') {
            return self::back('non-conformites', 'error', 'Un intitulé est requis.');
        }
        if (!in_array($request->input('source'), Quality::SOURCES, true)) {
            return self::back('non-conformites', 'error', 'Origine inconnue.');
        }
        if (!in_array($request->input('severity'), Quality::SEVERITIES, true)) {
            return self::back('non-conformites', 'error', 'Gravité inconnue.');
        }

        $detected = self::date($request, 'detected_on', true);
        if (!$detected['ok']) {
            return self::back('non-conformites', 'error', 'Date de constat invalide.');
        }

        $rawCost = $request->input('cost');
        $cost = 0.0;
        if ($rawCost !== '') {
            $value = str_replace([' ', ',', "\u{a0}"], ['', '.', ''], $rawCost);
            if (!is_numeric($value)) {
                return self::back('non-conformites', 'error', 'Coût invalide.');
            }
            $cost = round((float) $value, 2);
        }

        $user = Session::get('user');
        $id = Quality::createNonconformity([
            'title' => $title,
            'description' => self::text($request, 'description', 4000),
            'source' => $request->input('source'),
            'severity' => $request->input('severity'),
            'detectedOn' => $detected['value'],
            'detectedBy' => (int) $user['id'],
            'subject' => self::text($request, 'subject', 200),
            'immediateAction' => self::text($request, 'immediate_action', 2000),
            'cost' => $cost,
        ]);
        Audit::log('qualite.nc_ouverte', 'nonconformities', $id, ['titre' => $title, 'gravite' => $request->input('severity')]);
        return self::back('non-conformites', 'success', 'Non-conformité enregistrée.');
    }

    public static function setRootCause(Request $request, array $params): Response
    {
        $nc = Quality::nonconformityById((int) $params['id']);
        if ($nc === null) {
            return self::back('non-conformites', 'error', 'Non-conformité introuvable.');
        }

        Quality::setRootCause((int) $nc['id'], self::text($request, 'root_cause', 3000));
        return self::back('non-conformites', 'success', 'Cause racine consignée.');
    }

    public static function setNonconformityStatus(Request $request, array $params): Response
    {
        $nc = Quality::nonconformityById((int) $params['id']);
        if ($nc === null) {
            return self::back('non-conformites', 'error', 'Non-conformité introuvable.');
        }

        $status = $request->input('status');
        $verdict = Quality::setNonconformityStatus((int) $nc['id'], $status);
        if (!$verdict['ok']) {
            return self::back('non-conformites', 'error', $verdict['message']);
        }

        Audit::log('qualite.nc_statut', 'nonconformities', (int) $nc['id'], ['statut' => $status]);
        return self::back('non-conformites', 'success', 'Non-conformité ' . mb_strtolower($status) . '.');
    }

    public static function deleteNonconformity(Request $request, array $params): Response
    {
        $nc = Quality::nonconformityById((int) $params['id']);
        if ($nc === null) {
            return self::back('non-conformites', 'error', 'Non-conformité introuvable.');
        }

        Quality::deleteNonconformity((int) $nc['id']);
        Audit::log('qualite.nc_supprimee', 'nonconformities', (int) $nc['id'], ['reference' => $nc['reference']]);
        return self::back('non-conformites', 'success', 'Non-conformité supprimée.');
    }

    // ---------- Actions ----------

    public static function createAction(Request $request): Response
    {
        $label = self::text($request, 'label', 300);
        if ($label === '') {
            return self::back('actions', 'error', 'Un libellé est requis.');
        }
        if (!in_array($request->input('kind'), Quality::ACTION_KINDS, true)) {
            return self::back('actions', 'error', "Type d'action inconnu.");
        }

        $due = self::date($request, 'due_date');
        if (!$due['ok']) {
            return self::back('actions', 'error', 'Échéance invalide.');
        }

        $ncId = (int) $request->input('nonconformity_id') ?: null;
        $auditId = (int) $request->input('audit_id') ?: null;
        if ($ncId !== null && Quality::nonconformityById($ncId) === null) {
            return self::back('actions', 'error', 'Non-conformité inconnue.');
        }
        if ($auditId !== null && Quality::auditById($auditId) === null) {
            return self::back('actions', 'error', 'Audit inconnu.');
        }

        $id = Quality::createAction([
            'nonconformityId' => $ncId,
            'auditId' => $auditId,
            'kind' => $request->input('kind'),
            'label' => $label,
            'ownerId' => (int) $request->input('owner_id') ?: null,
            'dueDate' => $due['value'],
        ]);
        Audit::log('qualite.action_creee', 'quality_actions', $id, ['libelle' => $label, 'type' => $request->input('kind')]);
        return self::back('actions', 'success', 'Action enregistrée.');
    }

    public static function setActionStatus(Request $request, array $params): Response
    {
        if (!Quality::setActionStatus((int) $params['id'], $request->input('status'))) {
            return self::back('actions', 'error', 'Statut inconnu.');
        }
        return self::back('actions');
    }

    public static function verifyAction(Request $request, array $params): Response
    {
        $user = Session::get('user');
        $effectiveness = $request->input('effectiveness');
        $verdict = Quality::verifyAction((int) $params['id'], $effectiveness, (int) $user['id']);
        if (!$verdict['ok']) {
            return self::back('actions', 'error', $verdict['message']);
        }

        Audit::log('qualite.action_verifiee', 'quality_actions', (int) $params['id'], ['verdict' => $effectiveness]);
        return self::back('actions', 'success', $effectiveness === 'Efficace'
            ? 'Action jugée efficace.'
            : 'Action jugée inefficace : elle appelle une nouvelle réponse.');
    }

    public static function deleteAction(Request $request, array $params): Response
    {
        Quality::deleteAction((int) $params['id']);
        return self::back('actions');
    }

    // ---------- Audits internes ----------

    public static function createAudit(Request $request): Response
    {
        $scope = self::text($request, 'scope', 200);
        if ($scope === '') {
            return self::back('audits', 'error', 'Un périmètre est requis.');
        }

        $planned = self::date($request, 'planned_on');
        if (!$planned['ok']) {
            return self::back('audits', 'error', 'Date invalide.');
        }

        $id = Quality::createAudit([
            'scope' => $scope,
            'standard' => self::text($request, 'standard', 120),
            'plannedOn' => $planned['value'],
            'auditorId' => (int) $request->input('auditor_id') ?: null,
            'summary' => '',
        ]);
        Audit::log('qualite.audit_planifie', 'internal_audits', $id, ['perimetre' => $scope]);
        return self::back('audits', 'success', 'Audit interne planifié.');
    }

    public static function completeAudit(Request $request, array $params): Response
    {
        $internal = Quality::auditById((int) $params['id']);
        if ($internal === null) {
            return self::back('audits', 'error', 'Audit introuvable.');
        }

        $done = self::date($request, 'done_on', true);
        if (!$done['ok']) {
            return self::back('audits', 'error', 'Date de réalisation invalide.');
        }

        Quality::completeAudit((int) $internal['id'], (string) $done['value'], self::text($request, 'summary', 5000));
        Audit::log('qualite.audit_realise', 'internal_audits', (int) $internal['id'], ['date' => $done['value']]);
        return self::back('audits', 'success', 'Audit clos et synthèse enregistrée.');
    }

    public static function addFinding(Request $request, array $params): Response
    {
        $internal = Quality::auditById((int) $params['id']);
        if ($internal === null) {
            return self::back('audits', 'error', 'Audit introuvable.');
        }
        if (!in_array($request->input('kind'), Quality::FINDING_KINDS, true)) {
            return self::back('audits', 'error', 'Type de constat inconnu.');
        }

        $statement = self::text($request, 'statement', 1000);
        if ($statement === '') {
            return self::back('audits', 'error', 'Un constat est requis.');
        }

        Quality::addFinding((int) $internal['id'], $request->input('kind'), self::text($request, 'clause', 60), $statement);
        return self::back('audits', 'success', 'Constat ajouté.');
    }

    public static function promoteFinding(Request $request, array $params): Response
    {
        $user = Session::get('user');
        $verdict = Quality::promoteFinding((int) $params['id'], (int) $user['id']);
        if (!$verdict['ok']) {
            return self::back('audits', 'error', $verdict['message']);
        }

        Audit::log('qualite.constat_promu', 'nonconformities', (int) $verdict['id'], ['constat' => (int) $params['id']]);
        return self::back('non-conformites', 'success',
            'Constat transformé en non-conformité : il a maintenant un porteur et une échéance à recevoir.');
    }

    public static function deleteFinding(Request $request, array $params): Response
    {
        Quality::deleteFinding((int) $params['id']);
        return self::back('audits');
    }

    public static function deleteAudit(Request $request, array $params): Response
    {
        $internal = Quality::auditById((int) $params['id']);
        if ($internal === null) {
            return self::back('audits', 'error', 'Audit introuvable.');
        }

        Quality::deleteAudit((int) $internal['id']);
        Audit::log('qualite.audit_supprime', 'internal_audits', (int) $internal['id'], ['reference' => $internal['reference']]);
        return self::back('audits', 'success', 'Audit supprimé.');
    }
}
