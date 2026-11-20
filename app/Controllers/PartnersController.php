<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\CustomFields;
use App\Modules\Partners;

/**
 * Fiche d'un tiers.
 *
 * Même périmètre que l'espace de gestion, dont la fiche est le prolongement :
 * contrats et factures y sont déjà, on y ajoute les interlocuteurs, les pièces
 * de conformité et les évaluations.
 */
final class PartnersController
{
    private static function fail(string $target, string $message): Response
    {
        Flash::set('error', $message);
        return Response::redirect($target);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    private static function date(string $raw, bool $required = false): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => !$required, 'value' => null];
        }
        return Validate::date($trimmed) ? ['ok' => true, 'value' => $trimmed] : ['ok' => false, 'value' => null];
    }

    /** Une note va de 1 à 5, ou n'est pas donnée du tout. */
    private static function mark(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return ['ok' => true, 'value' => null];
        }
        $value = (int) $trimmed;
        if ((string) $value !== $trimmed || $value < 1 || $value > 5) {
            return ['ok' => false, 'value' => null];
        }
        return ['ok' => true, 'value' => $value];
    }

    public static function show(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::error('Tiers introuvable.', 404);
        }

        return Response::html(View::page('partners/show', array_merge($sheet, [
            'title' => $sheet['partner']['name'] . ' — ' . t('app.name'),
            'panelLabel' => t('ptn.panel'),
            'headerTitle' => $sheet['partner']['name'],
            'headerSubtitle' => t('ptn.sheetSub'),
            'navItems' => [
                ['tab' => 'fiche', 'label' => t('ptn.sheet')],
                ['tab' => 'contacts', 'label' => t('ptn.contacts'), 'badge' => count($sheet['contacts']) ?: null],
                ['tab' => 'conformite', 'label' => t('ptn.compliance'),
                 'badge' => ($sheet['compliance']['expired'] + $sheet['compliance']['soon']) ?: null],
                ['tab' => 'evaluations', 'label' => t('ptn.reviews'), 'badge' => count($sheet['reviews']) ?: null],
                ['tab' => 'banque', 'label' => t('sepa.bankTab'),
                 'badge' => count(array_filter(\App\Modules\Sepa::mandatsDe((int) $sheet['partner']['id']), static fn (array $m): bool => $m['status'] === 'Actif')) ?: null],
            ],
            'mandats' => \App\Modules\Sepa::mandatsDe((int) $sheet['partner']['id']),
            'rumProposee' => \App\Modules\Sepa::rumProposee((int) $sheet['partner']['id']),
            'footLinks' => [['href' => '/gestion#tiers', 'label' => t('nav.gestion')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'documentKinds' => Partners::DOCUMENT_KINDS,
            'warningDays' => Partners::EXPIRY_WARNING_DAYS,
            'today' => gmdate('Y-m-d'),
            'champsPerso' => CustomFields::valeurs('partenaire', (int) $sheet['partner']['id']),
        ])));
    }

    /**
     * Les champs personnalisés du tiers, dans leur propre formulaire.
     *
     * La fiche se modifie dans l'espace de gestion, pas ici : les champs ont
     * donc leur formulaire, sur l'écran où ils s'affichent, et il n'enregistre
     * qu'eux.
     */
    public static function saveFields(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }
        $id = (int) $sheet['partner']['id'];
        $cible = '/partenaires/' . $id;

        $refus = CustomFields::refus('partenaire', $id, $request->body);
        if ($refus !== null) {
            return self::fail($cible, $refus);
        }

        Flash::set('success', 'Champs enregistrés.');
        return Response::redirect($cible);
    }

    // ---------- Contacts ----------

    public static function addContact(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }

        $target = '/partenaires/' . (int) $sheet['partner']['id'] . '#contacts';
        $name = mb_substr(trim($request->input('name')), 0, 120);
        if ($name === '') {
            return self::fail($target, 'Nom du contact obligatoire.');
        }

        $email = mb_substr(trim($request->input('email')), 0, 254);
        if ($email !== '' && !Validate::email($email)) {
            return self::fail($target, 'Adresse électronique invalide.');
        }

        Partners::addContact([
            'partnerId' => (int) $sheet['partner']['id'],
            'name' => $name,
            'role' => mb_substr(trim($request->input('role')), 0, 80),
            'email' => $email,
            'phone' => mb_substr(trim($request->input('phone')), 0, 40),
            'isPrimary' => $request->input('is_primary') === '1',
            'notes' => mb_substr(trim($request->input('notes')), 0, 500),
        ]);
        Flash::set('success', 'Contact enregistré.');
        return Response::redirect($target);
    }

    public static function setPrimaryContact(Request $request, array $params): Response
    {
        $partnerId = (int) $request->input('partner_id');
        if (!Partners::setPrimary($partnerId, (int) $params['id'])) {
            return self::fail('/gestion#tiers', 'Contact introuvable.');
        }
        Flash::set('success', 'Interlocuteur principal mis à jour.');
        return Response::redirect('/partenaires/' . $partnerId . '#contacts');
    }

    public static function removeContact(Request $request, array $params): Response
    {
        $partnerId = Partners::removeContact((int) $params['id']);
        if ($partnerId === null) {
            return self::fail('/gestion#tiers', 'Contact introuvable.');
        }
        Flash::set('success', 'Contact supprimé.');
        return Response::redirect('/partenaires/' . $partnerId . '#contacts');
    }

    // ---------- Conformité ----------

    public static function addDocument(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }

        $target = '/partenaires/' . (int) $sheet['partner']['id'] . '#conformite';
        if (!in_array($request->input('kind'), Partners::DOCUMENT_KINDS, true)) {
            return self::fail($target, 'Nature de pièce invalide.');
        }

        $issued = self::date($request->input('issued_on'));
        $expires = self::date($request->input('expires_on'));
        if (!$issued['ok'] || !$expires['ok']) {
            return self::fail($target, 'Date invalide.');
        }
        // Une pièce périmée avant d'être émise trahit une inversion de saisie.
        if ($issued['value'] !== null && $expires['value'] !== null && $expires['value'] < $issued['value']) {
            return self::fail($target, "La date de validité précède la date d'émission.");
        }

        $id = Partners::addDocument([
            'partnerId' => (int) $sheet['partner']['id'],
            'kind' => $request->input('kind'),
            'reference' => mb_substr(trim($request->input('reference')), 0, 80),
            'issuedOn' => $issued['value'],
            'expiresOn' => $expires['value'],
            'notes' => mb_substr(trim($request->input('notes')), 0, 500),
        ]);
        Audit::log('partenaires.piece_ajoutee', 'partner_documents', $id, [
            'tiers' => $sheet['partner']['name'], 'nature' => $request->input('kind'),
        ]);
        Flash::set('success', 'Pièce enregistrée.');
        return Response::redirect($target);
    }

    public static function removeDocument(Request $request, array $params): Response
    {
        $partnerId = Partners::removeDocument((int) $params['id']);
        if ($partnerId === null) {
            return self::fail('/gestion#tiers', 'Pièce introuvable.');
        }
        Audit::log('partenaires.piece_supprimee', 'partner_documents', (int) $params['id'], []);
        Flash::set('success', 'Pièce supprimée.');
        return Response::redirect('/partenaires/' . $partnerId . '#conformite');
    }

    // ---------- Évaluation ----------

    public static function addReview(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }

        $target = '/partenaires/' . (int) $sheet['partner']['id'] . '#evaluations';
        $reviewed = self::date($request->input('reviewed_on'), true);
        $next = self::date($request->input('next_review'));
        if (!$reviewed['ok'] || !$next['ok']) {
            return self::fail($target, 'Date invalide.');
        }

        $quality = self::mark($request->input('quality'));
        $leadTime = self::mark($request->input('lead_time'));
        $price = self::mark($request->input('price'));
        if (!$quality['ok'] || !$leadTime['ok'] || !$price['ok']) {
            return self::fail($target, 'Note invalide : de 1 à 5.');
        }
        if ($quality['value'] === null && $leadTime['value'] === null && $price['value'] === null) {
            return self::fail($target, 'Renseignez au moins une note.');
        }

        Partners::addReview([
            'partnerId' => (int) $sheet['partner']['id'],
            'reviewedOn' => $reviewed['value'],
            'reviewerId' => (int) Session::get('user')['id'],
            'quality' => $quality['value'],
            'leadTime' => $leadTime['value'],
            'price' => $price['value'],
            'comment' => mb_substr(trim($request->input('comment')), 0, 2000),
            'nextReview' => $next['value'],
        ]);
        Flash::set('success', 'Évaluation enregistrée.');
        return Response::redirect($target);
    }

    public static function removeReview(Request $request, array $params): Response
    {
        $partnerId = Partners::removeReview((int) $params['id']);
        if ($partnerId === null) {
            return self::fail('/gestion#tiers', 'Évaluation introuvable.');
        }
        Flash::set('success', 'Évaluation supprimée.');
        return Response::redirect('/partenaires/' . $partnerId . '#evaluations');
    }

    // ---------- Banque et mandats SEPA ----------

    private const SEPA_REFUS = [
        'iban' => 'IBAN invalide : sa clé de contrôle ne correspond pas.',
        'bic' => 'BIC invalide.',
        'rum' => 'Référence de mandat invalide : 35 caractères au plus, lettres, chiffres et quelques signes.',
        'date' => 'Date de signature invalide : elle ne peut pas être à venir.',
        'duplicate' => 'Cette référence de mandat existe déjà.',
    ];

    public static function bank(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }
        $cible = '/partenaires/' . (int) $sheet['partner']['id'] . '#banque';
        $verdict = \App\Modules\Sepa::poserCoordonneesTiers((int) $sheet['partner']['id'], $request->input('iban'), $request->input('bic'));
        if (!$verdict['ok']) {
            return self::fail($cible, self::SEPA_REFUS[$verdict['reason']] ?? 'Tiers introuvable.');
        }
        Flash::set('success', 'Coordonnées bancaires enregistrées.');
        return Response::redirect($cible);
    }

    public static function createMandate(Request $request, array $params): Response
    {
        $sheet = Partners::sheet((int) $params['id']);
        if ($sheet === null) {
            return self::fail('/gestion#tiers', 'Tiers introuvable.');
        }
        $cible = '/partenaires/' . (int) $sheet['partner']['id'] . '#banque';
        $verdict = \App\Modules\Sepa::creerMandat((int) $sheet['partner']['id'], $request->input('rum'),
            $request->input('signed_on'), $request->input('iban'), $request->input('bic'));
        if (!$verdict['ok']) {
            return self::fail($cible, self::SEPA_REFUS[$verdict['reason']] ?? 'Mandat refusé.');
        }
        Flash::set('success', 'Mandat de prélèvement enregistré.');
        return Response::redirect($cible);
    }

    public static function revokeMandate(Request $request, array $params): Response
    {
        $mandat = \App\Modules\Sepa::mandatParId((int) $params['id']);
        if ($mandat === null) {
            return self::fail('/gestion#tiers', 'Mandat introuvable.');
        }
        \App\Modules\Sepa::revoquerMandat((int) $mandat['id']);
        Flash::set('success', 'Mandat révoqué : il ne servira plus à prélever.');
        return Response::redirect('/partenaires/' . (int) $mandat['partner_id'] . '#banque');
    }
}
