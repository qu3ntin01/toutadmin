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
use App\Modules\Ai;
use App\Modules\Currency;
use App\Modules\Finance;
use App\Modules\Intake;
use App\Modules\Mailbox;
use App\Modules\Org;
use App\Modules\Users;

/**
 * Corbeille des pièces comptables.
 *
 * Elle appartient à la gestion : elle contient les factures des fournisseurs
 * avant qu'elles n'entrent dans les comptes. Les réglages qui font sortir
 * quelque chose de l'instance — la boîte aux lettres relevée, le service
 * d'analyse extérieur — relèvent de l'administration, elle seule.
 */
final class PiecesController
{
    private static function back(string $anchor): Response
    {
        return Response::redirect('/pieces#' . $anchor);
    }

    private static function fail(string $anchor, string $message): Response
    {
        Flash::set('error', $message);
        return self::back($anchor);
    }

    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    /** Un montant saisi : « 1 234,56 » vaut 1234.56 ; le reste est refusé. */
    private static function amount(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return round((float) $value, 2);
    }

    private static function user(): array
    {
        return (array) Users::byId((int) Session::get('user')['id']);
    }

    private static function isAdmin(): bool
    {
        return self::user()['role'] === 'admin';
    }

    public static function index(Request $request): Response
    {
        $handled = array_merge(Intake::all(['status' => 'Facturée']), Intake::all(['status' => 'Écartée']));
        usort($handled, static fn (array $a, array $b): int => strcmp((string) $b['received_at'], (string) $a['received_at']));

        return Response::html(View::page('pieces/index', [
            'title' => t('nav.intake') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.intake'),
            'headerTitle' => t('nav.intake'),
            'headerSubtitle' => t('pcs.headerSub'),
            'navItems' => [
                ['tab' => 'a-traiter', 'label' => t('status.toProcess'), 'badge' => Intake::summary()['waiting'] ?: null],
                ['tab' => 'traitees', 'label' => t('pcs.tabHandled')],
                ['tab' => 'capture', 'label' => t('pcs.tabMailbox')],
                ['tab' => 'analyse', 'label' => t('pcs.tabAssisted')],
            ],
            'footLinks' => [
                ['href' => '/gestion', 'label' => t('status.stageManagement')],
                ['href' => '/comptabilite', 'label' => t('pcs.tabAccounting')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/meter.js'],
            'waiting' => Intake::all(['status' => 'À traiter']),
            'handled' => $handled,
            'stats' => Intake::summary(),
            'mail' => Mailbox::displayConfig(),
            'mailActions' => Mailbox::ACTIONS,
            'mailStatus' => Mailbox::status(),
            'mailReady' => Mailbox::isReady(),
            'aiConfig' => Ai::displayConfig(),
            'aiProviders' => Ai::PROVIDERS,
            'aiEfforts' => Ai::EFFORTS,
            'aiStatus' => Ai::status(),
            'aiReady' => Ai::isReady(),
            'admin' => self::isAdmin(),
            'maxBytes' => Intake::MAX_BYTES,
        ]));
    }

    // ---------- Dépôt manuel ----------

    public static function deposit(Request $request): Response
    {
        $file = $request->file('document');
        if ($file === null) {
            return self::fail('a-traiter', 'Aucun fichier reçu.');
        }
        if (strlen($file['bytes']) > Intake::MAX_BYTES) {
            return self::fail('a-traiter', 'Fichier refusé : ' . (int) round(Intake::MAX_BYTES / 1048576) . ' Mo maximum.');
        }

        $outcome = Intake::receive([
            'bytes' => $file['bytes'],
            'originalName' => $file['name'],
            'mimeType' => $file['mime'],
            'source' => 'Dépôt',
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if (empty($outcome['ok'])) {
            return self::fail('a-traiter', isset($outcome['duplicate'])
                ? $outcome['message'] . ' Voir la pièce n° ' . $outcome['duplicate'] . '.'
                : $outcome['message']);
        }

        Audit::log('pieces.deposee', 'incoming_documents', $outcome['id'], [
            'fichier' => $file['name'], 'confiance' => $outcome['analysis']['confidence'],
        ]);
        Flash::set('success', 'Pièce reçue et analysée (confiance ' . $outcome['analysis']['confidence'] . ' %).');
        return Response::redirect('/pieces/' . $outcome['id']);
    }

    // ---------- Pièce ----------

    public static function show(Request $request, array $params): Response
    {
        $document = Intake::byId((int) $params['id']);
        if ($document === null) {
            return self::error('Pièce introuvable.', 404);
        }

        return Response::html(View::page('pieces/show', [
            'title' => t('pcs.pieceNumber', ['id' => (int) $document['id']]) . ' — ' . t('app.name'),
            'panelLabel' => t('nav.intake'),
            'headerTitle' => t('pcs.pieceNumber', ['id' => (int) $document['id']]),
            'headerSubtitle' => (string) ($document['original_name'] ?: ''),
            'navItems' => [
                ['tab' => 'lecture', 'label' => t('pcs.reading')],
                ['tab' => 'facturer', 'label' => t('pcs.tabAccounting')],
            ],
            'footLinks' => [
                ['href' => '/pieces', 'label' => t('nav.intake')],
                ['href' => '/gestion', 'label' => t('status.stageManagement')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js', '/js/meter.js'],
            'document' => $document,
            'integrity' => Intake::verify($document),
            'partners' => Finance::partners(),
            'departments' => Org::departments(),
            'currencies' => Currency::usable(),
            'baseCurrency' => Currency::base(),
            'invoiceDirections' => Finance::INVOICE_DIRECTIONS,
            'invoiceStatuses' => Finance::INVOICE_STATUSES,
            'aiReady' => Ai::isReady(),
            'today' => gmdate('Y-m-d'),
        ]));
    }

    /** Le fichier d'origine, servi seulement si son empreinte correspond toujours. */
    public static function file(Request $request, array $params): Response
    {
        $document = Intake::byId((int) $params['id']);
        if ($document === null) {
            return self::error('Pièce introuvable.', 404);
        }

        $integrity = Intake::verify($document);
        if (!$integrity['ok']) {
            return self::error('Pièce non servie : ' . $integrity['reason'] . '.', 409);
        }

        Audit::log('pieces.telechargee', 'incoming_documents', (int) $document['id'], []);
        $name = str_replace('"', '', (string) ($document['original_name'] ?: 'piece'));
        return Response::text((string) file_get_contents(Intake::pathOf($document)))->withHeaders([
            'Content-Type' => (string) $document['mime_type'],
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    public static function reanalyse(Request $request, array $params): Response
    {
        $document = Intake::byId((int) $params['id']);
        if ($document === null) {
            return self::error('Pièce introuvable.', 404);
        }

        $verdict = Intake::reanalyse((int) $document['id']);
        if (empty($verdict['ok'])) {
            Flash::set('error', $verdict['message']);
            return Response::redirect('/pieces/' . (int) $document['id']);
        }

        Audit::log('pieces.reanalysee', 'incoming_documents', (int) $document['id'], [
            'confiance' => $verdict['analysis']['confidence'],
        ]);
        Flash::set('success', 'Nouvelle lecture : confiance ' . $verdict['analysis']['confidence'] . ' %.');
        return Response::redirect('/pieces/' . (int) $document['id']);
    }

    /**
     * Création de la facture depuis la pièce. Les champs proposés par l'analyse
     * ont été relus et, au besoin, corrigés à l'écran : c'est ce qui est validé
     * qui est enregistré, jamais ce qui a été lu.
     */
    public static function invoice(Request $request, array $params): Response
    {
        $document = Intake::byId((int) $params['id']);
        if ($document === null) {
            return self::error('Pièce introuvable.', 404);
        }

        $refuse = static function (string $message) use ($document): Response {
            Flash::set('error', $message);
            return Response::redirect('/pieces/' . (int) $document['id']);
        };

        if ($document['status'] === 'Facturée') {
            return $refuse('Cette pièce a déjà donné lieu à une facture.');
        }

        $direction = trim($request->input('direction'));
        $label = mb_substr(trim($request->input('label')), 0, 160);
        $issueDate = trim($request->input('issue_date'));
        $dueDate = trim($request->input('due_date'));
        $amountHt = self::amount($request->input('amount_ht'));
        $vatRate = $request->input('vat_rate');
        $code = strtoupper(trim($request->input('currency') ?: Currency::base()));
        $partnerId = (int) $request->input('partner_id') ?: null;
        $departmentId = (int) $request->input('department_id') ?: null;

        if (!in_array($direction, Finance::INVOICE_DIRECTIONS, true)) {
            return $refuse('Sens de facture invalide.');
        }
        if ($label === '') {
            return $refuse("L'intitulé est obligatoire.");
        }
        if (!Validate::date($issueDate)) {
            return $refuse("Date d'émission invalide.");
        }
        if ($dueDate !== '' && (!Validate::date($dueDate) || $dueDate < $issueDate)) {
            return $refuse("Date d'échéance invalide.");
        }
        if ($amountHt === null || $amountHt < 0) {
            return $refuse('Montant HT invalide.');
        }
        if (!is_numeric($vatRate) || (float) $vatRate < 0 || (float) $vatRate > 100) {
            return $refuse('Taux de TVA invalide.');
        }
        if (!Currency::isKnown($code) || Currency::rateOf($code) === null) {
            return $refuse("Aucun taux connu pour $code.");
        }
        if ($partnerId !== null && Finance::partnerById($partnerId) === null) {
            return $refuse('Tiers introuvable.');
        }
        if ($departmentId !== null && Org::departmentById($departmentId) === null) {
            return $refuse('Service introuvable.');
        }

        $invoiceId = Finance::createInvoice([
            'direction' => $direction,
            'partnerId' => $partnerId,
            'departmentId' => $departmentId,
            'label' => $label,
            'issueDate' => $issueDate,
            'dueDate' => $dueDate !== '' ? $dueDate : null,
            'amountHt' => round($amountHt, 2),
            'vatRate' => (float) $vatRate,
            'status' => 'Émise',
            'currency' => $code,
            'reference' => mb_substr(trim($request->input('reference')), 0, 60),
            'notes' => 'Créée depuis la pièce reçue n° ' . (int) $document['id']
                . ($document['mail_from'] !== '' ? ' (courriel de ' . $document['mail_from'] . ')' : '') . '.',
            'createdBy' => (int) Session::get('user')['id'],
        ]);
        if ($invoiceId === null) {
            return $refuse('Facture non créée : taux de change manquant.');
        }

        Intake::setStatus((int) $document['id'], 'Facturée', [
            'userId' => (int) Session::get('user')['id'], 'invoiceId' => $invoiceId,
        ]);
        if ($partnerId !== null) {
            Intake::attachPartner((int) $document['id'], $partnerId);
        }

        Audit::log('pieces.facturee', 'invoices', $invoiceId, [
            'piece' => (int) $document['id'], 'montant' => $amountHt, 'devise' => $code,
        ]);
        Flash::set('success', 'Facture créée depuis la pièce. La pièce reste attachée comme justificatif.');
        return Response::redirect('/pieces/' . (int) $document['id']);
    }

    public static function discard(Request $request, array $params): Response
    {
        $document = Intake::byId((int) $params['id']);
        if ($document === null) {
            return self::error('Pièce introuvable.', 404);
        }

        $note = trim($request->input('note'));
        Intake::setStatus((int) $document['id'], 'Écartée', [
            'userId' => (int) Session::get('user')['id'], 'note' => $note,
        ]);
        Audit::log('pieces.ecartee', 'incoming_documents', (int) $document['id'], ['motif' => mb_substr($note, 0, 120)]);
        Flash::set('success', 'Pièce écartée.');
        return self::back('traitees');
    }

    public static function delete(Request $request, array $params): Response
    {
        $verdict = Intake::remove((int) $params['id']);
        if (empty($verdict['ok'])) {
            return self::fail('traitees', $verdict['message']);
        }

        Audit::log('pieces.supprimee', 'incoming_documents', (int) $params['id'], []);
        Flash::set('success', 'Pièce supprimée.');
        return self::back('traitees');
    }

    // ---------- Capture de la boîte aux lettres ----------

    public static function saveCapture(Request $request): Response
    {
        if (!self::isAdmin()) {
            return self::fail('capture', "Les accès à la boîte aux lettres relèvent de l'administration.");
        }

        $verdict = Mailbox::setConfig([
            'host' => $request->input('host'),
            'port' => (int) $request->input('port'),
            'secure' => $request->input('secure') === '1',
            'user' => $request->input('user'),
            'password' => $request->input('password'),
            'folder' => $request->input('folder'),
            'action' => $request->input('action'),
            'moveFolder' => $request->input('move_folder'),
            'sinceDays' => (int) $request->input('since_days'),
            'batch' => (int) $request->input('batch'),
            'allowSelfSigned' => $request->input('allow_self_signed') === '1',
            'enabled' => $request->input('enabled') === '1',
        ]);
        if (empty($verdict['ok'])) {
            return self::fail('capture', $verdict['message']);
        }

        Audit::log('pieces.capture_configuree', 'settings', null, [
            'hote' => $request->input('host'), 'actif' => $request->input('enabled') === '1',
        ]);
        Flash::set('success', 'Capture enregistrée.');
        return self::back('capture');
    }

    public static function testCapture(Request $request): Response
    {
        $verdict = Mailbox::test();
        Audit::log('pieces.capture_testee', 'settings', null, ['ok' => !empty($verdict['ok'])]);
        Flash::set(!empty($verdict['ok']) ? 'success' : 'error', $verdict['message']);
        return self::back('capture');
    }

    public static function runCapture(Request $request): Response
    {
        $verdict = Mailbox::fetchOnce();
        Flash::set(!empty($verdict['ok']) ? 'success' : 'error', $verdict['message']);
        return self::back(!empty($verdict['ok']) && !empty($verdict['received']) ? 'a-traiter' : 'capture');
    }

    // ---------- Analyse par modèle ----------

    public static function saveAnalysis(Request $request): Response
    {
        if (!self::isAdmin()) {
            return self::fail('analyse', "L'envoi de documents à un service extérieur relève de l'administration.");
        }

        $verdict = Ai::setConfig([
            'provider' => $request->input('provider'),
            'model' => $request->input('model'),
            'baseUrl' => $request->input('base_url'),
            'effort' => $request->input('effort'),
            'key' => $request->input('key'),
            'enabled' => $request->input('enabled') === '1',
        ]);
        if (empty($verdict['ok'])) {
            return self::fail('analyse', $verdict['message']);
        }

        Audit::log('pieces.analyse_configuree', 'settings', null, [
            'service' => $request->input('provider'), 'modele' => $request->input('model'),
            'actif' => $request->input('enabled') === '1',
        ]);
        Flash::set('success', $request->input('enabled') === '1'
            ? 'Analyse activée : le texte des pièces sera envoyé au service choisi.'
            : "Réglages enregistrés. L'analyse reste éteinte.");
        return self::back('analyse');
    }

    public static function testAnalysis(Request $request): Response
    {
        $verdict = Ai::test();
        Audit::log('pieces.analyse_testee', 'settings', null, ['ok' => !empty($verdict['ok'])]);
        Flash::set(!empty($verdict['ok']) ? 'success' : 'error', $verdict['message']);
        return self::back('analyse');
    }
}
