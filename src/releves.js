const crypto = require('crypto');

/**
 * Lire un relevé bancaire : CFONB 120, CAMT.053 ou OFX.
 *
 * Le rapprochement existait, mais les mouvements se saisissaient à la main, un
 * par un, depuis le relevé papier ou le site de la banque. C'est le geste qui
 * décourage de tenir sa trésorerie à jour. Les trois formats couvrent ce que
 * les banques françaises et européennes remettent :
 *
 * - le CFONB 120, le relevé de compte historique des banques françaises, à
 *   enregistrements de 120 caractères ;
 * - le CAMT.053, le relevé ISO 20022 qui le remplace (XML) ;
 * - l'OFX, que proposent les sites des banques pour les logiciels de compte.
 *
 * Ce module lit et rend une liste de mouvements ; il n'écrit rien. Il ne
 * dépend d'aucune bibliothèque : le XML du CAMT est régulier, l'OFX est un
 * SGML de balises, et un analyseur de plus serait une surface d'attaque de plus
 * pour un fichier qui vient de l'extérieur.
 *
 * Chaque mouvement porte une empreinte stable, faite de ce que la banque dit de
 * lui : importer deux fois le même relevé — ou deux relevés qui se recouvrent —
 * ne double rien.
 */

const LIMITE = 20000; // mouvements par fichier : au-delà, ce n'est pas un relevé.

/** Le texte du fichier : UTF-8 s'il en est, sinon latin-1, l'encodage des banques. */
function texte(buffer) {
  const utf8 = buffer.toString('utf8');
  return utf8.includes('�') ? buffer.toString('latin1') : utf8.replace(/^﻿/, '');
}

function formatDe(contenu) {
  const debut = contenu.slice(0, 4000);
  if (/<BkToCstmrStmt\b/.test(debut) || /camt\.053/.test(debut)) return 'camt053';
  if (/OFXHEADER|<OFX>/i.test(debut)) return 'ofx';
  const lignes = contenu.split(/\r?\n/).filter((l) => l.trim());
  if (lignes.length && lignes.every((l) => /^(01|04|05|07)/.test(l) && l.replace(/\s+$/, '').length <= 120)) return 'cfonb120';
  return null;
}

const empreinte = (...morceaux) => crypto.createHash('sha256').update(morceaux.join('|')).digest('hex').slice(0, 32);
const propre = (s) => String(s || '').replace(/\s+/g, ' ').trim();

// ------------------------------------------------------------------ CFONB 120

/*
 * Le dernier caractère du montant porte le signe et le dernier chiffre : « { »
 * à « I » pour +0 à +9, « } » à « R » pour −0 à −9. C'est l'héritage des cartes
 * perforées, et c'est toujours la norme.
 */
const CREDIT = '{ABCDEFGHI';
const DEBIT = '}JKLMNOPQR';

function montantCfonb(zone, decimales) {
  const corps = zone.slice(0, -1);
  const fin = zone.slice(-1);
  let chiffre;
  let signe;
  if (CREDIT.includes(fin)) { chiffre = CREDIT.indexOf(fin); signe = 1; } else if (DEBIT.includes(fin)) { chiffre = DEBIT.indexOf(fin); signe = -1; } else return null;
  if (!/^\d+$/.test(corps)) return null;
  return signe * Number(`${corps}${chiffre}`) / 10 ** decimales;
}

function dateCfonb(jjmmaa) {
  if (!/^\d{6}$/.test(jjmmaa)) return null;
  const [j, m, a] = [jjmmaa.slice(0, 2), jjmmaa.slice(2, 4), jjmmaa.slice(4, 6)];
  return `20${a}-${m}-${j}`;
}

function lireCfonb(contenu) {
  const mouvements = [];
  let compte = null;
  for (const brute of contenu.split(/\r?\n/)) {
    const ligne = brute.padEnd(120, ' ');
    const code = ligne.slice(0, 2);
    if (code === '01' && !compte) compte = ligne.slice(21, 32).trim();
    if (code === '04') {
      const decimales = Number(ligne.slice(19, 20)) || 2;
      const montant = montantCfonb(ligne.slice(90, 104), decimales);
      const date = dateCfonb(ligne.slice(34, 40));
      if (montant === null || !date) continue;
      const ecriture = ligne.slice(81, 88).trim();
      mouvements.push({
        date,
        dateValeur: dateCfonb(ligne.slice(42, 48)) || date,
        libelle: propre(ligne.slice(48, 79)),
        montant,
        reference: propre(ligne.slice(104, 120)) || ecriture,
        empreinte: empreinte('cfonb', ligne.slice(2, 104)),
      });
    } else if (code === '05' && mouvements.length) {
      // Le complément de libellé du mouvement qui précède.
      const complement = propre(ligne.slice(48, 118));
      if (complement) mouvements[mouvements.length - 1].libelle = propre(`${mouvements[mouvements.length - 1].libelle} ${complement}`);
    }
  }
  return { compte, mouvements };
}

// ------------------------------------------------------------------- CAMT.053

const ENTITES = { '&lt;': '<', '&gt;': '>', '&quot;': '"', '&apos;': "'", '&amp;': '&' };
const decode = (s) => String(s || '').replace(/&(lt|gt|quot|apos|amp);|&#(\d+);|&#x([0-9a-f]+);/gi,
  (m, nom, dec, hex) => (nom ? ENTITES[m] : String.fromCodePoint(dec ? Number(dec) : parseInt(hex, 16))));

/** Le contenu de la première balise de ce nom, avec ou sans préfixe d'espace de noms. */
function balise(xml, nom) {
  const m = new RegExp(`<(?:\\w+:)?${nom}\\b[^>]*>([\\s\\S]*?)</(?:\\w+:)?${nom}>`).exec(xml);
  return m ? m[1] : null;
}

function toutes(xml, nom) {
  return [...xml.matchAll(new RegExp(`<(?:\\w+:)?${nom}\\b[^>]*>([\\s\\S]*?)</(?:\\w+:)?${nom}>`, 'g'))].map((m) => m[1]);
}

function lireCamt(contenu) {
  // Une entité externe n'a rien à faire dans un relevé : on refuse le fichier
  // plutôt que de risquer qu'un analyseur, un jour, la suive.
  if (/<!DOCTYPE|<!ENTITY/i.test(contenu)) throw new Error('doctype');
  const iban = propre(decode(balise(contenu, 'IBAN') || ''));
  const mouvements = [];
  for (const entree of toutes(contenu, 'Ntry')) {
    const montantBrut = /<(?:\w+:)?Amt\b[^>]*>([^<]+)</.exec(entree);
    const sens = propre(balise(entree, 'CdtDbtInd'));
    const date = propre(balise(balise(entree, 'BookgDt') || '', 'Dt') || balise(balise(entree, 'BookgDt') || '', 'DtTm') || '').slice(0, 10);
    if (!montantBrut || !/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
    const montant = Number(montantBrut[1].trim()) * (sens === 'DBIT' ? -1 : 1);
    if (!Number.isFinite(montant)) continue;
    const valeur = propre(balise(balise(entree, 'ValDt') || '', 'Dt') || '').slice(0, 10);
    const libelle = propre(decode(toutes(entree, 'Ustrd').join(' ') || balise(entree, 'AddtlNtryInf') || balise(entree, 'Nm') || ''));
    const reference = propre(decode(balise(entree, 'AcctSvcrRef') || balise(entree, 'EndToEndId') || ''));
    mouvements.push({
      date,
      dateValeur: /^\d{4}-\d{2}-\d{2}$/.test(valeur) ? valeur : date,
      libelle: libelle || (montant < 0 ? 'Débit' : 'Crédit'),
      montant,
      reference,
      empreinte: empreinte('camt', reference || '', date, montant.toFixed(2), libelle),
    });
  }
  return { compte: iban || null, mouvements };
}

// ------------------------------------------------------------------------ OFX

function champOfx(bloc, nom) {
  const m = new RegExp(`<${nom}>([^<\\r\\n]*)`, 'i').exec(bloc);
  return m ? decode(m[1].trim()) : '';
}

function lireOfx(contenu) {
  const compte = champOfx(contenu, 'ACCTID') || null;
  const mouvements = [];
  for (const bloc of contenu.split(/<STMTTRN>/i).slice(1)) {
    const fin = bloc.search(/<\/STMTTRN>/i);
    const t = fin >= 0 ? bloc.slice(0, fin) : bloc;
    const brut = champOfx(t, 'DTPOSTED');
    const montant = Number(champOfx(t, 'TRNAMT').replace(',', '.'));
    if (!/^\d{8}/.test(brut) || !Number.isFinite(montant)) continue;
    const date = `${brut.slice(0, 4)}-${brut.slice(4, 6)}-${brut.slice(6, 8)}`;
    const fitid = champOfx(t, 'FITID');
    const libelle = propre([champOfx(t, 'NAME'), champOfx(t, 'MEMO')].filter(Boolean).join(' — '));
    mouvements.push({
      date,
      dateValeur: date,
      libelle: libelle || (montant < 0 ? 'Débit' : 'Crédit'),
      montant,
      reference: fitid,
      empreinte: empreinte('ofx', compte || '', fitid || `${date}|${montant}|${libelle}`),
    });
  }
  return { compte, mouvements };
}

/**
 * Lire un relevé. Rend { ok, format, compte, mouvements } ou { ok: false, reason }.
 * « compte » est ce que le fichier dit du compte (IBAN, numéro), pour vérifier
 * qu'on n'importe pas le relevé d'un compte dans un autre.
 */
function lire(buffer) {
  if (!Buffer.isBuffer(buffer) || buffer.length === 0) return { ok: false, reason: 'empty' };
  const contenu = texte(buffer);
  const format = formatDe(contenu);
  if (!format) return { ok: false, reason: 'format' };
  let lu;
  try {
    lu = format === 'cfonb120' ? lireCfonb(contenu) : format === 'camt053' ? lireCamt(contenu) : lireOfx(contenu);
  } catch {
    return { ok: false, reason: 'format' };
  }
  if (lu.mouvements.length === 0) return { ok: false, reason: 'empty' };
  if (lu.mouvements.length > LIMITE) return { ok: false, reason: 'too-many' };
  for (const m of lu.mouvements) {
    m.montant = Math.round(m.montant * 100) / 100;
    m.libelle = m.libelle.slice(0, 160);
    m.reference = m.reference.slice(0, 60);
  }
  return { ok: true, format, compte: lu.compte, mouvements: lu.mouvements };
}

/** Les quatre derniers chiffres d'un identifiant de compte, pour le comparer à ce qui est enregistré. */
const quatreDerniers = (compte) => String(compte || '').replace(/[^0-9A-Za-z]/g, '').slice(-4).toUpperCase();

module.exports = { lire, formatDe, montantCfonb, quatreDerniers };
