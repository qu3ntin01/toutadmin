<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Core\Session;
use App\Core\View;
use App\Modules\Users;
use App\Modules\Whistleblow;

/**
 * Dispositif d'alerte interne.
 *
 * Deux publics, deux portes : celui qui signale, depuis son compte ou depuis
 * l'écran de suivi qui n'en demande pas ; et le référent, seul à lire. Aucune
 * action sur un signalement n'entre au journal général — elle y désignerait le
 * signalement, donc l'affaire, à des yeux qui n'y ont pas droit. La trace des
 * consultations vit à côté, dans le dispositif lui-même.
 */
final class AlertsController
{
    public static function isReferent(?array $user): bool
    {
        return $user !== null && (int) ($user['is_referent'] ?? 0) === 1;
    }

    private static function user(): ?array
    {
        $session = Session::get('user');
        return is_array($session) ? Users::byId((int) $session['id']) : null;
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    // ---------- Suivi public ----------

    /**
     * Le suivi d'un signalement anonyme ne peut pas passer par un compte : se
     * connecter pour lire la réponse, c'est signer son signalement. Ces routes
     * sont donc les seules du produit ouvertes sans session — protégées par la
     * référence, le code, et une limite de tentatives.
     */
    private static function renderFollow(array $options = []): Response
    {
        $report = $options['report'] ?? null;
        return Response::html(View::render('alerts/follow', [
            'title' => t('alr.followTitle') . ' — ' . t('app.name'),
            'report' => $report,
            'messages' => $report === null ? [] : Whistleblow::messages((int) $report['id']),
            'followError' => $options['error'] ?? false,
            'reference' => $options['reference'] ?? '',
            'code' => $options['code'] ?? '',
            'ackDays' => Whistleblow::ACK_DAYS,
            'outcomeDays' => Whistleblow::OUTCOME_DAYS,
        ]));
    }

    public static function followForm(Request $request): Response
    {
        return self::renderFollow();
    }

    private static function throttled(): bool
    {
        return Security::tooManyAttempts('alerte-suivi', (int) Config::get('login_rate_limit', 10), 900);
    }

    public static function follow(Request $request): Response
    {
        $reference = trim($request->input('reference'));
        if (self::throttled()) {
            return self::renderFollow(['error' => true, 'reference' => $reference]);
        }

        $report = Whistleblow::openFollow($reference, trim($request->input('code')));
        if ($report === null) {
            return self::renderFollow(['error' => true, 'reference' => $reference]);
        }
        return self::renderFollow([
            'report' => $report, 'reference' => $reference, 'code' => trim($request->input('code')),
        ]);
    }

    public static function followMessage(Request $request): Response
    {
        $reference = trim($request->input('reference'));
        $code = trim($request->input('code'));
        if (self::throttled()) {
            return self::renderFollow(['error' => true, 'reference' => $reference]);
        }

        $report = Whistleblow::openFollow($reference, $code);
        if ($report === null) {
            return self::renderFollow(['error' => true, 'reference' => $reference]);
        }

        Whistleblow::addMessage([
            'reportId' => (int) $report['id'], 'kind' => 'auteur', 'body' => $request->input('body'),
        ]);
        return self::renderFollow([
            'report' => Whistleblow::byId((int) $report['id']), 'reference' => $reference, 'code' => $code,
        ]);
    }

    // ---------- Dépôt ----------

    public static function index(Request $request): Response
    {
        $user = (array) self::user();
        $referent = self::isReferent($user);

        // Rendu une seule fois, au retour du dépôt : le code n'existe nulle part
        // ailleurs, et il disparaît de la session avant même d'être affiché.
        $issued = Session::get('whistleblow_issued');
        Session::forget('whistleblow_issued');

        return Response::html(View::page('alerts/index', [
            'title' => t('nav.whistleblow') . ' — ' . t('app.name'),
            'panelLabel' => t('alr.panel'),
            'headerTitle' => t('nav.whistleblow'),
            'headerSubtitle' => t('alr.headerSub'),
            'footLinks' => [['href' => '/alertes/suivi', 'label' => t('alr.followOpen')]],
            'scripts' => ['/js/confirm.js'],
            'isReferent' => $referent,
            'reportList' => $referent ? Whistleblow::all() : [],
            'summary' => $referent ? Whistleblow::summary() : null,
            'overdue' => $referent ? Whistleblow::overdue() : null,
            'categories' => Whistleblow::CATEGORIES,
            'ackDays' => Whistleblow::ACK_DAYS,
            'outcomeDays' => Whistleblow::OUTCOME_DAYS,
            'referentCount' => count(Whistleblow::referents()),
            'issued' => is_array($issued) ? $issued : null,
        ]));
    }

    public static function file(Request $request): Response
    {
        $subject = mb_substr(trim($request->input('subject')), 0, 200);
        if ($subject === '') {
            Flash::set('error', "L'objet du signalement est obligatoire.");
            return Response::redirect('/alertes');
        }
        if (!in_array($request->input('category'), Whistleblow::CATEGORIES, true)) {
            Flash::set('error', 'Catégorie invalide.');
            return Response::redirect('/alertes');
        }
        if (Whistleblow::referents() === []) {
            Flash::set('error', "Aucun référent n'est désigné : le dispositif n'est pas encore ouvert.");
            return Response::redirect('/alertes');
        }

        $anonymous = $request->input('anonymous') === '1';
        $issued = Whistleblow::create([
            'authorId' => (int) Session::get('user')['id'],
            'anonymous' => $anonymous,
            'category' => $request->input('category'),
            'subject' => $subject,
            'body' => mb_substr(trim($request->input('body')), 0, 10000),
        ]);

        // Le journal général retient qu'un signalement est arrivé, jamais lequel
        // ni de qui : le compte est nécessaire au dispositif, le détail lui nuirait.
        Audit::logSystem('alerte.deposee', 'whistleblow_reports', null, ['anonyme' => $anonymous]);

        Session::set('whistleblow_issued', $issued);
        return Response::redirect('/alertes');
    }

    // ---------- Instruction ----------

    public static function show(Request $request, array $params): Response
    {
        $report = Whistleblow::byId((int) $params['id']);
        if ($report === null) {
            return self::error('Signalement introuvable.', 404);
        }

        Whistleblow::noteAccess((int) $report['id'], (int) Session::get('user')['id']);

        return Response::html(View::page('alerts/show', [
            'title' => $report['reference'] . ' — ' . t('app.name'),
            'panelLabel' => t('alr.panel'),
            'headerTitle' => $report['reference'],
            'headerSubtitle' => t('alr.sheetSub'),
            'footLinks' => [['href' => '/alertes', 'label' => t('nav.whistleblow')]],
            'scripts' => ['/js/confirm.js'],
            'report' => $report,
            'messages' => Whistleblow::messages((int) $report['id']),
            'accessList' => Whistleblow::accessLog((int) $report['id']),
            'statuses' => Whistleblow::REPORT_STATUSES,
            'ackDays' => Whistleblow::ACK_DAYS,
            'outcomeDays' => Whistleblow::OUTCOME_DAYS,
            'age' => Whistleblow::daysSince($report['submitted_at']),
        ]));
    }

    /** Une action sur un signalement : elle n'entre jamais au journal général. */
    private static function withReport(array $params, callable $action): Response
    {
        $report = Whistleblow::byId((int) $params['id']);
        if ($report === null) {
            Flash::set('error', 'Signalement introuvable.');
            return Response::redirect('/alertes');
        }
        $action($report);
        return Response::redirect('/alertes/signalements/' . (int) $report['id']);
    }

    public static function acknowledge(Request $request, array $params): Response
    {
        return self::withReport($params, static function (array $report): void {
            Whistleblow::acknowledge((int) $report['id']);
            Flash::set('success', 'Accusé de réception enregistré.');
        });
    }

    public static function setStatus(Request $request, array $params): Response
    {
        return self::withReport($params, static function (array $report) use ($request): void {
            if (!Whistleblow::setStatus((int) $report['id'], $request->input('status'))) {
                Flash::set('error', 'Statut invalide.');
                return;
            }
            Flash::set('success', 'Statut mis à jour.');
        });
    }

    public static function setOutcome(Request $request, array $params): Response
    {
        return self::withReport($params, static function (array $report) use ($request): void {
            Whistleblow::setOutcome((int) $report['id'], $request->input('outcome'));
            Flash::set('success', 'Suites données enregistrées.');
        });
    }

    public static function reply(Request $request, array $params): Response
    {
        return self::withReport($params, static function (array $report) use ($request): void {
            $sent = Whistleblow::addMessage([
                'reportId' => (int) $report['id'], 'kind' => 'referent',
                'referentId' => (int) Session::get('user')['id'], 'body' => $request->input('body'),
            ]);
            Flash::set($sent ? 'success' : 'error', $sent
                ? "Message transmis à l'auteur du signalement."
                : 'Message vide.');
        });
    }
}
