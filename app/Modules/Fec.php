<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Le fichier des écritures comptables (FEC).
 *
 * Toute entreprise qui tient sa comptabilité au moyen d'un système informatisé
 * doit pouvoir le remettre à l'administration lors d'un contrôle (article
 * L47 A du livre des procédures fiscales), au format fixé par l'article A47 A-1.
 *
 * Ce que le format impose, et que ce module tient — le même que l'édition Node :
 *
 * - dix-huit colonnes, dans cet ordre, la première ligne portant leurs noms ;
 * - la tabulation comme séparateur ;
 * - les dates en AAAAMMJJ, les montants avec une virgule décimale et sans
 *   séparateur de milliers ;
 * - l'ISO 8859-15, un jeu de caractères que l'administration lit, qui porte
 *   l'euro et les lettres françaises ;
 * - le nom « SIRENFECAAAAMMJJ », la date étant celle de clôture de la période ;
 * - seules des écritures validées, numérotées de façon continue, et
 *   équilibrées une à une.
 *
 * Une période qui contient encore des écritures au brouillard est refusée : un
 * FEC qui en tairait une partie serait faux.
 */
final class Fec
{
    public const COLONNES = [
        'JournalCode', 'JournalLib', 'EcritureNum', 'EcritureDate', 'CompteNum', 'CompteLib',
        'CompAuxNum', 'CompAuxLib', 'PieceRef', 'PieceDate', 'EcritureLib', 'Debit', 'Credit',
        'EcritureLet', 'DateLet', 'ValidDate', 'Montantdevise', 'Idevise',
    ];

    /*
     * L'ISO 8859-15 : le latin-1, où huit positions changent pour porter l'euro,
     * le « œ » et quelques lettres. Ce qui n'y entre pas est rendu par son plus
     * proche équivalent, et à défaut par un point d'interrogation.
     */
    private const LATIN9 = ['€' => 0xA4, 'Š' => 0xA6, 'š' => 0xA8, 'Ž' => 0xB4, 'ž' => 0xB8, 'Œ' => 0xBC, 'œ' => 0xBD, 'Ÿ' => 0xBE];
    private const PERDUS = [0xA4, 0xA6, 0xA8, 0xB4, 0xB8, 0xBC, 0xBD, 0xBE];
    private const PROCHES = [
        "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{201E}" => '"',
        "\u{2013}" => '-', "\u{2014}" => '-', "\u{2026}" => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ',
        'Ł' => 'L', 'ł' => 'l', 'Đ' => 'D', 'đ' => 'd', 'ı' => 'i',
    ];

    /** Le SIREN : les neuf premiers chiffres du numéro d'immatriculation de la société. */
    public static function siren(?string $immatriculation): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $immatriculation) ?? '';
        return strlen($chiffres) >= 9 ? substr($chiffres, 0, 9) : null;
    }

    public static function latin9(string $chaine): string
    {
        $sortie = '';
        foreach (mb_str_split($chaine) as $car) {
            if (isset(self::LATIN9[$car])) {
                $sortie .= chr(self::LATIN9[$car]);
                continue;
            }
            if (isset(self::PROCHES[$car])) {
                $sortie .= self::PROCHES[$car];
                continue;
            }
            $code = mb_ord($car);
            if ($code < 0x100 && !in_array($code, self::PERDUS, true)) {
                $sortie .= chr($code);
                continue;
            }
            // Une lettre accentuée hors du jeu perd son accent plutôt que de
            // devenir illisible.
            $nue = class_exists(\Normalizer::class)
                ? preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($car, \Normalizer::FORM_D))
                : $car;
            $sortie .= (mb_strlen((string) $nue) === 1 && mb_ord((string) $nue) < 0x80) ? $nue : '?';
        }
        return $sortie;
    }

    private static function date(?string $iso): string
    {
        return str_replace('-', '', substr((string) $iso, 0, 10));
    }

    private static function montant(mixed $n): string
    {
        return str_replace('.', ',', number_format(round((float) $n, 2), 2, '.', ''));
    }

    private static function texte(mixed $v): string
    {
        return trim((string) preg_replace('/[\t\r\n]+/', ' ', (string) ($v ?? '')));
    }

    /** Les écritures du brouillard sur la période. */
    public static function brouillons(string $from, string $to): int
    {
        return (int) Db::value(
            'SELECT COUNT(*) FROM entries WHERE company_id = ? AND validated_at IS NULL AND entry_date BETWEEN ? AND ?',
            [Societes::courant(), $from, $to]
        );
    }

    /**
     * Le FEC d'une période. « to » est la date de clôture, qui nomme le fichier.
     *
     * @return array{ok: bool, reason?: string, nom?: string, contenu?: string, lignes?: int, ecritures?: int, drafts?: int}
     */
    public static function generer(string $from, string $to): array
    {
        $societe = Societes::courant();
        $numero = self::siren((string) Db::value('SELECT registration FROM companies WHERE id = ?', [$societe]));
        if ($numero === null) {
            return ['ok' => false, 'reason' => 'no-siren'];
        }
        if ($from === '' || $to === '' || $from > $to) {
            return ['ok' => false, 'reason' => 'bad-period'];
        }
        $enAttente = self::brouillons($from, $to);
        if ($enAttente > 0) {
            return ['ok' => false, 'reason' => 'drafts', 'drafts' => $enAttente];
        }

        $rows = Db::all(
            'SELECT e.id AS entry_id, e.number, e.entry_date, e.reference, e.label AS entry_label,
                    e.validated_at, j.code AS journal_code, j.label AS journal_label,
                    a.code AS account_code, a.label AS account_label,
                    l.label AS line_label, l.debit, l.credit, l.reconciliation, l.lettered_at,
                    i.issue_date AS invoice_date, p.id AS partner_id, p.name AS partner_name
             FROM entries e
             JOIN journals j ON j.id = e.journal_id
             JOIN entry_lines l ON l.entry_id = e.id
             JOIN accounts a ON a.id = l.account_id
             LEFT JOIN invoices i ON i.id = e.invoice_id
             LEFT JOIN partners p ON p.id = i.partner_id
             WHERE e.company_id = ? AND e.validated_at IS NOT NULL AND e.entry_date BETWEEN ? AND ?
             ORDER BY e.number, l.id',
            [$societe, $from, $to]
        );

        $soldes = [];
        foreach ($rows as $r) {
            $id = (int) $r['entry_id'];
            $soldes[$id] = round(($soldes[$id] ?? 0) + (float) $r['debit'] - (float) $r['credit'], 2);
        }
        foreach ($soldes as $solde) {
            if ($solde != 0.0) {
                return ['ok' => false, 'reason' => 'unbalanced'];
            }
        }

        $lignes = [implode("\t", self::COLONNES)];
        foreach ($rows as $r) {
            $code = (string) $r['account_code'];
            $tiers = $r['partner_id'] !== null && preg_match('/^4(01|11)/', $code) === 1;
            $lignes[] = implode("\t", array_map([self::class, 'texte'], [
                $r['journal_code'],
                $r['journal_label'],
                (string) $r['number'],
                self::date((string) $r['entry_date']),
                $code,
                $r['account_label'],
                $tiers ? (str_starts_with($code, '411') ? 'C' : 'F') . str_pad((string) $r['partner_id'], 6, '0', STR_PAD_LEFT) : '',
                $tiers ? $r['partner_name'] : '',
                (string) $r['reference'] !== '' ? $r['reference'] : (string) $r['number'],
                self::date((string) ($r['invoice_date'] ?? $r['entry_date'])),
                (string) $r['line_label'] !== '' ? $r['line_label'] : $r['entry_label'],
                self::montant($r['debit']),
                self::montant($r['credit']),
                (string) ($r['reconciliation'] ?? ''),
                (string) ($r['reconciliation'] ?? '') !== '' && $r['lettered_at'] !== null ? self::date((string) $r['lettered_at']) : '',
                self::date((string) $r['validated_at']),
                '',
                '',
            ]));
        }

        return [
            'ok' => true,
            'nom' => $numero . 'FEC' . self::date($to) . '.txt',
            'contenu' => self::latin9(implode("\r\n", $lignes) . "\r\n"),
            'lignes' => count($lignes) - 1,
            'ecritures' => count($soldes),
        ];
    }
}
