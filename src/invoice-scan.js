const settings = require('./settings');
const db = require('./db');

/**
 * Lecture automatique d'une facture reçue.
 *
 * Le comptable reçoit des PDF de vingt fournisseurs différents, tous mis en
 * page autrement. Ce module lit le texte du document et en tire ce qu'il faut
 * pour créer l'écriture : émetteur, numéro, dates, montants, taux, IBAN.
 *
 * Trois principes le tiennent :
 *
 *   1. **Rien n'est deviné en silence.** Chaque champ trouvé porte sa
 *      confiance et l'endroit d'où il vient. Un montant lu à côté du mot
 *      « Total TTC » ne vaut pas un nombre trouvé au hasard dans la page.
 *   2. **La cohérence prime sur la ressemblance.** HT + TVA doit faire TTC.
 *      Quand les trois montants s'accordent, la confiance monte ; quand ils
 *      se contredisent, elle tombe et l'écran le dit.
 *   3. **Le comptable tranche.** L'analyse pré-remplit un formulaire, elle ne
 *      crée jamais une facture toute seule. Une lecture automatique qui écrit
 *      directement en comptabilité est une erreur qu'on découvre au bilan.
 */

// Une facture peut être libellée dans une autre devise que celle des comptes.
const CURRENCY_MARKS = [
  { code: 'EUR', patterns: [/€/, /\beuros?\b/i, /\bEUR\b/] },
  { code: 'USD', patterns: [/\$/, /\bUSD\b/, /\bdollars?\b/i] },
  { code: 'GBP', patterns: [/£/, /\bGBP\b/] },
  { code: 'CHF', patterns: [/\bCHF\b/, /\bfrancs? suisses?\b/i] },
  { code: 'MAD', patterns: [/\bMAD\b/, /\bdirhams?\b/i] },
  { code: 'XOF', patterns: [/\bXOF\b/, /\bFCFA\b/, /\bF\s?CFA\b/] },
];

const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

// ---------- Nombres et dates ----------

/**
 * « 1 234,56 », « 1.234,56 », « 1,234.56 » et « 1234.56 » désignent le même
 * montant. Le dernier séparateur rencontré est le décimal : c'est la seule
 * règle qui marche sans savoir d'avance dans quel pays la facture a été faite.
 */
function parseNumber(raw) {
  const cleaned = String(raw || '').replace(/[\s   ]/g, '').replace(/[€$£]/g, '');
  if (!/\d/.test(cleaned)) return null;

  const lastComma = cleaned.lastIndexOf(',');
  const lastDot = cleaned.lastIndexOf('.');
  let normalized = cleaned;

  if (lastComma >= 0 && lastDot >= 0) {
    const decimal = lastComma > lastDot ? ',' : '.';
    const thousands = decimal === ',' ? '.' : ',';
    normalized = cleaned.split(thousands).join('').replace(decimal, '.');
  } else if (lastComma >= 0) {
    normalized = cleaned.replace(/,(?=\d{3}\b)/g, '').replace(',', '.');
  } else if (lastDot >= 0) {
    // « 1.234 » est un millier ; « 12.34 » est un décimal.
    const decimals = cleaned.length - lastDot - 1;
    normalized = decimals === 3 ? cleaned.split('.').join('') : cleaned;
  }

  const value = Number(normalized.replace(/[^0-9.-]/g, ''));
  return Number.isFinite(value) ? Math.round(value * 100) / 100 : null;
}

const pad = (value) => String(value).padStart(2, '0');

/** Rend une date ISO à partir des formats qu'on rencontre vraiment. */
function parseDate(raw) {
  const text = String(raw || '').trim().toLowerCase();

  let match = text.match(/(\d{4})-(\d{2})-(\d{2})/);
  if (match) return `${match[1]}-${match[2]}-${match[3]}`;

  match = text.match(/(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/);
  if (match) {
    const year = match[3].length === 2 ? `20${match[3]}` : match[3];
    const day = Number(match[1]);
    const month = Number(match[2]);
    if (month >= 1 && month <= 12 && day >= 1 && day <= 31) return `${year}-${pad(month)}-${pad(day)}`;
  }

  match = text.match(new RegExp(`(\\d{1,2})\\s*(?:er)?\\s+(${MONTHS.join('|')})\\.?\\s+(\\d{4})`, 'i'));
  if (match) {
    const month = MONTHS.indexOf(match[2].toLowerCase()) + 1;
    if (month) return `${match[3]}-${pad(month)}-${pad(Number(match[1]))}`;
  }
  return null;
}

// ---------- Identifiants ----------

/** Clé de Luhn : un SIRET mal recopié se voit sans interroger l'INSEE. */
function luhnValid(digits) {
  let sum = 0;
  for (let i = 0; i < digits.length; i += 1) {
    let value = Number(digits[digits.length - 1 - i]);
    if (i % 2 === 1) {
      value *= 2;
      if (value > 9) value -= 9;
    }
    sum += value;
  }
  return sum % 10 === 0;
}

function findSirets(text) {
  const found = [];
  for (const match of text.matchAll(/\b(\d[\d\s.]{12,19}\d)\b/g)) {
    const digits = match[1].replace(/\D/g, '');
    if (digits.length === 14 && luhnValid(digits)) found.push(digits);
  }
  return [...new Set(found)];
}

function findSirens(text) {
  const found = [];
  for (const match of text.matchAll(/\b(\d{3}[\s.]?\d{3}[\s.]?\d{3})\b/g)) {
    const digits = match[1].replace(/\D/g, '');
    if (digits.length === 9 && luhnValid(digits)) found.push(digits);
  }
  return [...new Set(found)];
}

function findVatNumbers(text) {
  return [...new Set([...text.matchAll(/\b(FR[\s]?[0-9A-Z]{2}[\s]?\d{3}[\s]?\d{3}[\s]?\d{3})\b/gi)]
    .map((m) => m[1].replace(/\s/g, '').toUpperCase()))];
}

/** IBAN : la clé modulo 97 écarte les coquilles de recopie. */
function ibanValid(iban) {
  const value = iban.replace(/\s/g, '').toUpperCase();
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/.test(value)) return false;

  const rearranged = value.slice(4) + value.slice(0, 4);
  const digits = [...rearranged].map((char) => (/[A-Z]/.test(char) ? String(char.charCodeAt(0) - 55) : char)).join('');

  let remainder = 0;
  for (const digit of digits) remainder = (remainder * 10 + Number(digit)) % 97;
  return remainder === 1;
}

function findIban(text) {
  for (const match of text.matchAll(/\b([A-Z]{2}\d{2}(?:[\s]?[A-Z0-9]{2,4}){2,8})\b/g)) {
    if (ibanValid(match[1])) return match[1].replace(/\s/g, '').toUpperCase();
  }
  return null;
}

// ---------- Recherche par étiquette ----------

const ACCENTS = /[̀-ͯ]/g;
const fold = (value) => String(value || '').normalize('NFD').replace(ACCENTS, '').toLowerCase();

const AMOUNT_TOKEN = /-?\d[\d\s   .,]*\d|\d/g;

/** Les nombres d'une ligne, les pourcentages écartés : « TVA 20 % 190,00 » vaut 190. */
function amountsInLine(line) {
  const withoutPercents = line.replace(/-?[\d.,]+\s*%/g, ' ');
  return [...withoutPercents.matchAll(AMOUNT_TOKEN)]
    .map((match) => parseNumber(match[0]))
    .filter((value) => value !== null);
}

/**
 * Cherche un montant annoncé par une étiquette, ligne par ligne : dans une
 * facture, le libellé et sa valeur sont sur la même ligne, séparés par une
 * colonne de blancs qu'aucune distance en caractères ne prédit. Faute de
 * valeur sur la ligne, on regarde la suivante — les tableaux cassent parfois
 * la ligne entre le libellé et le montant.
 *
 * La dernière occurrence l'emporte : le récapitulatif de bas de page est plus
 * fiable qu'une ligne de détail portant le même mot.
 */
function labelledAmount(text, labels) {
  const lines = String(text || '').split('\n');
  let best = null;

  lines.forEach((line, index) => {
    const folded = fold(line);
    for (const label of labels) {
      const needle = fold(label);
      const at = folded.indexOf(needle);
      if (at < 0) continue;

      const after = amountsInLine(line.slice(at + needle.length));
      if (after.length) {
        best = { value: after[after.length - 1], label, line: index + 1 };
        continue;
      }
      const next = lines[index + 1] ? amountsInLine(lines[index + 1]) : [];
      if (next.length) best = { value: next[0], label, line: index + 2 };
    }
  });
  return best;
}

/** Même principe pour les dates : l'étiquette, puis la date sur la même ligne. */
function labelledDate(text, labels) {
  const lines = String(text || '').split('\n');
  let best = null;

  lines.forEach((line, index) => {
    const folded = fold(line);
    for (const label of labels) {
      const needle = fold(label);
      const at = folded.indexOf(needle);
      if (at < 0) continue;

      const parsed = parseDate(line.slice(at + needle.length)) || (lines[index + 1] ? parseDate(lines[index + 1]) : null);
      if (parsed && !best) best = { value: parsed, label, line: index + 1 };
    }
  });
  return best;
}

/**
 * Le numéro de facture. Le « n° » est écrit de dix façons — « N° », « No »,
 * « Nº », « num. », ou rien du tout quand la mise en page l'a mangé — et le
 * candidat doit contenir un chiffre : sans cela, « Facture Acquittée » ferait
 * un numéro. Une date n'en est pas un non plus.
 */
function findReference(text) {
  const patterns = [
    /(?:facture|invoice)\s*(?:n[°ºo]?\.?|num[ée]ro|#)?\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_.]{2,24})/i,
    /(?:n[°ºo]|num[ée]ro)\s*(?:de\s*)?facture\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_.]{2,24})/i,
  ];

  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (!match) continue;

    const candidate = match[1].replace(/[.,;]$/, '');
    if (!/\d/.test(candidate)) continue;
    if (parseDate(candidate)) continue;
    return candidate;
  }
  return null;
}

function findCurrency(text) {
  for (const currency of CURRENCY_MARKS) {
    if (currency.patterns.some((pattern) => pattern.test(text))) return currency.code;
  }
  return null;
}

// ---------- Rapprochement avec les tiers connus ----------

/**
 * Le meilleur indice d'émetteur n'est pas la mise en page : c'est le tiers
 * déjà enregistré dont l'identifiant ou le nom figure dans le document.
 */
function matchPartner(text, { sirets = [], sirens = [], vats = [] } = {}) {
  const folded = fold(text);
  const partners = db.prepare('SELECT id, name, kind, registration FROM partners').all();

  for (const partner of partners) {
    const registration = String(partner.registration || '').replace(/\s/g, '').toUpperCase();
    if (!registration) continue;
    const digits = registration.replace(/\D/g, '');
    if (
      (digits.length >= 9 && (sirets.includes(digits) || sirens.includes(digits) || sirets.some((s) => s.startsWith(digits))))
      || vats.includes(registration)
    ) {
      return { partner, reason: 'identifiant', confidence: 95 };
    }
  }

  // À défaut, le nom : on exige au moins quatre caractères pour éviter qu'un
  // tiers nommé « SA » ne se rapproche de toutes les factures.
  const named = partners
    .filter((partner) => fold(partner.name).length >= 4 && folded.includes(fold(partner.name)))
    .sort((a, b) => b.name.length - a.name.length)[0];

  return named ? { partner: named, reason: 'nom', confidence: 70 } : null;
}

/** À défaut de tiers connu, la raison sociale se cherche en tête de document. */
function guessSupplierName(text) {
  const lines = String(text || '').split('\n').map((line) => line.trim()).filter(Boolean).slice(0, 12);
  const noise = /(facture|invoice|devis|adresse|t[ée]l|email|@|www|http|siret|siren|tva|page|\d{5}\s)/i;

  const candidate = lines.find((line) => line.length >= 3 && line.length <= 60 && !noise.test(line) && /[A-Za-zÀ-ÿ]/.test(line));
  return candidate || null;
}

// ---------- Analyse ----------

const round = (value) => Math.round(value * 100) / 100;

/**
 * Analyse le texte d'une facture. Rend toujours un résultat : un document
 * illisible donne des champs vides et une confiance nulle, jamais une erreur.
 */
function analyse(text, { fileName = '' } = {}) {
  const content = String(text || '');
  const notes = [];

  const sirets = findSirets(content);
  const sirens = findSirens(content);
  const vats = findVatNumbers(content);

  // Nos propres identifiants ne désignent pas un fournisseur : ils désignent
  // le destinataire, c'est-à-dire nous.
  const ourSiren = String(settings.get('company_siren') || '').replace(/\D/g, '');
  const ourVat = String(settings.get('company_vat') || '').replace(/\s/g, '').toUpperCase();

  const foreignSirets = sirets.filter((s) => !ourSiren || !s.startsWith(ourSiren));
  const foreignSirens = sirens.filter((s) => s !== ourSiren);
  const foreignVats = vats.filter((v) => v !== ourVat);
  const ourselves = (ourSiren && (sirens.includes(ourSiren) || sirets.some((s) => s.startsWith(ourSiren)))) || (ourVat && vats.includes(ourVat));

  const ht = labelledAmount(content, ['total ht', 'montant ht', 'total hors taxes', 'base ht', 'sous-total', 'prix ht']);
  const vat = labelledAmount(content, ['total tva', 'montant tva', 'tva', 'dont tva']);
  const ttc = labelledAmount(content, ['net a payer', 'total ttc', 'montant ttc', 'total a payer', 'total toutes taxes', 'montant du']);
  const rate = (() => {
    const match = content.match(/tva\s*(?:\(|\s|:|à)?\s*(\d{1,2}(?:[.,]\d{1,2})?)\s*%/i)
      || content.match(/(\d{1,2}(?:[.,]\d{1,2})?)\s*%\s*(?:de\s*)?tva/i);
    return match ? parseNumber(match[1]) : null;
  })();

  const issue = labelledDate(content, ['date de facture', "date d'emission", 'date d’émission', 'emise le', 'date facture', 'date']);
  const due = labelledDate(content, ['date d’échéance', "date d'echeance", 'echeance', 'a payer avant', 'date limite de paiement', 'payable avant']);

  const match = matchPartner(content, { sirets: foreignSirets, sirens: foreignSirens, vats: foreignVats });

  // Cohérence : c'est elle qui distingue une lecture réussie d'un ramassage
  // de nombres. Elle peut aussi reconstituer le montant manquant.
  const amounts = { ht: ht ? ht.value : null, vat: vat ? vat.value : null, ttc: ttc ? ttc.value : null };
  let coherent = null;

  if (amounts.ht !== null && amounts.vat !== null && amounts.ttc !== null) {
    coherent = Math.abs(round(amounts.ht + amounts.vat) - amounts.ttc) <= 0.02;
    if (!coherent) notes.push('HT + TVA ne fait pas TTC : les montants lus se contredisent.');
  } else if (amounts.ht !== null && amounts.ttc !== null) {
    amounts.vat = round(amounts.ttc - amounts.ht);
    coherent = amounts.vat >= 0;
    notes.push('TVA déduite de la différence entre TTC et HT.');
  } else if (amounts.ht !== null && rate !== null) {
    amounts.ttc = round(amounts.ht * (1 + rate / 100));
    amounts.vat = round(amounts.ttc - amounts.ht);
    notes.push('TTC reconstitué à partir du HT et du taux.');
  } else if (amounts.ttc !== null && rate !== null) {
    amounts.ht = round(amounts.ttc / (1 + rate / 100));
    amounts.vat = round(amounts.ttc - amounts.ht);
    notes.push('HT reconstitué à partir du TTC et du taux.');
  }

  const derivedRate = rate !== null
    ? rate
    : (amounts.ht && amounts.vat !== null ? round((amounts.vat / amounts.ht) * 100) : null);

  const fields = {
    reference: findReference(content),
    issueDate: issue ? issue.value : null,
    dueDate: due ? due.value : null,
    amountHt: amounts.ht,
    amountVat: amounts.vat,
    amountTtc: amounts.ttc,
    vatRate: derivedRate,
    currency: findCurrency(content) || 'EUR',
    iban: findIban(content),
    siret: foreignSirets[0] || null,
    siren: foreignSirens[0] || (foreignSirets[0] ? foreignSirets[0].slice(0, 9) : null),
    vatNumber: foreignVats[0] || null,
    supplierName: match ? match.partner.name : guessSupplierName(content),
    partnerId: match ? match.partner.id : null,
    partnerReason: match ? match.reason : null,
  };

  // La confiance se compose : chaque élément retrouvé en apporte une part, et
  // l'incohérence en retire. Elle sert à trier la pile, pas à décider seule.
  let confidence = 0;
  if (fields.reference) confidence += 15;
  if (fields.issueDate) confidence += 15;
  if (amounts.ttc !== null) confidence += 20;
  if (amounts.ht !== null) confidence += 10;
  if (derivedRate !== null) confidence += 5;
  if (fields.siret || fields.siren || fields.vatNumber) confidence += 10;
  if (fields.partnerId) confidence += match.confidence >= 90 ? 20 : 10;
  if (coherent === true) confidence += 10;
  if (coherent === false) confidence -= 25;
  if (!content.trim()) notes.push('Aucun texte lisible : document scanné en image ?');

  return {
    fields,
    notes,
    coherent,
    confidence: Math.max(0, Math.min(100, confidence)),
    direction: ourselves && foreignSirets.length === 0 && foreignSirens.length === 0 ? 'Client' : 'Fournisseur',
    source: 'règles',
    fileName,
    textLength: content.length,
  };
}

module.exports = {
  parseNumber, parseDate, luhnValid, ibanValid,
  findSirets, findSirens, findVatNumbers, findIban, findReference, findCurrency,
  labelledAmount, labelledDate, matchPartner, guessSupplierName, analyse,
};
