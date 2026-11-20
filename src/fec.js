const db = require('./db');
const societes = require('./societes');

/**
 * Le fichier des écritures comptables (FEC).
 *
 * Toute entreprise qui tient sa comptabilité au moyen d'un système informatisé
 * doit pouvoir le remettre à l'administration lors d'un contrôle (article
 * L47 A du livre des procédures fiscales), au format fixé par l'article A47 A-1.
 * C'est ce qui manquait pour qu'une entreprise contrôlable puisse tenir ses
 * comptes ici.
 *
 * Ce que le format impose, et que ce module tient :
 *
 * - dix-huit colonnes, dans cet ordre, la première ligne portant leurs noms ;
 * - la tabulation comme séparateur, aucune autre structure ;
 * - les dates en AAAAMMJJ, les montants avec une virgule décimale et sans
 *   séparateur de milliers ;
 * - un jeu de caractères parmi ceux que l'administration lit : ici l'ISO
 *   8859-15, qui porte l'euro et les lettres françaises ;
 * - le nom « SIRENFECAAAAMMJJ », la date étant celle de clôture de la période ;
 * - seules des écritures validées, numérotées de façon continue, et
 *   équilibrées une à une.
 *
 * Une période qui contient encore des écritures au brouillard est refusée : un
 * FEC qui en tairait une partie serait faux, et un FEC qui les livrerait sans
 * numéro ni date de validation serait rejeté.
 */

const COLONNES = [
  'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
  'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
  'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
];

const ici = () => societes.courant();

/** « 2026-03-05 » ou « 2026-03-05 10:00:00 » → « 20260305 ». */
const date = (iso) => String(iso || '').slice(0, 10).replace(/-/g, '');

/** 1234.5 → « 1234,50 ». Pas de séparateur de milliers : le format n'en veut pas. */
const montant = (n) => (Math.round((Number(n) || 0) * 100) / 100).toFixed(2).replace('.', ',');

/**
 * Un champ texte, propre à entrer dans une colonne : ni tabulation ni retour à
 * la ligne — l'un comme l'autre décaleraient tout le fichier.
 */
const texte = (v) => String(v == null ? '' : v).replace(/[\t\r\n]+/g, ' ').trim();

/** Le SIREN : les neuf premiers chiffres du numéro d'immatriculation de la société. */
function siren(registration) {
  const chiffres = String(registration || '').replace(/\D/g, '');
  return chiffres.length >= 9 ? chiffres.slice(0, 9) : null;
}

/*
 * L'ISO 8859-15 : le latin-1, où huit positions changent pour porter l'euro,
 * le « œ » et quelques lettres. Ce qui n'y entre pas est rendu par son plus
 * proche équivalent — une apostrophe typographique devient droite —, et à
 * défaut par un point d'interrogation, plutôt que de produire un octet faux.
 */
const LATIN9 = { '€': 0xa4, Š: 0xa6, š: 0xa8, Ž: 0xb4, ž: 0xb8, Œ: 0xbc, œ: 0xbd, Ÿ: 0xbe };
const PERDUS_EN_LATIN9 = new Set([0xa4, 0xa6, 0xa8, 0xb4, 0xb8, 0xbc, 0xbd, 0xbe]);
const PROCHES = {
  '‘': "'", '’': "'", '‚': "'", '“': '"', '”': '"', '„': '"',
  '–': '-', '—': '-', '…': '...', ' ': ' ', ' ': ' ', ' ': ' ',
  // Des lettres qui ne se décomposent pas en lettre et accent.
  'Ł': 'L', 'ł': 'l', 'Đ': 'D', 'đ': 'd', 'ı': 'i',
};

function latin9(chaine) {
  const octets = [];
  for (const car of chaine) {
    if (LATIN9[car] !== undefined) { octets.push(LATIN9[car]); continue; }
    const proche = PROCHES[car];
    if (proche !== undefined) { for (const c of proche) octets.push(c.charCodeAt(0)); continue; }
    const code = car.codePointAt(0);
    if (code < 0x100 && !PERDUS_EN_LATIN9.has(code)) { octets.push(code); continue; }
    // Une lettre accentuée hors du jeu (« ă », « ł ») perd son accent plutôt
    // que de devenir illisible.
    const nue = car.normalize('NFD').replace(/[̀-ͯ]/g, '');
    const c = nue.codePointAt(0);
    octets.push(nue.length === 1 && c < 0x80 ? c : 0x3f);
  }
  return Buffer.from(octets);
}

/** Les écritures du brouillard sur la période : un FEC ne se tire qu'une fois la période validée. */
function brouillons(from, to) {
  return db.prepare(`
    SELECT COUNT(*) AS n FROM entries
    WHERE company_id = ? AND validated_at IS NULL AND entry_date BETWEEN ? AND ?
  `).get(ici(), from, to).n;
}

/**
 * Le FEC d'une période.
 *
 * Rend { ok, nom, contenu (Buffer), lignes, ecritures } ou { ok: false, reason }.
 * La période est celle d'un exercice : « to » est sa date de clôture, qui
 * nomme le fichier.
 */
function generer({ from, to }) {
  const societe = db.prepare('SELECT * FROM companies WHERE id = ?').get(ici());
  const numero = siren(societe && societe.registration);
  if (!numero) return { ok: false, reason: 'no-siren' };
  if (!from || !to || from > to) return { ok: false, reason: 'bad-period' };
  const enAttente = brouillons(from, to);
  if (enAttente > 0) return { ok: false, reason: 'drafts', drafts: enAttente };

  const rows = db.prepare(`
    SELECT e.id AS entry_id, e.number, e.entry_date, e.reference, e.label AS entry_label,
           e.validated_at, e.invoice_id,
           j.code AS journal_code, j.label AS journal_label,
           a.code AS account_code, a.label AS account_label,
           l.id AS line_id, l.label AS line_label, l.debit, l.credit, l.reconciliation, l.lettered_at,
           i.issue_date AS invoice_date, i.currency AS invoice_currency,
           p.id AS partner_id, p.name AS partner_name, p.kind AS partner_kind
    FROM entries e
    JOIN journals j ON j.id = e.journal_id
    JOIN entry_lines l ON l.entry_id = e.id
    JOIN accounts a ON a.id = l.account_id
    LEFT JOIN invoices i ON i.id = e.invoice_id
    LEFT JOIN partners p ON p.id = i.partner_id
    WHERE e.company_id = ? AND e.validated_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
    ORDER BY e.number, l.id
  `).all(ici(), from, to);

  // Chaque écriture est équilibrée : c'est la première chose que vérifie
  // l'outil de l'administration, et une écriture qui ne l'est pas trahirait
  // une base abîmée qu'il vaut mieux voir ici que pendant un contrôle.
  const soldes = new Map();
  for (const r of rows) {
    const s = soldes.get(r.entry_id) || 0;
    soldes.set(r.entry_id, Math.round((s + Number(r.debit) - Number(r.credit)) * 100) / 100);
  }
  const desequilibrees = [...soldes.entries()].filter(([, s]) => s !== 0).map(([id]) => id);
  if (desequilibrees.length) return { ok: false, reason: 'unbalanced', entries: desequilibrees };

  const lignes = rows.map((r) => {
    // Le compte auxiliaire : le tiers de la facture, sur les comptes de tiers
    // (classe 4, clients 411 et fournisseurs 401) seulement.
    const tiers = r.partner_id && /^4(01|11)/.test(r.account_code);
    return [
      r.journal_code,
      r.journal_label,
      String(r.number),
      date(r.entry_date),
      r.account_code,
      r.account_label,
      tiers ? `${r.account_code.startsWith('411') ? 'C' : 'F'}${String(r.partner_id).padStart(6, '0')}` : '',
      tiers ? r.partner_name : '',
      r.reference || String(r.number),
      date(r.invoice_date || r.entry_date),
      r.line_label || r.entry_label,
      montant(r.debit),
      montant(r.credit),
      r.reconciliation || '',
      r.reconciliation && r.lettered_at ? date(r.lettered_at) : '',
      date(r.validated_at),
      '',
      '',
    ].map(texte).join('\t');
  });

  const contenu = latin9([COLONNES.join('\t'), ...lignes].join('\r\n') + '\r\n');
  return {
    ok: true,
    nom: `${numero}FEC${date(to)}.txt`,
    contenu,
    lignes: lignes.length,
    ecritures: soldes.size,
  };
}

module.exports = { COLONNES, generer, siren, latin9, brouillons };
