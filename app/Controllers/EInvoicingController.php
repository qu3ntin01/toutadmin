<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Modules\EInvoicing;
use App\Modules\Finance;

/**
 * Facturation électronique.
 *
 * L'écran ne dit pas « non conforme » : il liste ce qui manque, facture par
 * facture. Et le XML n'est produit que si le contrôle passe — rien d'invalide
 * ne sort d'ici.
 */
final class EInvoicingController
{
    private static function error(string $message, int $status): Response
    {
        return Response::html(View::page('error', ['title' => $message, 'message' => $message]), $status);
    }

    public static function index(Request $request): Response
    {
        // Seules les factures clients sont émises : une facture fournisseur est reçue.
        $invoices = array_map(static function (array $invoice): array {
            $invoice['conformity'] = EInvoicing::check($invoice);
            return $invoice;
        }, Finance::invoices('Client'));

        $ready = count(array_filter($invoices, static fn (array $row): bool => $row['conformity']['ok']));

        return Response::html(View::page('einvoicing/index', [
            'title' => t('einv.panel') . ' — ' . t('app.name'),
            'panelLabel' => t('einv.panel'),
            'headerTitle' => t('einv.panel'),
            'headerSubtitle' => t('einv.headerSub'),
            'navItems' => [
                ['tab' => 'factures', 'label' => t('erp.invoices'), 'badge' => (count($invoices) - $ready) ?: null],
                ['tab' => 'emetteur', 'label' => t('common.issuer')],
            ],
            'footLinks' => [['href' => '/gestion', 'label' => t('nav.gestion')]],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'issuerFields' => EInvoicing::ISSUER_FIELDS,
            'issuer' => EInvoicing::issuer(),
            'issuerGaps' => EInvoicing::issuerGaps(),
            'invoices' => $invoices,
            'readyCount' => $ready,
        ]));
    }

    public static function saveIssuer(Request $request): Response
    {
        $values = [];
        foreach (EInvoicing::ISSUER_FIELDS as $field) {
            $values[$field['key']] = $request->input($field['key']);
        }

        $result = EInvoicing::saveIssuer($values);
        Flash::set($result['ok'] ? 'success' : 'error', $result['ok']
            ? "Identité de l'émetteur enregistrée."
            : implode(' ', $result['errors']));
        return Response::redirect('/facturation-electronique#emetteur');
    }

    /** Le XML n'est produit que si la facture passe le contrôle. */
    public static function xml(Request $request, array $params): Response
    {
        $invoice = Finance::invoiceById((int) $params['id']);
        if ($invoice === null || $invoice['direction'] !== 'Client') {
            return self::error('Facture client introuvable.', 404);
        }

        $conformity = EInvoicing::check($invoice);
        if (!$conformity['ok']) {
            Flash::set('error', 'Facture non conforme : ' . implode(' · ', $conformity['gaps']));
            return Response::redirect('/facturation-electronique#factures');
        }

        return Response::text(EInvoicing::toXml($invoice))->withHeaders([
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . EInvoicing::fileName($invoice) . '"',
        ]);
    }
}
