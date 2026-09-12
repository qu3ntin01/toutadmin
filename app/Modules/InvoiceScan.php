<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

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
final class InvoiceScan
{
    /** Une facture peut être libellée dans une autre devise que celle des comptes. */
    public const CURRENCY_MARKS = [
        'EUR' => ['/€/u', '/\beuros?\b/iu', '/\bEUR\b/u'],
        'USD' => ['/\$/u', '/\bUSD\b/u', '/\bdollars?\b/iu'],
        'GBP' => ['/£/u', '/\bGBP\b/u'],
        'CHF' => ['/\bCHF\b/u', '/\bfrancs? suisses?\b/iu'],
        'MAD' => ['/\bMAD\b/u', '/\bdirhams?\b/iu'],
        'XOF' => ['/\bXOF\b/u', '/\bFCFA\b/u', '/\bF\s?CFA\b/u'],
    ];

    private const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    // ---------- Nombres et dates ----------

    /**
     * « 1 234,56 », « 1.234,56 », « 1,234.56 » et « 1234.56 » désignent le même
     * montant. Le dernier séparateur rencontré est le décimal : c'est la seule
     * règle qui marche sans savoir d'avance dans quel pays la facture a été faite.
     */
    public static function parseNumber(mixed $raw): ?float
    {
        if (is_float($raw) || is_int($raw)) {
            return round((float) $raw, 2);
        }
        $cleaned = preg_replace('/[\s\x{00a0}\x{202f}\x{2009}]/u', '', (string) $raw) ?? '';
        $cleaned = str_replace(['€', '$', '£'], '', $cleaned);
        if (!preg_match('/\d/', $cleaned)) {
            return null;
        }

        $lastComma = strrpos($cleaned, ',');
        $lastDot = strrpos($cleaned, '.');
        $normalized = $cleaned;

        if ($lastComma !== false && $lastDot !== false) {
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $normalized = str_replace($decimal, '.', str_replace($thousands, '', $cleaned));
        } elseif ($lastComma !== false) {
            $normalized = str_replace(',', '.', preg_replace('/,(?=\d{3}\b)/', '', $cleaned) ?? $cleaned);
        } elseif ($lastDot !== false) {
            // « 1.234 » est un millier ; « 12.34 » est un décimal.
            $decimals = strlen($cleaned) - $lastDot - 1;
            $normalized = $decimals === 3 ? str_replace('.', '', $cleaned) : $cleaned;
        }

        $normalized = preg_replace('/[^0-9.-]/', '', $normalized) ?? '';
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }
        return round((float) $normalized, 2);
    }

    private static function pad(int $value): string
    {
        return str_pad((string) $value, 2, '0', STR_PAD_LEFT);
    }

    /** Rend une date ISO à partir des formats qu'on rencontre vraiment. */
    public static function parseDate(?string $raw): ?string
    {
        $text = mb_strtolower(trim((string) $raw));

        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $text, $m)) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('#(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})#', $text, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $day = (int) $m[1];
            $month = (int) $m[2];
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return $year . '-' . self::pad($month) . '-' . self::pad($day);
            }
        }
        $months = implode('|', self::MONTHS);
        if (preg_match('/(\d{1,2})\s*(?:er)?\s+(' . $months . ')\.?\s+(\d{4})/iu', $text, $m)) {
            $month = array_search(mb_strtolower($m[2]), self::MONTHS, true);
            if ($month !== false) {
                return $m[3] . '-' . self::pad((int) $month + 1) . '-' . self::pad((int) $m[1]);
            }
        }
        return null;
    }

    // ---------- Identifiants ----------

    /** Clé de Luhn : un SIRET mal recopié se voit sans interroger l'INSEE. */
    public static function luhnValid(string $digits): bool
    {
        $sum = 0;
        $length = strlen($digits);
        for ($i = 0; $i < $length; $i++) {
            $value = (int) $digits[$length - 1 - $i];
            if ($i % 2 === 1) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }
        return $sum % 10 === 0;
    }

    public static function findSirets(string $text): array
    {
        $found = [];
        preg_match_all('/\b(\d[\d\s.]{12,19}\d)\b/', $text, $matches);
        foreach ($matches[1] as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) === 14 && self::luhnValid($digits)) {
                $found[] = $digits;
            }
        }
        return array_values(array_unique($found));
    }

    public static function findSirens(string $text): array
    {
        $found = [];
        preg_match_all('/\b(\d{3}[\s.]?\d{3}[\s.]?\d{3})\b/', $text, $matches);
        foreach ($matches[1] as $raw) {
            $digits = preg_replace('/\D/', '', $raw) ?? '';
            if (strlen($digits) === 9 && self::luhnValid($digits)) {
                $found[] = $digits;
            }
        }
        return array_values(array_unique($found));
    }

    public static function findVatNumbers(string $text): array
    {
        preg_match_all('/\b(FR[\s]?[0-9A-Z]{2}[\s]?\d{3}[\s]?\d{3}[\s]?\d{3})\b/i', $text, $matches);
        $found = array_map(
            static fn (string $value): string => strtoupper(preg_replace('/\s/', '', $value) ?? ''),
            $matches[1]
        );
        return array_values(array_unique($found));
    }

    /** IBAN : la clé modulo 97 écarte les coquilles de recopie. */
    public static function ibanValid(string $iban): bool
    {
        $value = strtoupper(preg_replace('/\s/', '', $iban) ?? '');
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $value)) {
            return false;
        }

        $rearranged = substr($value, 4) . substr($value, 0, 4);
        $digits = '';
        foreach (str_split($rearranged) as $char) {
            $digits .= ctype_upper($char) && !ctype_digit($char)
                ? (string) (ord($char) - 55)
                : $char;
        }

        $remainder = 0;
        foreach (str_split($digits) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }
        return $remainder === 1;
    }

    public static function findIban(string $text): ?string
    {
        preg_match_all('/\b([A-Z]{2}\d{2}(?:[\s]?[A-Z0-9]{2,4}){2,8})\b/', $text, $matches);
        foreach ($matches[1] as $candidate) {
            if (self::ibanValid($candidate)) {
                return strtoupper(preg_replace('/\s/', '', $candidate) ?? '');
            }
        }
        return null;
    }

    // ---------- Recherche par étiquette ----------

    /** Sans accents ni casse : « Échéance » et « echeance » sont la même étiquette. */
    public static function fold(?string $value): string
    {
        $map = ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
            'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i',
            'î' => 'i', 'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ö' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', '’' => "'"];
        return strtr(mb_strtolower((string) $value), $map);
    }

    /** Les nombres d'une ligne, les pourcentages écartés : « TVA 20 % 190,00 » vaut 190. */
    public static function amountsInLine(string $line): array
    {
        $withoutPercents = preg_replace('/-?[\d.,]+\s*%/u', ' ', $line) ?? $line;
        preg_match_all('/-?\d[\d\s\x{00a0}\x{202f}\x{2009}.,]*\d|\d/u', $withoutPercents, $matches);

        $values = [];
        foreach ($matches[0] as $token) {
            $value = self::parseNumber($token);
            if ($value !== null) {
                $values[] = $value;
            }
        }
        return $values;
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
    public static function labelledAmount(?string $text, array $labels): ?array
    {
        $lines = explode("\n", (string) $text);
        $best = null;

        foreach ($lines as $index => $line) {
            $folded = self::fold($line);
            foreach ($labels as $label) {
                $needle = self::fold($label);
                $at = mb_strpos($folded, $needle);
                if ($at === false) {
                    continue;
                }

                $after = self::amountsInLine(mb_substr($line, $at + mb_strlen($needle)));
                if ($after !== []) {
                    $best = ['value' => $after[count($after) - 1], 'label' => $label, 'line' => $index + 1];
                    continue;
                }
                $next = isset($lines[$index + 1]) ? self::amountsInLine($lines[$index + 1]) : [];
                if ($next !== []) {
                    $best = ['value' => $next[0], 'label' => $label, 'line' => $index + 2];
                }
            }
        }
        return $best;
    }

    /** Même principe pour les dates : l'étiquette, puis la date sur la même ligne. */
    public static function labelledDate(?string $text, array $labels): ?array
    {
        $lines = explode("\n", (string) $text);
        $best = null;

        foreach ($lines as $index => $line) {
            $folded = self::fold($line);
            foreach ($labels as $label) {
                $needle = self::fold($label);
                $at = mb_strpos($folded, $needle);
                if ($at === false) {
                    continue;
                }

                $parsed = self::parseDate(mb_substr($line, $at + mb_strlen($needle)))
                    ?? (isset($lines[$index + 1]) ? self::parseDate($lines[$index + 1]) : null);
                if ($parsed !== null && $best === null) {
                    $best = ['value' => $parsed, 'label' => $label, 'line' => $index + 1];
                }
            }
        }
        return $best;
    }

    /**
     * Le numéro de facture. Le « n° » est écrit de dix façons — « N° », « No »,
     * « Nº », « num. », ou rien du tout quand la mise en page l'a mangé — et le
     * candidat doit contenir un chiffre : sans cela, « Facture Acquittée » ferait
     * un numéro. Une date n'en est pas un non plus.
     */
    public static function findReference(string $text): ?string
    {
        $patterns = [
            '/(?:facture|invoice)\s*(?:n[°ºo]?\.?|num[ée]ro|#)?\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_.]{2,24})/iu',
            '/(?:n[°ºo]|num[ée]ro)\s*(?:de\s*)?facture\s*[:\-]?\s*([A-Z0-9][A-Z0-9\/\-_.]{2,24})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (!preg_match($pattern, $text, $m)) {
                continue;
            }
            $candidate = preg_replace('/[.,;]$/', '', $m[1]) ?? $m[1];
            if (!preg_match('/\d/', $candidate)) {
                continue;
            }
            if (self::parseDate($candidate) !== null) {
                continue;
            }
            return $candidate;
        }
        return null;
    }

    public static function findCurrency(string $text): ?string
    {
        foreach (self::CURRENCY_MARKS as $code => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text)) {
                    return $code;
                }
            }
        }
        return null;
    }

    // ---------- Rapprochement avec les tiers connus ----------

    /**
     * Le meilleur indice d'émetteur n'est pas la mise en page : c'est le tiers
     * déjà enregistré dont l'identifiant ou le nom figure dans le document.
     */
    public static function matchPartner(string $text, array $identifiers = []): ?array
    {
        $sirets = $identifiers['sirets'] ?? [];
        $sirens = $identifiers['sirens'] ?? [];
        $vats = $identifiers['vats'] ?? [];

        $folded = self::fold($text);
        $partners = Db::all('SELECT id, name, kind, registration FROM partners');

        foreach ($partners as $partner) {
            $registration = strtoupper(preg_replace('/\s/', '', (string) ($partner['registration'] ?? '')) ?? '');
            if ($registration === '') {
                continue;
            }
            $digits = preg_replace('/\D/', '', $registration) ?? '';
            $startsWith = false;
            foreach ($sirets as $siret) {
                if ($digits !== '' && str_starts_with($siret, $digits)) {
                    $startsWith = true;
                }
            }
            if ((strlen($digits) >= 9 && (in_array($digits, $sirets, true) || in_array($digits, $sirens, true) || $startsWith))
                || in_array($registration, $vats, true)) {
                return ['partner' => $partner, 'reason' => 'identifiant', 'confidence' => 95];
            }
        }

        // À défaut, le nom : on exige au moins quatre caractères pour éviter qu'un
        // tiers nommé « SA » ne se rapproche de toutes les factures.
        $named = [];
        foreach ($partners as $partner) {
            $name = self::fold((string) $partner['name']);
            if (mb_strlen($name) >= 4 && str_contains($folded, $name)) {
                $named[] = $partner;
            }
        }
        usort($named, static fn (array $a, array $b): int => mb_strlen((string) $b['name']) <=> mb_strlen((string) $a['name']));

        return $named === [] ? null : ['partner' => $named[0], 'reason' => 'nom', 'confidence' => 70];
    }

    /** À défaut de tiers connu, la raison sociale se cherche en tête de document. */
    public static function guessSupplierName(?string $text): ?string
    {
        $lines = array_slice(array_values(array_filter(
            array_map('trim', explode("\n", (string) $text)),
            static fn (string $line): bool => $line !== ''
        )), 0, 12);

        foreach ($lines as $line) {
            if (mb_strlen($line) < 3 || mb_strlen($line) > 60) {
                continue;
            }
            if (preg_match('/(facture|invoice|devis|adresse|t[ée]l|email|@|www|http|siret|siren|tva|page|\d{5}\s)/iu', $line)) {
                continue;
            }
            if (preg_match('/[A-Za-zÀ-ÿ]/u', $line)) {
                return $line;
            }
        }
        return null;
    }

    // ---------- Analyse ----------

    private static function round(float $value): float
    {
        return round($value, 2);
    }

    /**
     * Analyse le texte d'une facture. Rend toujours un résultat : un document
     * illisible donne des champs vides et une confiance nulle, jamais une erreur.
     */
    public static function analyse(?string $text, array $options = []): array
    {
        $content = (string) $text;
        $fileName = (string) ($options['fileName'] ?? '');
        $notes = [];

        $sirets = self::findSirets($content);
        $sirens = self::findSirens($content);
        $vats = self::findVatNumbers($content);

        // Nos propres identifiants ne désignent pas un fournisseur : ils désignent
        // le destinataire, c'est-à-dire nous.
        $ourSiren = preg_replace('/\D/', '', (string) Settings::get('company_siren')) ?? '';
        $ourVat = strtoupper(preg_replace('/\s/', '', (string) Settings::get('company_vat')) ?? '');

        $foreignSirets = array_values(array_filter(
            $sirets,
            static fn (string $s): bool => $ourSiren === '' || !str_starts_with($s, $ourSiren)
        ));
        $foreignSirens = array_values(array_filter($sirens, static fn (string $s): bool => $s !== $ourSiren));
        $foreignVats = array_values(array_filter($vats, static fn (string $v): bool => $v !== $ourVat));

        $startsWithOurs = false;
        foreach ($sirets as $siret) {
            if ($ourSiren !== '' && str_starts_with($siret, $ourSiren)) {
                $startsWithOurs = true;
            }
        }
        $ourselves = ($ourSiren !== '' && (in_array($ourSiren, $sirens, true) || $startsWithOurs))
            || ($ourVat !== '' && in_array($ourVat, $vats, true));

        $ht = self::labelledAmount($content, ['total ht', 'montant ht', 'total hors taxes', 'base ht', 'sous-total', 'prix ht']);
        $vat = self::labelledAmount($content, ['total tva', 'montant tva', 'tva', 'dont tva']);
        $ttc = self::labelledAmount($content, ['net a payer', 'total ttc', 'montant ttc', 'total a payer', 'total toutes taxes', 'montant du']);

        $rate = null;
        if (preg_match('/tva\s*(?:\(|\s|:|à)?\s*(\d{1,2}(?:[.,]\d{1,2})?)\s*%/iu', $content, $m)
            || preg_match('/(\d{1,2}(?:[.,]\d{1,2})?)\s*%\s*(?:de\s*)?tva/iu', $content, $m)) {
            $rate = self::parseNumber($m[1]);
        }

        $issue = self::labelledDate($content, ['date de facture', "date d'emission", 'date d’émission', 'emise le', 'date facture', 'date']);
        $due = self::labelledDate($content, ['date d’échéance', "date d'echeance", 'echeance', 'a payer avant', 'date limite de paiement', 'payable avant']);

        $match = self::matchPartner($content, ['sirets' => $foreignSirets, 'sirens' => $foreignSirens, 'vats' => $foreignVats]);

        // Cohérence : c'est elle qui distingue une lecture réussie d'un ramassage
        // de nombres. Elle peut aussi reconstituer le montant manquant.
        $amounts = [
            'ht' => $ht !== null ? $ht['value'] : null,
            'vat' => $vat !== null ? $vat['value'] : null,
            'ttc' => $ttc !== null ? $ttc['value'] : null,
        ];
        $coherent = null;

        if ($amounts['ht'] !== null && $amounts['vat'] !== null && $amounts['ttc'] !== null) {
            $coherent = abs(self::round($amounts['ht'] + $amounts['vat']) - $amounts['ttc']) <= 0.02;
            if (!$coherent) {
                $notes[] = 'HT + TVA ne fait pas TTC : les montants lus se contredisent.';
            }
        } elseif ($amounts['ht'] !== null && $amounts['ttc'] !== null) {
            $amounts['vat'] = self::round($amounts['ttc'] - $amounts['ht']);
            $coherent = $amounts['vat'] >= 0;
            $notes[] = 'TVA déduite de la différence entre TTC et HT.';
        } elseif ($amounts['ht'] !== null && $rate !== null) {
            $amounts['ttc'] = self::round($amounts['ht'] * (1 + $rate / 100));
            $amounts['vat'] = self::round($amounts['ttc'] - $amounts['ht']);
            $notes[] = 'TTC reconstitué à partir du HT et du taux.';
        } elseif ($amounts['ttc'] !== null && $rate !== null) {
            $amounts['ht'] = self::round($amounts['ttc'] / (1 + $rate / 100));
            $amounts['vat'] = self::round($amounts['ttc'] - $amounts['ht']);
            $notes[] = 'HT reconstitué à partir du TTC et du taux.';
        }

        $derivedRate = $rate ?? (($amounts['ht'] ?? 0.0) > 0 && $amounts['vat'] !== null
            ? self::round(($amounts['vat'] / $amounts['ht']) * 100)
            : null);

        $fields = [
            'reference' => self::findReference($content),
            'issueDate' => $issue !== null ? $issue['value'] : null,
            'dueDate' => $due !== null ? $due['value'] : null,
            'amountHt' => $amounts['ht'],
            'amountVat' => $amounts['vat'],
            'amountTtc' => $amounts['ttc'],
            'vatRate' => $derivedRate,
            'currency' => self::findCurrency($content) ?? 'EUR',
            'iban' => self::findIban($content),
            'siret' => $foreignSirets[0] ?? null,
            'siren' => $foreignSirens[0] ?? ($foreignSirets !== [] ? substr($foreignSirets[0], 0, 9) : null),
            'vatNumber' => $foreignVats[0] ?? null,
            'supplierName' => $match !== null ? $match['partner']['name'] : self::guessSupplierName($content),
            'partnerId' => $match !== null ? (int) $match['partner']['id'] : null,
            'partnerReason' => $match !== null ? $match['reason'] : null,
        ];

        // La confiance se compose : chaque élément retrouvé en apporte une part, et
        // l'incohérence en retire. Elle sert à trier la pile, pas à décider seule.
        $confidence = 0;
        if ($fields['reference'] !== null) {
            $confidence += 15;
        }
        if ($fields['issueDate'] !== null) {
            $confidence += 15;
        }
        if ($amounts['ttc'] !== null) {
            $confidence += 20;
        }
        if ($amounts['ht'] !== null) {
            $confidence += 10;
        }
        if ($derivedRate !== null) {
            $confidence += 5;
        }
        if ($fields['siret'] !== null || $fields['siren'] !== null || $fields['vatNumber'] !== null) {
            $confidence += 10;
        }
        if ($fields['partnerId'] !== null) {
            $confidence += $match['confidence'] >= 90 ? 20 : 10;
        }
        if ($coherent === true) {
            $confidence += 10;
        }
        if ($coherent === false) {
            $confidence -= 25;
        }
        if (trim($content) === '') {
            $notes[] = 'Aucun texte lisible : document scanné en image ?';
        }

        return [
            'fields' => $fields,
            'notes' => $notes,
            'coherent' => $coherent,
            'confidence' => max(0, min(100, $confidence)),
            'direction' => $ourselves && $foreignSirets === [] && $foreignSirens === [] ? 'Client' : 'Fournisseur',
            'source' => 'règles',
            'fileName' => $fileName,
            'textLength' => strlen($content),
        ];
    }
}
