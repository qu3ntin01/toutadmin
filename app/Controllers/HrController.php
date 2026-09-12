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
use App\Modules\Hr;
use App\Modules\Org;
use App\Modules\Talent;
use App\Modules\Users;

/**
 * Espace RH.
 *
 * Ouvert à l'administration et aux personnes à qui elle a donné l'accès RH —
 * jamais à un manager du seul fait qu'il encadre : les congés et les fiches de
 * paie ne sont pas des informations d'équipe.
 */
final class HrController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1);
    }

    public static function home(Request $request): Response
    {
        $employees = array_values(array_filter(Users::employees(), static fn (array $e): bool => Hr::isEligible($e)));
        $requests = Hr::allRequests();
        $pending = array_values(array_filter($requests, static fn (array $r): bool => $r['status'] === 'En attente'));

        return Response::html(View::page('hr/index', [
            'title' => t('nav.hrSpace') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.hrSpace'),
            'headerTitle' => t('hr.title'),
            'headerSubtitle' => t('hr.subtitle'),
            'navItems' => [
                ['tab' => 'demandes', 'label' => t('nav.requests'), 'badge' => count($pending) ?: null],
                ['tab' => 'personnel', 'label' => t('admin.staffMembers')],
                ['tab' => 'paie', 'label' => t('nav.payroll')],
                ['tab' => 'documents', 'label' => t('hr.catalogue')],
                ['tab' => 'formations', 'label' => t('hr.training')],
                ['tab' => 'entretiens', 'label' => t('erp.reviews')],
                ['tab' => 'cse', 'label' => t('nav.cse')],
            ],
            'footLinks' => [
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
                ['href' => '/annuaire', 'label' => t('nav.directory')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'employees' => $employees,
            'requests' => $requests,
            'pendingCount' => count($pending),
            'payslips' => Hr::allPayslips(),
            'types' => Hr::REQUEST_TYPES,
            'documents' => Talent::documents(),
            'documentCategories' => Talent::DOCUMENT_CATEGORIES,
            'trainings' => Talent::trainings(),
            'sessions' => Talent::sessions(),
            'registrations' => Talent::registrations(),
            'sessionStatuses' => Talent::SESSION_STATUSES,
            'reviews' => Talent::reviews(),
            // Le CSE : les RH convoquent, valident les candidatures et tiennent
            // la composition ; les élus, eux, écrivent les comptes rendus.
            'cseMandates' => \App\Modules\Cse::mandates(),
            'cseMandateRoles' => \App\Modules\Cse::MANDATE_ROLES,
            'cseElections' => \App\Modules\Cse::elections(),
            'cseElectionStatuses' => \App\Modules\Cse::ELECTION_STATUSES,
            'cseCandidacies' => array_reduce(
                \App\Modules\Cse::elections(),
                static function (array $all, array $election): array {
                    $all[(int) $election['id']] = \App\Modules\Cse::candidacies((int) $election['id']);
                    return $all;
                },
                []
            ),
            'cseTurnout' => array_reduce(
                \App\Modules\Cse::elections(),
                static function (array $all, array $election): array {
                    $all[(int) $election['id']] = \App\Modules\Cse::turnout((int) $election['id']);
                    return $all;
                },
                []
            ),
            'cseResults' => array_reduce(
                \App\Modules\Cse::elections(),
                static function (array $all, array $election): array {
                    $all[(int) $election['id']] = \App\Modules\Cse::results((int) $election['id']);
                    return $all;
                },
                []
            ),
            'cseEligible' => array_values(array_filter(
                Users::employees(),
                static fn (array $e): bool => Hr::isEligible($e)
            )),
            'cseMeetings' => \App\Modules\Cse::meetings(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Demandes ----------

    private static function decide(Request $request, array $params, string $action): Response
    {
        $session = Session::get('user');
        $note = mb_substr($request->input('note'), 0, 500);
        $id = (int) $params['id'];
        $reviewer = (int) $session['id'];

        $result = match ($action) {
            'approve' => Hr::approve($id, $reviewer, $note),
            'reject' => Hr::reject($id, $reviewer, $note),
            default => Hr::revoke($id, $reviewer, $note),
        };
        $messages = [
            'approve' => ['Demande approuvée.', "Cette demande n'est plus en attente."],
            'reject' => ['Demande refusée.', "Cette demande n'est plus en attente."],
            'revoke' => ['Demande annulée, solde recrédité si nécessaire.', 'Cette demande ne peut plus être annulée.'],
        ];
        Flash::set($result['ok'] ? 'success' : 'error', $messages[$action][$result['ok'] ? 0 : 1]);
        return Response::redirect('/rh#demandes');
    }

    public static function approve(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'approve');
    }

    public static function reject(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'reject');
    }

    public static function revoke(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'revoke');
    }

    // ---------- Solde ----------

    public static function adjustBalance(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $employee = Users::employeeById($id);
        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/rh#personnel');
        };
        if ($employee === null || !Hr::isEligible($employee)) {
            return $fail('Membre introuvable ou non éligible.');
        }
        $raw = str_replace(',', '.', $request->input('amount'));
        if (!is_numeric($raw)) {
            return $fail('Ajustement invalide.');
        }
        $amount = round((float) $raw, 2);
        if ($amount === 0.0 || abs($amount) > 365) {
            return $fail('Ajustement invalide.');
        }

        Hr::adjustBalance($id, $amount, mb_substr($request->input('reason'), 0, 300), (int) Session::get('user')['id']);
        $sign = $amount > 0 ? '+' : '';
        Flash::set('success', "Solde de {$employee['first_name']} {$employee['last_name']} ajusté de $sign$amount j.");
        return Response::redirect('/rh#personnel');
    }

    // ---------- Fiches de paie ----------

    public static function createPayslip(Request $request): Response
    {
        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/rh#paie');
        };
        $employee = Users::employeeById((int) $request->input('employee_id'));
        if ($employee === null || !Hr::isEligible($employee)) {
            return $fail('Membre introuvable ou non éligible.');
        }
        $period = $request->input('period');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $fail('Période invalide (format attendu : AAAA-MM).');
        }
        $gross = str_replace(',', '.', $request->input('gross_amount'));
        $net = str_replace(',', '.', $request->input('net_amount'));
        if (!is_numeric($gross) || !is_numeric($net) || (float) $gross < 0 || (float) $net < 0 || (float) $net > (float) $gross) {
            return $fail('Montants invalides (le net ne peut pas dépasser le brut).');
        }

        Hr::createPayslip(
            (int) $employee['id'],
            $period,
            round((float) $gross, 2),
            round((float) $net, 2),
            mb_substr($request->input('note'), 0, 300),
            (int) Session::get('user')['id']
        );
        Flash::set('success', "Fiche de paie $period créée pour {$employee['first_name']} {$employee['last_name']}.");
        return Response::redirect('/rh#paie');
    }

    public static function markPayslipPaid(Request $request, array $params): Response
    {
        Hr::markPayslipPaid((int) $params['id']);
        Flash::set('success', 'Fiche de paie marquée comme payée.');
        return Response::redirect('/rh#paie');
    }

    public static function deletePayslip(Request $request, array $params): Response
    {
        Hr::deletePayslip((int) $params['id']);
        Flash::set('success', 'Fiche de paie supprimée.');
        return Response::redirect('/rh#paie');
    }

    // ---------- Documents d'entreprise ----------

    private static function talentBack(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/rh#' . $anchor);
    }

    public static function createDocument(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 160);
        $category = $request->input('category');
        $url = mb_substr($request->input('url'), 0, 500);

        if ($title === '') {
            return self::talentBack('documents', 'error', "L'intitulé du document est obligatoire.");
        }
        if ($category !== '' && !in_array($category, Talent::DOCUMENT_CATEGORIES, true)) {
            return self::talentBack('documents', 'error', 'Catégorie invalide.');
        }
        if ($url !== '' && !Validate::url($url)) {
            return self::talentBack('documents', 'error', 'Lien invalide : une adresse http(s) est attendue.');
        }

        Talent::createDocument([
            'title' => $title,
            'category' => $category,
            'url' => $url,
            'description' => mb_substr($request->input('description'), 0, 2000),
            'requiresAck' => $request->input('requires_ack') === 'on',
            'publishedAt' => null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        return self::talentBack('documents', 'success', 'Document publié.');
    }

    public static function deleteDocument(Request $request, array $params): Response
    {
        Talent::deleteDocument((int) $params['id']);
        return self::talentBack('documents', 'success', 'Document supprimé, avec ses accusés de réception.');
    }

    // ---------- Formation ----------

    public static function createTraining(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 160);
        if ($title === '') {
            return self::talentBack('formations', 'error', "L'intitulé de la formation est obligatoire.");
        }
        $duration = self::number($request->input('duration_hours'));
        $cost = self::number($request->input('cost'));
        if ($duration === false || ($duration !== null && ($duration < 0 || $duration > 2000))) {
            return self::talentBack('formations', 'error', 'Durée invalide.');
        }
        if ($cost === false || ($cost !== null && $cost < 0)) {
            return self::talentBack('formations', 'error', 'Coût invalide.');
        }

        Talent::createTraining([
            'title' => $title,
            'category' => mb_substr($request->input('category'), 0, 80),
            'provider' => mb_substr($request->input('provider'), 0, 120),
            'description' => mb_substr($request->input('description'), 0, 2000),
            'durationHours' => $duration,
            'cost' => $cost,
        ]);
        return self::talentBack('formations', 'success', 'Formation ajoutée au catalogue.');
    }

    /** null si le champ est vide, false s'il n'est pas un nombre. */
    private static function number(string $raw): float|null|false
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], $trimmed);
        return is_numeric($value) ? round((float) $value, 2) : false;
    }

    public static function deleteTraining(Request $request, array $params): Response
    {
        Talent::deleteTraining((int) $params['id']);
        return self::talentBack('formations', 'success', 'Formation supprimée, avec ses sessions.');
    }

    public static function createSession(Request $request): Response
    {
        $trainingId = (int) $request->input('training_id');
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $seats = (int) $request->input('seats');

        $known = in_array($trainingId, array_map('intval', array_column(Talent::trainings(), 'id')), true);
        if (!$known) {
            return self::talentBack('formations', 'error', 'Formation introuvable.');
        }
        if (!Validate::date($start)) {
            return self::talentBack('formations', 'error', 'Date de début invalide.');
        }
        if ($end !== '' && !Validate::date($end)) {
            return self::talentBack('formations', 'error', 'Date de fin invalide.');
        }
        if ($end !== '' && $end < $start) {
            return self::talentBack('formations', 'error', 'La fin précède le début.');
        }
        if ($seats < 0 || $seats > 1000) {
            return self::talentBack('formations', 'error', 'Nombre de places invalide.');
        }

        Talent::createSession([
            'trainingId' => $trainingId,
            'startDate' => $start,
            'endDate' => $end ?: null,
            'seats' => $seats,
            'location' => mb_substr($request->input('location'), 0, 140),
        ]);
        return self::talentBack('formations', 'success', 'Session programmée.');
    }

    public static function setSessionStatus(Request $request, array $params): Response
    {
        if (!Talent::setSessionStatus((int) $params['id'], $request->input('status'))) {
            return self::talentBack('formations', 'error', 'Statut invalide ou session introuvable.');
        }
        return self::talentBack('formations', 'success', 'Session mise à jour.');
    }

    public static function deleteSession(Request $request, array $params): Response
    {
        Talent::deleteSession((int) $params['id']);
        return self::talentBack('formations', 'success', 'Session supprimée.');
    }

    public static function reviewRegistration(Request $request, array $params): Response
    {
        $result = Talent::reviewRegistration((int) $params['id'], $request->input('status'), (int) Session::get('user')['id']);
        if (!$result['ok']) {
            $messages = [
                'not-found' => 'Inscription introuvable.',
                'full' => 'La session est complète : libérez une place ou augmentez le quota.',
                'bad-status' => 'Décision invalide.',
            ];
            return self::talentBack('formations', 'error', $messages[$result['reason']] ?? 'Décision impossible.');
        }
        return self::talentBack('formations', 'success', 'Inscription mise à jour.');
    }

    // ---------- Entretiens annuels ----------

    public static function createReview(Request $request): Response
    {
        $employeeId = (int) $request->input('employee_id');
        $period = mb_substr($request->input('period'), 0, 40);
        $scheduledOn = $request->input('scheduled_on');

        if (Users::employeeById($employeeId) === null) {
            return self::talentBack('entretiens', 'error', 'Membre introuvable.');
        }
        if ($period === '') {
            return self::talentBack('entretiens', 'error', "La période de l'entretien est obligatoire.");
        }
        if ($scheduledOn !== '' && !Validate::date($scheduledOn)) {
            return self::talentBack('entretiens', 'error', 'Date invalide.');
        }

        Talent::createReview([
            'employeeId' => $employeeId,
            'reviewerId' => (int) $request->input('reviewer_id') ?: null,
            'period' => $period,
            'scheduledOn' => $scheduledOn ?: null,
        ]);
        return self::talentBack('entretiens', 'success', 'Entretien planifié.');
    }

    public static function completeReview(Request $request, array $params): Response
    {
        $rating = $request->input('rating');
        $result = Talent::completeReview((int) $params['id'], [
            'strengths' => mb_substr($request->input('strengths'), 0, 2000),
            'improvements' => mb_substr($request->input('improvements'), 0, 2000),
            'objectives' => mb_substr($request->input('objectives'), 0, 2000),
            'rating' => $rating === '' ? null : (int) $rating,
        ]);
        if (!$result['ok']) {
            $messages = [
                'not-found' => 'Entretien introuvable.',
                'cancelled' => 'Cet entretien est annulé.',
                'bad-rating' => 'Appréciation invalide (1 à 5).',
            ];
            return self::talentBack('entretiens', 'error', $messages[$result['reason']] ?? 'Enregistrement impossible.');
        }
        return self::talentBack('entretiens', 'success', "Compte-rendu d'entretien enregistré.");
    }

    public static function cancelReview(Request $request, array $params): Response
    {
        Talent::cancelReview((int) $params['id']);
        return self::talentBack('entretiens', 'success', 'Entretien annulé.');
    }

    public static function deleteReview(Request $request, array $params): Response
    {
        Talent::deleteReview((int) $params['id']);
        return self::talentBack('entretiens', 'success', 'Entretien supprimé.');
    }

    // ---------- CSE : mandats, élections, réunions ----------

    private static function cseBack(string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/rh#cse');
    }

    public static function addCseMandate(Request $request): Response
    {
        $userId = (int) $request->input('user_id');
        $role = $request->input('mandate_role');
        $startedOn = $request->input('started_on');
        $endsOn = $request->input('ends_on');

        $employee = Users::byId($userId);
        if ($employee === null || $employee['role'] !== 'employee' || !Hr::isEligible($employee)) {
            return self::cseBack('error', 'Membre introuvable ou non représenté par le CSE.');
        }
        if (!in_array($role, \App\Modules\Cse::MANDATE_ROLES, true)) {
            return self::cseBack('error', 'Rôle de mandat invalide.');
        }
        if (!Validate::date($startedOn)) {
            return self::cseBack('error', 'Date de début de mandat invalide.');
        }
        if ($endsOn !== '' && !Validate::date($endsOn)) {
            return self::cseBack('error', 'Date de fin de mandat invalide.');
        }
        if ($endsOn !== '' && $endsOn < $startedOn) {
            return self::cseBack('error', 'La fin du mandat précède son début.');
        }

        \App\Modules\Cse::addMandate([
            'userId' => $userId,
            'mandateRole' => $role,
            'startedOn' => $startedOn,
            'endsOn' => $endsOn !== '' ? $endsOn : null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        return self::cseBack('success',
            $employee['first_name'] . ' ' . $employee['last_name'] . ' siège désormais au CSE.');
    }

    public static function removeCseMandate(Request $request, array $params): Response
    {
        \App\Modules\Cse::removeMandate((int) $params['id']);
        return self::cseBack('success', 'Mandat retiré.');
    }

    public static function createCseElection(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 160);
        $seats = $request->input('seats');
        $candidacyDeadline = $request->input('candidacy_deadline');
        $voteStart = $request->input('vote_start');
        $voteEnd = $request->input('vote_end');

        if ($title === '') {
            return self::cseBack('error', "L'intitulé de l'élection est obligatoire.");
        }
        if (!ctype_digit($seats) || (int) $seats < 1 || (int) $seats > 100) {
            return self::cseBack('error', 'Nombre de sièges invalide.');
        }
        foreach ([[$candidacyDeadline, 'clôture des candidatures'], [$voteStart, 'ouverture du vote'], [$voteEnd, 'clôture du vote']] as [$value, $label]) {
            if ($value !== '' && !Validate::date($value)) {
                return self::cseBack('error', "Date de $label invalide.");
            }
        }
        if ($voteStart !== '' && $voteEnd !== '' && $voteEnd < $voteStart) {
            return self::cseBack('error', 'La clôture du vote précède son ouverture.');
        }

        \App\Modules\Cse::createElection([
            'title' => $title,
            'description' => mb_substr($request->input('description'), 0, 2000),
            'seats' => (int) $seats,
            'candidacyDeadline' => $candidacyDeadline !== '' ? $candidacyDeadline : null,
            'voteStart' => $voteStart !== '' ? $voteStart : null,
            'voteEnd' => $voteEnd !== '' ? $voteEnd : null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        return self::cseBack('success', 'Élection créée : les candidatures sont ouvertes.');
    }

    public static function setCseElectionStatus(Request $request, array $params): Response
    {
        $result = \App\Modules\Cse::setElectionStatus((int) $params['id'], $request->input('status'));
        $messages = [
            'no-candidate' => 'Aucune candidature validée : le scrutin serait vide.',
            'closed' => 'Cette élection est déjà close.',
            'not-found' => 'Élection introuvable.',
            'bad-status' => 'Phase invalide.',
        ];

        if (!$result['ok']) {
            return self::cseBack('error', $messages[$result['reason']] ?? 'Changement de phase impossible.');
        }
        return self::cseBack('success', 'Phase du scrutin mise à jour.');
    }

    public static function deleteCseElection(Request $request, array $params): Response
    {
        \App\Modules\Cse::deleteElection((int) $params['id']);
        return self::cseBack('success', 'Élection supprimée.');
    }

    public static function reviewCseCandidacy(Request $request, array $params): Response
    {
        $status = $request->input('status');
        $result = \App\Modules\Cse::reviewCandidacy((int) $params['id'], $status, (int) Session::get('user')['id']);
        $messages = [
            'closed' => 'Les candidatures de cette élection ne sont plus modifiables.',
            'not-found' => 'Candidature introuvable.',
            'bad-status' => 'Décision invalide.',
        ];

        if (!$result['ok']) {
            return self::cseBack('error', $messages[$result['reason']] ?? 'Décision impossible.');
        }
        return self::cseBack('success', $status === 'Validée' ? 'Candidature validée.' : 'Candidature refusée.');
    }

    public static function createCseMeeting(Request $request): Response
    {
        $title = mb_substr($request->input('title'), 0, 160);
        $meetingDate = $request->input('meeting_date');
        $meetingTime = $request->input('meeting_time');

        if ($title === '') {
            return self::cseBack('error', "L'intitulé de la réunion est obligatoire.");
        }
        if (!Validate::date($meetingDate)) {
            return self::cseBack('error', 'Date de réunion invalide.');
        }
        if ($meetingTime !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $meetingTime)) {
            return self::cseBack('error', 'Heure de réunion invalide.');
        }

        \App\Modules\Cse::createMeeting([
            'title' => $title,
            'meetingDate' => $meetingDate,
            'meetingTime' => $meetingTime,
            'location' => mb_substr($request->input('location'), 0, 140),
            'agenda' => mb_substr($request->input('agenda'), 0, 4000),
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        return self::cseBack('success', 'Réunion convoquée. Les élus pourront y déposer le compte-rendu.');
    }

    public static function deleteCseMeeting(Request $request, array $params): Response
    {
        \App\Modules\Cse::deleteMeeting((int) $params['id']);
        return self::cseBack('success', 'Réunion supprimée.');
    }
}
