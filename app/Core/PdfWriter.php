<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Écriture d'un PDF/A-3, sans dépendance.
 *
 * Le format PDF est un assemblage d'objets numérotés, suivi d'une table qui dit
 * où chacun commence. Rien là-dedans ne demande de bibliothèque : ce qui est
 * difficile, ce n'est pas d'écrire un PDF, c'est d'en écrire un que les règles
 * d'archivage acceptent. PDF/A-3 en pose quatre qui gouvernent tout ce fichier :
 *
 *   — **toute police est incorporée**, les quatorze polices dites standard
 *     comprises. D'où la police de tracés de `PdfFont` ;
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
final class PdfWriter
{
    public const A4 = ['width' => 595.28, 'height' => 841.89];

    /** @var array<int, ?string> L'objet 0 n'existe pas : la table le réserve. */
    private array $objects = [null];

    /* ------------------------------------------------------------ profil ICC */

    /** Un nombre à virgule fixe s15.16, comme les profils ICC les écrivent. */
    private static function s15(float $value): string
    {
        return pack('N', (int) round($value * 65536) & 0xFFFFFFFF);
    }

    private static function xyzTag(float $x, float $y, float $z): string
    {
        return "XYZ \0\0\0\0" . self::s15($x) . self::s15($y) . self::s15($z);
    }

    private static function curveTag(float $gamma): string
    {
        // Une seule valeur : un gamma.
        return 'curv' . "\0\0\0\0" . pack('N', 1) . pack('n', (int) round($gamma * 256));
    }

    private static function textTag(string $value): string
    {
        return "text\0\0\0\0" . $value . "\0";
    }

    private static function descTag(string $value): string
    {
        $ascii = $value . "\0";
        // Après la chaîne ASCII, le type v2 veut encore les variantes Unicode et
        // Macintosh. Vides, mais présentes : un profil tronqué n'est pas un profil.
        return 'desc' . "\0\0\0\0" . pack('N', strlen($ascii)) . $ascii . str_repeat("\0", 4 + 4 + 2 + 1 + 67);
    }

    /**
     * Un profil sRGB minimal mais complet : en-tête, table des étiquettes, et les
     * neuf étiquettes qu'un profil d'affichage RVB doit porter. Les primaires sont
     * celles de sRGB adaptées au blanc D50, comme le veut l'espace de connexion.
     */
    public static function srgbProfile(): string
    {
        $tags = [
            ['desc', self::descTag('sRGB')],
            ['wtpt', self::xyzTag(0.9642, 1.0, 0.8249)],
            ['rXYZ', self::xyzTag(0.4360, 0.2225, 0.0139)],
            ['gXYZ', self::xyzTag(0.3851, 0.7169, 0.0971)],
            ['bXYZ', self::xyzTag(0.1431, 0.0606, 0.7141)],
            ['rTRC', self::curveTag(2.2)],
            ['gTRC', self::curveTag(2.2)],
            ['bTRC', self::curveTag(2.2)],
            ['cprt', self::textTag('Domaine public')],
        ];

        $tableSize = 4 + count($tags) * 12;
        $offset = 128 + $tableSize;
        $table = pack('N', count($tags));
        $bodies = '';

        foreach ($tags as [$signature, $data]) {
            $padded = strlen($data) % 4 === 0 ? $data : $data . str_repeat("\0", 4 - (strlen($data) % 4));
            $table .= $signature . pack('N', $offset) . pack('N', strlen($data));
            $bodies .= $padded;
            $offset += strlen($padded);
        }

        $header = str_repeat("\0", 128);
        $put = static function (string $piece, int $at) use (&$header): void {
            $header = substr($header, 0, $at) . $piece . substr($header, $at + strlen($piece));
        };
        $put(pack('N', $offset), 0);            // taille totale
        $put(pack('N', 0x02100000), 8);         // version du format
        $put('mntr', 12);                       // classe : périphérique d'affichage
        $put('RGB ', 16);
        $put('XYZ ', 20);
        $put(pack('n', 2026), 24);              // date de création
        $put(pack('n', 1), 26);
        $put(pack('n', 1), 28);
        $put('acsp', 36);
        $put(pack('N', 0), 64);                 // rendu perceptuel
        $put(self::s15(0.9642), 68);            // illuminant de l'espace de connexion
        $put(self::s15(1.0), 72);
        $put(self::s15(0.8249), 76);

        return $header . $table . $bodies;
    }

    /* ---------------------------------------------------------------- objets */

    public static function escapeString(string $value): string
    {
        return (string) preg_replace('/([\\\\()])/', '\\\\$1', $value);
    }

    /** Une date au format PDF : D:AAAAMMJJhhmmssZ. */
    public static function pdfDate(?int $stamp = null): string
    {
        return 'D:' . gmdate('YmdHis', $stamp ?? time()) . 'Z';
    }

    /** Réserve un numéro, pour les renvois croisés qui doivent exister d'avance. */
    public function reserve(): int
    {
        $this->objects[] = null;
        return count($this->objects) - 1;
    }

    public function put(string $body, ?int $id = null): int
    {
        if ($id === null) {
            $this->objects[] = $body;
            return count($this->objects) - 1;
        }
        $this->objects[$id] = $body;
        return $id;
    }

    /**
     * Un flux. Compressé sauf demande contraire : le XML joint reste lisible tel
     * quel dans le fichier, ce qui aide quand on l'examine à la main.
     */
    public function stream(string $data, array $dict = [], bool $compress = true): int
    {
        $payload = $data;
        $entries = $dict;

        if ($compress) {
            $payload = (string) gzcompress($payload);
            $entries['Filter'] = '/FlateDecode';
        }
        $entries['Length'] = strlen($payload);

        $head = '<< ';
        foreach ($entries as $key => $value) {
            $head .= "/$key $value ";
        }
        $head .= ">>\nstream\n";

        return $this->put($head . $payload . "\nendstream");
    }

    public function build(int $rootId, int $infoId, string $fileId): string
    {
        // Le commentaire binaire dit aux outils de transfert que le fichier n'est
        // pas du texte : sans lui, un transfert FTP en mode ASCII le mutilerait.
        $out = "%PDF-1.7\n%\xe2\xe3\xcf\xd3\n";
        $count = count($this->objects);
        $offsets = [0];

        for ($i = 1; $i < $count; $i++) {
            $offsets[$i] = strlen($out);
            $out .= "$i 0 obj\n" . (string) $this->objects[$i] . "\nendobj\n";
        }

        $xrefAt = strlen($out);
        $out .= "xref\n0 $count\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $out .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT) . " 00000 n \n";
        }
        $out .= "trailer\n<< /Size $count /Root $rootId 0 R /Info $infoId 0 R"
            . " /ID [<$fileId> <$fileId>] >>\nstartxref\n$xrefAt\n%%EOF\n";

        return $out;
    }

    /* ------------------------------------------------------------- la police */

    /**
     * Construit la police Type 3 pour les seuls caractères employés. Un document
     * qui ne contient pas de « ø » n'a pas à en porter le tracé.
     *
     * Rend le numéro de l'objet police et la table des codes, qui sert ensuite à
     * encoder chaque ligne de texte.
     */
    public function buildFont(string $text): array
    {
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $used = array_values(array_unique(array_filter($chars, static fn (string $c): bool => $c !== "\n")));
        sort($used, SORT_STRING);

        $codes = [];
        $next = 33;
        foreach ($used as $char) {
            if ($next > 255) {
                break; // au-delà, le caractère sortira en « ? »
            }
            $codes[$char] = $next;
            $next++;
        }

        $procs = [];
        foreach ($codes as $char => $code) {
            $id = $this->stream(PdfFont::charProc((string) $char), [], false);
            $procs[] = "/g$code $id 0 R";
        }

        $first = 33;
        $last = max($first, $next - 1);
        $byCode = array_flip($codes);
        $widths = [];
        for ($code = $first; $code <= $last; $code++) {
            $widths[] = isset($byCode[$code]) ? PdfFont::advanceOf((string) $byCode[$code]) : 0;
        }

        $differences = [];
        $previous = null;
        foreach ($codes as $code) {
            $differences[] = $previous !== null && $code === $previous + 1 ? "/g$code" : "$code /g$code";
            $previous = $code;
        }

        $charProcs = $this->put('<< ' . implode(' ', $procs) . ' >>');
        $encoding = $this->put('<< /Type /Encoding /Differences [' . implode(' ', $differences) . '] >>');

        // La table ToUnicode : sans elle, le texte s'affiche mais ne se copie pas —
        // et une facture qu'on ne peut pas relire mécaniquement n'a qu'une moitié
        // de valeur.
        $bf = [];
        foreach ($codes as $char => $code) {
            $hex = '';
            foreach (preg_split('//u', (string) $char, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
                $hex .= str_pad(dechex(self::codePoint($piece)), 4, '0', STR_PAD_LEFT);
            }
            $bf[] = '<' . str_pad(dechex($code), 2, '0', STR_PAD_LEFT) . "> <$hex>";
        }

        $groups = '';
        foreach (array_chunk($bf, 100) as $chunk) {
            $groups .= count($chunk) . " beginbfchar\n" . implode("\n", $chunk) . "\nendbfchar\n";
        }

        $toUnicode = $this->stream(
            "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
            . "/CIDSystemInfo << /Registry (Toutadmin) /Ordering (Trace) /Supplement 0 >> def\n"
            . "/CMapName /Toutadmin-Trace def\n/CMapType 2 def\n1 begincodespacerange\n<21> <ff>\nendcodespacerange\n"
            . $groups
            . "endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend\n"
        );

        $id = $this->put(
            '<< /Type /Font /Subtype /Type3 /FontBBox [-60 -200 660 880]'
            . " /FontMatrix [0.001 0 0 0.001 0 0] /CharProcs $charProcs 0 R /Encoding $encoding 0 R"
            . " /FirstChar $first /LastChar $last /Widths [" . implode(' ', $widths) . ']'
            . " /Resources << /ProcSet [/PDF] >> /ToUnicode $toUnicode 0 R >>"
        );

        return ['id' => $id, 'codes' => $codes];
    }

    /** Le point de code d'un caractère UTF-8, sans dépendre d'une extension. */
    private static function codePoint(string $char): int
    {
        $bytes = unpack('C*', $char) ?: [];
        $first = $bytes[1] ?? 0;
        if ($first < 0x80) {
            return $first;
        }
        if ($first < 0xE0) {
            return (($first & 0x1F) << 6) | (($bytes[2] ?? 0) & 0x3F);
        }
        if ($first < 0xF0) {
            return (($first & 0x0F) << 12) | ((($bytes[2] ?? 0) & 0x3F) << 6) | (($bytes[3] ?? 0) & 0x3F);
        }
        return (($first & 0x07) << 18) | ((($bytes[2] ?? 0) & 0x3F) << 12)
            | ((($bytes[3] ?? 0) & 0x3F) << 6) | (($bytes[4] ?? 0) & 0x3F);
    }

    /** Encode un texte dans les codes de la police construite. */
    public static function encodeWith(array $codes, string $text): string
    {
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (isset($codes[$char])) {
                $out .= chr($codes[$char]);
            } elseif (isset($codes['?'])) {
                $out .= chr($codes['?']);
            } else {
                $out .= ' ';
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------- XMP */

    /**
     * Les métadonnées. Deux choses s'y jouent : l'annonce de PDF/A-3 B, et la
     * déclaration du schéma Factur-X — un schéma hors norme doit être décrit dans
     * le document qui l'emploie, faute de quoi le fichier n'est plus conforme.
     */
    public static function xmp(string $title, string $author, string $profile, string $attachment): string
    {
        $esc = static fn (string $v): string => str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $v);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        return '<?xpacket begin="' . "\u{FEFF}" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>
<x:xmpmeta xmlns:x="adobe:ns:meta/">
  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
    <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/">
      <dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $esc($title) . '</rdf:li></rdf:Alt></dc:title>
      <dc:creator><rdf:Seq><rdf:li>' . $esc($author) . '</rdf:li></rdf:Seq></dc:creator>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:xmp="http://ns.adobe.com/xap/1.0/">
      <xmp:CreatorTool>Toutadmin</xmp:CreatorTool>
      <xmp:CreateDate>' . $now . '</xmp:CreateDate>
      <xmp:ModifyDate>' . $now . '</xmp:ModifyDate>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:pdfaid="http://www.aiim.org/pdfa/ns/id/">
      <pdfaid:part>3</pdfaid:part>
      <pdfaid:conformance>B</pdfaid:conformance>
    </rdf:Description>
    <rdf:Description rdf:about="" xmlns:fx="urn:factur-x:pdfa:CrossIndustryDocument:invoice:1p0#">
      <fx:DocumentType>INVOICE</fx:DocumentType>
      <fx:DocumentFileName>' . $esc($attachment) . '</fx:DocumentFileName>
      <fx:Version>1.0</fx:Version>
      <fx:ConformanceLevel>' . $esc($profile) . '</fx:ConformanceLevel>
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
<?xpacket end="w"?>';
    }

    /* ------------------------------------------------------------ mise en page */

    /**
     * Une page composée de lignes. Chaque ligne dit sa taille, sa position et son
     * alignement ; le reste est arithmétique.
     */
    public static function contentStream(array $lines, array $codes): string
    {
        $out = ['0 0 0 RG', '0 0 0 rg'];

        foreach ($lines as $line) {
            if (!empty($line['rule'])) {
                $out[] = ($line['width'] ?? 0.6) . ' w ' . $line['x'] . ' ' . $line['y']
                    . ' m ' . $line['x2'] . ' ' . $line['y'] . ' l S';
                continue;
            }
            $size = $line['size'] ?? 9;
            $text = (string) ($line['text'] ?? '');
            if ($text === '') {
                continue;
            }

            $x = (float) $line['x'];
            $align = $line['align'] ?? '';
            if ($align === 'right') {
                $x -= (PdfFont::widthOf($text) * $size) / 1000;
            } elseif ($align === 'center') {
                $x -= (PdfFont::widthOf($text) * $size) / 2000;
            }

            $out[] = "BT /F1 $size Tf " . number_format($x, 2, '.', '') . ' '
                . number_format((float) $line['y'], 2, '.', '') . ' Td ('
                . self::escapeString(self::encodeWith($codes, $text)) . ') Tj ET';
        }

        return implode("\n", $out);
    }

    /**
     * Assemble le document.
     *
     * `attachment` est le fichier joint qui fait de ce PDF une facture lisible par
     * une machine autant que par une personne.
     */
    public static function render(array $options): string
    {
        $title = (string) $options['title'];
        $author = (string) ($options['author'] ?? 'Toutadmin');
        $profile = (string) ($options['profile'] ?? 'EN 16931');
        $lines = $options['lines'];
        $attachment = $options['attachment'];

        $doc = new self();
        $text = '';
        foreach ($lines as $line) {
            if (empty($line['rule'])) {
                $text .= (string) ($line['text'] ?? '');
            }
        }
        $typeface = $doc->buildFont($text . '?');

        $content = $doc->stream(self::contentStream($lines, $typeface['codes']));

        // Le fichier joint. Sa relation au document — `/Data` — est ce qui distingue
        // une pièce jointe décorative d'une charge utile à traiter.
        $payload = (string) $attachment['content'];
        $embedded = $doc->stream($payload, [
            'Type' => '/EmbeddedFile',
            'Subtype' => '/text#2Fxml',
            'Params' => '<< /Size ' . strlen($payload) . ' /ModDate (' . self::pdfDate() . ') >>',
        ], false);

        $name = self::escapeString((string) $attachment['name']);
        $fileSpec = $doc->put(
            "<< /Type /Filespec /F ($name) /UF ($name)"
            . ' /Desc (' . self::escapeString((string) ($attachment['description'] ?? '')) . ') /AFRelationship /Data'
            . " /EF << /F $embedded 0 R /UF $embedded 0 R >> >>"
        );

        $icc = $doc->stream(self::srgbProfile(), ['N' => 3]);
        $outputIntent = $doc->put(
            '<< /Type /OutputIntent /S /GTS_PDFA1 /OutputConditionIdentifier (sRGB)'
            . " /Info (sRGB) /DestOutputProfile $icc 0 R >>"
        );

        $metadata = $doc->stream(
            self::xmp($title, $author, $profile, (string) $attachment['name']),
            ['Type' => '/Metadata', 'Subtype' => '/XML'],
            false
        );

        $pagesId = $doc->reserve();
        $page = $doc->put(
            "<< /Type /Page /Parent $pagesId 0 R /MediaBox [0 0 " . self::A4['width'] . ' ' . self::A4['height'] . ']'
            . ' /Resources << /Font << /F1 ' . $typeface['id'] . ' 0 R >> /ProcSet [/PDF /Text] >>'
            . " /Contents $content 0 R >>"
        );
        $doc->put("<< /Type /Pages /Kids [$page 0 R] /Count 1 >>", $pagesId);

        $names = $doc->put("<< /EmbeddedFiles << /Names [($name) $fileSpec 0 R] >> >>");

        $root = $doc->put(
            "<< /Type /Catalog /Pages $pagesId 0 R /Names $names 0 R /AF [$fileSpec 0 R]"
            . " /OutputIntents [$outputIntent 0 R] /Metadata $metadata 0 R"
            . ' /MarkInfo << /Marked true >> /Lang (fr-FR) >>'
        );

        $info = $doc->put(
            '<< /Title (' . self::escapeString($title) . ') /Author (' . self::escapeString($author) . ')'
            . ' /Producer (Toutadmin) /Creator (Toutadmin) /CreationDate (' . self::pdfDate() . ')'
            . ' /ModDate (' . self::pdfDate() . ') >>'
        );

        return $doc->build($root, $info, strtoupper(md5($title . $payload)));
    }
}
