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
use App\Modules\Notifications;
use App\Modules\Signing;
use App\Modules\Users;

/**
 * Parapheur.
 *
 * Mettre un document à la signature engage l'entreprise : c'est l'affaire de
 * l'administration et des RH. Signer, en revanche, concerne chacun.
 */
final class SigningController
{
    public static function canOpen(?array $user): bool
    {
        return HrController::canAccess($user);
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/parapheur#' . $anchor);
    }

    private static function toRequest(int $id, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect("/parapheur/$id");
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    /**
     * Un document au parapheur ne se lit que par ses parties, l'administration
     * et les RH : c'est souvent un contrat de travail.
     */
    private static function load(int $id, array $user): array|Response
    {
        $request = Signing::decorate(Signing::byId($id));
        if ($request === null) {
            return self::error('Document introuvable.', 404);
        }
        if (!self::canOpen($user) && !Signing::isParty($request, (int) $user['id'])) {
            return self::error('Ce document ne vous concerne pas.', 403);
        }
        return $request;
    }

    public static function index(Request $request): Response
    {
        $user = self::user();
        $opener = self::canOpen($user);
        $pending = Signing::pendingFor((int) $user['id']);

        return Response::html(View::page('signing/index', [
            'title' => t('nav.signing') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.signing'),
            'headerTitle' => t('nav.signing'),
            'headerSubtitle' => t('sig.headerSub'),
            'navItems' => array_values(array_filter([
                ['tab' => 'documents', 'label' => t('sig.tabMyDocuments'), 'badge' => count($pending) ?: null],
                $opener ? ['tab' => 'nouveau', 'label' => t('sig.tabPutToSign')] : null,
            ])),
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'opener' => $opener,
            'mine' => Signing::forUser((int) $user['id']),
            'pending' => $pending,
            'all' => $opener ? Signing::list() : [],
            'stats' => Signing::summary(),
            'kinds' => Signing::KINDS,
            'employees' => Db::all(
                "SELECT id, first_name, last_name, grade FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE"
            ),
            'maxSigners' => Signing::MAX_SIGNERS,
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function create(Request $request): Response
    {
        $deadline = $request->input('deadline');
        if ($deadline !== '' && !Validate::date($deadline)) {
            return self::back('nouveau', 'error', 'Échéance invalide.');
        }

        // L'ordre des signataires est celui de la saisie : le parapheur circule.
        // Les rôles sont appariés avant d'écarter les cases vides, sinon un rang
        // laissé libre décalerait tous les suivants.
        $ids = $request->inputs('signer_ids');
        $roles = $request->inputs('signer_roles');
        $signers = [];
        foreach ($ids as $index => $id) {
            if ((int) $id !== 0) {
                $signers[] = ['userId' => (int) $id, 'roleLabel' => $roles[$index] ?? ''];
            }
        }

        $verdict = Signing::create([
            'title' => $request->input('title'),
            'kind' => $request->input('kind'),
            'body' => $request->input('body'),
            'file' => $request->file('document'),
            'deadline' => $deadline ?: null,
            'createdBy' => (int) Session::get('user')['id'],
            'signers' => $signers,
        ]);
        if (!$verdict['ok']) {
            return self::back('nouveau', 'error', $verdict['message']);
        }

        Audit::log('parapheur.ouvert', 'signature_requests', $verdict['id'], [
            'titre' => $request->input('title'),
            'empreinte' => substr($verdict['sha256'], 0, 16),
            'signataires' => count($signers),
        ]);

        // Le premier signataire est prévenu ; les suivants le seront à leur tour.
        $first = Signing::signersOf($verdict['id'])[0] ?? null;
        if ($first !== null) {
            Notifications::push(
                (int) $first['user_id'],
                'Document à signer — ' . mb_substr($request->input('title'), 0, 120),
                [
                    'kind' => 'document',
                    'body' => 'Le parapheur attend votre signature.',
                    'link' => '/parapheur/' . $verdict['id'],
                    'dedupeKey' => 'parapheur:' . $verdict['id'] . ':' . $first['user_id'],
                ]
            );
        }
        return self::toRequest($verdict['id'], 'success', 'Document mis à la signature.');
    }

    public static function show(Request $request, array $params): Response
    {
        $user = self::user();
        $loaded = self::load((int) $params['id'], $user);
        if ($loaded instanceof Response) {
            return $loaded;
        }

        return Response::html(View::page('signing/show', [
            'title' => $loaded['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.signing'),
            'headerTitle' => $loaded['title'],
            'headerSubtitle' => $loaded['kind'],
            'navItems' => [['href' => '/parapheur', 'label' => t('nav.signing')]],
            'footLinks' => [['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/confirm.js'],
            'request' => $loaded,
            'verification' => Signing::verify($loaded),
            'myTurn' => Signing::canSign($loaded, (int) $user['id']),
            'opener' => self::canOpen($user),
        ]));
    }

    /** Le document tel qu'il a été mis à la signature — jamais une version d'après. */
    public static function download(Request $request, array $params): Response
    {
        $loaded = self::load((int) $params['id'], self::user());
        if ($loaded instanceof Response) {
            return $loaded;
        }
        if (empty($loaded['file_name'])) {
            return self::error('Ce document est un texte, affiché sur sa page.', 404);
        }

        $integrity = Signing::verifyDocument($loaded);
        if (!$integrity['ok']) {
            return self::error(
                'Document non servi : ' . $integrity['reason'] . ". Prévenez l'administration.",
                409
            );
        }

        Audit::log('parapheur.telecharge', 'signature_requests', (int) $loaded['id']);
        return Response::text((string) file_get_contents((string) Signing::pathOf($loaded)))->withHeaders([
            'Content-Type' => (string) $loaded['mime_type'],
            'Content-Disposition' => 'attachment; filename="'
                . str_replace('"', '', (string) ($loaded['original_name'] ?: 'document')) . '"',
        ]);
    }

    public static function sign(Request $request, array $params): Response
    {
        $user = self::user();
        $loaded = self::load((int) $params['id'], $user);
        if ($loaded instanceof Response) {
            return $loaded;
        }
        $id = (int) $loaded['id'];

        $verdict = Signing::sign(
            $id,
            (int) $user['id'],
            $request->input('password'),
            $request->input('consent') === '1',
            $request->header('x-forwarded-for')
        );
        if (!$verdict['ok']) {
            return self::toRequest($id, 'error', $verdict['message']);
        }

        Audit::log('parapheur.signe', 'signature_requests', $id, [
            'sceau' => substr($verdict['seal'], 0, 16), 'reste' => $verdict['remaining'],
        ]);

        // Au suivant : la notification suit le circuit plutôt que d'attendre
        // qu'on y pense.
        $next = null;
        foreach (Signing::signersOf($id) as $signer) {
            if ($signer['status'] === 'En attente') {
                $next = $signer;
                break;
            }
        }
        if ($next !== null) {
            Notifications::push(
                (int) $next['user_id'],
                'Document à signer — ' . mb_substr((string) $loaded['title'], 0, 120),
                [
                    'kind' => 'document',
                    'body' => 'Le parapheur attend votre signature.',
                    'link' => "/parapheur/$id",
                    'dedupeKey' => "parapheur:$id:" . $next['user_id'],
                ]
            );
        } elseif (!empty($loaded['created_by'])) {
            Notifications::push(
                (int) $loaded['created_by'],
                'Document signé — ' . mb_substr((string) $loaded['title'], 0, 120),
                [
                    'kind' => 'document',
                    'body' => 'Tous les signataires se sont prononcés.',
                    'link' => "/parapheur/$id/attestation",
                    'dedupeKey' => "parapheur:$id:complet",
                ]
            );
        }

        return self::toRequest($id, 'success', $verdict['completed']
            ? 'Signature apposée. Le document est intégralement signé.'
            : 'Signature apposée. Le parapheur passe au signataire suivant.');
    }

    public static function refuse(Request $request, array $params): Response
    {
        $user = self::user();
        $loaded = self::load((int) $params['id'], $user);
        if ($loaded instanceof Response) {
            return $loaded;
        }
        $id = (int) $loaded['id'];
        $reason = $request->input('reason');

        $verdict = Signing::refuse($id, (int) $user['id'], $reason, $request->header('x-forwarded-for'));
        if (!$verdict['ok']) {
            return self::toRequest($id, 'error', $verdict['message']);
        }

        Audit::log('parapheur.refuse', 'signature_requests', $id, ['motif' => mb_substr($reason, 0, 120)]);
        if (!empty($loaded['created_by'])) {
            Notifications::push(
                (int) $loaded['created_by'],
                'Signature refusée — ' . mb_substr((string) $loaded['title'], 0, 120),
                [
                    'kind' => 'document',
                    'body' => mb_substr($reason, 0, 300),
                    'link' => "/parapheur/$id",
                    'dedupeKey' => "parapheur:$id:refus",
                ]
            );
        }
        return self::toRequest($id, 'success', 'Refus enregistré et motivé. Le circuit est interrompu.');
    }

    public static function cancel(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $verdict = Signing::cancel($id, $request->input('reason'));
        if (!$verdict['ok']) {
            return self::back('documents', 'error', $verdict['message']);
        }
        Audit::log('parapheur.annule', 'signature_requests', $id, ['motif' => mb_substr($request->input('reason'), 0, 120)]);
        return self::toRequest($id, 'success', 'Document retiré de la signature.');
    }

    public static function remove(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $verdict = Signing::remove($id);
        if (!$verdict['ok']) {
            return self::back('documents', 'error', $verdict['message']);
        }
        Audit::log('parapheur.supprime', 'signature_requests', $id);
        return self::back('documents', 'success', 'Document supprimé.');
    }

    /** L'attestation : ce qu'on imprime et qu'on joint au dossier. */
    public static function certificate(Request $request, array $params): Response
    {
        $loaded = self::load((int) $params['id'], self::user());
        if ($loaded instanceof Response) {
            return $loaded;
        }
        $certificate = Signing::certificate((int) $loaded['id']);
        return Response::html(View::page('signing/certificate', [
            'title' => t('sig.attestation') . ' — ' . $loaded['title'],
            'panelLabel' => t('nav.signing'),
            'headerTitle' => t('sig.attestation'),
            'headerSubtitle' => $loaded['title'],
            'navItems' => [['href' => '/parapheur/' . $loaded['id'], 'label' => t('common.back')]],
            'footLinks' => [],
            'request' => $certificate['request'],
            'verification' => $certificate['verification'],
            'generatedAt' => gmdate('c'),
        ]));
    }
}
