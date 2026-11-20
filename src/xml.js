/**
 * Un lecteur XML strict, et la canonicalisation exclusive (Exclusive XML
 * Canonicalization 1.0, sans commentaires) — ce qu'il faut pour vérifier une
 * signature SAML, et rien de plus.
 *
 * Strict veut dire : pas de DOCTYPE, pas de déclaration d'entité, pas d'entité
 * autre que les cinq prédéfinies et les références numériques, pas
 * d'instruction de traitement hormis la déclaration XML de tête, pas d'attribut
 * en double, pas de préfixe sans déclaration. Un document qui sort de ce cadre
 * est refusé, pas interprété : c'est par l'interprétation des cas limites que
 * passent les attaques sur les signatures XML.
 *
 * La canonicalisation suit la recommandation W3C : seuls les espaces de noms
 * « visiblement utilisés » par un élément (et ceux de la liste inclusive)
 * sont rendus, s'ils ne l'ont pas déjà été par un ancêtre rendu ; les
 * déclarations sont triées par préfixe, les attributs par espace de noms puis
 * nom local ; les éléments vides s'écrivent en ouverture et fermeture ; les
 * caractères sont échappés comme la norme le fixe. Ses sorties sont éprouvées
 * contre celles de libxml, par les jeux de données de l'édition PHP.
 */

const NS_XML = 'http://www.w3.org/XML/1998/namespace';
const NOM = /[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?/y;
const PROFONDEUR = 64;
const TAILLE = 1024 * 1024;

class ErreurXml extends Error {}

const ENTITES = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" };

function decoder(texte) {
  return texte.replace(/&([^;]*);/g, (tout, nom) => {
    if (ENTITES[nom] !== undefined) return ENTITES[nom];
    const m = /^#(x[0-9A-Fa-f]{1,6}|[0-9]{1,7})$/.exec(nom);
    if (!m) throw new ErreurXml(`entité refusée : ${nom}`);
    const code = m[1][0] === 'x' ? parseInt(m[1].slice(1), 16) : parseInt(m[1], 10);
    if (code === 0 || code > 0x10ffff || (code >= 0xd800 && code <= 0xdfff)) throw new ErreurXml('caractère invalide');
    return String.fromCodePoint(code);
  });
}

/** Lire un document : rend son élément racine, espaces de noms résolus. */
function lire(source) {
  const brut = Buffer.isBuffer(source) ? source.toString('utf8') : String(source);
  if (brut.length > TAILLE) throw new ErreurXml('document trop grand');
  // Fins de ligne normalisées, comme le ferait tout lecteur XML.
  const s = brut.replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  let i = 0;

  const ignorerBlancs = () => { while (i < s.length && /\s/.test(s[i])) i += 1; };
  const commence = (motif) => s.startsWith(motif, i);
  const nom = () => {
    NOM.lastIndex = i;
    const m = NOM.exec(s);
    if (!m) throw new ErreurXml(`nom attendu en ${i}`);
    i += m[0].length;
    return m[0];
  };
  const sauterCommentaire = () => {
    const fin = s.indexOf('-->', i + 4);
    if (fin < 0) throw new ErreurXml('commentaire non fermé');
    if (s.slice(i + 4, fin).includes('--')) throw new ErreurXml('commentaire invalide');
    i = fin + 3;
  };

  if (commence('<?xml')) {
    const fin = s.indexOf('?>', i);
    if (fin < 0) throw new ErreurXml('déclaration non fermée');
    i = fin + 2;
  }
  // Avant la racine : des blancs et des commentaires, rien d'autre.
  for (;;) {
    ignorerBlancs();
    if (commence('<!--')) { sauterCommentaire(); continue; }
    break;
  }
  if (commence('<!')) throw new ErreurXml('DOCTYPE refusé');
  if (commence('<?')) throw new ErreurXml('instruction de traitement refusée');

  const resoudre = (prefixe, portee, pourAttribut) => {
    if (prefixe === 'xml') return NS_XML;
    if (prefixe === '') return pourAttribut ? '' : (portee.get('') || '');
    const uri = portee.get(prefixe);
    if (uri === undefined || uri === '') throw new ErreurXml(`préfixe non déclaré : ${prefixe}`);
    return uri;
  };

  const element = (parent, profondeur) => {
    if (profondeur > PROFONDEUR) throw new ErreurXml('trop imbriqué');
    if (s[i] !== '<') throw new ErreurXml(`élément attendu en ${i}`);
    i += 1;
    const qname = nom();
    const brutAttrs = [];
    const vus = new Set();
    for (;;) {
      const avant = i;
      ignorerBlancs();
      if (commence('/>') || s[i] === '>') break;
      if (i === avant) throw new ErreurXml(`blanc attendu en ${i}`);
      const n = nom();
      ignorerBlancs();
      if (s[i] !== '=') throw new ErreurXml('= attendu');
      i += 1;
      ignorerBlancs();
      const guillemet = s[i];
      if (guillemet !== '"' && guillemet !== "'") throw new ErreurXml('valeur attendue');
      const fin = s.indexOf(guillemet, i + 1);
      if (fin < 0) throw new ErreurXml('valeur non fermée');
      const valeurBrute = s.slice(i + 1, fin);
      if (valeurBrute.includes('<')) throw new ErreurXml('< dans un attribut');
      i = fin + 1;
      if (vus.has(n)) throw new ErreurXml(`attribut en double : ${n}`);
      vus.add(n);
      // Normalisation des valeurs d'attribut : blancs littéraux en espaces, puis entités.
      brutAttrs.push({ name: n, value: decoder(valeurBrute.replace(/[\t\n]/g, ' ')) });
    }
    const portee = new Map(parent ? parent.scope : []);
    const declarations = new Map();
    const attrs = [];
    for (const a of brutAttrs) {
      if (a.name === 'xmlns') { portee.set('', a.value); declarations.set('', a.value); continue; }
      if (a.name.startsWith('xmlns:')) {
        const p = a.name.slice(6);
        if (p === 'xml' || p === 'xmlns' || a.value === '') throw new ErreurXml('déclaration invalide');
        portee.set(p, a.value);
        declarations.set(p, a.value);
        continue;
      }
      attrs.push(a);
    }
    const [prefixe, local] = qname.includes(':') ? qname.split(':') : ['', qname];
    const el = {
      type: 'element', name: qname, prefix: prefixe, local, scope: portee, declarations, parent, children: [],
      ns: resoudre(prefixe, portee, false),
    };
    const attrsNs = new Set();
    el.attrs = attrs.map((a) => {
      const [p, l] = a.name.includes(':') ? a.name.split(':') : ['', a.name];
      const ns = resoudre(p, portee, true);
      const cle = `${ns}|${l}`;
      if (attrsNs.has(cle)) throw new ErreurXml('attribut en double');
      attrsNs.add(cle);
      return { name: a.name, prefix: p, local: l, ns, value: a.value };
    });
    if (commence('/>')) { i += 2; return el; }
    i += 1;
    for (;;) {
      if (i >= s.length) throw new ErreurXml(`élément non fermé : ${qname}`);
      if (commence('</')) {
        i += 2;
        const fermeture = nom();
        if (fermeture !== qname) throw new ErreurXml(`fermeture inattendue : ${fermeture}`);
        ignorerBlancs();
        if (s[i] !== '>') throw new ErreurXml('> attendu');
        i += 1;
        return el;
      }
      if (commence('<!--')) { sauterCommentaire(); continue; }
      if (commence('<![CDATA[')) {
        const fin = s.indexOf(']]>', i);
        if (fin < 0) throw new ErreurXml('CDATA non fermé');
        el.children.push({ type: 'text', value: s.slice(i + 9, fin) });
        i = fin + 3;
        continue;
      }
      if (commence('<!') || commence('<?')) throw new ErreurXml('construction refusée');
      if (s[i] === '<') { el.children.push(element(el, profondeur + 1)); continue; }
      const fin = s.indexOf('<', i);
      if (fin < 0) throw new ErreurXml('texte non fermé');
      const texte = s.slice(i, fin);
      if (texte.includes(']]>')) throw new ErreurXml(']]> dans le texte');
      el.children.push({ type: 'text', value: decoder(texte) });
      i = fin;
    }
  };

  const racine = element(null, 0);
  for (;;) {
    ignorerBlancs();
    if (commence('<!--')) { sauterCommentaire(); continue; }
    break;
  }
  if (i !== s.length) throw new ErreurXml('contenu après la racine');
  return racine;
}

// ------------------------------------------------------------- parcours

const enfants = (el, ns, local) => el.children.filter((c) => c.type === 'element' && c.ns === ns && c.local === local);
const enfant = (el, ns, local) => { const t = enfants(el, ns, local); return t.length === 1 ? t[0] : null; };
const texte = (el) => el.children.map((c) => (c.type === 'text' ? c.value : texte(c))).join('');
const attr = (el, nomLocal) => { const a = el.attrs.find((x) => x.ns === '' && x.local === nomLocal); return a ? a.value : ''; };
function* tous(el) {
  yield el;
  for (const c of el.children) if (c.type === 'element') yield* tous(c);
}

// ---------------------------------------------------- canonicalisation

const echapperTexte = (v) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\r/g, '&#xD;');
const echapperAttr = (v) => v.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;')
  .replace(/\t/g, '&#x9;').replace(/\n/g, '&#xA;').replace(/\r/g, '&#xD;');

const comparer = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

/**
 * La forme canonique exclusive d'un élément. « exclure » écarte des nœuds (la
 * signature, pour la transformation enveloppée) ; « inclusifs » est la liste
 * de préfixes à traiter comme en canonicalisation inclusive.
 */
function canoniser(apex, { exclure = [], inclusifs = [] } = {}) {
  const ecartes = new Set(exclure);
  const listeInclusive = new Set(inclusifs.map((p) => (p === '#default' ? '' : p)));
  let sortie = '';

  const rendre = (el, rendus) => {
    const utilises = new Set([el.prefix]);
    for (const a of el.attrs) if (a.prefix && a.prefix !== 'xml') utilises.add(a.prefix);
    for (const p of listeInclusive) if (el.scope.has(p)) utilises.add(p);
    const nouveaux = new Map(rendus);
    const decl = [];
    for (const p of utilises) {
      const uri = p === '' ? (el.scope.get('') || '') : el.scope.get(p);
      if (uri === undefined) continue;
      if (p === '') {
        const avant = rendus.has('') ? rendus.get('') : '';
        if (uri !== avant) { decl.push(['', uri]); nouveaux.set('', uri); }
      } else if (rendus.get(p) !== uri) {
        decl.push([p, uri]);
        nouveaux.set(p, uri);
      }
    }
    decl.sort((a, b) => comparer(a[0], b[0]));
    const attrs = [...el.attrs].sort((a, b) => comparer(a.ns, b.ns) || comparer(a.local, b.local));
    sortie += `<${el.name}`;
    for (const [p, uri] of decl) sortie += p === '' ? ` xmlns="${echapperAttr(uri)}"` : ` xmlns:${p}="${echapperAttr(uri)}"`;
    for (const a of attrs) sortie += ` ${a.name}="${echapperAttr(a.value)}"`;
    sortie += '>';
    for (const c of el.children) {
      if (c.type === 'text') sortie += echapperTexte(c.value);
      else if (!ecartes.has(c)) rendre(c, nouveaux);
    }
    sortie += `</${el.name}>`;
  };

  rendre(apex, new Map());
  return sortie;
}

module.exports = { lire, canoniser, enfants, enfant, texte, attr, tous, ErreurXml, NS_XML };
