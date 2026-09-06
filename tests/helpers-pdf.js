/**
 * Un PDF minimal mais réel, écrit à la main pour les tests : la chaîne de
 * lecture (signature du fichier, extraction du texte, analyse) doit être
 * éprouvée sur un vrai PDF, pas sur un fichier texte déguisé.
 */
function makePdf(lines) {
  const content = lines
    .map((line, index) => `BT /F1 12 Tf 50 ${760 - index * 18} Td (${String(line).replace(/[()\\]/g, '')}) Tj ET`)
    .join('\n');

  const objects = [
    '<< /Type /Catalog /Pages 2 0 R >>',
    '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
    `<< /Length ${content.length} >>\nstream\n${content}\nendstream`,
    '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
  ];

  let pdf = '%PDF-1.4\n';
  const offsets = [];
  objects.forEach((object, index) => {
    offsets.push(pdf.length);
    pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
  });

  const xref = pdf.length;
  pdf += `xref\n0 ${objects.length + 1}\n0000000000 65535 f \n`
    + offsets.map((offset) => `${String(offset).padStart(10, '0')} 00000 n \n`).join('');
  pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`;

  return Buffer.from(pdf, 'latin1');
}

/** Une facture d'essai, telle qu'un fournisseur l'enverrait. */
function invoicePdf({
  supplier = 'ACIERS DU NORD SAS',
  siret = '732 829 320 00074',
  reference = 'F2026-0147',
  issueDate = '12/03/2026',
  dueDate = '11/04/2026',
  ht = '950,00',
  vat = '190,00',
  ttc = '1 140,00',
} = {}) {
  return makePdf([
    supplier,
    `SIRET ${siret} - TVA FR44732829320`,
    `FACTURE N ${reference}`,
    `Date de facture : ${issueDate}`,
    `Date d'echeance : ${dueDate}`,
    `Total HT ${ht} EUR`,
    `TVA 20 % ${vat} EUR`,
    `Net a payer ${ttc} EUR`,
    'IBAN FR76 3000 6000 0112 3456 7890 189',
  ]);
}

/** Un courriel multipart portant une pièce jointe, au format brut. */
function mailWithAttachment({
  from = 'Aciers du Nord <compta@aciers.test>',
  subject = 'Facture F2026-0147',
  date = 'Thu, 12 Mar 2026 09:00:00 +0100',
  fileName = 'facture.pdf',
  contentType = 'application/pdf',
  attachment = null,
  body = 'Bonjour, veuillez trouver notre facture.',
} = {}) {
  const parts = [
    `From: ${from}`,
    'To: factures@exemple.fr',
    `Subject: ${subject}`,
    `Date: ${date}`,
    'MIME-Version: 1.0',
  ];

  if (!attachment) {
    return `${parts.concat(['Content-Type: text/plain; charset=utf-8', '', body, '']).join('\r\n')}`;
  }

  return parts.concat([
    'Content-Type: multipart/mixed; boundary="sep"',
    '',
    '--sep',
    'Content-Type: text/plain; charset=utf-8',
    '',
    body,
    '--sep',
    `Content-Type: ${contentType}; name="${fileName}"`,
    `Content-Disposition: attachment; filename="${fileName}"`,
    'Content-Transfer-Encoding: base64',
    '',
    attachment.toString('base64').replace(/(.{76})/g, '$1\r\n'),
    '--sep--',
    '',
  ]).join('\r\n');
}

module.exports = { makePdf, invoicePdf, mailWithAttachment };
