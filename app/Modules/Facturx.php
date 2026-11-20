<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\PdfFont;
use App\Core\PdfWriter;

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
 * joindre un fichier de données. C'est `PdfWriter` qui en tient les règles.
 *
 * Ce que ce module ne fait pas : déposer la facture sur une plateforme agréée.
 * Cela demande un compte chez un opérateur, pas du code.
 */
final class Facturx
{
    public const ATTACHMENT = 'factur-x.xml';
    public const PROFILE = 'EN 16931';

    private const LEFT = 56.0;

    private static function right(): float
    {
        return PdfWriter::A4['width'] - 56.0;
    }

    /** Un montant tel qu'on l'écrit sur une facture française. */
    public static function money(float $amount, string $currency = 'EUR'): string
    {
        $fixed = str_replace('.', ',', number_format(round($amount, 2), 2, '.', ''));
        $withSeparators = (string) preg_replace('/\B(?=(\d{3})+(?!\d)\,)/', ' ', $fixed);
        $symbols = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF'];
        return $withSeparators . ' ' . ($symbols[$currency] ?? $currency);
    }

    /** Une date au format du pays, pas au format de la base. */
    public static function day(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }
        [$y, $m, $d] = array_pad(explode('-', substr($iso, 0, 10)), 3, '');
        return "$d/$m/$y";
    }

    /**
     * Coupe un texte à la largeur donnée, en points. Une adresse sur trois lignes
     * vaut mieux qu'une adresse qui déborde de la page.
     */
    public static function wrap(string $text, float $maxPoints, float $size): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', $text) ?: [], static fn (string $w): bool => $w !== ''));
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : "$current $word";
            if ($current !== '' && (PdfFont::widthOf($candidate) * $size) / 1000 > $maxPoints) {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }
        return $lines;
    }

    /**
     * La mise en page. Rien de décoratif : l'en-tête, les deux parties, la ligne
     * de prestation, les totaux, les dates, et la mention qui dit à celui qui
     * ouvre le fichier qu'un XML l'accompagne.
     */
    public static function layout(array $invoice): array
    {
        $partner = EInvoicing::check($invoice)['partner'];
        $seller = EInvoicing::issuer();
        $currency = (string) ($invoice['currency'] ?? 'EUR') ?: 'EUR';
        $right = self::right();

        $ht = round((float) $invoice['amount_ht'], 2);
        $vat = round($ht * ((float) $invoice['vat_rate'] / 100), 2);
        $ttc = round($ht + $vat, 2);

        $lines = [];
        $put = static function (string $text, float $x, float $y, array $extra = []) use (&$lines): void {
            $lines[] = array_merge(['text' => $text, 'x' => $x, 'y' => $y], $extra);
        };

        // En-tête
        $put($seller['company_legal_name'] !== '' ? $seller['company_legal_name'] : '—', self::LEFT, 790, ['size' => 15]);
        $put('FACTURE', $right, 790, ['size' => 15, 'align' => 'right']);
        $reference = ($invoice['reference'] ?? '') !== '' ? (string) $invoice['reference'] : 'FA-' . (int) $invoice['id'];
        $put($reference, $right, 772, ['size' => 11, 'align' => 'right']);
        $lines[] = ['rule' => true, 'x' => self::LEFT, 'x2' => $right, 'y' => 762, 'width' => 1];

        // Émetteur
        $y = 738.0;
        $put('Émetteur', self::LEFT, $y, ['size' => 8]);
        $y -= 14;
        $pieces = array_filter([
            $seller['company_legal_name'],
            $seller['company_address'],
            trim(($seller['company_postal_code'] ?? '') . ' ' . ($seller['company_city'] ?? '')),
            $seller['company_country'],
            ($seller['company_siren'] ?? '') !== '' ? 'SIREN ' . $seller['company_siren'] : '',
            ($seller['company_vat'] ?? '') !== '' ? 'TVA ' . $seller['company_vat'] : '',
        ], static fn (string $piece): bool => $piece !== '');
        foreach ($pieces as $piece) {
            foreach (self::wrap($piece, 220, 9) as $row) {
                $put($row, self::LEFT, $y);
                $y -= 12;
            }
        }

        // Client
        $yb = 738.0;
        $middle = self::LEFT + 280;
        $put('Client', $middle, $yb, ['size' => 8]);
        $yb -= 14;
        $client = array_filter([
            $partner !== null ? (string) $partner['name'] : '—',
            $partner !== null ? (string) $partner['address'] : '',
            $partner !== null && ($partner['registration'] ?? '') !== '' ? 'Identifiant ' . $partner['registration'] : '',
        ], static fn (string $piece): bool => $piece !== '');
        foreach ($client as $piece) {
            foreach (self::wrap($piece, 230, 9) as $row) {
                $put($row, $middle, $yb);
                $yb -= 12;
            }
        }

        // Dates
        $yd = min($y, $yb) - 16;
        $put("Date d'émission : " . self::day($invoice['issue_date'] ?? null), self::LEFT, $yd);
        $put('Échéance de paiement : ' . self::day($invoice['due_date'] ?? null), $middle, $yd);
        $yd -= 26;

        // La prestation
        $lines[] = ['rule' => true, 'x' => self::LEFT, 'x2' => $right, 'y' => $yd + 14, 'width' => 0.8];
        $put('Désignation', self::LEFT, $yd, ['size' => 8]);
        $put('Taux', $right - 210, $yd, ['size' => 8, 'align' => 'right']);
        $put('Montant HT', $right, $yd, ['size' => 8, 'align' => 'right']);
        $lines[] = ['rule' => true, 'x' => self::LEFT, 'x2' => $right, 'y' => $yd - 6, 'width' => 0.4];

        $yd -= 22;
        $rate = str_replace('.', ',', number_format((float) $invoice['vat_rate'], 2, '.', ''));
        $designation = self::wrap((string) ($invoice['label'] ?? '') ?: 'Prestation', 225, 9);
        foreach ($designation as $index => $row) {
            $put($row, self::LEFT, $yd - $index * 12);
        }
        $put("$rate %", $right - 210, $yd, ['align' => 'right']);
        $put(self::money($ht, $currency), $right, $yd, ['align' => 'right']);
        $yd -= max(24, count($designation) * 12 + 12);

        // Les totaux
        $lines[] = ['rule' => true, 'x' => $right - 220, 'x2' => $right, 'y' => $yd + 12, 'width' => 0.4];
        $put('Total hors taxes', $right - 120, $yd, ['align' => 'right']);
        $put(self::money($ht, $currency), $right, $yd, ['align' => 'right']);
        $yd -= 14;
        $put("TVA $rate %", $right - 120, $yd, ['align' => 'right']);
        $put(self::money($vat, $currency), $right, $yd, ['align' => 'right']);
        $yd -= 18;
        $lines[] = ['rule' => true, 'x' => $right - 220, 'x2' => $right, 'y' => $yd + 12, 'width' => 0.8];
        $put('Total toutes taxes comprises', $right - 120, $yd, ['align' => 'right', 'size' => 10]);
        $put(self::money($ttc, $currency), $right, $yd, ['align' => 'right', 'size' => 10]);

        // Le pied de page dit ce que le fichier contient : quelqu'un qui l'ouvre
        // dans une visionneuse ordinaire ne verrait pas le XML autrement.
        $lines[] = ['rule' => true, 'x' => self::LEFT, 'x2' => $right, 'y' => 84, 'width' => 0.4];
        $put('Facture électronique Factur-X — profil ' . self::PROFILE . '.', self::LEFT, 70, ['size' => 8]);
        $put('Les données de cette facture sont jointes à ce fichier sous le nom ' . self::ATTACHMENT . '.', self::LEFT, 58, ['size' => 8]);
        $put("Devise : $currency", $right, 70, ['size' => 8, 'align' => 'right']);

        return $lines;
    }

    /**
     * Construit le fichier. Rien ne sort d'une facture qui ne passe pas le
     * contrôle EN 16931 : un Factur-X non conforme est pire qu'un PDF ordinaire,
     * parce qu'il promet une conformité qu'il n'a pas.
     */
    public static function build(array $invoice): array
    {
        $conformity = EInvoicing::check($invoice);
        if (!$conformity['ok']) {
            return ['ok' => false, 'gaps' => $conformity['gaps']];
        }

        $xml = EInvoicing::toXml($invoice);
        $seller = EInvoicing::issuer();
        $reference = ($invoice['reference'] ?? '') !== '' ? (string) $invoice['reference'] : 'FA-' . (int) $invoice['id'];

        $content = PdfWriter::render([
            'title' => "Facture $reference",
            'author' => $seller['company_legal_name'] !== '' ? $seller['company_legal_name'] : 'Toutadmin',
            'lines' => self::layout($invoice),
            'profile' => self::PROFILE,
            'attachment' => [
                'name' => self::ATTACHMENT,
                'content' => $xml,
                'description' => "Facture $reference au format CII (EN 16931)",
            ],
        ]);

        return ['ok' => true, 'content' => $content, 'xml' => $xml, 'fileName' => self::fileName($invoice)];
    }

    /** Nom de fichier normalisé, utilisable tel quel dans un dépôt. */
    public static function fileName(array $invoice): string
    {
        $reference = ($invoice['reference'] ?? '') !== '' ? (string) $invoice['reference'] : 'FA-' . (int) $invoice['id'];
        return 'facture-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $reference) . '.pdf';
    }
}
