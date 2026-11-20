const einvoicing = require('./einvoicing');
const pdf = require('./pdf-writer');
const font = require('./pdf-font');

/**
 * Factur-X : la facture lisible et la facture calculable dans le même fichier.
 *
 * Une facture électronique au sens de la norme, ce n'est ni un PDF ni un XML,
 * c'est les deux ensemble. Le PDF est ce que lit la comptable ; le XML joint
 * est ce que lit son logiciel. Les deux doivent dire la même chose — d'où le
 * fait qu'ils soient produits ici d'une seule source, la facture en base, et
 * jamais recopiés l'un depuis l'autre.
 *
 * Le contenant est un PDF/A-3 : le format d'archivage qui, seul, autorise à
 * joindre un fichier de données. C'est `pdf-writer.js` qui en tient les règles.
 *
 * Ce que ce module ne fait pas : déposer la facture sur une plateforme agréée.
 * Cela demande un compte chez un opérateur, pas du code.
 */

const A4 = pdf.A4;
const LEFT = 56;
const RIGHT = A4.width - 56;
const ATTACHMENT = 'factur-x.xml';
const PROFILE = 'EN 16931';

const round = (n) => Math.round(n * 100) / 100;

/** Un montant tel qu'on l'écrit sur une facture française. */
function money(amount, currency = 'EUR') {
  const fixed = round(Number(amount) || 0).toFixed(2).replace('.', ',');
  const withSeparators = fixed.replace(/\B(?=(\d{3})+(?!\d)\,)/g, ' ');
  const symbols = { EUR: '€', USD: '$', GBP: '£', CHF: 'CHF' };
  return `${withSeparators} ${symbols[currency] || currency}`;
}

/** Une date au format du pays, pas au format de la base. */
function day(iso) {
  if (!iso) return '—';
  const [y, m, d] = String(iso).slice(0, 10).split('-');
  return `${d}/${m}/${y}`;
}

/**
 * Coupe un texte à la largeur donnée, en points. Une adresse sur trois lignes
 * vaut mieux qu'une adresse qui déborde de la page.
 */
function wrap(text, maxPoints, size) {
  const words = String(text || '').split(/\s+/).filter(Boolean);
  const lines = [];
  let current = '';

  for (const word of words) {
    const candidate = current ? `${current} ${word}` : word;
    if (current && (font.widthOf(candidate) * size) / 1000 > maxPoints) {
      lines.push(current);
      current = word;
    } else {
      current = candidate;
    }
  }
  if (current) lines.push(current);
  return lines;
}

/**
 * La mise en page. Rien de décoratif : l'en-tête, les deux parties, la ligne
 * de prestation, les totaux, les dates, et la mention qui dit à celui qui
 * ouvre le fichier qu'un XML l'accompagne.
 */
function layout(invoice) {
  const { partner } = einvoicing.check(invoice);
  const seller = einvoicing.issuer();
  const currency = invoice.currency || 'EUR';

  const ht = round(invoice.amount_ht);
  const vat = round(ht * (invoice.vat_rate / 100));
  const ttc = round(ht + vat);

  const lines = [];
  const put = (text, x, y, extra = {}) => lines.push({ text, x, y, ...extra });

  // En-tête
  put(seller.company_legal_name || '—', LEFT, 790, { size: 15 });
  put('FACTURE', RIGHT, 790, { size: 15, align: 'right' });
  put(invoice.reference || `FA-${invoice.id}`, RIGHT, 772, { size: 11, align: 'right' });
  lines.push({ rule: true, x: LEFT, x2: RIGHT, y: 762, width: 1 });

  // Émetteur
  let y = 738;
  put('Émetteur', LEFT, y, { size: 8 });
  y -= 14;
  for (const piece of [
    seller.company_legal_name,
    seller.company_address,
    `${seller.company_postal_code || ''} ${seller.company_city || ''}`.trim(),
    seller.company_country,
    seller.company_siren ? `SIREN ${seller.company_siren}` : '',
    seller.company_vat ? `TVA ${seller.company_vat}` : '',
  ].filter(Boolean)) {
    for (const row of wrap(piece, 220, 9)) { put(row, LEFT, y); y -= 12; }
  }

  // Client
  let yb = 738;
  const middle = LEFT + 280;
  put('Client', middle, yb, { size: 8 });
  yb -= 14;
  for (const piece of [
    partner ? partner.name : '—',
    partner ? partner.address : '',
    partner && partner.registration ? `Identifiant ${partner.registration}` : '',
  ].filter(Boolean)) {
    for (const row of wrap(piece, 230, 9)) { put(row, middle, yb); yb -= 12; }
  }

  // Dates
  let yd = Math.min(y, yb) - 16;
  put(`Date d'émission : ${day(invoice.issue_date)}`, LEFT, yd);
  put(`Échéance de paiement : ${day(invoice.due_date)}`, middle, yd);
  yd -= 26;

  // La prestation
  lines.push({ rule: true, x: LEFT, x2: RIGHT, y: yd + 14, width: 0.8 });
  put('Désignation', LEFT, yd, { size: 8 });
  put('Taux', RIGHT - 210, yd, { size: 8, align: 'right' });
  put('Montant HT', RIGHT, yd, { size: 8, align: 'right' });
  lines.push({ rule: true, x: LEFT, x2: RIGHT, y: yd - 6, width: 0.4 });

  yd -= 22;
  const designation = wrap(invoice.label || 'Prestation', 225, 9);
  designation.forEach((row, i) => put(row, LEFT, yd - i * 12));
  put(`${Number(invoice.vat_rate).toFixed(2).replace('.', ',')} %`, RIGHT - 210, yd, { align: 'right' });
  put(money(ht, currency), RIGHT, yd, { align: 'right' });
  yd -= Math.max(24, designation.length * 12 + 12);

  // Les totaux
  lines.push({ rule: true, x: RIGHT - 220, x2: RIGHT, y: yd + 12, width: 0.4 });
  put('Total hors taxes', RIGHT - 120, yd, { align: 'right' });
  put(money(ht, currency), RIGHT, yd, { align: 'right' });
  yd -= 14;
  put(`TVA ${Number(invoice.vat_rate).toFixed(2).replace('.', ',')} %`, RIGHT - 120, yd, { align: 'right' });
  put(money(vat, currency), RIGHT, yd, { align: 'right' });
  yd -= 18;
  lines.push({ rule: true, x: RIGHT - 220, x2: RIGHT, y: yd + 12, width: 0.8 });
  put('Total toutes taxes comprises', RIGHT - 120, yd, { align: 'right', size: 10 });
  put(money(ttc, currency), RIGHT, yd, { align: 'right', size: 10 });

  // Le pied de page dit ce que le fichier contient : quelqu'un qui l'ouvre
  // dans une visionneuse ordinaire ne verrait pas le XML autrement.
  lines.push({ rule: true, x: LEFT, x2: RIGHT, y: 84, width: 0.4 });
  put(`Facture électronique Factur-X — profil ${PROFILE}.`, LEFT, 70, { size: 8 });
  put(`Les données de cette facture sont jointes à ce fichier sous le nom ${ATTACHMENT}.`, LEFT, 58, { size: 8 });
  put(`Devise : ${currency}`, RIGHT, 70, { size: 8, align: 'right' });

  return lines;
}

/**
 * Construit le fichier. Rien ne sort d'une facture qui ne passe pas le
 * contrôle EN 16931 : un Factur-X non conforme est pire qu'un PDF ordinaire,
 * parce qu'il promet une conformité qu'il n'a pas.
 */
function build(invoice) {
  const conformity = einvoicing.check(invoice);
  if (!conformity.ok) return { ok: false, gaps: conformity.gaps };

  const xml = einvoicing.toXml(invoice);
  const seller = einvoicing.issuer();
  const reference = invoice.reference || `FA-${invoice.id}`;

  const content = pdf.render({
    title: `Facture ${reference}`,
    author: seller.company_legal_name || 'Toutadmin',
    lines: layout(invoice),
    profile: PROFILE,
    attachment: {
      name: ATTACHMENT,
      content: xml,
      description: `Facture ${reference} au format CII (EN 16931)`,
    },
  });

  return { ok: true, content, xml, fileName: fileName(invoice) };
}

/** Nom de fichier normalisé, utilisable tel quel dans un dépôt. */
function fileName(invoice) {
  const reference = (invoice.reference || `FA-${invoice.id}`).replace(/[^A-Za-z0-9_-]/g, '-');
  return `facture-${reference}.pdf`;
}

module.exports = { ATTACHMENT, PROFILE, build, layout, fileName, money, day, wrap };
