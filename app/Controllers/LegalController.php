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
use App\Modules\Corporate;
use App\Modules\Finance;

/**
 * Espace juridique.
 *
 * Réservé à l'administration — sauf les déclarations, que chacun dépose pour
 * soi. Un registre de conflits d'intérêts que seuls les dirigeants peuvent
 * alimenter ne recense que les leurs.
 */
final class LegalController
{
    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect('/juridique#' . $anchor);
    }

    private static function done(string $anchor, string $message): Response
    {
        Flash::set('success', $message);
        return Response::redirect('/juridique#' . $anchor);
    }

    private static function date(Request $request, string $key, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return ['ok' => Validate::date($raw), 'value' => $raw];
    }

    /** Un nombre de formulaire : virgule décimale acceptée, bornes explicites. */
    private static function number(Request $request, string $key, float $min = 0, float $max = 1e12, bool $required = false): array
    {
        $raw = $request->input($key);
        if ($raw === '') {
            return ['ok' => !$required, 'value' => null];
        }
        $value = str_replace([' ', "\u{a0}", ','], ['', '', '.'], $raw);
        if (!is_numeric($value)) {
            return ['ok' => false, 'value' => null];
        }
        $number = round((float) $value, 2);
        if ($number < $min || $number > $max) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $number];
    }

    private static function employees(): array
    {
        return Db::all('SELECT id, first_name, last_name FROM users WHERE active = 1 ORDER BY last_name COLLATE NOCASE');
    }

    public static function isAdmin(): bool
    {
        $user = Session::get('user');
        return $user !== null && $user['role'] === 'admin';
    }

    public static function index(Request $request): Response
    {
        $isAdmin = self::isAdmin();
        $me = (int) Session::get('user')['id'];

        return Response::html(View::page('legal/index', [
            'title' => t('nav.legal') . ' — ' . t('app.name'),
            'panelLabel' => t('jur.panel'),
            'headerTitle' => t('nav.legal'),
            'headerSubtitle' => $isAdmin ? t('jur.headerSub') : t('jur.headerSubMember'),
            'navItems' => $isAdmin ? [
                ['tab' => 'capital', 'label' => t('jur.tabCapital')],
                ['tab' => 'mandats', 'label' => t('jur.tabMandates')],
                ['tab' => 'assemblees', 'label' => t('jur.tabMeetings')],
                ['tab' => 'delegations', 'label' => t('jur.tabDelegations')],
                ['tab' => 'interets', 'label' => t('jur.tabInterests'), 'badge' => Corporate::summary()['openDeclarations'] ?: null],
                ['tab' => 'cadeaux', 'label' => t('jur.tabGifts'), 'badge' => count(Corporate::giftsToReview()) ?: null],
            ] : [
                ['tab' => 'interets', 'label' => t('jur.tabMyInterests')],
                ['tab' => 'cadeaux', 'label' => t('jur.tabMyGifts')],
            ],
            'footLinks' => [$isAdmin
                ? ['href' => '/admin', 'label' => t('nav.adminConsole')]
                : ['href' => '/mon-espace', 'label' => t('nav.mySpace')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'isAdmin' => $isAdmin,
            'summary' => $isAdmin ? Corporate::summary() : null,
            'capital' => $isAdmin ? Corporate::capital() : null,
            'shareholderList' => $isAdmin ? Corporate::shareholders() : [],
            'movementList' => $isAdmin ? Corporate::movements() : [],
            'mandateList' => $isAdmin ? Corporate::mandates() : [],
            'meetingList' => $isAdmin ? Corporate::meetings() : [],
            'delegationList' => $isAdmin ? Corporate::delegations() : [],
            // Chacun voit ses propres déclarations ; l'administration les voit toutes.
            'declarationList' => $isAdmin ? Corporate::declarations() : Corporate::declarations($me),
            'giftList' => $isAdmin ? Corporate::gifts() : Corporate::gifts($me),
            'giftsToReview' => $isAdmin ? Corporate::giftsToReview() : [],
            'shareholderKinds' => Corporate::SHAREHOLDER_KINDS,
            'movementKinds' => Corporate::MOVEMENT_KINDS,
            'mandateRoles' => Corporate::MANDATE_ROLES,
            'mandateStatuses' => Corporate::MANDATE_STATUSES,
            'meetingKinds' => Corporate::MEETING_KINDS,
            'interestKinds' => Corporate::INTEREST_KINDS,
            'interestStatuses' => Corporate::INTEREST_STATUSES,
            'giftDirections' => Corporate::GIFT_DIRECTIONS,
            'giftKinds' => Corporate::GIFT_KINDS,
            'giftStatuses' => Corporate::GIFT_STATUSES,
            'delegationStatuses' => Corporate::DELEGATION_STATUSES,
            'giftThreshold' => Corporate::GIFT_REVIEW_THRESHOLD,
            'partners' => Finance::partners(),
            'employees' => self::employees(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    public static function showMeeting(Request $request, array $params): Response
    {
        $meeting = Corporate::meetingById((int) $params['id']);
        if ($meeting === null) {
            return Response::html(View::page('error', ['message' => 'Assemblée introuvable.', 'title' => 'Assemblée introuvable.']), 404);
        }

        return Response::html(View::page('legal/meeting', [
            'title' => $meeting['reference'] . ' — ' . t('app.name'),
            'panelLabel' => t('nav.legal'),
            'headerTitle' => $meeting['reference'],
            'headerSubtitle' => st($meeting['kind']),
            'navItems' => [
                ['tab' => 'resolutions', 'label' => t('jur.resolutions'), 'badge' => count(Corporate::resolutions((int) $meeting['id'])) ?: null],
                ['tab' => 'proces-verbal', 'label' => t('jur.minutes')],
                ['tab' => 'fiche', 'label' => t('jur.meetingSheet')],
            ],
            'footLinks' => [['href' => '/juridique#assemblees', 'label' => t('jur.tabMeetings')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'meeting' => $meeting,
            'resolutionList' => Corporate::resolutions((int) $meeting['id']),
            'quorum' => Corporate::quorum($meeting),
            'kinds' => Corporate::MEETING_KINDS,
            'statuses' => Corporate::MEETING_STATUSES,
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------------------------------------------------------------- capital

    public static function createShareholder(Request $request): Response
    {
        $name = mb_substr($request->input('name'), 0, 150);
        if ($name === '') {
            return self::fail('capital', "Nom de l'associé obligatoire.");
        }
        if (!in_array($request->input('kind'), Corporate::SHAREHOLDER_KINDS, true)) {
            return self::fail('capital', 'Nature invalide.');
        }

        $email = mb_substr($request->input('email'), 0, 254);
        if ($email !== '' && !Validate::email($email)) {
            return self::fail('capital', 'Adresse électronique invalide.');
        }

        $id = Corporate::createShareholder([
            'name' => $name,
            'kind' => $request->input('kind'),
            'userId' => (int) $request->input('user_id') ?: null,
            'registration' => mb_substr($request->input('registration'), 0, 40),
            'email' => $email,
            'address' => mb_substr($request->input('address'), 0, 300),
            'notes' => mb_substr($request->input('notes'), 0, 500),
        ]);
        Audit::log('juridique.associe_ajoute', 'shareholders', $id, ['nom' => $name]);
        return self::done('capital', 'Associé enregistré.');
    }

    public static function deleteShareholder(Request $request, array $params): Response
    {
        if (!Corporate::removeShareholder((int) $params['id'])) {
            return self::fail('capital', 'Cet associé détient encore des titres : cédez-les avant de le retirer du registre.');
        }
        Audit::log('juridique.associe_supprime', 'shareholders', (int) $params['id']);
        return self::done('capital', 'Associé retiré du registre.');
    }

    public static function recordMovement(Request $request): Response
    {
        if (!in_array($request->input('kind'), Corporate::MOVEMENT_KINDS, true)) {
            return self::fail('capital', 'Nature de mouvement invalide.');
        }

        $on = self::date($request, 'moved_on', true);
        $shares = self::number($request, 'shares', 0.0001, 1e12, true);
        $price = self::number($request, 'unit_price');
        if (!$on['ok']) {
            return self::fail('capital', 'Date invalide.');
        }
        if (!$shares['ok']) {
            return self::fail('capital', 'Nombre de titres invalide.');
        }
        if (!$price['ok']) {
            return self::fail('capital', 'Prix unitaire invalide.');
        }

        $result = Corporate::recordMovement([
            'shareholderId' => (int) $request->input('shareholder_id'),
            'kind' => $request->input('kind'),
            'movedOn' => $on['value'],
            'shares' => (float) $shares['value'],
            'unitPrice' => $price['value'],
            'counterpartyId' => (int) $request->input('counterparty_id') ?: null,
            'note' => $request->input('note'),
        ]);
        if (!$result['ok']) {
            return self::fail('capital', $result['reason'] === 'insuffisant'
                ? 'Cet associé ne détient que ' . $result['held'] . ' titres.'
                : 'Associé introuvable.');
        }
        Audit::log('juridique.mouvement_titres', 'share_movements', null, [
            'nature' => $request->input('kind'), 'titres' => $shares['value'],
        ]);
        return self::done('capital', 'Mouvement inscrit au registre.');
    }

    public static function deleteMovement(Request $request, array $params): Response
    {
        Corporate::removeMovement((int) $params['id']);
        Audit::log('juridique.mouvement_supprime', 'share_movements', (int) $params['id']);
        return self::done('capital', 'Mouvement retiré.');
    }

    // ---------------------------------------------------------------- mandats

    public static function createMandate(Request $request): Response
    {
        $holderName = mb_substr($request->input('holder_name'), 0, 150);
        if ($holderName === '') {
            return self::fail('mandats', 'Nom du mandataire obligatoire.');
        }
        if (!in_array($request->input('role'), Corporate::MANDATE_ROLES, true)) {
            return self::fail('mandats', 'Fonction invalide.');
        }

        $started = self::date($request, 'started_on', true);
        $ends = self::date($request, 'ends_on');
        if (!$started['ok'] || !$ends['ok']) {
            return self::fail('mandats', 'Date invalide.');
        }

        $id = Corporate::createMandate([
            'holderName' => $holderName,
            'userId' => (int) $request->input('user_id') ?: null,
            'role' => $request->input('role'),
            'startedOn' => $started['value'],
            'endsOn' => $ends['value'],
            'appointedBy' => mb_substr($request->input('appointed_by'), 0, 150),
            'notes' => mb_substr($request->input('notes'), 0, 500),
        ]);
        Audit::log('juridique.mandat_cree', 'corporate_mandates', $id, ['fonction' => $request->input('role')]);
        return self::done('mandats', 'Mandat enregistré.');
    }

    public static function setMandateStatus(Request $request, array $params): Response
    {
        if (!Corporate::setMandateStatus((int) $params['id'], $request->input('status'))) {
            return self::fail('mandats', 'Statut invalide.');
        }
        Audit::log('juridique.mandat_statut', 'corporate_mandates', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::done('mandats', 'Mandat mis à jour.');
    }

    public static function deleteMandate(Request $request, array $params): Response
    {
        Corporate::removeMandate((int) $params['id']);
        return self::done('mandats', 'Mandat supprimé.');
    }

    // ---------------------------------------------------------------- assemblées

    public static function createMeeting(Request $request): Response
    {
        if (!in_array($request->input('kind'), Corporate::MEETING_KINDS, true)) {
            return self::fail('assemblees', "Nature d'assemblée invalide.");
        }

        $on = self::date($request, 'held_on', true);
        $quorum = self::number($request, 'quorum_required');
        if (!$on['ok']) {
            return self::fail('assemblees', 'Date invalide.');
        }
        if (!$quorum['ok']) {
            return self::fail('assemblees', 'Quorum invalide.');
        }

        $id = Corporate::createMeeting([
            'kind' => $request->input('kind'),
            'heldOn' => $on['value'],
            'location' => mb_substr($request->input('location'), 0, 200),
            'quorumRequired' => $quorum['value'] ?? 0,
        ]);
        Audit::log('juridique.assemblee_creee', 'general_meetings', $id);
        Flash::set('success', 'Assemblée convoquée.');
        return Response::redirect('/juridique/assemblees/' . $id);
    }

    public static function updateMeeting(Request $request, array $params): Response
    {
        $meeting = Corporate::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::fail('assemblees', 'Assemblée introuvable.');
        }

        $target = '/juridique/assemblees/' . (int) $meeting['id'];
        if (!in_array($request->input('kind'), Corporate::MEETING_KINDS, true)
            || !in_array($request->input('status'), Corporate::MEETING_STATUSES, true)) {
            Flash::set('error', 'Saisie invalide.');
            return Response::redirect($target);
        }
        $on = self::date($request, 'held_on', true);
        $quorum = self::number($request, 'quorum_required');
        $present = self::number($request, 'shares_present');
        if (!$on['ok'] || !$quorum['ok'] || !$present['ok']) {
            Flash::set('error', 'Valeur invalide.');
            return Response::redirect($target);
        }

        Corporate::updateMeeting((int) $meeting['id'], [
            'kind' => $request->input('kind'),
            'heldOn' => $on['value'],
            'location' => mb_substr($request->input('location'), 0, 200),
            'quorumRequired' => $quorum['value'] ?? 0,
            'sharesPresent' => $present['value'] ?? 0,
            'status' => $request->input('status'),
        ]);
        Flash::set('success', 'Assemblée mise à jour.');
        return Response::redirect($target);
    }

    public static function updateMinutes(Request $request, array $params): Response
    {
        $meeting = Corporate::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::fail('assemblees', 'Assemblée introuvable.');
        }

        Corporate::updateMinutes((int) $meeting['id'], $request->input('minutes'));
        Flash::set('success', 'Procès-verbal enregistré.');
        return Response::redirect('/juridique/assemblees/' . (int) $meeting['id']);
    }

    public static function deleteMeeting(Request $request, array $params): Response
    {
        Corporate::removeMeeting((int) $params['id']);
        Audit::log('juridique.assemblee_supprimee', 'general_meetings', (int) $params['id']);
        return self::done('assemblees', 'Assemblée supprimée.');
    }

    public static function addResolution(Request $request, array $params): Response
    {
        $meeting = Corporate::meetingById((int) $params['id']);
        if ($meeting === null) {
            return self::fail('assemblees', 'Assemblée introuvable.');
        }

        $target = '/juridique/assemblees/' . (int) $meeting['id'];
        $label = mb_substr($request->input('label'), 0, 300);
        $majority = self::number($request, 'majority_required', 1, 100);
        if ($label === '' || !$majority['ok'] || $majority['value'] === null) {
            Flash::set('error', 'Résolution ou majorité invalide.');
            return Response::redirect($target);
        }

        Corporate::addResolution((int) $meeting['id'], $label, (float) $majority['value']);
        Flash::set('success', 'Résolution ajoutée.');
        return Response::redirect($target);
    }

    public static function recordVote(Request $request, array $params): Response
    {
        $resolution = Db::get('SELECT * FROM meeting_resolutions WHERE id = ?', [(int) $params['id']]);
        if ($resolution === null) {
            return self::fail('assemblees', 'Résolution introuvable.');
        }

        $target = '/juridique/assemblees/' . (int) $resolution['meeting_id'];
        $for = self::number($request, 'votes_for');
        $against = self::number($request, 'votes_against');
        $abstain = self::number($request, 'votes_abstain');
        if (!$for['ok'] || !$against['ok'] || !$abstain['ok']) {
            Flash::set('error', 'Nombre de voix invalide.');
            return Response::redirect($target);
        }

        Corporate::recordVote(
            (int) $resolution['id'],
            (float) ($for['value'] ?? 0),
            (float) ($against['value'] ?? 0),
            (float) ($abstain['value'] ?? 0)
        );
        Flash::set('success', 'Vote enregistré.');
        return Response::redirect($target);
    }

    public static function deleteResolution(Request $request, array $params): Response
    {
        $resolution = Db::get('SELECT meeting_id FROM meeting_resolutions WHERE id = ?', [(int) $params['id']]);
        Corporate::removeResolution((int) $params['id']);
        Flash::set('success', 'Résolution supprimée.');
        return Response::redirect($resolution === null
            ? '/juridique#assemblees'
            : '/juridique/assemblees/' . (int) $resolution['meeting_id']);
    }

    // ---------------------------------------------------------------- conformité

    public static function declareInterest(Request $request): Response
    {
        $entity = mb_substr($request->input('entity'), 0, 200);
        if ($entity === '') {
            return self::fail('interets', "L'organisme concerné est obligatoire.");
        }
        if (!in_array($request->input('kind'), Corporate::INTEREST_KINDS, true)) {
            return self::fail('interets', 'Nature invalide.');
        }

        $declared = self::date($request, 'declared_on', true);
        $ends = self::date($request, 'ends_on');
        if (!$declared['ok'] || !$ends['ok']) {
            return self::fail('interets', 'Date invalide.');
        }

        Corporate::declareInterest([
            // Chacun déclare pour soi : l'identifiant vient de la session, jamais du formulaire.
            'userId' => (int) Session::get('user')['id'],
            'kind' => $request->input('kind'),
            'entity' => $entity,
            'partnerId' => (int) $request->input('partner_id') ?: null,
            'description' => mb_substr($request->input('description'), 0, 2000),
            'declaredOn' => $declared['value'],
            'endsOn' => $ends['value'],
        ]);
        return self::done('interets', 'Déclaration enregistrée.');
    }

    public static function reviewDeclaration(Request $request, array $params): Response
    {
        $reviewed = Corporate::reviewDeclaration(
            (int) $params['id'],
            $request->input('status'),
            $request->input('measure'),
            (int) Session::get('user')['id']
        );
        if (!$reviewed) {
            return self::fail('interets', 'Statut invalide.');
        }

        Audit::log('juridique.interet_examine', 'interest_declarations', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::done('interets', 'Déclaration examinée.');
    }

    public static function declareGift(Request $request): Response
    {
        if (!in_array($request->input('direction'), Corporate::GIFT_DIRECTIONS, true)) {
            return self::fail('cadeaux', 'Sens invalide.');
        }
        if (!in_array($request->input('kind'), Corporate::GIFT_KINDS, true)) {
            return self::fail('cadeaux', 'Nature invalide.');
        }

        $on = self::date($request, 'occurred_on', true);
        $value = self::number($request, 'value', 0, 1e6);
        if (!$on['ok']) {
            return self::fail('cadeaux', 'Date invalide.');
        }
        if (!$value['ok']) {
            return self::fail('cadeaux', 'Valeur invalide.');
        }

        Corporate::declareGift([
            'userId' => (int) Session::get('user')['id'],
            'direction' => $request->input('direction'),
            'kind' => $request->input('kind'),
            'partnerId' => (int) $request->input('partner_id') ?: null,
            'thirdParty' => mb_substr($request->input('third_party'), 0, 200),
            'occurredOn' => $on['value'],
            'value' => $value['value'] ?? 0,
            'description' => mb_substr($request->input('description'), 0, 1000),
        ]);
        return self::done('cadeaux', 'Déclaration enregistrée.');
    }

    public static function reviewGift(Request $request, array $params): Response
    {
        if (!Corporate::reviewGift((int) $params['id'], $request->input('status'), (int) Session::get('user')['id'])) {
            return self::fail('cadeaux', 'Statut invalide.');
        }
        Audit::log('juridique.cadeau_examine', 'gift_records', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::done('cadeaux', 'Déclaration examinée.');
    }

    // ---------------------------------------------------------------- délégations

    public static function createDelegation(Request $request): Response
    {
        $scope = mb_substr($request->input('scope'), 0, 300);
        if ($scope === '') {
            return self::fail('delegations', "L'objet de la délégation est obligatoire.");
        }

        $holderId = (int) $request->input('holder_id');
        if (Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$holderId]) === null) {
            return self::fail('delegations', 'Délégataire introuvable.');
        }

        $starts = self::date($request, 'starts_on', true);
        $ends = self::date($request, 'ends_on');
        $limit = self::number($request, 'amount_limit', 0, 1e9);
        if (!$starts['ok'] || !$ends['ok']) {
            return self::fail('delegations', 'Date invalide.');
        }
        if (!$limit['ok']) {
            return self::fail('delegations', 'Plafond invalide.');
        }
        // Une délégation qui s'achève avant de commencer n'a jamais eu d'effet.
        if ($ends['value'] !== null && $ends['value'] < $starts['value']) {
            return self::fail('delegations', 'La fin précède le début.');
        }

        $id = Corporate::createDelegation([
            'holderId' => $holderId,
            'grantedById' => (int) Session::get('user')['id'],
            'scope' => $scope,
            'amountLimit' => $limit['value'],
            'startsOn' => $starts['value'],
            'endsOn' => $ends['value'],
            'notes' => mb_substr($request->input('notes'), 0, 1000),
        ]);
        Audit::log('juridique.delegation_creee', 'power_delegations', $id, [
            'delegataire' => $holderId, 'plafond' => $limit['value'],
        ]);
        return self::done('delegations', 'Délégation enregistrée.');
    }

    public static function setDelegationStatus(Request $request, array $params): Response
    {
        if (!Corporate::setDelegationStatus((int) $params['id'], $request->input('status'))) {
            return self::fail('delegations', 'Statut invalide.');
        }
        Audit::log('juridique.delegation_statut', 'power_delegations', (int) $params['id'], ['statut' => $request->input('status')]);
        return self::done('delegations', 'Délégation mise à jour.');
    }

    public static function deleteDelegation(Request $request, array $params): Response
    {
        Corporate::removeDelegation((int) $params['id']);
        return self::done('delegations', 'Délégation supprimée.');
    }
}
