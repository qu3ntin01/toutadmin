const zlib = require('zlib');

const font = require('./pdf-font');

/**
 * Écriture d'un PDF/A-3, sans dépendance.
 *
 * Le format PDF est un assemblage d'objets numérotés, suivi d'une table qui dit
 * où chacun commence. Rien là-dedans ne demande de bibliothèque : ce qui est
 * difficile, ce n'est pas d'écrire un PDF, c'est d'en écrire un que les règles
 * d'archivage acceptent. PDF/A-3 en pose quatre qui gouvernent tout ce fichier :
 *
 *   — **toute police est incorporée**, les quatorze polices dites standard
 *     comprises. D'où la police de tracés de `pdf-font.js` ;
 *   — **le document déclare son espace de couleur** par un profil ICC embarqué
 *     (`OutputIntent`). Le profil sRGB minimal est construit ici, octet par
 *     octet, pour la même raison ;
 *   — **le document porte ses métadonnées en XMP**, et y annonce sa version de
 *     PDF/A. Les schémas hors norme — ceux de Factur-X — doivent en plus être
 *     décrits dans le XMP lui-même ;
 *   — **un fichier joint est déclaré deux fois** : dans l'arbre des noms, pour
 *     qu'un lecteur le montre, et dans le tableau `/AF`, pour qu'un traitement
 *     automatique sache qu'il fait partie du document.
 *
 * Le reste est de la mise en page.
 */

const A4 = { width: 595.28, height: 841.89 };

/* ------------------------------------------------------------ profil ICC */

const s15 = (value) => {
  const raw = Math.round(value * 65536);
  const buf = Buffer.alloc(4);
  buf.writeInt32BE(raw);
  return buf;
};

const xyzTag = (x, y, z) => Buffer.concat([Buffer.from('XYZ \0\0\0\0', 'latin1'), s15(x), s15(y), s15(z)]);

const curveTag = (gamma) => {
  const buf = Buffer.alloc(14);
  buf.write('curv', 0, 'latin1');
  buf.writeUInt32BE(1, 8);             // une seule valeur : un gamma
  buf.writeUInt16BE(Math.round(gamma * 256), 12);
  return buf;
};

const textTag = (value) => Buffer.concat([
  Buffer.from('text\0\0\0\0', 'latin1'),
  Buffer.from(`${value}\0`, 'latin1'),
]);

const descTag = (value) => {
  const ascii = Buffer.from(`${value}\0`, 'latin1');
  const head = Buffer.alloc(12);
  head.write('desc', 0, 'latin1');
  head.writeUInt32BE(ascii.length, 8);
  // Après la chaîne ASCII, le type v2 veut encore les variantes Unicode et
  // Macintosh. Vides, mais présentes : un profil tronqué n'est pas un profil.
  const tail = Buffer.alloc(4 + 4 + 2 + 1 + 67);
  return Buffer.concat([head, ascii, tail]);
};

/**
 * Un profil sRGB minimal mais complet : en-tête, table des étiquettes, et les
 * neuf étiquettes qu'un profil d'affichage RVB doit porter. Les primaires sont
 * celles de sRGB adaptées au blanc D50, comme le veut l'espace de connexion.
 */
function srgbProfile() {
  const tags = [
    ['desc', descTag('sRGB')],
    ['wtpt', xyzTag(0.9642, 1.0, 0.8249)],
    ['rXYZ', xyzTag(0.4360, 0.2225, 0.0139)],
    ['gXYZ', xyzTag(0.3851, 0.7169, 0.0971)],
    ['bXYZ', xyzTag(0.1431, 0.0606, 0.7141)],
    ['rTRC', curveTag(2.2)],
    ['gTRC', curveTag(2.2)],
    ['bTRC', curveTag(2.2)],
    ['cprt', textTag('Domaine public')],
  ];

  const tableSize = 4 + tags.length * 12;
  let offset = 128 + tableSize;
  const table = Buffer.alloc(tableSize);
  table.writeUInt32BE(tags.length, 0);

  const bodies = [];
  tags.forEach(([sig, data], i) => {
    const padded = data.length % 4 ? Buffer.concat([data, Buffer.alloc(4 - (data.length % 4))]) : data;
    table.write(sig, 4 + i * 12, 'latin1');
    table.writeUInt32BE(offset, 4 + i * 12 + 4);
    table.writeUInt32BE(data.length, 4 + i * 12 + 8);
    bodies.push(padded);
    offset += padded.length;
  });

  const header = Buffer.alloc(128);
  header.writeUInt32BE(offset, 0);            // taille totale
  header.write('mntr', 12, 'latin1');         // classe : périphérique d'affichage
  header.write('RGB ', 16, 'latin1');
  header.write('XYZ ', 20, 'latin1');
  header.writeUInt16BE(2026, 24);             // date de création
  header.writeUInt16BE(1, 26);
  header.writeUInt16BE(1, 28);
  header.write('acsp', 36, 'latin1');
  header.writeUInt32BE(0, 64);                // rendu perceptuel
  s15(0.9642).copy(header, 68);               // illuminant de l'espace de connexion
  s15(1.0).copy(header, 72);
  s15(0.8249).copy(header, 76);
  const version = Buffer.alloc(4);
  version.writeUInt32BE(0x02100000);
  version.copy(header, 8);

  return Buffer.concat([header, table, ...bodies]);
}

/* ---------------------------------------------------------------- objets */

const escapeString = (value) => String(value).replace(/([\\()])/g, '\\$1');

/** Une date au format PDF : D:AAAAMMJJhhmmss+hh'mm'. */
function pdfDate(date = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `D:${date.getUTCFullYear()}${p(date.getUTCMonth() + 1)}${p(date.getUTCDate())}`
    + `${p(date.getUTCHours())}${p(date.getUTCMinutes())}${p(date.getUTCSeconds())}Z`;
}

class Document {
  constructor() {
    this.objects = [null]; // l'objet 0 n'existe pas : la table le réserve
  }

  /** Réserve un numéro, pour les renvois croisés qui doivent exister d'avance. */
  reserve() {
    this.objects.push(undefined);
    return this.objects.length - 1;
  }

  put(body, id = null) {
    if (id === null) {
      this.objects.push(body);
      return this.objects.length - 1;
    }
    this.objects[id] = body;
    return id;
  }

  /**
   * Un flux. Compressé sauf demande contraire : le XML joint reste lisible tel
   * quel dans le fichier, ce qui aide quand on l'examine à la main.
   */
  stream(data, dict = {}, { compress = true } = {}) {
    let payload = Buffer.isBuffer(data) ? data : Buffer.from(String(data), 'latin1');
    const entries = { ...dict };

    if (compress) {
      payload = zlib.deflateSync(payload);
      entries.Filter = '/FlateDecode';
    }
    entries.Length = payload.length;

    const head = `<< ${Object.entries(entries).map(([k, v]) => `/${k} ${v}`).join(' ')} >>\nstream\n`;
    return this.put(Buffer.concat([Buffer.from(head, 'latin1'), payload, Buffer.from('\nendstream', 'latin1')]));
  }

  build(rootId, infoId, fileId) {
    const chunks = [];
    let position = 0;
    const push = (buf) => { chunks.push(buf); position += buf.length; };

    // Le commentaire binaire dit aux outils de transfert que le fichier n'est
    // pas du texte : sans lui, un transfert FTP en mode ASCII le mutilerait.
    push(Buffer.from('%PDF-1.7\n%\xe2\xe3\xcf\xd3\n', 'latin1'));

    const offsets = [0];
    for (let i = 1; i < this.objects.length; i += 1) {
      offsets[i] = position;
      const body = this.objects[i];
      push(Buffer.from(`${i} 0 obj\n`, 'latin1'));
      push(Buffer.isBuffer(body) ? body : Buffer.from(String(body), 'latin1'));
      push(Buffer.from('\nendobj\n', 'latin1'));
    }

    const xrefAt = position;
    const lines = [`xref\n0 ${this.objects.length}\n`, '0000000000 65535 f \n'];
    for (let i = 1; i < this.objects.length; i += 1) {
      lines.push(`${String(offsets[i]).padStart(10, '0')} 00000 n \n`);
    }
    push(Buffer.from(lines.join(''), 'latin1'));
    push(Buffer.from(
      `trailer\n<< /Size ${this.objects.length} /Root ${rootId} 0 R /Info ${infoId} 0 R`
      + ` /ID [<${fileId}> <${fileId}>] >>\nstartxref\n${xrefAt}\n%%EOF\n`,
      'latin1',
    ));

    return Buffer.concat(chunks);
  }
}

/* ------------------------------------------------------------- la police */

/**
 * Construit la police Type 3 pour les seuls caractères employés. Un document
 * qui ne contient pas de « ø » n'a pas à en porter le tracé.
 */
function buildFont(doc, text) {
  const used = Array.from(new Set(Array.from(text))).filter((c) => c !== '\n').sort();
  const codes = new Map();
  let next = 33;
  for (const char of used) {
    if (next > 255) break; // au-delà, le caractère sortira en « ? »
    codes.set(char, next);
    next += 1;
  }

  const procs = [];
  const names = [];
  for (const [char, code] of codes) {
    const id = doc.stream(font.charProc(char), {}, { compress: false });
    procs.push(`/g${code} ${id} 0 R`);
    names.push([code, char]);
  }

  const first = 33;
  const last = Math.max(first, next - 1);
  const widths = [];
  for (let code = first; code <= last; code += 1) {
    const entry = names.find(([c]) => c === code);
    widths.push(entry ? font.advanceOf(entry[1]) : 0);
  }

  const differences = names.map(([code], i, arr) => {
    const previous = i === 0 ? null : arr[i - 1][0];
    return previous !== null && code === previous + 1 ? `/g${code}` : `${code} /g${code}`;
  }).join(' ');

  const charProcs = doc.put(`<< ${procs.join(' ')} >>`);
  const encoding = doc.put(`<< /Type /Encoding /Differences [${differences}] >>`);

  // La table ToUnicode : sans elle, le texte s'affiche mais ne se copie pas —
  // et une facture qu'on ne peut pas relire mécaniquement n'a qu'une moitié
  // de valeur.
  const hex = (s) => Array.from(s).map((c) => c.codePointAt(0).toString(16).padStart(4, '0')).join('');
  const bf = names.map(([code, char]) => `<${code.toString(16).padStart(2, '0')}> <${hex(char)}>`);
  const groups = [];
  for (let i = 0; i < bf.length; i += 100) groups.push(bf.slice(i, i + 100));
  const toUnicode = doc.stream(
    '/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n'
    + '/CIDSystemInfo << /Registry (Toutadmin) /Ordering (Trace) /Supplement 0 >> def\n'
    + '/CMapName /Toutadmin-Trace def\n/CMapType 2 def\n1 begincodespacerange\n<21> <ff>\nendcodespacerange\n'
    + groups.map((g) => `${g.length} beginbfchar\n${g.join('\n')}\nendbfchar\n`).join('')
    + 'endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n',
  );

  const id = doc.put(
    `<< /Type /Font /Subtype /Type3 /FontBBox [-60 -200 660 880]`
    + ` /FontMatrix [0.001 0 0 0.001 0 0] /CharProcs ${charProcs} 0 R /Encoding ${encoding} 0 R`
    + ` /FirstChar ${first} /LastChar ${last} /Widths [${widths.join(' ')}]`
    + ` /Resources << /ProcSet [/PDF] >> /ToUnicode ${toUnicode} 0 R >>`,
  );

  const encode = (value) => Array.from(String(value))
    .map((c) => (codes.has(c) ? String.fromCharCode(codes.get(c)) : (codes.has('?') ? String.fromCharCode(codes.get('?')) : ' ')))
    .join('');

  return { id, encode, widthOf: font.widthOf };
}

/* ------------------------------------------------------------------- XMP */

/**
 * Les métadonnées. Deux choses s'y jouent : l'annonce de PDF/A-3 B, et la
 * déclaration du schéma Factur-X — un schéma hors norme doit être décrit dans
 * le document qui l'emploie, faute de quoi le fichier n'est plus conforme.
 */
function xmp({ title, author, profile, attachment }) {
  const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  const now = new Date().toISOString().replace(/\.\d{3}Z$/, 'Z');

  return `<?xpacket begin="﻿" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">
      <dc:title><rdf:Alt><rdf:li xml:lang="x-default">${esc(title)}</rdf:li></rdf:Alt></dc:title>
      <dc:creator><rdf:Seq><rdf:li>${esc(author)}</rdf:li></rdf:Seq></dc:creator>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">
      <xmp:CreatorTool>Toutadmin</xmp:CreatorTool>
      <xmp:CreateDate>${now}</xmp:CreateDate>
      <xmp:ModifyDate>${now}</xmp:ModifyDate>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">
      <pdfaid:part>3</pdfaid:part>
      <pdfaid:conformance>B</pdfaid:conformance>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">
      <fx:DocumentType>INVOICE</fx:DocumentType>
      <fx:DocumentFileName>${esc(attachment)}</fx:DocumentFileName>
      <fx:Version>1.0</fx:Version>
      <fx:ConformanceLevel>${esc(profile)}</fx:ConformanceLevel>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:pdfaExtension="http://www.aiim.org/pdfa/ns/extension/"
        xmlns:pdfaSchema="http://www.aiim.org/pdfa/ns/schema#" xmlns:pdfaProperty="http://www.aiim.org/pdfa/ns/property#">
      <pdfaExtension:schemas>
        <rdf:Bag>
          <rdf:li rdf:parseType="Resource">
            <pdfaSchema:schema>Factur-X PDFA Extension Schema</pdfaSchema:schema>
            <pdfaSchema:namespaceURI>urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#</pdfaSchema:namespaceURI>
            <pdfaSchema:prefix>fx</pdfaSchema:prefix>
            <pdfaSchema:property>
              <rdf:Seq>
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>DocumentFileName</pdfaProperty:name>
                  <pdfaProperty:valueType>Text</pdfaProperty:valueType>
                  <pdfaProperty:category>external</pdfaProperty:category>
                  <pdfaProperty:description>Nom du fichier XML joint</pdfaProperty:description>
                </rdf:li>
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>DocumentType</pdfaProperty:name>
                  <pdfaProperty:valueType>Text</pdfaProperty:valueType>
                  <pdfaProperty:category>external</pdfaProperty:category>
                  <pdfaProperty:description>INVOICE</pdfaProperty:description>
                </rdf:li>
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>Version</pdfaProperty:name>
                  <pdfaProperty:valueType>Text</pdfaProperty:valueType>
                  <pdfaProperty:category>external</pdfaProperty:category>
                  <pdfaProperty:description>Version du format Factur-X</pdfaProperty:description>
                </rdf:li>
                <rdf:li rdf:parseType="Resource">
                  <pdfaProperty:name>ConformanceLevel</pdfaProperty:name>
                  <pdfaProperty:valueType>Text</pdfaProperty:valueType>
                  <pdfaProperty:category>external</pdfaProperty:category>
                  <pdfaProperty:description>Profil Factur-X employé</pdfaProperty:description>
                </rdf:li>
              </rdf:Seq>
            </pdfaSchema:property>
          </rdf:li>
        </rdf:Bag>
      </pdfaExtension:schemas>
    </rdf:Description>
  </rdf:RDF>
</x:xmpmeta>
<?xpacket end="w"?>`;
}

/* ------------------------------------------------------------ mise en page */

/**
 * Une page composée de lignes. Chaque ligne dit sa taille, sa position et son
 * alignement ; le reste est arithmétique.
 */
function contentStream(lines, encode) {
  const out = ['0 0 0 RG', '0 0 0 rg'];

  for (const line of lines) {
    if (line.rule) {
      out.push(`${line.width || 0.6} w ${line.x} ${line.y} m ${line.x2} ${line.y} l S`);
      continue;
    }
    const size = line.size || 9;
    const text = String(line.text == null ? '' : line.text);
    if (!text) continue;

    let x = line.x;
    if (line.align === 'right') x = line.x - (font.widthOf(text) * size) / 1000;
    else if (line.align === 'center') x = line.x - (font.widthOf(text) * size) / 2000;

    out.push(`BT /F1 ${size} Tf ${x.toFixed(2)} ${line.y.toFixed(2)} Td (${escapeString(encode(text))}) Tj ET`);
  }

  return out.join('\n');
}

/**
 * Assemble le document.
 *
 * `attachment` est le fichier joint qui fait de ce PDF une facture lisible par
 * une machine autant que par une personne.
 */
function render({ title, author = 'Toutadmin', lines, attachment, profile = 'EN 16931' }) {
  const doc = new Document();
  const text = lines.filter((l) => !l.rule).map((l) => String(l.text || '')).join('');
  const typeface = buildFont(doc, text + '?');

  const content = doc.stream(contentStream(lines, typeface.encode));

  // Le fichier joint. Sa relation au document — `/Data` — est ce qui distingue
  // une pièce jointe décorative d'une charge utile à traiter.
  const payload = Buffer.isBuffer(attachment.content) ? attachment.content : Buffer.from(attachment.content, 'utf8');
  const embedded = doc.stream(payload, {
    Type: '/EmbeddedFile',
    Subtype: '/text#2Fxml',
    Params: `<< /Size ${payload.length} /ModDate (${pdfDate()}) >>`,
  }, { compress: false });

  const fileSpec = doc.put(
    `<< /Type /Filespec /F (${escapeString(attachment.name)}) /UF (${escapeString(attachment.name)})`
    + ` /Desc (${escapeString(attachment.description || '')}) /AFRelationship /Data`
    + ` /EF << /F ${embedded} 0 R /UF ${embedded} 0 R >> >>`,
  );

  const icc = doc.stream(srgbProfile(), { N: 3 });
  const outputIntent = doc.put(
    `<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB)`
    + ` /Info (sRGB) /DestOutputProfile ${icc} 0 R >>`,
  );

  const metadata = doc.stream(
    Buffer.from(xmp({ title, author, profile, attachment: attachment.name }), 'utf8'),
    { Type: '/Metadata', Subtype: '/XML' },
    { compress: false },
  );

  const pagesId = doc.reserve();
  const page = doc.put(
    `<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 ${A4.width} ${A4.height}]`
    + ` /Resources << /Font << /F1 ${typeface.id} 0 R >> /ProcSet [/PDF /Text] >>`
    + ` /Contents ${content} 0 R >>`,
  );
  doc.put(`<< /Type /Pages /Kids [${page} 0 R] /Count 1 >>`, pagesId);

  const names = doc.put(
    `<< /EmbeddedFiles << /Names [(${escapeString(attachment.name)}) ${fileSpec} 0 R] >> >>`,
  );

  const root = doc.put(
    `<< /Type /Catalog /Pages ${pagesId} 0 R /Names ${names} 0 R /AF [${fileSpec} 0 R]`
    + ` /OutputIntents [${outputIntent} 0 R] /Metadata ${metadata} 0 R`
    + ` /MarkInfo << /Marked true >> /Lang (fr-FR) >>`,
  );

  const info = doc.put(
    `<< /Title (${escapeString(title)}) /Author (${escapeString(author)}) /Producer (Toutadmin)`
    + ` /Creator (Toutadmin) /CreationDate (${pdfDate()}) /ModDate (${pdfDate()}) >>`,
  );

  const fileId = require('crypto').createHash('md5').update(title + payload).digest('hex').toUpperCase();
  return doc.build(root, info, fileId);
}

module.exports = { A4, Document, render, srgbProfile, xmp, contentStream, buildFont, pdfDate };
