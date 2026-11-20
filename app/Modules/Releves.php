<?php

declare(strict_types=1);

namespace App\Modules;

/**
 * Lire un relevé bancaire : CFONB 120, CAMT.053 ou OFX — la même lecture que
 * l'édition Node (src/releves.js).
 *
 * Ce module lit et rend une liste de mouvements ; il n'écrit rien. Il ne
 * dépend d'aucune bibliothèque, et un CAMT qui porte un DOCTYPE est refusé :
 * un fichier venu de l'extérieur ne fait pas suivre d'entité externe.
 *
 * Chaque mouvement porte une empreinte stable, faite de ce que la banque dit de
 * lui : importer deux fois le même relevé ne double rien.
 */
final class Releves
{
    private const LIMITE = 20000;
    private const CREDIT = '{ABCDEFGHI';
    private const DEBIT = '}JKLMNOPQR';

    private static function texte(string $octets): string
    {
        $texte = mb_check_encoding($octets, 'UTF-8') ? $octets : mb_convert_encoding($octets, 'UTF-8', 'ISO-8859-1');
        return (string) preg_replace('/^\x{FEFF}/u', '', $texte);
    }

    public static function formatDe(string $contenu): ?string
    {
        $debut = substr($contenu, 0, 4000);
        if (preg_match('/<BkToCstmrStmt\b/', $debut) === 1 || str_contains($debut, 'camt.053')) {
            return 'camt053';
        }
        if (preg_match('/OFXHEADER|<OFX>/i', $debut) === 1) {
            return 'ofx';
        }
        $lignes = array_filter(preg_split('/\r?\n/', $contenu) ?: [], static fn (string $l): bool => trim($l) !== '');
        if ($lignes === []) {
            return null;
        }
        foreach ($lignes as $ligne) {
            if (preg_match('/^(01|04|05|07)/', $ligne) !== 1 || mb_strlen(rtrim($ligne)) > 120) {
                return null;
            }
        }
        return 'cfonb120';
    }

    private static function empreinte(string ...$morceaux): string
    {
        return substr(hash('sha256', implode('|', $morceaux)), 0, 32);
    }

    private static function propre(?string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $s));
    }

    /** Le dernier caractère porte le signe et le dernier chiffre : « { »..« I » = +0..+9, « } »..« R » = −0..−9. */
    public static function montantCfonb(string $zone, int $decimales): ?float
    {
        $corps = substr($zone, 0, -1);
        $fin = substr($zone, -1);
        if (($chiffre = strpos(self::CREDIT, $fin)) !== false) {
            $signe = 1;
        } elseif (($chiffre = strpos(self::DEBIT, $fin)) !== false) {
            $signe = -1;
        } else {
            return null;
        }
        if (preg_match('/^\d+$/', $corps) !== 1) {
            return null;
        }
        return $signe * ((int) ($corps . $chiffre)) / (10 ** $decimales);
    }

    private static function dateCfonb(string $jjmmaa): ?string
    {
        if (preg_match('/^(\d{2})(\d{2})(\d{2})$/', $jjmmaa, $m) !== 1) {
            return null;
        }
        return "20{$m[3]}-{$m[2]}-{$m[1]}";
    }

    private static function lireCfonb(string $contenu): array
    {
        $mouvements = [];
        $compte = null;
        foreach (preg_split('/\r?\n/', $contenu) ?: [] as $brute) {
            $ligne = str_pad($brute, 120);
            $code = substr($ligne, 0, 2);
            if ($code === '01' && $compte === null) {
                $compte = trim(substr($ligne, 21, 11));
            }
            if ($code === '04') {
                $decimales = (int) substr($ligne, 19, 1) ?: 2;
                $montant = self::montantCfonb(substr($ligne, 90, 14), $decimales);
                $date = self::dateCfonb(substr($ligne, 34, 6));
                if ($montant === null || $date === null) {
                    continue;
                }
                $ecriture = trim(substr($ligne, 81, 7));
                $mouvements[] = [
                    'date' => $date,
                    'dateValeur' => self::dateCfonb(substr($ligne, 42, 6)) ?? $date,
                    'libelle' => self::propre(substr($ligne, 48, 31)),
                    'montant' => $montant,
                    'reference' => self::propre(substr($ligne, 104, 16)) ?: $ecriture,
                    'empreinte' => self::empreinte('cfonb', substr($ligne, 2, 102)),
                ];
            } elseif ($code === '05' && $mouvements !== []) {
                $complement = self::propre(substr($ligne, 48, 70));
                if ($complement !== '') {
                    $dernier = count($mouvements) - 1;
                    $mouvements[$dernier]['libelle'] = self::propre($mouvements[$dernier]['libelle'] . ' ' . $complement);
                }
            }
        }
        return ['compte' => $compte, 'mouvements' => $mouvements];
    }

    private static function balise(string $xml, string $nom): ?string
    {
        return preg_match('/<(?:\w+:)?' . $nom . '\b[^>]*>([\s\S]*?)<\/(?:\w+:)?' . $nom . '>/', $xml, $m) === 1 ? $m[1] : null;
    }

    /** @return list<string> */
    private static function toutes(string $xml, string $nom): array
    {
        preg_match_all('/<(?:\w+:)?' . $nom . '\b[^>]*>([\s\S]*?)<\/(?:\w+:)?' . $nom . '>/', $xml, $m);
        return $m[1];
    }

    private static function decode(?string $s): string
    {
        return html_entity_decode((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function lireCamt(string $contenu): array
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $contenu) === 1) {
            throw new \RuntimeException('doctype');
        }
        $iban = self::propre(self::decode(self::balise($contenu, 'IBAN')));
        $mouvements = [];
        foreach (self::toutes($contenu, 'Ntry') as $entree) {
            if (preg_match('/<(?:\w+:)?Amt\b[^>]*>([^<]+)</', $entree, $m) !== 1) {
                continue;
            }
            $sens = self::propre(self::balise($entree, 'CdtDbtInd'));
            $reservation = self::balise($entree, 'BookgDt') ?? '';
            $date = substr(self::propre(self::balise($reservation, 'Dt') ?? self::balise($reservation, 'DtTm') ?? ''), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !is_numeric(trim($m[1]))) {
                continue;
            }
            $montant = (float) trim($m[1]) * ($sens === 'DBIT' ? -1 : 1);
            $valeur = substr(self::propre(self::balise(self::balise($entree, 'ValDt') ?? '', 'Dt') ?? ''), 0, 10);
            $ustrd = self::toutes($entree, 'Ustrd');
            $libelle = self::propre(self::decode($ustrd !== [] ? implode(' ', $ustrd)
                : (self::balise($entree, 'AddtlNtryInf') ?? self::balise($entree, 'Nm') ?? '')));
            $reference = self::propre(self::decode(self::balise($entree, 'AcctSvcrRef') ?? self::balise($entree, 'EndToEndId') ?? ''));
            $mouvements[] = [
                'date' => $date,
                'dateValeur' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur) === 1 ? $valeur : $date,
                'libelle' => $libelle !== '' ? $libelle : ($montant < 0 ? 'Débit' : 'Crédit'),
                'montant' => $montant,
                'reference' => $reference,
                'empreinte' => self::empreinte('camt', $reference, $date, number_format($montant, 2, '.', ''), $libelle),
            ];
        }
        return ['compte' => $iban !== '' ? $iban : null, 'mouvements' => $mouvements];
    }

    private static function champOfx(string $bloc, string $nom): string
    {
        return preg_match('/<' . $nom . '>([^<\r\n]*)/i', $bloc, $m) === 1 ? self::decode(trim($m[1])) : '';
    }

    private static function lireOfx(string $contenu): array
    {
        $compte = self::champOfx($contenu, 'ACCTID');
        $mouvements = [];
        foreach (array_slice(preg_split('/<STMTTRN>/i', $contenu) ?: [], 1) as $bloc) {
            $fin = stripos($bloc, '</STMTTRN>');
            $t = $fin !== false ? substr($bloc, 0, $fin) : $bloc;
            $brut = self::champOfx($t, 'DTPOSTED');
            $texteMontant = str_replace(',', '.', self::champOfx($t, 'TRNAMT'));
            if (preg_match('/^\d{8}/', $brut) !== 1 || !is_numeric($texteMontant)) {
                continue;
            }
            $montant = (float) $texteMontant;
            $date = substr($brut, 0, 4) . '-' . substr($brut, 4, 2) . '-' . substr($brut, 6, 2);
            $fitid = self::champOfx($t, 'FITID');
            $libelle = self::propre(implode(' — ', array_filter([self::champOfx($t, 'NAME'), self::champOfx($t, 'MEMO')])));
            $mouvements[] = [
                'date' => $date,
                'dateValeur' => $date,
                'libelle' => $libelle !== '' ? $libelle : ($montant < 0 ? 'Débit' : 'Crédit'),
                'montant' => $montant,
                'reference' => $fitid,
                'empreinte' => self::empreinte('ofx', $compte, $fitid !== '' ? $fitid : "$date|$montant|$libelle"),
            ];
        }
        return ['compte' => $compte !== '' ? $compte : null, 'mouvements' => $mouvements];
    }

    /**
     * Lire un relevé. Rend ['ok' => true, 'format', 'compte', 'mouvements'] ou
     * ['ok' => false, 'reason'].
     */
    public static function lire(string $octets): array
    {
        if ($octets === '') {
            return ['ok' => false, 'reason' => 'empty'];
        }
        $contenu = self::texte($octets);
        $format = self::formatDe($contenu);
        if ($format === null) {
            return ['ok' => false, 'reason' => 'format'];
        }
        try {
            $lu = match ($format) {
                'cfonb120' => self::lireCfonb($contenu),
                'camt053' => self::lireCamt($contenu),
                default => self::lireOfx($contenu),
            };
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'format'];
        }
        if ($lu['mouvements'] === []) {
            return ['ok' => false, 'reason' => 'empty'];
        }
        if (count($lu['mouvements']) > self::LIMITE) {
            return ['ok' => false, 'reason' => 'too-many'];
        }
        foreach ($lu['mouvements'] as &$m) {
            $m['montant'] = round($m['montant'], 2);
            $m['libelle'] = mb_substr($m['libelle'], 0, 160);
            $m['reference'] = mb_substr($m['reference'], 0, 60);
        }
        unset($m);
        return ['ok' => true, 'format' => $format, 'compte' => $lu['compte'], 'mouvements' => $lu['mouvements']];
    }

    /** Les quatre derniers caractères d'un identifiant de compte, pour le comparer à ce qui est enregistré. */
    public static function quatreDerniers(?string $compte): string
    {
        return strtoupper(substr((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $compte), -4));
    }
}
