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
use App\Modules\Governance;
use App\Modules\Org;
use App\Modules\Surveys;

/**
 * Direction : réunions, décisions, actions, risques et sondages.
 *
 * La gouvernance de l'entreprise — ce qui est décidé et ce qui est risqué —
 * relève de la direction. Elle n'est pas déléguée par un droit : c'est
 * l'administration de l'instance.
 */
final class GovernanceController
{
    private static function back(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/direction#' . $anchor);
    }

    private static function meetingBack(int $id, string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/direction/reunions/' . $id . '#' . $anchor);
    }

    private static function text(Request $request, string $key, int $max): string
    {
        return mb_substr($request->input($key), 0, $max);
    }

    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    private static function scaleValue(string $raw): ?int
    {
        $value = (int) $raw;
        return in_array($value, Governance::SCALE, true) ? $value : null;
    }

    private static function people(): array
    {
        return Db::all('SELECT id, first_name, last_name, grade FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    public static function index(Request $request): Response
    {
        $stats = Governance::summary();

        return Response::html(View::page('governance/index', [
            'title' => t('gov.title') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.governance'),
            'headerTitle' => t('gov.title'),
            'headerSubtitle' => t('gov.subtitle'),
            'navItems' => [
                ['tab' => 'reunions', 'label' => t('cse.meetings')],
                ['tab' => 'decisions', 'label' => t('gov.decisionRegister'), 'badge' => $stats['decisions'] ?: null],
                ['tab' => 'actions', 'label' => t('gov.actionsToFollow'), 'badge' => $stats['overdueActions'] ?: null],
                ['tab' => 'risques', 'label' => t('gov.riskRegister'), 'badge' => $stats['criticalRisks'] ?: null],
                ['tab' => 'sondages', 'label' => t('nav.surveys')],
            ],
            'footLinks' => [
                ['href' => '/pilotage', 'label' => t('common.dashboard')],
                ['href' => '/admin', 'label' => t('admin.title')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/meter.js'],
            'stats' => $stats,
            'meetingList' => Governance::meetings(),
            'decisionList' => Governance::decisions(),
            'actionList' => Governance::actions(['openOnly' => true]),
            'riskList' => Governance::risks(),
            'matrix' => Governance::matrix(),
            'surveyList' => Surveys::list(),
            'barometer' => Surveys::barometer(),
            'meetingKinds' => Governance::MEETING_KINDS,
            'decisionScopes' => Governance::DECISION_SCOPES,
            'decisionStatuses' => Governance::DECISION_STATUSES,
            'actionStatuses' => Governance::ACTION_STATUSES,
            'riskCategories' => Governance::RISK_CATEGORIES,
            'treatments' => Governance::TREATMENTS,
            'riskStatuses' => Governance::RISK_STATUSES,
            'scale' => Governance::SCALE,
            'criticalThreshold' => Governance::CRITICAL_THRESHOLD,
            'surveyKinds' => Surveys::KINDS,
            'surveyAudiences' => Surveys::AUDIENCES,
            'questionTypes' => Surveys::QUESTION_TYPES,
            'anonymityThreshold' => Surveys::ANONYMITY_THRESHOLD,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
            'employees' => self::people(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Réunions ----------

    public static function createMeeting(Request $request): Response
    {
        $title = self::text($request, 'title', 200);
        if ($title === '') {
            return self::back('reunions', 'error', 'Un intitulé est requis.');
        }
        if (!in_array($request->input('kind'), Governance::MEETING_KINDS, true)) {
            return self::back('reunions', 'error', 'Type de réunion inconnu.');
        }

        $held = self::date($request, 'held_on', true);
        if (!$held['ok']) {
            return self::back('reunions', 'error', 'Date de réunion invalide.');
        }

        $id = Governance::createMeeting([
            'title' => $title,
            'kind' => $request->input('kind'),
            'heldOn' => $held['value'],
            'startsAt' => self::text($request, 'starts_at', 5),
            'endsAt' => self::text($request, 'ends_at', 5),
            'location' => self::text($request, 'location', 160),
            'agenda' => self::text($request, 'agenda', 5000),
            'chairId' => (int) $request->input('chair_id') ?: null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);

        Audit::log('reunion.creee', 'meetings', $id, ['titre' => $title, 'date' => $held['value']]);
        Flash::set('success', "Réunion inscrite à l'agenda de direction.");
        return Response::redirect('/direction/reunions/' . $id);
    }

    public static function showMeeting(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return Response::html(View::page('error', ['message' => 'Réunion introuvable.', 'title' => 'Réunion introuvable.']), 404);
        }

        return Response::html(View::page('governance/meeting', [
            'title' => $meeting['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.governance'),
            'headerTitle' => $meeting['title'],
            'headerSubtitle' => $meeting['kind'],
            'navItems' => [
                ['tab' => 'ordre-du-jour', 'label' => t('cse.agenda')],
                ['tab' => 'participants', 'label' => t('common.participants')],
                ['tab' => 'compte-rendu', 'label' => t('cse.minutes')],
                ['tab' => 'decisions', 'label' => t('gov.decisions')],
                ['tab' => 'actions', 'label' => t('common.actions')],
            ],
            'footLinks' => [['href' => '/direction#reunions', 'label' => t('nav.governance')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'meeting' => $meeting,
            'attendeeList' => Governance::attendees((int) $meeting['id']),
            'decisionList' => Governance::decisions(['meetingId' => (int) $meeting['id']]),
            'actionList' => Governance::actions(['meetingId' => (int) $meeting['id']]),
            'meetingKinds' => Governance::MEETING_KINDS,
            'meetingStatuses' => Governance::MEETING_STATUSES,
            'attendances' => Governance::ATTENDANCES,
            'decisionScopes' => Governance::DECISION_SCOPES,
            'decisionStatuses' => Governance::DECISION_STATUSES,
            'actionStatuses' => Governance::ACTION_STATUSES,
            'employees' => self::people(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function updateMeeting(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::back('reunions', 'error', 'Réunion introuvable.');
        }
        if (!in_array($request->input('kind'), Governance::MEETING_KINDS, true)
            || !in_array($request->input('status'), Governance::MEETING_STATUSES, true)) {
            return self::back('reunions', 'error', 'Valeur inconnue.');
        }

        $held = self::date($request, 'held_on', true);
        $title = self::text($request, 'title', 200);
        if (!$held['ok'] || $title === '') {
            return self::back('reunions', 'error', 'Intitulé ou date invalide.');
        }

        Governance::updateMeeting((int) $meeting['id'], [
            'title' => $title,
            'kind' => $request->input('kind'),
            'heldOn' => $held['value'],
            'startsAt' => self::text($request, 'starts_at', 5),
            'endsAt' => self::text($request, 'ends_at', 5),
            'location' => self::text($request, 'location', 160),
            'agenda' => self::text($request, 'agenda', 5000),
            'chairId' => (int) $request->input('chair_id') ?: null,
            'status' => $request->input('status'),
        ]);
        Audit::log('reunion.modifiee', 'meetings', (int) $meeting['id'], ['titre' => $title]);
        return self::meetingBack((int) $meeting['id'], 'ordre-du-jour', 'success', 'Réunion mise à jour.');
    }

    public static function setMinutes(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::back('reunions', 'error', 'Réunion introuvable.');
        }

        Governance::setMinutes((int) $meeting['id'], self::text($request, 'minutes', 20000));
        Audit::log('reunion.compte_rendu', 'meetings', (int) $meeting['id']);
        return self::meetingBack((int) $meeting['id'], 'compte-rendu', 'success',
            'Compte rendu enregistré. La réunion est marquée tenue.');
    }

    public static function invite(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::back('reunions', 'error', 'Réunion introuvable.');
        }

        $added = 0;
        foreach ($request->inputs('user_ids') as $raw) {
            $userId = (int) $raw;
            if ($userId === 0) {
                continue;
            }
            if (Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$userId]) !== null
                && Governance::invite((int) $meeting['id'], $userId)) {
                $added++;
            }
        }
        return self::meetingBack((int) $meeting['id'], 'participants', $added ? 'success' : 'error',
            $added ? "$added participant(s) convié(s)." : 'Aucun nouveau participant.');
    }

    public static function setAttendance(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::back('reunions', 'error', 'Réunion introuvable.');
        }
        if (!Governance::setAttendance((int) $meeting['id'], (int) $params['userId'], $request->input('attendance'))) {
            return self::back('reunions', 'error', 'Présence inconnue.');
        }
        return self::meetingBack((int) $meeting['id'], 'participants');
    }

    public static function removeAttendee(Request $request, array $params): Response
    {
        Governance::removeAttendee((int) $params['id'], (int) $params['userId']);
        return self::meetingBack((int) $params['id'], 'participants');
    }

    public static function deleteMeeting(Request $request, array $params): Response
    {
        $meeting = Governance::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::back('reunions', 'error', 'Réunion introuvable.');
        }

        Governance::deleteMeeting((int) $meeting['id']);
        Audit::log('reunion.supprimee', 'meetings', (int) $meeting['id'], ['titre' => $meeting['title']]);
        return self::back('reunions', 'success', 'Réunion supprimée. Les décisions prises restent au registre.');
    }

    // ---------- Décisions ----------

    public static function createDecision(Request $request): Response
    {
        $title = self::text($request, 'title', 200);
        if ($title === '') {
            return self::back('decisions', 'error', 'Un intitulé est requis.');
        }
        if (!in_array($request->input('scope'), Governance::DECISION_SCOPES, true)) {
            return self::back('decisions', 'error', 'Portée inconnue.');
        }

        $decided = self::date($request, 'decided_on', true);
        $review = self::date($request, 'review_on');
        if (!$decided['ok'] || !$review['ok']) {
            return self::back('decisions', 'error', 'Date invalide.');
        }

        $meetingId = (int) $request->input('meeting_id') ?: null;
        if ($meetingId !== null && Governance::meetingById($meetingId) === null) {
            return self::back('decisions', 'error', 'Réunion inconnue.');
        }

        $id = Governance::createDecision([
            'meetingId' => $meetingId,
            'title' => $title,
            'body' => self::text($request, 'body', 5000),
            'rationale' => self::text($request, 'rationale', 5000),
            'decidedOn' => $decided['value'],
            'decidedBy' => (int) $request->input('decided_by') ?: (int) Session::get('user')['id'],
            'scope' => $request->input('scope'),
            'reviewOn' => $review['value'],
        ]);
        Audit::log('decision.enregistree', 'decisions', $id, ['titre' => $title, 'portee' => $request->input('scope')]);

        if ($meetingId !== null) {
            return self::meetingBack($meetingId, 'decisions', 'success', 'Décision inscrite au registre.');
        }
        return self::back('decisions', 'success', 'Décision inscrite au registre.');
    }

    public static function setDecisionStatus(Request $request, array $params): Response
    {
        if (!Governance::setDecisionStatus((int) $params['id'], $request->input('status'))) {
            return self::back('decisions', 'error', 'Statut inconnu.');
        }
        Audit::log('decision.statut', 'decisions', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::back('decisions');
    }

    public static function deleteDecision(Request $request, array $params): Response
    {
        Governance::deleteDecision((int) $params['id']);
        Audit::log('decision.supprimee', 'decisions', (int) $params['id']);
        return self::back('decisions', 'success', 'Décision retirée du registre.');
    }

    // ---------- Actions ----------

    public static function createAction(Request $request): Response
    {
        $label = self::text($request, 'label', 300);
        if ($label === '') {
            return self::back('actions', 'error', 'Un libellé est requis.');
        }

        $due = self::date($request, 'due_date');
        if (!$due['ok']) {
            return self::back('actions', 'error', 'Échéance invalide.');
        }

        $meetingId = (int) $request->input('meeting_id') ?: null;
        $id = Governance::createAction([
            'meetingId' => $meetingId,
            'decisionId' => (int) $request->input('decision_id') ?: null,
            'label' => $label,
            'assigneeId' => (int) $request->input('assignee_id') ?: null,
            'dueDate' => $due['value'],
        ]);
        Audit::log('action.creee', 'meeting_actions', $id, ['libelle' => $label]);

        if ($meetingId !== null) {
            return self::meetingBack($meetingId, 'actions', 'success', 'Action confiée.');
        }
        return self::back('actions', 'success', 'Action confiée.');
    }

    public static function setActionStatus(Request $request, array $params): Response
    {
        if (!Governance::setActionStatus((int) $params['id'], $request->input('status'))) {
            return self::back('actions', 'error', 'Statut inconnu.');
        }
        $meetingId = (int) $request->input('meeting_id');
        if ($request->input('back') === 'reunion' && $meetingId > 0) {
            return self::meetingBack($meetingId, 'actions');
        }
        return self::back('actions');
    }

    public static function deleteAction(Request $request, array $params): Response
    {
        Governance::deleteAction((int) $params['id']);
        return self::back('actions');
    }

    // ---------- Registre des risques ----------

    private static function readRisk(Request $request): array
    {
        $title = self::text($request, 'title', 200);
        if ($title === '') {
            return ['ok' => false, 'message' => 'Un intitulé est requis.'];
        }
        if (!in_array($request->input('category'), Governance::RISK_CATEGORIES, true)) {
            return ['ok' => false, 'message' => 'Catégorie inconnue.'];
        }
        if (!in_array($request->input('treatment'), Governance::TREATMENTS, true)) {
            return ['ok' => false, 'message' => 'Traitement inconnu.'];
        }

        $likelihood = self::scaleValue($request->input('likelihood'));
        $impact = self::scaleValue($request->input('impact'));
        if ($likelihood === null || $impact === null) {
            return ['ok' => false, 'message' => 'Cotation hors échelle.'];
        }

        $rawResidualLikelihood = $request->input('residual_likelihood');
        $rawResidualImpact = $request->input('residual_impact');
        $residualLikelihood = $rawResidualLikelihood !== '' ? self::scaleValue($rawResidualLikelihood) : null;
        $residualImpact = $rawResidualImpact !== '' ? self::scaleValue($rawResidualImpact) : null;
        if (($rawResidualLikelihood !== '' && $residualLikelihood === null)
            || ($rawResidualImpact !== '' && $residualImpact === null)) {
            return ['ok' => false, 'message' => 'Cotation résiduelle hors échelle.'];
        }

        $identified = self::date($request, 'identified_on');
        $review = self::date($request, 'next_review');
        if (!$identified['ok'] || !$review['ok']) {
            return ['ok' => false, 'message' => 'Date invalide.'];
        }

        return [
            'ok' => true,
            'fields' => [
                'reference' => self::text($request, 'reference', 40),
                'category' => $request->input('category'),
                'title' => $title,
                'description' => self::text($request, 'description', 3000),
                'likelihood' => $likelihood,
                'impact' => $impact,
                'ownerId' => (int) $request->input('owner_id') ?: null,
                'treatment' => $request->input('treatment'),
                'actionPlan' => self::text($request, 'action_plan', 3000),
                'residualLikelihood' => $residualLikelihood,
                'residualImpact' => $residualImpact,
                'identifiedOn' => $identified['value'],
                'nextReview' => $review['value'],
            ],
        ];
    }

    public static function createRisk(Request $request): Response
    {
        $read = self::readRisk($request);
        if (!$read['ok']) {
            return self::back('risques', 'error', $read['message']);
        }

        $id = Governance::createRisk($read['fields']);
        Audit::log('risque.enregistre', 'enterprise_risks', $id, [
            'titre' => $read['fields']['title'], 'categorie' => $read['fields']['category'],
        ]);
        return self::back('risques', 'success', 'Risque inscrit au registre.');
    }

    public static function updateRisk(Request $request, array $params): Response
    {
        $risk = Governance::riskById((int) $params['id']);
        if ($risk === null) {
            return self::back('risques', 'error', 'Risque introuvable.');
        }
        if (!in_array($request->input('status'), Governance::RISK_STATUSES, true)) {
            return self::back('risques', 'error', 'Statut inconnu.');
        }

        $read = self::readRisk($request);
        if (!$read['ok']) {
            return self::back('risques', 'error', $read['message']);
        }

        Governance::updateRisk((int) $risk['id'], $read['fields'] + ['status' => $request->input('status')]);
        Audit::log('risque.modifie', 'enterprise_risks', (int) $risk['id'], [
            'titre' => $read['fields']['title'], 'statut' => $request->input('status'),
        ]);
        return self::back('risques', 'success', 'Risque mis à jour.');
    }

    public static function deleteRisk(Request $request, array $params): Response
    {
        $risk = Governance::riskById((int) $params['id']);
        if ($risk === null) {
            return self::back('risques', 'error', 'Risque introuvable.');
        }

        Governance::deleteRisk((int) $risk['id']);
        Audit::log('risque.supprime', 'enterprise_risks', (int) $risk['id'], ['titre' => $risk['title']]);
        return self::back('risques', 'success', 'Risque retiré du registre.');
    }

    // ---------- Sondages ----------

    public static function createSurvey(Request $request): Response
    {
        $title = self::text($request, 'title', 200);
        if ($title === '') {
            return self::back('sondages', 'error', 'Un intitulé est requis.');
        }
        if (!in_array($request->input('kind'), Surveys::KINDS, true)) {
            return self::back('sondages', 'error', 'Type de sondage inconnu.');
        }
        if (!in_array($request->input('audience'), Surveys::AUDIENCES, true)) {
            return self::back('sondages', 'error', 'Population inconnue.');
        }

        $opens = self::date($request, 'opens_on');
        $closes = self::date($request, 'closes_on');
        if (!$opens['ok'] || !$closes['ok']) {
            return self::back('sondages', 'error', 'Date invalide.');
        }

        $audience = $request->input('audience');
        $audienceId = $audience === 'Tous' ? null : ((int) $request->input('audience_id') ?: null);
        if ($audience !== 'Tous' && $audienceId === null) {
            return self::back('sondages', 'error', "Précisez le service ou l'équipe concernée.");
        }

        $id = Surveys::create([
            'title' => $title,
            'intro' => self::text($request, 'intro', 2000),
            'kind' => $request->input('kind'),
            'audience' => $audience,
            'audienceId' => $audienceId,
            'opensOn' => $opens['value'],
            'closesOn' => $closes['value'],
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        Audit::log('sondage.cree', 'surveys', $id, ['titre' => $title]);
        return self::back('sondages', 'success', 'Sondage créé. Ajoutez ses questions, puis ouvrez-le.');
    }

    public static function addQuestion(Request $request, array $params): Response
    {
        $survey = Surveys::byId((int) $params['id']);
        if ($survey === null) {
            return self::back('sondages', 'error', 'Sondage introuvable.');
        }
        if ($survey['status'] !== 'Brouillon') {
            return self::back('sondages', 'error',
                'Un sondage ouvert ne se modifie plus : les réponses ne seraient plus comparables.');
        }

        $label = self::text($request, 'label', 300);
        $type = $request->input('type');
        if ($label === '') {
            return self::back('sondages', 'error', 'Une question est requise.');
        }
        if (!in_array($type, array_column(Surveys::QUESTION_TYPES, 'key'), true)) {
            return self::back('sondages', 'error', 'Type de question inconnu.');
        }

        $choices = [];
        if ($type === 'choix') {
            foreach (explode("\n", self::text($request, 'choices', 1000)) as $choice) {
                $choice = trim($choice);
                if ($choice !== '') {
                    $choices[] = $choice;
                }
            }
            $choices = array_slice($choices, 0, 12);
            if (count($choices) < 2) {
                return self::back('sondages', 'error',
                    'Un choix multiple demande au moins deux réponses possibles.');
            }
        }

        Surveys::addQuestion((int) $survey['id'], [
            'label' => $label,
            'type' => $type,
            'choices' => $choices,
            'required' => $request->input('required') === '1',
        ]);
        return self::back('sondages', 'success', 'Question ajoutée.');
    }

    public static function deleteQuestion(Request $request, array $params): Response
    {
        $survey = Surveys::byId((int) $params['id']);
        if ($survey === null) {
            return self::back('sondages', 'error', 'Sondage introuvable.');
        }
        if ($survey['status'] !== 'Brouillon') {
            return self::back('sondages', 'error', 'Un sondage ouvert ne se modifie plus.');
        }

        Surveys::removeQuestion((int) $survey['id'], (int) $params['questionId']);
        return self::back('sondages');
    }

    public static function openSurvey(Request $request, array $params): Response
    {
        $verdict = Surveys::open((int) $params['id']);
        if (!$verdict['ok']) {
            return self::back('sondages', 'error', $verdict['message']);
        }

        Audit::log('sondage.ouvert', 'surveys', (int) $params['id']);
        return self::back('sondages', 'success',
            'Sondage ouvert. Les personnes conviées le voient dans leur espace.');
    }

    public static function closeSurvey(Request $request, array $params): Response
    {
        if (!Surveys::close((int) $params['id'])) {
            return self::back('sondages', 'error', "Ce sondage n'est pas ouvert.");
        }

        Audit::log('sondage.clos', 'surveys', (int) $params['id']);
        return self::back('sondages', 'success', 'Sondage clos.');
    }

    public static function deleteSurvey(Request $request, array $params): Response
    {
        $survey = Surveys::byId((int) $params['id']);
        if ($survey === null) {
            return self::back('sondages', 'error', 'Sondage introuvable.');
        }

        Surveys::remove((int) $survey['id']);
        Audit::log('sondage.supprime', 'surveys', (int) $survey['id'], ['titre' => $survey['title']]);
        return self::back('sondages', 'success', 'Sondage supprimé, réponses comprises.');
    }

    public static function surveyResults(Request $request, array $params): Response
    {
        $result = Surveys::results((int) $params['id']);
        if ($result === null) {
            return Response::html(View::page('error', ['message' => 'Sondage introuvable.', 'title' => 'Sondage introuvable.']), 404);
        }

        return Response::html(View::page('governance/survey-results', [
            'title' => $result['survey']['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.governance'),
            'headerTitle' => $result['survey']['title'],
            'headerSubtitle' => $result['survey']['kind'],
            'navItems' => [],
            'footLinks' => [['href' => '/direction#sondages', 'label' => t('nav.governance')]],
            'scripts' => ['/js/meter.js'],
            'result' => $result,
            'scale' => Surveys::SCALE,
        ]));
    }
}
