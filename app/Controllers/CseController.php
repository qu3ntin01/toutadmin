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
use App\Modules\Cse;
use App\Modules\Users;

/**
 * Comité social et économique.
 *
 * L'espace suppose d'être représenté par le comité : ni les administrateurs,
 * ni les freelances n'y entrent. La gestion, elle, est réservée aux élus dont
 * le mandat court encore.
 */
final class CseController
{
    public static function canAccess(?array $user): bool
    {
        if ($user === null) {
            return false;
        }
        $row = Users::byId((int) $user['id']);
        return $row !== null && Cse::isEligible($row);
    }

    public static function isElected(?array $user): bool
    {
        return $user !== null && Cse::isElected((int) $user['id']);
    }

    private static function back(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/cse#' . $anchor);
    }

    private static function manageBack(string $anchor, string $type = 'success', string $message = ''): Response
    {
        if ($message !== '') {
            Flash::set($type, $message);
        }
        return Response::redirect('/cse/gestion#' . $anchor);
    }

    // ---------- Espace salarié ----------

    public static function index(Request $request): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        $userId = (int) $user['id'];
        $election = Cse::openElection();
        $lastClosed = Db::get("SELECT * FROM cse_elections WHERE status = 'Clôturée' ORDER BY closed_at DESC LIMIT 1");

        return Response::html(View::page('cse/index', [
            'title' => t('cse.title') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('cse.title'),
            'headerSubtitle' => t('cse.subtitle'),
            'navItems' => MemberController::nav($user),
            'scripts' => ['/js/confirm.js'],
            'benefits' => Cse::benefits(true),
            'categories' => Cse::BENEFIT_CATEGORIES,
            'meetings' => Cse::meetingsForStaff(),
            'mandates' => Cse::mandates(),
            'election' => $election,
            // En phase de candidature chacun voit les postulants ; pendant le vote, seuls les validés.
            'candidacies' => $election === null
                ? []
                : Cse::candidacies((int) $election['id'], $election['status'] === 'Vote'),
            'myCandidacy' => $election === null ? null : Cse::candidacyFor((int) $election['id'], $userId),
            'hasVoted' => $election !== null && Cse::hasVoted((int) $election['id'], $userId),
            'isElected' => Cse::isElected($userId),
            'lastClosed' => $lastClosed,
            'closedResults' => $lastClosed === null ? [] : Cse::results((int) $lastClosed['id']),
            'closedTurnout' => $lastClosed === null ? null : Cse::turnout((int) $lastClosed['id']),
        ]));
    }

    /** Se présenter au CSE. */
    public static function apply(Request $request): Response
    {
        $election = Cse::openElection();
        if ($election === null) {
            return self::back('elections', 'error', 'Aucune élection ouverte pour le moment.');
        }

        $result = Cse::applyForElection(
            (int) $election['id'],
            (int) Session::get('user')['id'],
            mb_substr($request->input('statement'), 0, 2000)
        );
        $messages = [
            'closed' => 'Les candidatures ne sont plus ouvertes pour cette élection.',
            'already-applied' => 'Vous avez déjà déposé une candidature.',
            'not-found' => 'Élection introuvable.',
        ];

        if (!$result['ok']) {
            return self::back('elections', 'error', $messages[$result['reason']] ?? 'Candidature impossible.');
        }
        return self::back('elections', 'success', 'Candidature déposée. Elle sera validée par les ressources humaines.');
    }

    public static function withdraw(Request $request): Response
    {
        $election = Cse::openElection();
        if ($election === null) {
            return self::back('elections', 'error', 'Aucune élection ouverte pour le moment.');
        }

        $result = Cse::withdrawCandidacy((int) $election['id'], (int) Session::get('user')['id']);
        if (!$result['ok']) {
            return self::back('elections', 'error', 'Candidature non retirable : le scrutin a déjà avancé.');
        }
        return self::back('elections', 'success', 'Candidature retirée.');
    }

    public static function vote(Request $request): Response
    {
        $election = Cse::openElection();
        if ($election === null) {
            return self::back('elections', 'error', 'Aucune élection ouverte pour le moment.');
        }

        $result = Cse::castBallot(
            (int) $election['id'],
            (int) Session::get('user')['id'],
            (int) $request->input('candidacy_id')
        );
        $messages = [
            'not-open' => "Le vote n'est pas ouvert.",
            'already-voted' => 'Vous avez déjà voté pour cette élection.',
            'bad-candidate' => 'Candidat invalide.',
            'not-found' => 'Élection introuvable.',
        ];

        if (!$result['ok']) {
            return self::back('elections', 'error', $messages[$result['reason']] ?? 'Vote impossible.');
        }
        return self::back('elections', 'success', 'Vote enregistré. Votre bulletin est anonyme.');
    }

    // ---------- Espace de gestion des élus ----------

    public static function manage(Request $request): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        $meetings = Cse::meetings();
        $pendingMinutes = count(array_filter($meetings, static fn (array $m): bool => (int) $m['minutes_published'] === 0));

        return Response::html(View::page('cse/manage', [
            'title' => t('cse.manageTitle') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.cse'),
            'headerTitle' => t('cse.manageTitle'),
            'headerSubtitle' => t('cse.manageSubtitle'),
            'navItems' => [
                ['tab' => 'avantages', 'label' => t('cse.benefits')],
                ['tab' => 'reunions', 'label' => t('cse.meetings'), 'badge' => $pendingMinutes ?: null],
                ['tab' => 'elus', 'label' => t('cse.elected')],
            ],
            'footLinks' => [['href' => '/cse', 'label' => t('nav.cse')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'benefits' => Cse::benefits(),
            'categories' => Cse::BENEFIT_CATEGORIES,
            'meetings' => $meetings,
            'mandates' => Cse::mandates(),
            'myMandate' => Cse::mandateFor((int) $user['id']),
            'stats' => [
                'benefitCount' => count(Cse::benefits(true)),
                'meetingCount' => count(Cse::upcomingMeetings(50)),
                'pendingMinutes' => $pendingMinutes,
                'memberCount' => count(Cse::mandates()),
            ],
            'today' => gmdate('Y-m-d'),
        ]));
    }

    private static function readBenefit(Request $request, bool $activeFromForm): array
    {
        $title = mb_substr($request->input('title'), 0, 140);
        $url = mb_substr($request->input('url'), 0, 500);
        $validUntil = $request->input('valid_until');

        if ($title === '') {
            return ['ok' => false, 'message' => "L'intitulé de l'avantage est obligatoire."];
        }
        if ($url !== '' && !Validate::url($url)) {
            return ['ok' => false, 'message' => 'Lien invalide : une adresse http(s) est attendue.'];
        }
        if ($validUntil !== '' && !Validate::date($validUntil)) {
            return ['ok' => false, 'message' => 'Date de validité invalide.'];
        }

        $category = $request->input('category');
        if ($category !== '' && !in_array($category, Cse::BENEFIT_CATEGORIES, true)) {
            return ['ok' => false, 'message' => 'Catégorie invalide.'];
        }

        return [
            'ok' => true,
            'data' => [
                'title' => $title,
                'category' => $category,
                'partner' => mb_substr($request->input('partner'), 0, 140),
                'description' => mb_substr($request->input('description'), 0, 2000),
                'discount' => mb_substr($request->input('discount'), 0, 60),
                'code' => mb_substr($request->input('code'), 0, 60),
                'url' => $url,
                'validUntil' => $validUntil !== '' ? $validUntil : null,
                'active' => $activeFromForm,
            ],
        ];
    }

    public static function createBenefit(Request $request): Response
    {
        $parsed = self::readBenefit($request, true);
        if (!$parsed['ok']) {
            return self::manageBack('avantages', 'error', $parsed['message']);
        }

        Cse::createBenefit($parsed['data'] + ['createdBy' => (int) Session::get('user')['id']]);
        return self::manageBack('avantages', 'success', 'Avantage publié.');
    }

    public static function updateBenefit(Request $request, array $params): Response
    {
        $benefit = Cse::benefitById((int) $params['id']);
        if ($benefit === null) {
            return self::manageBack('avantages', 'error', 'Avantage introuvable.');
        }

        $parsed = self::readBenefit($request, $request->input('active') === 'on');
        if (!$parsed['ok']) {
            return self::manageBack('avantages', 'error', $parsed['message']);
        }

        Cse::updateBenefit((int) $benefit['id'], $parsed['data']);
        return self::manageBack('avantages', 'success', 'Avantage mis à jour.');
    }

    public static function deleteBenefit(Request $request, array $params): Response
    {
        Cse::deleteBenefit((int) $params['id']);
        return self::manageBack('avantages', 'success', 'Avantage supprimé.');
    }

    /** Le compte-rendu est rédigé par les élus ; la convocation, elle, vient des RH. */
    public static function saveMinutes(Request $request, array $params): Response
    {
        $minutes = mb_substr($request->input('minutes'), 0, 20000);
        $publish = $request->input('publish') === 'on';

        if (!Cse::saveMinutes((int) $params['id'], $minutes, $publish)) {
            return self::manageBack('reunions', 'error', 'Réunion introuvable.');
        }
        return self::manageBack('reunions', 'success',
            $publish ? 'Compte-rendu publié.' : 'Compte-rendu enregistré en brouillon.');
    }
}
