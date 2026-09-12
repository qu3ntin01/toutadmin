<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

/**
 * Contrôle des mentions obligatoires et export du XML CII (UN/CEFACT), la
 * charge utile réglementaire d'une facture électronique au sens EN 16931.
 * L'encapsulation Factur-X (PDF/A-3) reste à faire par un outil dédié.
 */
final class EInvoicing
{
    /** Identité de l'émetteur : sans elle, aucune facture n'est conforme. */
    public const ISSUER_FIELDS = [
        ['key' => 'company_legal_name', 'label' => 'Raison sociale', 'required' => true, 'pattern' => null, 'hint' => ''],
        ['key' => 'company_siren', 'label' => 'SIREN', 'required' => true, 'pattern' => '/^\d{9}$/', 'hint' => '9 chiffres'],
        ['key' => 'company_vat', 'label' => 'Numéro de TVA intracommunautaire', 'required' => true,
         'pattern' => '/^[A-Z]{2}[0-9A-Z]{2,13}$/', 'hint' => 'Ex : FR12345678901'],
        ['key' => 'company_address', 'label' => 'Adresse', 'required' => true, 'pattern' => null, 'hint' => ''],
        ['key' => 'company_postal_code', 'label' => 'Code postal', 'required' => true, 'pattern' => null, 'hint' => ''],
        ['key' => 'company_city', 'label' => 'Ville', 'required' => true, 'pattern' => null, 'hint' => ''],
        ['key' => 'company_country', 'label' => 'Pays (code ISO)', 'required' => true,
         'pattern' => '/^[A-Z]{2}$/', 'hint' => 'Ex : FR'],
    ];

    public static function issuer(): array
    {
        $values = [];
        foreach (self::ISSUER_FIELDS as $field) {
            $values[$field['key']] = (string) Settings::get($field['key']);
        }
        return $values;
    }

    public static function saveIssuer(array $values): array
    {
        $errors = [];
        $clean = [];

        foreach (self::ISSUER_FIELDS as $field) {
            $value = mb_substr(trim((string) ($values[$field['key']] ?? '')), 0, 200);
            if ($value !== '' && $field['pattern'] !== null && !preg_match($field['pattern'], $value)) {
                $errors[] = $field['label'] . ' : format attendu ' . $field['hint'] . '.';
            }
            $clean[$field['key']] = $value;
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }
        Settings::setMany($clean);
        return ['ok' => true, 'errors' => []];
    }

    /** Les manques qui empêchent d'émettre : identité de l'émetteur et du client. */
    public static function issuerGaps(): array
    {
        $values = self::issuer();
        $gaps = [];
        foreach (self::ISSUER_FIELDS as $field) {
            if ($field['required'] && $values[$field['key']] === '') {
                $gaps[] = $field['label'];
            }
        }
        return $gaps;
    }

    /**
     * Contrôle EN 16931 d'une facture : identifiant, dates, montants, et
     * identification des deux parties. La liste des manques est rendue telle
     * quelle à l'écran plutôt que résumée en « non conforme ».
     */
    public static function check(array $invoice): array
    {
        $gaps = [];
        foreach (self::issuerGaps() as $label) {
            $gaps[] = 'Émetteur — ' . $label;
        }

        if (($invoice['reference'] ?? '') === '') {
            $gaps[] = 'Numéro de facture (référence) : obligatoire et séquentiel';
        }
        if (empty($invoice['issue_date'])) {
            $gaps[] = "Date d'émission";
        }
        if (empty($invoice['due_date'])) {
            $gaps[] = 'Date d\'échéance de paiement';
        }
        if (empty($invoice['partner_id'])) {
            $gaps[] = 'Client destinataire';
        }
        if (!((float) ($invoice['amount_ht'] ?? 0) > 0)) {
            $gaps[] = 'Montant hors taxes strictement positif';
        }

        $partner = empty($invoice['partner_id'])
            ? null
            : Db::get('SELECT * FROM partners WHERE id = ?', [(int) $invoice['partner_id']]);

        if ($partner !== null) {
            if (($partner['registration'] ?? '') === '') {
                $gaps[] = 'Client « ' . $partner['name'] . " » — SIREN ou identifiant d'entreprise";
            }
            if (($partner['address'] ?? '') === '') {
                $gaps[] = 'Client « ' . $partner['name'] . ' » — adresse postale';
            }
        }

        return ['ok' => $gaps === [], 'gaps' => $gaps, 'partner' => $partner];
    }

    private static function escapeXml(mixed $value): string
    {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            (string) ($value ?? '')
        );
    }

    private static function xmlDate(?string $iso): string
    {
        return $iso === null || $iso === '' ? '' : str_replace('-', '', $iso);
    }

    /** XML CII conforme au profil EN 16931, une ligne unique par facture. */
    public static function toXml(array $invoice): string
    {
        $partner = self::check($invoice)['partner'];
        $seller = self::issuer();
        $ht = round((float) $invoice['amount_ht'], 2);
        $vat = round($ht * ((float) $invoice['vat_rate'] / 100), 2);
        $ttc = round($ht + $vat, 2);

        $currency = self::escapeXml($invoice['currency'] ?: 'EUR');
        $rate = number_format((float) $invoice['vat_rate'], 2, '.', '');
        $htText = number_format($ht, 2, '.', '');
        $vatText = number_format($vat, 2, '.', '');
        $ttcText = number_format($ttc, 2, '.', '');

        return '<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice
    xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
    xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
    xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocumentContext>
    <ram:GuidelineSpecifiedDocumentContextParameter>
      <ram:ID>urn:cen.eu:en16931:2017</ram:ID>
    </ram:GuidelineSpecifiedDocumentContextParameter>
  </rsm:ExchangedDocumentContext>
  <rsm:ExchangedDocument>
    <ram:ID>' . self::escapeXml($invoice['reference']) . '</ram:ID>
    <ram:TypeCode>380</ram:TypeCode>
    <ram:IssueDateTime>
      <udt:DateTimeString format="102">' . self::xmlDate($invoice['issue_date']) . '</udt:DateTimeString>
    </ram:IssueDateTime>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>' . self::escapeXml($invoice['label']) . '</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice><ram:ChargeAmount>' . $htText . '</ram:ChargeAmount></ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>' . $rate . '</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:SpecifiedTradeSettlementLineMonetarySummation>
          <ram:LineTotalAmount>' . $htText . '</ram:LineTotalAmount>
        </ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>
    </ram:IncludedSupplyChainTradeLineItem>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty>
        <ram:Name>' . self::escapeXml($seller['company_legal_name']) . '</ram:Name>
        <ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . self::escapeXml($seller['company_siren']) . '</ram:ID></ram:SpecifiedLegalOrganization>
        <ram:PostalTradeAddress>
          <ram:PostcodeCode>' . self::escapeXml($seller['company_postal_code']) . '</ram:PostcodeCode>
          <ram:LineOne>' . self::escapeXml($seller['company_address']) . '</ram:LineOne>
          <ram:CityName>' . self::escapeXml($seller['company_city']) . '</ram:CityName>
          <ram:CountryID>' . self::escapeXml($seller['company_country']) . '</ram:CountryID>
        </ram:PostalTradeAddress>
        <ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">' . self::escapeXml($seller['company_vat']) . '</ram:ID></ram:SpecifiedTaxRegistration>
      </ram:SellerTradeParty>
      <ram:BuyerTradeParty>
        <ram:Name>' . self::escapeXml($partner === null ? '' : $partner['name']) . '</ram:Name>
        <ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">' . self::escapeXml($partner === null ? '' : $partner['registration']) . '</ram:ID></ram:SpecifiedLegalOrganization>
        <ram:PostalTradeAddress><ram:LineOne>' . self::escapeXml($partner === null ? '' : $partner['address']) . '</ram:LineOne></ram:PostalTradeAddress>
      </ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeDelivery />
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>' . $currency . '</ram:InvoiceCurrencyCode>
      <ram:ApplicableTradeTax>
        <ram:CalculatedAmount>' . $vatText . '</ram:CalculatedAmount>
        <ram:TypeCode>VAT</ram:TypeCode>
        <ram:BasisAmount>' . $htText . '</ram:BasisAmount>
        <ram:CategoryCode>S</ram:CategoryCode>
        <ram:RateApplicablePercent>' . $rate . '</ram:RateApplicablePercent>
      </ram:ApplicableTradeTax>
      <ram:SpecifiedTradePaymentTerms>
        <ram:DueDateDateTime>
          <udt:DateTimeString format="102">' . self::xmlDate($invoice['due_date']) . '</udt:DateTimeString>
        </ram:DueDateDateTime>
      </ram:SpecifiedTradePaymentTerms>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:LineTotalAmount>' . $htText . '</ram:LineTotalAmount>
        <ram:TaxBasisTotalAmount>' . $htText . '</ram:TaxBasisTotalAmount>
        <ram:TaxTotalAmount currencyID="' . $currency . '">' . $vatText . '</ram:TaxTotalAmount>
        <ram:GrandTotalAmount>' . $ttcText . '</ram:GrandTotalAmount>
        <ram:DuePayableAmount>' . $ttcText . '</ram:DuePayableAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
';
    }

    /** Nom de fichier normalisé, utilisable tel quel dans un dépôt. */
    public static function fileName(array $invoice): string
    {
        $reference = ($invoice['reference'] ?? '') !== '' ? $invoice['reference'] : 'FA-' . (int) $invoice['id'];
        return 'facture-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $reference) . '.xml';
    }
}
