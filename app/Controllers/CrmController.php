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
use App\Modules\Crm;
use App\Modules\Finance;

/**
 * Relation client.
 *
 * Module optionnel, et du ressort de la gestion : un pipeline commercial dit
 * ce que l'entreprise attend d'argent, un devis l'engage.
 */
final class CrmController
{
    private static function back(string $anchor): Response
    {
        return Response::redirect('/crm#' . $anchor);
    }

    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::back($anchor);
    }

    private static function amount(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        return $value === '' || !is_numeric($value) ? null : round((float) $value, 2);
    }

    public static function index(Request $request): Response
    {
        $pipeline = Crm::pipeline();
        $activities = Crm::activities();
        $pending = count(array_filter($activities, static fn (array $row): bool => $row['done_at'] === null));

        return Response::html(View::page('crm/index', [
            'title' => 'CRM commercial — ' . t('app.name'),
            'panelLabel' => 'CRM commercial',
            'headerTitle' => 'CRM commercial',
            'headerSubtitle' => t('crm.headerSub'),
            'navItems' => [
                ['tab' => 'pipeline', 'label' => t('crm.tabPipeline')],
                ['tab' => 'devis', 'label' => t('crm.tabQuotes')],
                ['tab' => 'contacts', 'label' => t('crm.tabContacts')],
                ['tab' => 'relances', 'label' => t('crm.tabFollowUps'), 'badge' => $pending ?: null],
            ],
            'footLinks' => [['href' => '/gestion', 'label' => t('nav.gestion')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/meter.js'],
            'clients' => Finance::partners('Client'),
            'contacts' => Crm::contacts(),
            'opportunities' => Crm::opportunities(),
            'stages' => Crm::STAGES,
            'openStages' => Crm::OPEN_STAGES,
            'pipeline' => $pipeline,
            'quotes' => Crm::quotes(),
            'quoteStatuses' => Crm::QUOTE_STATUSES,
            'activities' => $activities,
            'activityKinds' => Crm::ACTIVITY_KINDS,
            'employees' => Db::all("SELECT * FROM users WHERE role = 'employee' AND active = 1 ORDER BY last_name COLLATE NOCASE"),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    // ---------- Contacts ----------

    public static function createContact(Request $request): Response
    {
        $firstName = mb_substr(trim($request->input('first_name')), 0, 100);
        $lastName = mb_substr(trim($request->input('last_name')), 0, 100);
        $email = mb_substr(trim($request->input('email')), 0, 254);

        if ($firstName === '' || $lastName === '') {
            return self::fail('contacts', 'Prénom et nom sont obligatoires.');
        }
        if ($email !== '' && !Validate::email($email)) {
            return self::fail('contacts', 'Adresse email invalide.');
        }

        $result = Crm::createContact([
            'partnerId' => (int) $request->input('partner_id'),
            'firstName' => $firstName, 'lastName' => $lastName, 'email' => $email,
            'role' => mb_substr(trim($request->input('role')), 0, 100),
            'phone' => mb_substr(trim($request->input('phone')), 0, 40),
            'notes' => mb_substr(trim($request->input('notes')), 0, 1000),
        ]);
        if (!$result['ok']) {
            return self::fail('contacts', 'Client introuvable.');
        }

        Flash::set('success', 'Contact enregistré.');
        return self::back('contacts');
    }

    public static function deleteContact(Request $request, array $params): Response
    {
        Crm::deleteContact((int) $params['id']);
        Flash::set('success', 'Contact supprimé.');
        return self::back('contacts');
    }

    // ---------- Opportunités ----------

    public static function createOpportunity(Request $request): Response
    {
        $title = mb_substr(trim($request->input('title')), 0, 160);
        $expectedClose = trim($request->input('expected_close'));

        if ($title === '') {
            return self::fail('pipeline', "L'intitulé de l'affaire est obligatoire.");
        }
        if ($expectedClose !== '' && !Validate::date($expectedClose)) {
            return self::fail('pipeline', 'Date de clôture prévue invalide.');
        }

        $probabilityRaw = trim($request->input('probability'));
        $result = Crm::createOpportunity([
            'partnerId' => (int) $request->input('partner_id'),
            'title' => $title,
            'amount' => self::amount($request->input('amount') ?: '0'),
            'probability' => (string) (int) $probabilityRaw === $probabilityRaw ? (int) $probabilityRaw : null,
            'expectedClose' => $expectedClose !== '' ? $expectedClose : null,
            'ownerId' => (int) $request->input('owner_id') ?: null,
            'notes' => mb_substr(trim($request->input('notes')), 0, 2000),
        ]);
        if (!$result['ok']) {
            return self::fail('pipeline', match ($result['reason']) {
                'no-partner' => 'Client introuvable.',
                'bad-amount' => 'Montant invalide.',
                'bad-probability' => 'Probabilité invalide (0 à 100 %).',
                default => 'Création impossible.',
            });
        }

        Flash::set('success', 'Affaire ajoutée au pipeline.');
        return self::back('pipeline');
    }

    public static function setStage(Request $request, array $params): Response
    {
        $result = Crm::setStage((int) $params['id'], trim($request->input('stage')));
        if (!$result['ok']) {
            return self::fail('pipeline', 'Étape invalide ou affaire introuvable.');
        }
        Flash::set('success', 'Étape mise à jour.');
        return self::back('pipeline');
    }

    public static function deleteOpportunity(Request $request, array $params): Response
    {
        Crm::deleteOpportunity((int) $params['id']);
        Flash::set('success', 'Affaire supprimée.');
        return self::back('pipeline');
    }

    // ---------- Devis ----------

    public static function createQuote(Request $request): Response
    {
        $label = mb_substr(trim($request->input('label')), 0, 160);
        $issueDate = trim($request->input('issue_date'));
        $validUntil = trim($request->input('valid_until'));

        if ($label === '') {
            return self::fail('devis', "L'intitulé du devis est obligatoire.");
        }
        if (!Validate::date($issueDate)) {
            return self::fail('devis', "Date d'émission invalide.");
        }
        if ($validUntil !== '' && !Validate::date($validUntil)) {
            return self::fail('devis', 'Date de validité invalide.');
        }

        $vatRaw = trim($request->input('vat_rate'));
        $result = Crm::createQuote([
            'partnerId' => (int) $request->input('partner_id'),
            'opportunityId' => (int) $request->input('opportunity_id') ?: null,
            'reference' => mb_substr(trim($request->input('reference')), 0, 60),
            'label' => $label, 'issueDate' => $issueDate,
            'validUntil' => $validUntil !== '' ? $validUntil : null,
            'amountHt' => self::amount($request->input('amount_ht') ?: '0'),
            'vatRate' => is_numeric($vatRaw) ? (float) $vatRaw : null,
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (!$result['ok']) {
            return self::fail('devis', match ($result['reason']) {
                'no-partner' => 'Client introuvable.',
                'bad-amount' => 'Montant HT invalide.',
                'bad-vat' => 'Taux de TVA invalide.',
                'bad-validity' => "La date de validité précède l'émission.",
                default => 'Création impossible.',
            });
        }

        Flash::set('success', 'Devis enregistré.');
        return self::back('devis');
    }

    public static function setQuoteStatus(Request $request, array $params): Response
    {
        if (!Crm::setQuoteStatus((int) $params['id'], trim($request->input('status')))) {
            return self::fail('devis', 'Statut invalide ou devis introuvable.');
        }
        Flash::set('success', 'Devis mis à jour.');
        return self::back('devis');
    }

    public static function invoiceQuote(Request $request, array $params): Response
    {
        $result = Crm::convertToInvoice((int) $params['id'], (int) Session::get('user')['id']);
        if (!$result['ok']) {
            return self::fail('devis', match ($result['reason']) {
                'not-found' => 'Devis introuvable.',
                'not-accepted' => 'Seul un devis accepté se transforme en facture.',
                'already-invoiced' => 'Ce devis a déjà été facturé.',
                default => 'Facturation impossible.',
            });
        }
        Flash::set('success', 'Facture client créée depuis le devis. Elle est visible dans la gestion.');
        return self::back('devis');
    }

    public static function deleteQuote(Request $request, array $params): Response
    {
        Crm::deleteQuote((int) $params['id']);
        Flash::set('success', 'Devis supprimé.');
        return self::back('devis');
    }

    // ---------- Relances ----------

    public static function createActivity(Request $request): Response
    {
        $dueOn = trim($request->input('due_on'));
        if (!Validate::date($dueOn)) {
            return self::fail('relances', "Date d'échéance invalide.");
        }

        $result = Crm::createActivity([
            'partnerId' => (int) $request->input('partner_id') ?: null,
            'opportunityId' => (int) $request->input('opportunity_id') ?: null,
            'kind' => trim($request->input('kind')) ?: 'Relance',
            'dueOn' => $dueOn,
            'note' => mb_substr(trim($request->input('note')), 0, 1000),
            'ownerId' => (int) $request->input('owner_id') ?: (int) Session::get('user')['id'],
        ]);
        if (!$result['ok']) {
            return self::fail('relances', 'Une relance vise un client ou une affaire.');
        }

        Flash::set('success', 'Relance planifiée.');
        return self::back('relances');
    }

    public static function completeActivity(Request $request, array $params): Response
    {
        if (!Crm::completeActivity((int) $params['id'])) {
            return self::fail('relances', 'Relance introuvable ou déjà faite.');
        }
        Flash::set('success', 'Relance marquée comme faite.');
        return self::back('relances');
    }

    public static function deleteActivity(Request $request, array $params): Response
    {
        Crm::deleteActivity((int) $params['id']);
        Flash::set('success', 'Relance supprimée.');
        return self::back('relances');
    }
}
