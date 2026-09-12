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
use App\Modules\Events;
use App\Modules\Org;
use App\Modules\Users;

/**
 * Événements d'entreprise.
 *
 * Tout le monde voit les événements qui le concernent ; seuls l'administration
 * et les ressources humaines en créent. Un séminaire engage un budget et le
 * temps de travail de l'entreprise : ce n'est pas une invitation entre
 * collègues.
 */
final class EventsController
{
    public static function canManage(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1);
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function fail(string $target, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($target);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    private static function people(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    /** « 2026-09-07 09:00 » : la minute suffit, la seconde n'apporte rien ici. */
    private static function moment(string $raw, bool $required = false): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => !$required, 'value' => null];
        }
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})$/', $trimmed, $m) || !Validate::date($m[1])) {
            return ['ok' => false, 'value' => null];
        }
        if ((int) $m[2] > 23 || (int) $m[3] > 59) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $m[1] . ' ' . $m[2] . ':' . $m[3]];
    }

    private static function amount(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => null];
        }
        $value = str_replace([' ', ','], ['', '.'], $trimmed);
        if (!is_numeric($value) || (float) $value < 0 || (float) $value > 1e8) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => round((float) $value, 2)];
    }

    /** Les champs d'un événement, contrôlés une fois pour la création et la reprise. */
    private static function fields(Request $request): array
    {
        $title = mb_substr(trim($request->input('title')), 0, 150);
        if ($title === '') {
            return ['ok' => false, 'message' => "Intitulé de l'événement obligatoire."];
        }
        if (!in_array($request->input('kind'), Events::KINDS, true)) {
            return ['ok' => false, 'message' => "Nature d'événement invalide."];
        }

        $scope = in_array($request->input('scope'), ['department', 'team'], true) ? $request->input('scope') : 'company';
        $scopeId = (int) $request->input('scope_id') ?: null;
        if ($scope !== 'company' && $scopeId === null) {
            return ['ok' => false, 'message' => "Choisissez le service ou l'équipe visé."];
        }
        if ($scope === 'department' && Org::departmentById($scopeId) === null) {
            return ['ok' => false, 'message' => 'Service introuvable.'];
        }
        if ($scope === 'team' && Org::teamById($scopeId) === null) {
            return ['ok' => false, 'message' => 'Équipe introuvable.'];
        }

        $starts = self::moment($request->input('starts_at'), true);
        $ends = self::moment($request->input('ends_at'));
        if (!$starts['ok'] || !$ends['ok']) {
            return ['ok' => false, 'message' => 'Date invalide.'];
        }
        if ($ends['value'] !== null && $ends['value'] < $starts['value']) {
            return ['ok' => false, 'message' => 'La fin précède le début.'];
        }

        $closes = trim($request->input('registration_closes_on'));
        if ($closes !== '' && !Validate::date($closes)) {
            return ['ok' => false, 'message' => 'Date de clôture invalide.'];
        }
        // Clore les inscriptions après l'événement n'aurait aucun effet : autant le dire.
        if ($closes !== '' && $closes > substr((string) $starts['value'], 0, 10)) {
            return ['ok' => false, 'message' => "La clôture des inscriptions doit précéder l'événement."];
        }

        $capacityRaw = trim($request->input('capacity'));
        $capacity = $capacityRaw === '' ? 0 : (int) $capacityRaw;
        if ((string) $capacity !== ($capacityRaw === '' ? '0' : $capacityRaw) || $capacity < 0 || $capacity > 100000) {
            return ['ok' => false, 'message' => 'Capacité invalide.'];
        }

        $budget = self::amount($request->input('budget'));
        $cost = self::amount($request->input('cost'));
        if (!$budget['ok'] || !$cost['ok']) {
            return ['ok' => false, 'message' => 'Montant invalide.'];
        }

        return ['ok' => true, 'fields' => [
            'title' => $title,
            'kind' => $request->input('kind'),
            'description' => mb_substr(trim($request->input('description')), 0, 4000),
            'location' => mb_substr(trim($request->input('location')), 0, 200),
            'startsAt' => $starts['value'],
            'endsAt' => $ends['value'],
            'scope' => $scope,
            'scopeId' => $scopeId,
            'capacity' => $capacity,
            'registrationClosesOn' => $closes !== '' ? $closes : null,
            'budget' => $budget['value'],
            'cost' => $cost['value'],
        ]];
    }

    // ---------- Écrans ----------

    public static function index(Request $request): Response
    {
        $user = self::user();
        $manage = self::canManage($user);

        $mine = array_values(array_filter(
            Events::visibleTo($user, ['limit' => 200]),
            static fn (array $event): bool => !empty($event['my_status']) && $event['my_status'] !== 'Annulée'
        ));

        return Response::html(View::page('events/index', [
            'title' => t('nav.events') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.events'),
            'headerTitle' => t('nav.events'),
            'headerSubtitle' => t('evt.headerSub'),
            'footLinks' => [['href' => '/agenda', 'label' => t('nav.agenda')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'eventList' => $manage ? Events::all() : Events::visibleTo($user),
            'mine' => $mine,
            'canManage' => $manage,
            'summary' => Events::summary(),
            'kinds' => Events::KINDS,
            'statuses' => Events::EVENT_STATUSES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
            'employees' => self::people(),
        ]));
    }

    public static function show(Request $request, array $params): Response
    {
        $user = self::user();
        $event = Events::byId((int) $params['id']);
        $manage = self::canManage($user);

        // Un brouillon n'existe pas encore pour l'entreprise ; hors de son
        // périmètre, un événement n'est pas non plus une information publique.
        if ($event === null
            || (!$manage && ($event['status'] === 'Brouillon' || !Events::concerns($event, $user)))) {
            return self::error('Événement introuvable.', 404);
        }

        return Response::html(View::page('events/show', [
            'title' => $event['title'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.events'),
            'headerTitle' => $event['title'],
            'headerSubtitle' => t('evt.sheetSub'),
            'footLinks' => [['href' => '/evenements', 'label' => t('nav.events')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'event' => $event,
            // La liste nominative des participants reste à l'organisateur :
            // l'annuaire laisse chacun se retirer, un émargement ne doit pas le
            // contourner.
            'registrationList' => $manage ? Events::registrations((int) $event['id']) : [],
            'myRegistration' => Events::registrationOf((int) $event['id'], (int) $user['id']),
            'seatsLeft' => Events::seatsLeft($event),
            'canManage' => $manage,
            'concerned' => Events::concerns($event, $user),
            'kinds' => Events::KINDS,
            'statuses' => Events::EVENT_STATUSES,
            'departments' => Org::departments(),
            'teams' => Org::teams(),
            'employees' => self::people(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Organisation ----------

    public static function create(Request $request): Response
    {
        $parsed = self::fields($request);
        if (!$parsed['ok']) {
            return self::fail('/evenements#nouveau', $parsed['message']);
        }

        $id = Events::create(array_merge($parsed['fields'], [
            'organizerId' => (int) Session::get('user')['id'], 'status' => 'Brouillon',
        ]));
        Audit::log('evenements.cree', 'company_events', $id, ['titre' => $parsed['fields']['title']]);
        Flash::set('success', 'Événement créé en brouillon : ouvrez les inscriptions quand il est prêt.');
        return Response::redirect('/evenements/' . $id);
    }

    public static function update(Request $request, array $params): Response
    {
        $event = Events::byId((int) $params['id']);
        if ($event === null) {
            return self::fail('/evenements', 'Événement introuvable.');
        }

        $parsed = self::fields($request);
        if (!$parsed['ok']) {
            return self::fail('/evenements/' . (int) $event['id'], $parsed['message']);
        }
        if (!in_array($request->input('status'), Events::EVENT_STATUSES, true)) {
            return self::fail('/evenements/' . (int) $event['id'], 'Statut invalide.');
        }
        // Réduire la capacité sous le nombre d'inscrits reviendrait à décider en
        // silence qui reste dehors.
        if ($parsed['fields']['capacity'] > 0 && $parsed['fields']['capacity'] < (int) $event['taken']) {
            return self::fail(
                '/evenements/' . (int) $event['id'],
                $event['taken'] . ' personnes sont inscrites : la capacité ne peut pas descendre à '
                . $parsed['fields']['capacity'] . '.'
            );
        }

        $wasCancelled = $event['status'] === 'Annulé';
        Events::update((int) $event['id'], array_merge($parsed['fields'], [
            'organizerId' => (int) $request->input('organizer_id') ?: $event['organizer_id'],
            'status' => $request->input('status'),
        ]));
        if ($request->input('status') === 'Annulé' && !$wasCancelled) {
            Events::notifyCancellation((array) Events::byId((int) $event['id']));
        }
        Events::syncFullness((int) $event['id']);

        Flash::set('success', 'Événement mis à jour.');
        return Response::redirect('/evenements/' . (int) $event['id']);
    }

    public static function delete(Request $request, array $params): Response
    {
        $event = Events::byId((int) $params['id']);
        if ($event === null) {
            return self::fail('/evenements', 'Événement introuvable.');
        }

        Events::remove((int) $event['id']);
        Audit::log('evenements.supprime', 'company_events', (int) $event['id'], ['titre' => $event['title']]);
        Flash::set('success', 'Événement supprimé, avec ses inscriptions.');
        return Response::redirect('/evenements');
    }

    /** L'organisateur inscrit quelqu'un : la file s'applique de la même façon. */
    public static function registerSomeone(Request $request, array $params): Response
    {
        $event = Events::byId((int) $params['id']);
        if ($event === null) {
            return self::fail('/evenements', 'Événement introuvable.');
        }

        $userId = (int) $request->input('user_id');
        if (Db::get('SELECT 1 AS ok FROM users WHERE id = ? AND active = 1', [$userId]) === null) {
            return self::fail('/evenements/' . (int) $event['id'], 'Membre introuvable.');
        }

        $result = Events::register((int) $event['id'], $userId);
        if (!$result['ok']) {
            return self::fail('/evenements/' . (int) $event['id'], match ($result['reason']) {
                'ferme' => 'Les inscriptions ne sont pas ouvertes.',
                'cloture' => 'Les inscriptions sont closes.',
                'deja' => 'Cette personne est déjà inscrite.',
                default => 'Inscription impossible.',
            });
        }
        Flash::set('success', $result['status'] === 'Inscrit' ? 'Inscription enregistrée.' : "Ajouté à la liste d'attente.");
        return Response::redirect('/evenements/' . (int) $event['id']);
    }

    public static function markAttendance(Request $request, array $params): Response
    {
        $row = Db::get('SELECT event_id FROM event_registrations WHERE id = ?', [(int) $params['id']]);
        if ($row === null) {
            return self::fail('/evenements', 'Inscription introuvable.');
        }
        if (!Events::markAttendance((int) $params['id'], $request->input('presence'))) {
            return self::fail('/evenements/' . (int) $row['event_id'], 'Émargement invalide.');
        }
        Flash::set('success', 'Émargement enregistré.');
        return Response::redirect('/evenements/' . (int) $row['event_id']);
    }

    // ---------- Participation ----------

    public static function register(Request $request, array $params): Response
    {
        $user = self::user();
        $event = Events::byId((int) $params['id']);
        if ($event === null) {
            return self::fail('/evenements', 'Événement introuvable.');
        }
        if ($event['status'] === 'Brouillon' || !Events::concerns($event, $user)) {
            return self::error("Cet événement ne vous est pas ouvert.", 403);
        }

        $result = Events::register((int) $event['id'], (int) $user['id']);
        if (!$result['ok']) {
            return self::fail('/evenements/' . (int) $event['id'], match ($result['reason']) {
                'ferme' => 'Les inscriptions ne sont pas ouvertes.',
                'cloture' => 'Les inscriptions sont closes.',
                'deja' => 'Vous êtes déjà inscrit.',
                default => 'Inscription impossible.',
            });
        }
        Flash::set('success', $result['status'] === 'Inscrit'
            ? 'Vous êtes inscrit.'
            : "L'événement est complet : vous êtes en liste d'attente, et vous serez prévenu si une place se libère.");
        return Response::redirect('/evenements/' . (int) $event['id']);
    }

    public static function withdraw(Request $request, array $params): Response
    {
        $event = Events::byId((int) $params['id']);
        if ($event === null) {
            return self::fail('/evenements', 'Événement introuvable.');
        }

        $result = Events::cancel((int) $event['id'], (int) Session::get('user')['id']);
        if (!$result['ok']) {
            return self::fail('/evenements/' . (int) $event['id'], "Vous n'êtes pas inscrit.");
        }
        Flash::set('success', 'Votre inscription est annulée.');
        return Response::redirect('/evenements/' . (int) $event['id']);
    }
}
