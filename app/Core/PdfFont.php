<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Une police vectorielle écrite à la main.
 *
 * PDF/A interdit toute police non incorporée — y compris les quatorze polices
 * dites standard. Un PDF/A-3 qui affiche du texte doit donc porter sa police
 * avec lui. Plutôt que d'embarquer un fichier de police de sept cents kilos
 * dans le dépôt, chaque glyphe est décrit ici comme un tracé : la police *est*
 * son incorporation, et il n'y a rien à installer.
 *
 * C'est une Type 3, celle que PDF prévoit précisément pour ça : chaque glyphe
 * est un petit programme de dessin. Le texte reste sélectionnable et
 * copiable — une table ToUnicode l'accompagne —, ce qui compte pour une
 * facture qu'un logiciel comptable relira.
 *
 * Repères, sur une chasse de 1000 :
 *
 *     700  hauteur des capitales
 *     500  hauteur d'x
 *       0  ligne de base
 *    −180  jambage descendant
 *
 * Un tracé est une suite de points « x,y » ; les tracés d'un même glyphe sont
 * séparés par « | ». Les tables sont celles de l'édition Node, au caractère près.
 */
final class PdfFont
{
    // Chasse par défaut, puis les exceptions : un « i » n'occupe pas la place
    // d'un « m », et un texte où tout est à la même distance se lit comme un
    // tableau.
    public const DEFAULT_ADVANCE = 520;

    /** Les lettres capitales. */
    private const UPPER = [
        'A' => '50,0 250,700 450,0|140,250 360,250',
        'B' => '50,0 50,700 300,700 380,610 380,480 300,390 50,390|300,390 400,290 400,90 300,0 50,0',
        'C' => '430,560 340,680 180,700 70,560 50,350 70,140 180,20 340,40 430,160',
        'D' => '50,0 50,700 270,700 400,560 420,350 400,140 270,0 50,0',
        'E' => '430,700 50,700 50,0 430,0|50,360 360,360',
        'F' => '430,700 50,700 50,0|50,360 350,360',
        'G' => '430,560 340,680 180,700 70,560 50,350 70,140 180,20 340,40 430,150 430,320 270,320',
        'H' => '50,700 50,0|450,700 450,0|50,360 450,360',
        'I' => '250,700 250,0|130,700 370,700|130,0 370,0',
        'J' => '400,700 400,150 320,20 180,20 90,140',
        'K' => '50,700 50,0|430,700 120,330|200,420 450,0',
        'L' => '50,700 50,0 430,0',
        'M' => '50,0 50,700 250,300 450,700 450,0',
        'N' => '50,0 50,700 450,0 450,700',
        'O' => '250,700 90,600 50,350 90,100 250,0 410,100 450,350 410,600 250,700',
        'P' => '50,0 50,700 300,700 410,600 410,460 300,360 50,360',
        'Q' => '250,700 90,600 50,350 90,100 250,0 410,100 450,350 410,600 250,700|300,120 460,-60',
        'R' => '50,0 50,700 300,700 410,600 410,460 300,360 50,360|280,360 450,0',
        'S' => '430,600 320,690 160,690 60,600 60,470 150,390 330,340 430,250 430,110 330,10 160,10 50,110',
        'T' => '30,700 470,700|250,700 250,0',
        'U' => '50,700 50,180 160,20 340,20 450,180 450,700',
        'V' => '50,700 250,0 450,700',
        'W' => '30,700 140,0 250,450 360,0 470,700',
        'X' => '50,700 450,0|450,700 50,0',
        'Y' => '50,700 250,340 450,700|250,340 250,0',
        'Z' => '50,700 450,700 50,0 450,0',
    ];

    /** Les bas de casse. */
    private const LOWER = [
        'a' => '400,500 400,0|400,380 300,500 150,480 60,330 60,150 150,10 300,-10 400,110',
        'b' => '60,700 60,0|60,380 160,500 320,480 420,330 420,150 320,10 160,-10 60,110',
        'c' => '420,400 320,500 160,490 60,340 60,150 160,0 320,-10 420,90',
        'd' => '420,700 420,0|420,380 320,500 160,480 60,330 60,150 160,10 320,-10 420,110',
        'e' => '60,240 420,240 420,360 320,500 160,490 60,340 60,150 160,0 320,-10 420,80',
        'f' => '380,620 300,700 200,680 180,560 180,0|70,500 340,500',
        'g' => '420,500 420,-60 340,-170 180,-180 80,-120|420,380 320,500 160,480 60,330 60,180 160,30 320,10 420,120',
        'h' => '60,700 60,0|60,370 170,490 320,490 420,370 420,0',
        'i' => '250,500 250,0|250,600 250,660',
        'j' => '300,500 300,-60 230,-170 120,-170|300,600 300,660',
        'k' => '60,700 60,0|400,500 120,220|190,290 420,0',
        'l' => '200,700 200,110 260,0 340,10',
        'm' => '60,500 60,0|60,380 150,490 250,490 320,380 320,0|320,380 400,490 480,490 540,380 540,0',
        'n' => '60,500 60,0|60,370 170,490 320,490 420,370 420,0',
        'o' => '240,500 100,420 60,250 100,80 240,-10 380,80 420,250 380,420 240,500',
        'p' => '60,500 60,-180|60,380 160,500 320,480 420,330 420,150 320,10 160,-10 60,110',
        'q' => '420,500 420,-180|420,380 320,500 160,480 60,330 60,150 160,10 320,-10 420,110',
        'r' => '60,500 60,0|60,340 160,480 330,500',
        's' => '390,430 290,510 150,500 70,420 100,320 240,280 370,230 400,130 330,20 180,-10 70,60',
        't' => '180,700 180,110 250,0 360,10|70,500 340,500',
        'u' => '60,500 60,130 170,10 320,10 420,130|420,500 420,0',
        'v' => '60,500 240,0 420,500',
        'w' => '30,500 140,0 240,350 340,0 450,500',
        'x' => '60,500 420,0|420,500 60,0',
        'y' => '60,500 240,0|420,500 180,-180',
        'z' => '60,500 420,500 60,0 420,0',
    ];

    /** Les chiffres. */
    private const DIGITS = [
        '0' => '250,700 90,600 50,350 90,100 250,0 410,100 450,350 410,600 250,700',
        '1' => '150,560 250,700 250,0|130,0 370,0',
        '2' => '60,580 150,690 330,690 430,580 430,450 60,0 440,0',
        '3' => '60,600 160,690 330,690 420,590 420,470 320,380 180,380|320,380 430,290 430,110 330,10 160,10 60,110',
        '4' => '330,0 330,700 50,200 450,200',
        '5' => '430,700 90,700 70,400 200,440 340,420 430,320 430,140 320,10 150,10 50,110',
        '6' => '400,620 280,700 140,660 60,420 50,220 120,50 280,10 410,110 430,260 340,390 180,400 70,320',
        '7' => '50,700 450,700 200,0',
        '8' => '250,380 120,450 80,590 180,690 320,690 420,590 380,450 250,380 110,300 60,150 170,10 330,10 440,150 390,300 250,380',
        '9' => '100,80 220,0 360,40 440,280 450,480 380,650 220,690 90,590 70,440 160,310 320,300 430,380',
    ];

    /** Les ligatures, qui ne se composent pas d'un accent. */
    private const LIGATURES = [
        'œ' => '300,500 160,420 120,250 160,80 300,-10 440,80 480,250 480,360 380,490 260,480|480,250 300,250',
        'Œ' => '350,700 190,600 150,350 190,100 350,0 480,0 480,700 350,700|480,360 300,360',
        'æ' => '240,500 100,420 60,250 100,80 240,-10 380,80|380,250 480,250 480,360 400,490 300,480|480,250 480,0',
        'ß' => '60,-180 60,560 140,690 280,700 360,610 340,480 240,400 340,320 380,180 300,20 160,10',
    ];

    public const ADVANCE = [
        ' ' => 300,
        '.' => 280,
        ',' => 280,
        ':' => 280,
        ';' => 280,
        '!' => 300,
        "'" => 260,
        '’' => 260,
        '|' => 300,
        '-' => 400,
        '–' => 560,
        '—' => 680,
        '(' => 340,
        ')' => 340,
        '[' => 340,
        ']' => 340,
        '{' => 360,
        '}' => 360,
        '/' => 460,
        '\\' => 460,
        '"' => 420,
        '“' => 420,
        '”' => 420,
        'i' => 300,
        'j' => 340,
        'l' => 380,
        't' => 400,
        'f' => 400,
        'r' => 380,
        'm' => 620,
        'w' => 520,
        '°' => 360,
        'I' => 420,
        'J' => 460,
        'M' => 560,
        'W' => 560,
        '#' => 520,
        '%' => 560,
        '@' => 560,
        '—' => 680,
        'œ' => 640,
        'Œ' => 620,
        'æ' => 620,
        'm̃' => 620,
    ];

    /**
     * Les accents ne sont pas redessinés lettre par lettre : ils sont posés
     * au-dessus du glyphe de base. Une seule description de l'aigu, portée par
     * le e, le a et le i — c'est ce qui rend les seize langues tenables.
     */
    private const ACCENTS = [
        'grave' => '190,%T 300,%B',
        'acute' => '310,%T 200,%B',
        'circumflex' => '150,%B 250,%T 340,%B',
        'diaeresis' => '%D1|%D2',
        'tilde' => '140,%B 210,%T 290,%B 360,%T',
        'ring' => '250,%T 185,%M 250,%B 315,%M 250,%T',

    ];

    /** La cédille pend sous la ligne de base ; elle ne se pose pas comme un accent. */
    private const CEDILLA = '250,10 250,-60 190,-110 280,-160';

    /** Les lettres accentuées, chacune avec sa base et son accent. */
    private const ACCENTED = [
        'à' => ['a', 'grave'],
        'â' => ['a', 'circumflex'],
        'ä' => ['a', 'diaeresis'],
        'á' => ['a', 'acute'],
        'ã' => ['a', 'tilde'],
        'å' => ['a', 'ring'],
        'è' => ['e', 'grave'],
        'é' => ['e', 'acute'],
        'ê' => ['e', 'circumflex'],
        'ë' => ['e', 'diaeresis'],
        'ì' => ['i', 'grave'],
        'í' => ['i', 'acute'],
        'î' => ['i', 'circumflex'],
        'ï' => ['i', 'diaeresis'],
        'ò' => ['o', 'grave'],
        'ó' => ['o', 'acute'],
        'ô' => ['o', 'circumflex'],
        'ö' => ['o', 'diaeresis'],
        'õ' => ['o', 'tilde'],
        'ù' => ['u', 'grave'],
        'ú' => ['u', 'acute'],
        'û' => ['u', 'circumflex'],
        'ü' => ['u', 'diaeresis'],
        'ç' => ['c', 'cedilla'],
        'ñ' => ['n', 'tilde'],
        'ý' => ['y', 'acute'],
        'ÿ' => ['y', 'diaeresis'],
        'À' => ['A', 'grave'],
        'Â' => ['A', 'circumflex'],
        'Ä' => ['A', 'diaeresis'],
        'Á' => ['A', 'acute'],
        'È' => ['E', 'grave'],
        'É' => ['E', 'acute'],
        'Ê' => ['E', 'circumflex'],
        'Ë' => ['E', 'diaeresis'],
        'Î' => ['I', 'circumflex'],
        'Ï' => ['I', 'diaeresis'],
        'Í' => ['I', 'acute'],
        'Ô' => ['O', 'circumflex'],
        'Ö' => ['O', 'diaeresis'],
        'Ó' => ['O', 'acute'],
        'Ù' => ['U', 'grave'],
        'Û' => ['U', 'circumflex'],
        'Ü' => ['U', 'diaeresis'],
        'Ú' => ['U', 'acute'],
        'Ç' => ['C', 'cedilla'],
        'Ñ' => ['N', 'tilde'],
    ];

    /** @var array<string, string>|null Les glyphes, composés une fois pour toutes. */
    private static ?array $glyphs = null;

    /**
     * Un point est un petit carré fermé : un tracé d'un seul point ne laisserait
     * rien sur le papier.
     */
    private static function dot(int $x, int $y): string
    {
        return ($x - 25) . ",$y " . ($x + 25) . ",$y " . ($x + 25) . ',' . ($y + 50)
            . ' ' . ($x - 25) . ',' . ($y + 50) . ' ' . ($x - 25) . ",$y";
    }

    /** Ponctuation et signes. */
    private static function marks(): array
    {
        return [
            ' ' => '',
            '.' => self::dot(250, 0),
            ',' => self::dot(250, 0) . '|250,0 210,-130',
            ':' => self::dot(250, 0) . '|' . self::dot(250, 330),
            ';' => self::dot(250, 330) . '|' . self::dot(250, 0) . '|250,0 210,-130',
            '!' => '250,700 250,170|' . self::dot(250, 0),
            '?' => '70,580 160,690 330,690 430,580 430,460 250,350 250,190|' . self::dot(250, 0),
            '-' => '80,250 380,250',
            '–' => '40,250 520,250',
            '—' => '20,250 640,250',
            '_' => '30,-120 470,-120',
            '/' => '60,-50 400,720',
            '\\' => '60,720 400,-50',
            '|' => '250,720 250,-140',
            '(' => '320,720 190,500 190,40 320,-140',
            ')' => '140,720 270,500 270,40 140,-140',
            '[' => '320,720 180,720 180,-140 320,-140',
            ']' => '140,720 280,720 280,-140 140,-140',
            '{' => '330,720 240,660 240,330 160,290 240,250 240,-80 330,-140',
            '}' => '170,720 260,660 260,330 340,290 260,250 260,-80 170,-140',
            "'" => '250,700 250,540',
            '"' => '180,700 180,540|320,700 320,540',
            '’' => '250,700 210,540',
            '“' => '180,540 240,700|300,540 360,700',
            '”' => '180,700 240,540|300,700 360,540',
            '«' => '300,420 160,250 300,80|460,420 320,250 460,80',
            '»' => '200,420 340,250 200,80|360,420 500,250 360,80',
            '+' => '250,420 250,80|80,250 420,250',
            '=' => '80,330 420,330|80,170 420,170',
            '<' => '380,450 120,250 380,50',
            '>' => '120,450 380,250 120,50',
            '*' => '250,600 250,320|130,540 370,380|370,540 130,380',
            '#' => '160,600 110,-20|340,600 290,-20|60,420 420,420|40,180 400,180',
            '&' => '450,0 150,540 200,660 320,660 370,560 300,440 120,300 70,160 150,20 300,10 420,120',
            '%' => '110,700 60,620 110,540 180,620 110,700|420,160 350,80 420,0 490,80 420,160|450,700 80,0',
            '@' => '380,120 250,60 120,150 100,330 200,480 360,470 440,350 430,180 350,160 330,330 250,390 180,320 200,220 300,190 350,250',
            '€' => '430,120 330,20 200,20 90,150 70,350 150,480 300,490 420,400|40,300 300,300|40,180 300,180',
            '$' => '390,560 290,650 150,640 70,560 100,450 240,410 370,350 400,240 330,130 180,100 70,180|250,700 250,40',
            '£' => '420,0 60,0 60,180 200,300 200,560 300,690 420,650|60,330 340,330',
            '°' => '250,700 180,640 250,570 320,640 250,700',
            '§' => '380,640 280,700 160,680 110,580 200,490 350,420 400,320 320,230 190,210|110,120 200,50 330,70 380,170 290,260 140,330 90,430 170,520 300,540',
            '©' => '250,700 90,600 50,350 90,100 250,0 410,100 450,350 410,600 250,700|330,480 260,520 190,450 180,270 250,200 330,250',
            '~' => '60,280 150,360 250,280 350,200 440,280',
            '^' => '100,540 250,680 400,540',
            '`' => '190,700 300,590',
            '´' => '310,700 200,590',
        ];
    }

    private static function accentStrokes(string $kind, int $top): string
    {
        $bottom = $top - 110;
        $middle = $top - 55;
        if ($kind === 'diaeresis') {
            return self::dot(180, $bottom) . '|' . self::dot(320, $bottom);
        }
        return str_replace(
            ['%T', '%B', '%M'],
            [(string) $top, (string) $bottom, (string) $middle],
            self::ACCENTS[$kind]
        );
    }

    /** Compose un glyphe accentué : le tracé de base, plus celui de l'accent. */
    private static function accented(string $base, string $kind): ?string
    {
        $isUpper = $base === mb_strtoupper($base);
        $strokes = $isUpper ? (self::UPPER[$base] ?? null) : (self::LOWER[$base] ?? null);
        if ($strokes === null) {
            return null;
        }
        if ($kind === 'cedilla') {
            return $strokes . '|' . self::CEDILLA;
        }
        return $strokes . '|' . self::accentStrokes($kind, $isUpper ? 860 : 690);
    }

    /** Tous les glyphes de la police. */
    public static function glyphs(): array
    {
        if (self::$glyphs !== null) {
            return self::$glyphs;
        }

        $accented = [];
        foreach (self::ACCENTED as $char => [$base, $kind]) {
            $strokes = self::accented($base, $kind);
            if ($strokes !== null) {
                $accented[$char] = $strokes;
            }
        }

        self::$glyphs = array_merge(
            self::marks(),
            self::UPPER,
            self::LOWER,
            self::DIGITS,
            $accented,
            self::LIGATURES
        );
        return self::$glyphs;
    }

    /** Les caractères que la police sait dessiner. */
    public static function supported(): array
    {
        return array_keys(self::glyphs());
    }

    /** La base d'une lettre accentuée : « é » garde la chasse du « e ». */
    private static function baseOf(string $char): string
    {
        if (isset(self::ACCENTED[$char])) {
            return self::ACCENTED[$char][0];
        }
        if (class_exists('\Normalizer')) {
            $decomposed = \Normalizer::normalize($char, \Normalizer::FORM_D);
            if (is_string($decomposed) && $decomposed !== '') {
                return mb_substr($decomposed, 0, 1);
            }
        }
        return $char;
    }

    public static function advanceOf(string $char): int
    {
        if (isset(self::ADVANCE[$char])) {
            return self::ADVANCE[$char];
        }
        // Un caractère accentué garde la chasse de sa base.
        return self::ADVANCE[self::baseOf($char)] ?? self::DEFAULT_ADVANCE;
    }

    /** Le tracé d'un caractère, ou celui du caractère de remplacement. */
    public static function strokesOf(string $char): string
    {
        $glyphs = self::glyphs();
        if (isset($glyphs[$char])) {
            return $glyphs[$char];
        }
        // Ni le glyphe, ni sa forme décomposée : on rend un cadre vide plutôt
        // que rien du tout, pour qu'une lettre manquante se voie au lieu de se
        // taire.
        $folded = self::baseOf($char);
        if (isset($glyphs[$folded])) {
            return $glyphs[$folded];
        }
        return '60,0 460,0 460,700 60,700 60,0';
    }

    /**
     * Le programme de dessin d'un glyphe, au format des flux Type 3.
     *
     * `d1` déclare la chasse et la boîte : le lecteur en déduit qu'il s'agit
     * d'une forme, dont la couleur vient de l'état graphique de la page et non
     * du glyphe.
     */
    public static function charProc(string $char): string
    {
        $strokes = self::strokesOf($char);
        $width = self::advanceOf($char);

        if ($strokes === '') {
            return "$width 0 0 0 0 0 d1\n";
        }

        $paths = [];
        foreach (array_filter(explode('|', $strokes), static fn (string $s): bool => $s !== '') as $stroke) {
            $points = preg_split('/\s+/', trim($stroke)) ?: [];
            $lines = [];
            foreach ($points as $index => $point) {
                [$x, $y] = explode(',', $point);
                $lines[] = $x . ' ' . $y . ' ' . ($index === 0 ? 'm' : 'l');
            }
            $paths[] = implode("\n", $lines);
        }

        // L'épaisseur est donnée dans l'espace du glyphe : 45 millièmes de
        // cadratin, soit 0,45 point pour un corps de 10 — lisible sans être gras.
        return "$width 0 -60 -200 660 880 d1\n45 w 1 J 1 j\n" . implode("\n", $paths) . "\nS\n";
    }

    /** La largeur d'un texte, dans la même unité que la chasse. */
    public static function widthOf(string $text): int
    {
        $total = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $total += self::advanceOf($char);
        }
        return $total;
    }
}
