const crypto = require('crypto');
const db = require('./db');
const societes = require('./societes');

/**
 * Les paiements SEPA : virements aux fournisseurs, prélèvements des clients.
 *
 * Le produit tenait les factures, mais les payer se faisait ailleurs : on
 * recopiait IBAN et montants un par un dans l'interface de la banque. Ce module
 * produit les deux fichiers que toutes les banques de la zone SEPA acceptent :
 *
 * - le **pain.001.001.03**, un lot de virements, pour régler d'un coup les
 *   factures fournisseurs en attente ;
 * - le **pain.008.001.02**, un lot de prélèvements SEPA (schéma « CORE »), pour
 *   encaisser les factures des clients qui ont signé un mandat.
 *
 * Le fichier se dépose dans l'espace de la banque ; il n'est pas transmis
 * d'ici, et rien n'est marqué payé à sa production : c'est le relevé, importé
 * puis rapproché, qui dit ce qui est réellement passé.
 *
 * Ce que la norme impose et que ce module tient : l'euro seul ; un jeu de
 * caractères restreint (lettres latines sans accent, chiffres et quelques
 * signes), auquel les libellés sont ramenés ; des longueurs bornées ; la clé
 * de contrôle de l'IBAN et de l'identifiant créancier (ICS) ; une référence de
 * mandat (RUM) et sa date de signature sur chaque prélèvement.
 */

const ici = () => societes.courant();

// ---------------------------------------------------------------- contrôles

/** La clé modulo 97 de l'ISO 13616 : elle écarte les coquilles de recopie. */
function mod97(chaine) {
  let reste = 0;
  for (const car of chaine) {
    const valeur = /[A-Z]/.test(car) ? String(car.charCodeAt(0) - 55) : car;
    for (const chiffre of valeur) reste = (reste * 10 + Number(chiffre)) % 97;
  }
  return reste;
}

const compacter = (v) => String(v || '').replace(/\s+/g, '').toUpperCase();

function ibanValide(iban) {
  const v = compacter(iban);
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(v)) return false;
  return mod97(v.slice(4) + v.slice(0, 4)) === 1;
}

function bicValide(bic) {
  return /^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/.test(compacter(bic));
}

/**
 * L'identifiant créancier SEPA (ICS) : pays, clé, code d'activité, identifiant
 * national. La clé se calcule comme celle d'un IBAN, le code d'activité écarté.
 */
function icsValide(ics) {
  const v = compacter(ics);
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/.test(v)) return false;
  return mod97(v.slice(7) + v.slice(0, 4)) === 1;
}

/** La RUM : trente-cinq caractères au plus, du jeu restreint, sans « // » ni barre en tête. */
function rumValide(rum) {
  const v = String(rum || '').trim();
  return /^[A-Za-z0-9+?/\-:().,' ]{1,35}$/.test(v) && !v.startsWith('/') && !v.includes('//');
}

// ------------------------------------------------------------------- texte

/** Ramène un libellé au jeu de caractères SEPA : sans accent, sans signe exotique. */
function sepaTexte(valeur, longueur) {
  const nu = String(valeur || '')
    .replace(/[’‘]/g, "'").replace(/[«»“”]/g, '').replace(/[–—]/g, '-').replace(/&/g, '+')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[Œœ]/g, (c) => (c === 'Œ' ? 'OE' : 'oe')).replace(/[Ææ]/g, (c) => (c === 'Æ' ? 'AE' : 'ae')).replace(/ß/g, 'ss')
    .replace(/[^A-Za-z0-9/\-?:().,'+ ]/g, ' ')
    .replace(/\s+/g, ' ').trim();
  return nu.slice(0, longueur).trim();
}

const xml = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const montant = (n) => (Math.round(n * 100) / 100).toFixed(2);
const identifiant = (prefixe) => `${prefixe}-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}-${crypto.randomBytes(3).toString('hex').toUpperCase()}`;

function agent(bic) {
  return bicValide(bic)
    ? `<FinInstnId><BIC>${compacter(bic)}</BIC></FinInstnId>`
    // Depuis 2016, l'IBAN suffit : la banque se déduit de lui.
    : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
}

// ----------------------------------------------------------- coordonnées

function societe() {
  return db.prepare('SELECT * FROM companies WHERE id = ?').get(ici()) || null;
}

/** Ce qui manque à la société pour émettre des virements, ou des prélèvements. */
function manquesSociete({ prelevement = false } = {}) {
  const s = societe();
  const manques = [];
  if (!s || !ibanValide(s.iban)) manques.push('iban');
  if (prelevement && (!s || !icsValide(s.sepa_ics))) manques.push('ics');
  return manques;
}

function poserCoordonneesSociete({ iban, bic, ics }) {
  const v = { iban: compacter(iban), bic: compacter(bic), ics: compacter(ics) };
  if (v.iban && !ibanValide(v.iban)) return { ok: false, reason: 'iban' };
  if (v.bic && !bicValide(v.bic)) return { ok: false, reason: 'bic' };
  if (v.ics && !icsValide(v.ics)) return { ok: false, reason: 'ics' };
  db.prepare('UPDATE companies SET iban = ?, bic = ?, sepa_ics = ? WHERE id = ?').run(v.iban, v.bic, v.ics, ici());
  return { ok: true };
}

function poserCoordonneesTiers(partnerId, { iban, bic }) {
  const v = { iban: compacter(iban), bic: compacter(bic) };
  if (v.iban && !ibanValide(v.iban)) return { ok: false, reason: 'iban' };
  if (v.bic && !bicValide(v.bic)) return { ok: false, reason: 'bic' };
  const n = db.prepare('UPDATE partners SET iban = ?, bic = ? WHERE id = ? AND company_id = ?')
    .run(v.iban, v.bic, Number(partnerId) || 0, ici()).changes;
  return n ? { ok: true } : { ok: false, reason: 'not-found' };
}

// ----------------------------------------------------------------- mandats

function mandatsDe(partnerId) {
  return db.prepare('SELECT * FROM sepa_mandates WHERE partner_id = ? AND company_id = ? ORDER BY signed_on DESC, id DESC')
    .all(Number(partnerId) || 0, ici());
}

/** Une RUM proposée : lisible, unique dans la société. */
function rumProposee(partnerId) {
  const base = `RUM-${String(partnerId).padStart(5, '0')}-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}`;
  let rum = base;
  for (let i = 2; db.prepare('SELECT 1 AS ok FROM sepa_mandates WHERE rum = ? AND company_id = ?').get(rum, ici()); i += 1) rum = `${base}-${i}`;
  return rum;
}

function creerMandat(partnerId, { rum, signedOn, iban, bic }, today = new Date().toISOString().slice(0, 10)) {
  const tiers = db.prepare('SELECT id FROM partners WHERE id = ? AND company_id = ?').get(Number(partnerId) || 0, ici());
  if (!tiers) return { ok: false, reason: 'not-found' };
  const propre = { rum: String(rum || '').trim(), iban: compacter(iban), bic: compacter(bic) };
  if (!rumValide(propre.rum)) return { ok: false, reason: 'rum' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(signedOn || '') || signedOn > today) return { ok: false, reason: 'date' };
  if (!ibanValide(propre.iban)) return { ok: false, reason: 'iban' };
  if (propre.bic && !bicValide(propre.bic)) return { ok: false, reason: 'bic' };
  if (db.prepare('SELECT 1 AS ok FROM sepa_mandates WHERE rum = ? AND company_id = ?').get(propre.rum, ici())) {
    return { ok: false, reason: 'duplicate' };
  }
  const id = db.prepare(`
    INSERT INTO sepa_mandates (company_id, partner_id, rum, signed_on, iban, bic) VALUES (?, ?, ?, ?, ?, ?)
  `).run(ici(), tiers.id, propre.rum, signedOn, propre.iban, propre.bic).lastInsertRowid;
  return { ok: true, id };
}

function revoquerMandat(id) {
  const n = db.prepare("UPDATE sepa_mandates SET status = 'Révoqué' WHERE id = ? AND company_id = ? AND status = 'Actif'")
    .run(Number(id) || 0, ici()).changes;
  return n > 0;
}

function mandatParId(id) {
  return db.prepare('SELECT * FROM sepa_mandates WHERE id = ? AND company_id = ?').get(Number(id) || 0, ici()) || null;
}

// --------------------------------------------------------- factures éligibles

const ttc = (f) => Math.round(Number(f.amount_ht) * (1 + Number(f.vat_rate) / 100) * 100) / 100;

/** Les factures fournisseurs à régler par virement, avec ce qui en empêcherait le paiement. */
function aVirer() {
  return db.prepare(`
    SELECT i.*, p.name AS partner_name, p.iban AS partner_iban, p.bic AS partner_bic
    FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
    WHERE i.company_id = ? AND i.direction = 'Fournisseur' AND i.status = 'Émise'
    ORDER BY COALESCE(i.due_date, i.issue_date), i.id
  `).all(ici()).map((f) => ({ ...f, total: ttc(f), obstacle: obstacleVirement(f) }));
}

function obstacleVirement(f) {
  if ((f.currency || 'EUR') !== 'EUR') return 'currency';
  if (!f.partner_id) return 'no-partner';
  if (!ibanValide(f.partner_iban)) return 'no-iban';
  if (!(ttc(f) > 0)) return 'amount';
  return null;
}

/** Les factures clients à prélever : celles d'un tiers qui a un mandat actif. */
function aPrelever() {
  return db.prepare(`
    SELECT i.*, p.name AS partner_name,
      (SELECT m.id FROM sepa_mandates m WHERE m.partner_id = i.partner_id AND m.company_id = i.company_id
         AND m.status = 'Actif' ORDER BY m.signed_on DESC, m.id DESC LIMIT 1) AS mandate_id
    FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
    WHERE i.company_id = ? AND i.direction = 'Client' AND i.status = 'Émise'
    ORDER BY COALESCE(i.due_date, i.issue_date), i.id
  `).all(ici()).map((f) => ({ ...f, total: ttc(f), obstacle: obstaclePrelevement(f) }));
}

function obstaclePrelevement(f) {
  if ((f.currency || 'EUR') !== 'EUR') return 'currency';
  if (!f.partner_id) return 'no-partner';
  if (!f.mandate_id) return 'no-mandate';
  if (!(ttc(f) > 0)) return 'amount';
  return null;
}

// ------------------------------------------------------------------ fichiers

function choisir(liste, ids) {
  const voulus = new Set(ids.map(Number));
  const retenues = liste.filter((f) => voulus.has(f.id));
  if (retenues.length === 0) return { ok: false, reason: 'none' };
  const bloquee = retenues.find((f) => f.obstacle);
  if (bloquee) return { ok: false, reason: 'blocked', reference: bloquee.reference || bloquee.label, obstacle: bloquee.obstacle };
  return { ok: true, retenues };
}

const enTete = (racine, espace, msgId, n, somme, nom) => `<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:${espace}">
<${racine}>
<GrpHdr><MsgId>${xml(msgId)}</MsgId><CreDtTm>${new Date().toISOString().slice(0, 19)}</CreDtTm><NbOfTxs>${n}</NbOfTxs><CtrlSum>${montant(somme)}</CtrlSum><InitgPty><Nm>${xml(nom)}</Nm></InitgPty></GrpHdr>`;

/**
 * Le lot de virements d'une sélection de factures fournisseurs.
 * Rend { ok, xml, nom, nombre, total } ou { ok: false, reason, … }.
 */
function virements(ids, date, today = new Date().toISOString().slice(0, 10)) {
  if (manquesSociete().length) return { ok: false, reason: 'company' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date || '') || date < today) return { ok: false, reason: 'date' };
  const choix = choisir(aVirer(), ids);
  if (!choix.ok) return choix;
  const s = societe();
  const nom = sepaTexte(s.name, 70);
  const total = choix.retenues.reduce((t, f) => t + f.total, 0);
  const msgId = identifiant('VIR');
  const lignes = choix.retenues.map((f, i) => `<CdtTrfTxInf>
<PmtId><EndToEndId>${xml(sepaTexte(f.reference || `FAC-${f.id}`, 35) || `FAC-${f.id}`)}</EndToEndId></PmtId>
<Amt><InstdAmt Ccy="EUR">${montant(f.total)}</InstdAmt></Amt>
${bicValide(f.partner_bic) ? `<CdtrAgt>${agent(f.partner_bic)}</CdtrAgt>\n` : ''}<Cdtr><Nm>${xml(sepaTexte(f.partner_name, 70) || `Fournisseur ${i + 1}`)}</Nm></Cdtr>
<CdtrAcct><Id><IBAN>${compacter(f.partner_iban)}</IBAN></Id></CdtrAcct>
<RmtInf><Ustrd>${xml(sepaTexte(`${f.reference || ''} ${f.label || ''}`, 140) || `Facture ${f.id}`)}</Ustrd></RmtInf>
</CdtTrfTxInf>`).join('\n');
  const contenu = `${enTete('CstmrCdtTrfInitn', 'pain.001.001.03', msgId, choix.retenues.length, total, nom)}
<PmtInf><PmtInfId>${xml(msgId)}-1</PmtInfId><PmtMtd>TRF</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>${choix.retenues.length}</NbOfTxs><CtrlSum>${montant(total)}</CtrlSum>
<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf><ReqdExctnDt>${date}</ReqdExctnDt>
<Dbtr><Nm>${xml(nom)}</Nm></Dbtr><DbtrAcct><Id><IBAN>${compacter(s.iban)}</IBAN></Id></DbtrAcct><DbtrAgt>${agent(s.bic)}</DbtrAgt><ChrgBr>SLEV</ChrgBr>
${lignes}
</PmtInf>
</CstmrCdtTrfInitn>
</Document>
`;
  return { ok: true, xml: contenu, nom: `${msgId}.xml`, nombre: choix.retenues.length, total: Math.round(total * 100) / 100 };
}

/**
 * Le lot de prélèvements d'une sélection de factures clients.
 *
 * Le premier prélèvement sur un mandat part en séquence « FRST », les suivants
 * en « RCUR » : deux blocs de paiement, que la banque traite chacun selon sa
 * séquence. Le mandat est marqué utilisé à la production du fichier — si le
 * fichier n'est pas déposé, le suivant partira en « RCUR », ce que la norme
 * admet depuis 2016 pour une première présentation.
 *
 * La date d'échéance est au plus tôt le lendemain : c'est le délai de
 * présentation du schéma CORE.
 */
function prelevements(ids, date, today = new Date().toISOString().slice(0, 10)) {
  if (manquesSociete({ prelevement: true }).length) return { ok: false, reason: 'company' };
  if (!/^\d{4}-\d{2}-\d{2}$/.test(date || '') || date <= today) return { ok: false, reason: 'date' };
  const choix = choisir(aPrelever(), ids);
  if (!choix.ok) return choix;
  const s = societe();
  const nom = sepaTexte(s.name, 70);
  const msgId = identifiant('PRL');
  const avecMandat = choix.retenues.map((f) => ({ ...f, mandat: mandatParId(f.mandate_id) }));
  const groupes = { FRST: avecMandat.filter((f) => !f.mandat.first_used_on), RCUR: avecMandat.filter((f) => f.mandat.first_used_on) };
  const total = avecMandat.reduce((t, f) => t + f.total, 0);

  const bloc = (sequence, liste, rang) => {
    const somme = liste.reduce((t, f) => t + f.total, 0);
    return `<PmtInf><PmtInfId>${xml(msgId)}-${rang}</PmtInfId><PmtMtd>DD</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>${liste.length}</NbOfTxs><CtrlSum>${montant(somme)}</CtrlSum>
<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm><SeqTp>${sequence}</SeqTp></PmtTpInf><ReqdColltnDt>${date}</ReqdColltnDt>
<Cdtr><Nm>${xml(nom)}</Nm></Cdtr><CdtrAcct><Id><IBAN>${compacter(s.iban)}</IBAN></Id></CdtrAcct><CdtrAgt>${agent(s.bic)}</CdtrAgt><ChrgBr>SLEV</ChrgBr>
<CdtrSchmeId><Id><PrvtId><Othr><Id>${compacter(s.sepa_ics)}</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>
${liste.map((f, i) => `<DrctDbtTxInf>
<PmtId><EndToEndId>${xml(sepaTexte(f.reference || `FAC-${f.id}`, 35) || `FAC-${f.id}`)}</EndToEndId></PmtId>
<InstdAmt Ccy="EUR">${montant(f.total)}</InstdAmt>
<DrctDbtTx><MndtRltdInf><MndtId>${xml(f.mandat.rum)}</MndtId><DtOfSgntr>${f.mandat.signed_on}</DtOfSgntr></MndtRltdInf></DrctDbtTx>
<DbtrAgt>${agent(f.mandat.bic)}</DbtrAgt>
<Dbtr><Nm>${xml(sepaTexte(f.partner_name, 70) || `Client ${i + 1}`)}</Nm></Dbtr>
<DbtrAcct><Id><IBAN>${compacter(f.mandat.iban)}</IBAN></Id></DbtrAcct>
<RmtInf><Ustrd>${xml(sepaTexte(`${f.reference || ''} ${f.label || ''}`, 140) || `Facture ${f.id}`)}</Ustrd></RmtInf>
</DrctDbtTxInf>`).join('\n')}
</PmtInf>`;
  };

  const blocs = [];
  if (groupes.FRST.length) blocs.push(bloc('FRST', groupes.FRST, blocs.length + 1));
  if (groupes.RCUR.length) blocs.push(bloc('RCUR', groupes.RCUR, blocs.length + 1));
  const contenu = `${enTete('CstmrDrctDbtInitn', 'pain.008.001.02', msgId, avecMandat.length, total, nom)}
${blocs.join('\n')}
</CstmrDrctDbtInitn>
</Document>
`;
  const marquer = db.prepare('UPDATE sepa_mandates SET first_used_on = ? WHERE id = ? AND company_id = ? AND first_used_on IS NULL');
  for (const f of groupes.FRST) marquer.run(today, f.mandat.id, ici());
  return { ok: true, xml: contenu, nom: `${msgId}.xml`, nombre: avecMandat.length, total: Math.round(total * 100) / 100 };
}

module.exports = {
  ibanValide, bicValide, icsValide, rumValide, sepaTexte, compacter,
  societe, manquesSociete, poserCoordonneesSociete, poserCoordonneesTiers,
  mandatsDe, rumProposee, creerMandat, revoquerMandat, mandatParId,
  aVirer, aPrelever, virements, prelevements,
};
