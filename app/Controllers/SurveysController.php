<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Surveys;
use App\Modules\Users;

/**
 * Sondages, côté salarié.
 *
 * Répondre est un acte personnel : chacun accède à ses sondages, personne
 * d'autre. Rien ici ne permet de lire les réponses — c'est l'écran de
 * direction qui montre les agrégats, et lui seul.
 */
final class SurveysController
{
    public static function index(Request $request): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        $userId = (int) $user['id'];

        $answered = array_values(array_filter(
            array_merge(Surveys::list('Clos'), Surveys::list('Ouvert')),
            static fn (array $s): bool => Surveys::hasAnswered((int) $s['id'], $userId)
        ));

        return Response::html(View::page('surveys/index', [
            'title' => t('nav.surveys') . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => t('nav.surveys'),
            'headerSubtitle' => t('srv.headerSub'),
            'navItems' => MemberController::nav($user),
            'invitations' => Surveys::openFor($userId),
            'answered' => $answered,
            'anonymityThreshold' => Surveys::ANONYMITY_THRESHOLD,
        ]));
    }

    public static function show(Request $request, array $params): Response
    {
        $user = Users::byId((int) Session::get('user')['id']);
        $userId = (int) $user['id'];
        $survey = Surveys::byId((int) $params['id']);

        if ($survey === null || $survey['status'] !== 'Ouvert' || !Surveys::isInvited($survey, $userId)) {
            return Response::html(View::page('error', [
                'message' => "Ce sondage ne vous est pas ouvert.",
                'title' => "Ce sondage ne vous est pas ouvert.",
            ]), 404);
        }
        if (Surveys::hasAnswered((int) $survey['id'], $userId)) {
            Flash::set('error', 'Vous avez déjà répondu à ce sondage.');
            return Response::redirect('/sondages');
        }

        return Response::html(View::page('surveys/show', [
            'title' => $survey['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('app.portal'),
            'headerTitle' => $survey['title'],
            'headerSubtitle' => $survey['kind'] . ' · réponse anonyme',
            'navItems' => MemberController::nav($user),
            'survey' => $survey,
            'questionList' => Surveys::questions((int) $survey['id']),
            'scale' => Surveys::SCALE,
        ]));
    }

    public static function submit(Request $request, array $params): Response
    {
        $userId = (int) Session::get('user')['id'];
        $id = (int) $params['id'];

        $values = [];
        foreach (Surveys::questions($id) as $question) {
            $key = 'q_' . (int) $question['id'];
            $values[$key] = $request->input($key);
        }

        $verdict = Surveys::submit($id, $userId, $values);
        if (!$verdict['ok']) {
            Flash::set('error', $verdict['message']);
            return Response::redirect('/sondages/' . $id);
        }

        // La trace dit qu'il a répondu, jamais ce qu'il a répondu : le journal
        // d'audit ne doit pas rendre par la bande ce que les tables refusent.
        Audit::log('sondage.repondu', 'surveys', $id);
        Flash::set('success', 'Merci. Votre réponse est enregistrée sans lien avec votre nom.');
        return Response::redirect('/sondages');
    }
}
