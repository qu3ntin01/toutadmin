const db = require('./db');
const settings = require('./settings');

/**
 * Contrôle des mentions obligatoires et export du XML CII (UN/CEFACT), la
 * charge utile réglementaire d'une facture électronique au sens EN 16931.
 * L'encapsulation Factur-X (PDF/A-3) reste à faire par un outil dédié.
 */

// Identité de l'émetteur : sans elle, aucune facture n'est conforme.
const ISSUER_FIELDS = [
  { key: 'company_legal_name', label: 'Raison sociale', required: true },
  { key: 'company_siren', label: 'SIREN', required: true, pattern: /^\d{9}$/, hint: '9 chiffres' },
  { key: 'company_vat', label: 'Numéro de TVA intracommunautaire', required: true, pattern: /^[A-Z]{2}[0-9A-Z]{2,13}$/, hint: 'Ex : FR12345678901' },
  { key: 'company_address', label: 'Adresse', required: true },
  { key: 'company_postal_code', label: 'Code postal', required: true },
  { key: 'company_city', label: 'Ville', required: true },
  { key: 'company_country', label: 'Pays (code ISO)', required: true, pattern: /^[A-Z]{2}$/, hint: 'Ex : FR' },
];

const round = (n) => Math.round(n * 100) / 100;

function issuer() {
  return Object.fromEntries(ISSUER_FIELDS.map((f) => [f.key, settings.get(f.key) || '']));
}

function saveIssuer(values) {
  const errors = [];
  const clean = {};

  for (const field of ISSUER_FIELDS) {
    const value = (values[field.key] || '').trim().slice(0, 200);
    if (value && field.pattern && !field.pattern.test(value)) {
      errors.push(`${field.label} : format attendu ${field.hint}.`);
    }
    clean[field.key] = value;
  }

  if (errors.length) return { ok: false, errors };
  settings.setMany(clean);
  return { ok: true };
}

/** Les manques qui empêchent d'émettre : identité de l'émetteur et du client. */
function issuerGaps() {
  const values = issuer();
  return ISSUER_FIELDS.filter((f) => f.required && !values[f.key]).map((f) => f.label);
}

/**
 * Contrôle EN 16931 d'une facture : identifiant, dates, montants, et
 * identification des deux parties. La liste des manques est rendue telle
 * quelle à l'écran plutôt que résumée en « non conforme ».
 */
function check(invoice) {
  const gaps = [];

  for (const label of issuerGaps()) gaps.push(`Émetteur — ${label}`);

  if (!invoice.reference) gaps.push("Numéro de facture (référence) : obligatoire et séquentiel");
  if (!invoice.issue_date) gaps.push("Date d'émission");
  if (!invoice.due_date) gaps.push("Date d'échéance de paiement");
  if (!invoice.partner_id) gaps.push('Client destinataire');
  if (!(invoice.amount_ht > 0)) gaps.push('Montant hors taxes strictement positif');

  const partner = invoice.partner_id ? db.prepare('SELECT * FROM partners WHERE id = ?').get(invoice.partner_id) : null;
  if (partner) {
    if (!partner.registration) gaps.push(`Client « ${partner.name} » — SIREN ou identifiant d'entreprise`);
    if (!partner.address) gaps.push(`Client « ${partner.name} » — adresse postale`);
  }

  return { ok: gaps.length === 0, gaps, partner };
}

const escapeXml = (value) =>
  String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');

const xmlDate = (iso) => (iso ? iso.replace(/-/g, '') : '');

/** XML CII conforme au profil EN 16931, une ligne unique par facture. */
function toXml(invoice) {
  const { partner } = check(invoice);
  const seller = issuer();
  const ht = round(invoice.amount_ht);
  const vat = round(ht * (invoice.vat_rate / 100));
  const ttc = round(ht + vat);

  return `<?xml version="1.0" encoding="UTF-8"?>
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
    <ram:ID>${escapeXml(invoice.reference)}</ram:ID>
    <ram:TypeCode>380</ram:TypeCode>
    <ram:IssueDateTime>
      <udt:DateTimeString format="102">${xmlDate(invoice.issue_date)}</udt:DateTimeString>
    </ram:IssueDateTime>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:IncludedSupplyChainTradeLineItem>
      <ram:AssociatedDocumentLineDocument><ram:LineID>1</ram:LineID></ram:AssociatedDocumentLineDocument>
      <ram:SpecifiedTradeProduct><ram:Name>${escapeXml(invoice.label)}</ram:Name></ram:SpecifiedTradeProduct>
      <ram:SpecifiedLineTradeAgreement>
        <ram:NetPriceProductTradePrice><ram:ChargeAmount>${ht.toFixed(2)}</ram:ChargeAmount></ram:NetPriceProductTradePrice>
      </ram:SpecifiedLineTradeAgreement>
      <ram:SpecifiedLineTradeDelivery><ram:BilledQuantity unitCode="C62">1</ram:BilledQuantity></ram:SpecifiedLineTradeDelivery>
      <ram:SpecifiedLineTradeSettlement>
        <ram:ApplicableTradeTax>
          <ram:TypeCode>VAT</ram:TypeCode>
          <ram:CategoryCode>S</ram:CategoryCode>
          <ram:RateApplicablePercent>${Number(invoice.vat_rate).toFixed(2)}</ram:RateApplicablePercent>
        </ram:ApplicableTradeTax>
        <ram:SpecifiedTradeSettlementLineMonetarySummation>
          <ram:LineTotalAmount>${ht.toFixed(2)}</ram:LineTotalAmount>
        </ram:SpecifiedTradeSettlementLineMonetarySummation>
      </ram:SpecifiedLineTradeSettlement>
    </ram:IncludedSupplyChainTradeLineItem>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty>
        <ram:Name>${escapeXml(seller.company_legal_name)}</ram:Name>
        <ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">${escapeXml(seller.company_siren)}</ram:ID></ram:SpecifiedLegalOrganization>
        <ram:PostalTradeAddress>
          <ram:PostcodeCode>${escapeXml(seller.company_postal_code)}</ram:PostcodeCode>
          <ram:LineOne>${escapeXml(seller.company_address)}</ram:LineOne>
          <ram:CityName>${escapeXml(seller.company_city)}</ram:CityName>
          <ram:CountryID>${escapeXml(seller.company_country)}</ram:CountryID>
        </ram:PostalTradeAddress>
        <ram:SpecifiedTaxRegistration><ram:ID schemeID="VA">${escapeXml(seller.company_vat)}</ram:ID></ram:SpecifiedTaxRegistration>
      </ram:SellerTradeParty>
      <ram:BuyerTradeParty>
        <ram:Name>${escapeXml(partner ? partner.name : '')}</ram:Name>
        <ram:SpecifiedLegalOrganization><ram:ID schemeID="0002">${escapeXml(partner ? partner.registration : '')}</ram:ID></ram:SpecifiedLegalOrganization>
        <ram:PostalTradeAddress><ram:LineOne>${escapeXml(partner ? partner.address : '')}</ram:LineOne></ram:PostalTradeAddress>
      </ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeDelivery />
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:ApplicableTradeTax>
        <ram:CalculatedAmount>${vat.toFixed(2)}</ram:CalculatedAmount>
        <ram:TypeCode>VAT</ram:TypeCode>
        <ram:BasisAmount>${ht.toFixed(2)}</ram:BasisAmount>
        <ram:CategoryCode>S</ram:CategoryCode>
        <ram:RateApplicablePercent>${Number(invoice.vat_rate).toFixed(2)}</ram:RateApplicablePercent>
      </ram:ApplicableTradeTax>
      <ram:SpecifiedTradePaymentTerms>
        <ram:DueDateDateTime>
          <udt:DateTimeString format="102">${xmlDate(invoice.due_date)}</udt:DateTimeString>
        </ram:DueDateDateTime>
      </ram:SpecifiedTradePaymentTerms>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:LineTotalAmount>${ht.toFixed(2)}</ram:LineTotalAmount>
        <ram:TaxBasisTotalAmount>${ht.toFixed(2)}</ram:TaxBasisTotalAmount>
        <ram:TaxTotalAmount currencyID="EUR">${vat.toFixed(2)}</ram:TaxTotalAmount>
        <ram:GrandTotalAmount>${ttc.toFixed(2)}</ram:GrandTotalAmount>
        <ram:DuePayableAmount>${ttc.toFixed(2)}</ram:DuePayableAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
`;
}

/** Nom de fichier normalisé, utilisable tel quel dans un dépôt. */
function fileName(invoice) {
  const reference = (invoice.reference || `FA-${invoice.id}`).replace(/[^A-Za-z0-9_-]/g, '-');
  return `facture-${reference}.xml`;
}

module.exports = { ISSUER_FIELDS, issuer, saveIssuer, issuerGaps, check, toXml, fileName };
