<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Les paiements SEPA : virements aux fournisseurs (pain.001.001.03),
 * prélèvements des clients (pain.008.001.02, schéma CORE) — le même module que
 * l'édition Node (src/sepa.js).
 *
 * Le fichier se dépose dans l'espace de la banque ; rien n'est marqué payé à
 * sa production : c'est le relevé, importé puis rapproché, qui le dit.
 */
final class Sepa
{
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    // ------------------------------------------------------------ contrôles

    private static function mod97(string $chaine): int
    {
        $reste = 0;
        foreach (str_split($chaine) as $car) {
            $valeur = ctype_alpha($car) ? (string) (ord($car) - 55) : $car;
            foreach (str_split($valeur) as $chiffre) {
                $reste = ($reste * 10 + (int) $chiffre) % 97;
            }
        }
        return $reste;
    }

    public static function compacter(?string $v): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', (string) $v));
    }

    public static function ibanValide(?string $iban): bool
    {
        $v = self::compacter($iban);
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $v) === 1 && self::mod97(substr($v, 4) . substr($v, 0, 4)) === 1;
    }

    public static function bicValide(?string $bic): bool
    {
        return preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', self::compacter($bic)) === 1;
    }

    /** L'ICS : la clé se calcule comme celle d'un IBAN, le code d'activité (positions 5 à 7) écarté. */
    public static function icsValide(?string $ics): bool
    {
        $v = self::compacter($ics);
        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{3}[A-Z0-9]{1,28}$/', $v) === 1 && self::mod97(substr($v, 7) . substr($v, 0, 4)) === 1;
    }

    public static function rumValide(?string $rum): bool
    {
        $v = trim((string) $rum);
        return preg_match("/^[A-Za-z0-9+?\\/\\-:().,' ]{1,35}$/", $v) === 1 && !str_starts_with($v, '/') && !str_contains($v, '//');
    }

    /** Ramène un libellé au jeu de caractères SEPA. */
    public static function sepaTexte(?string $valeur, int $longueur): string
    {
        $v = str_replace(['’', '‘', '«', '»', '“', '”', '–', '—', '&', 'Œ', 'œ', 'Æ', 'æ', 'ß'],
            ["'", "'", '', '', '', '', '-', '-', '+', 'OE', 'oe', 'AE', 'ae', 'ss'], (string) $valeur);
        if (class_exists(\Normalizer::class)) {
            $v = (string) preg_replace('/\p{Mn}/u', '', (string) \Normalizer::normalize($v, \Normalizer::FORM_D));
        } else {
            $v = (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v);
        }
        $v = (string) preg_replace("/[^A-Za-z0-9\\/\\-?:().,'+ ]/", ' ', $v);
        $v = trim((string) preg_replace('/\s+/', ' ', $v));
        return trim(substr($v, 0, $longueur));
    }

    private static function xml(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function montant(float $n): string
    {
        return number_format(round($n, 2), 2, '.', '');
    }

    private static function identifiant(string $prefixe): string
    {
        return $prefixe . '-' . gmdate('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
    }

    private static function agent(?string $bic): string
    {
        return self::bicValide($bic)
            ? '<FinInstnId><BIC>' . self::compacter($bic) . '</BIC></FinInstnId>'
            : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
    }

    // --------------------------------------------------------- coordonnées

    public static function societe(): ?array
    {
        return Db::get('SELECT * FROM companies WHERE id = ?', [self::ici()]);
    }

    /** @return list<string> */
    public static function manquesSociete(bool $prelevement = false): array
    {
        $s = self::societe();
        $manques = [];
        if ($s === null || !self::ibanValide((string) $s['iban'])) {
            $manques[] = 'iban';
        }
        if ($prelevement && ($s === null || !self::icsValide((string) $s['sepa_ics']))) {
            $manques[] = 'ics';
        }
        return $manques;
    }

    public static function poserCoordonneesSociete(string $iban, string $bic, string $ics): array
    {
        $v = ['iban' => self::compacter($iban), 'bic' => self::compacter($bic), 'ics' => self::compacter($ics)];
        if ($v['iban'] !== '' && !self::ibanValide($v['iban'])) {
            return ['ok' => false, 'reason' => 'iban'];
        }
        if ($v['bic'] !== '' && !self::bicValide($v['bic'])) {
            return ['ok' => false, 'reason' => 'bic'];
        }
        if ($v['ics'] !== '' && !self::icsValide($v['ics'])) {
            return ['ok' => false, 'reason' => 'ics'];
        }
        Db::run('UPDATE companies SET iban = ?, bic = ?, sepa_ics = ? WHERE id = ?', [$v['iban'], $v['bic'], $v['ics'], self::ici()]);
        return ['ok' => true];
    }

    public static function poserCoordonneesTiers(int $partnerId, string $iban, string $bic): array
    {
        $v = ['iban' => self::compacter($iban), 'bic' => self::compacter($bic)];
        if ($v['iban'] !== '' && !self::ibanValide($v['iban'])) {
            return ['ok' => false, 'reason' => 'iban'];
        }
        if ($v['bic'] !== '' && !self::bicValide($v['bic'])) {
            return ['ok' => false, 'reason' => 'bic'];
        }
        $n = Db::run('UPDATE partners SET iban = ?, bic = ? WHERE id = ? AND company_id = ?', [$v['iban'], $v['bic'], $partnerId, self::ici()]);
        return $n > 0 ? ['ok' => true] : ['ok' => false, 'reason' => 'not-found'];
    }

    // -------------------------------------------------------------- mandats

    public static function mandatsDe(int $partnerId): array
    {
        return Db::all('SELECT * FROM sepa_mandates WHERE partner_id = ? AND company_id = ? ORDER BY signed_on DESC, id DESC',
            [$partnerId, self::ici()]);
    }

    public static function rumProposee(int $partnerId): string
    {
        $base = 'RUM-' . str_pad((string) $partnerId, 5, '0', STR_PAD_LEFT) . '-' . gmdate('Ymd');
        $rum = $base;
        for ($i = 2; Db::get('SELECT 1 AS ok FROM sepa_mandates WHERE rum = ? AND company_id = ?', [$rum, self::ici()]) !== null; $i++) {
            $rum = "$base-$i";
        }
        return $rum;
    }

    public static function creerMandat(int $partnerId, string $rum, string $signedOn, string $iban, string $bic, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $tiers = Db::get('SELECT id FROM partners WHERE id = ? AND company_id = ?', [$partnerId, self::ici()]);
        if ($tiers === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        $rum = trim($rum);
        if (!self::rumValide($rum)) {
            return ['ok' => false, 'reason' => 'rum'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $signedOn) !== 1 || $signedOn > $today) {
            return ['ok' => false, 'reason' => 'date'];
        }
        if (!self::ibanValide($iban)) {
            return ['ok' => false, 'reason' => 'iban'];
        }
        if (self::compacter($bic) !== '' && !self::bicValide($bic)) {
            return ['ok' => false, 'reason' => 'bic'];
        }
        if (Db::get('SELECT 1 AS ok FROM sepa_mandates WHERE rum = ? AND company_id = ?', [$rum, self::ici()]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        $id = Db::insert(
            'INSERT INTO sepa_mandates (company_id, partner_id, rum, signed_on, iban, bic) VALUES (?, ?, ?, ?, ?, ?)',
            [self::ici(), (int) $tiers['id'], $rum, $signedOn, self::compacter($iban), self::compacter($bic)]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function revoquerMandat(int $id): bool
    {
        return Db::run("UPDATE sepa_mandates SET status = 'Révoqué' WHERE id = ? AND company_id = ? AND status = 'Actif'",
            [$id, self::ici()]) > 0;
    }

    public static function mandatParId(int $id): ?array
    {
        return Db::get('SELECT * FROM sepa_mandates WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    // -------------------------------------------------- factures éligibles

    private static function ttc(array $f): float
    {
        return round((float) $f['amount_ht'] * (1 + (float) $f['vat_rate'] / 100), 2);
    }

    public static function aVirer(): array
    {
        $rows = Db::all(
            "SELECT i.*, p.name AS partner_name, p.iban AS partner_iban, p.bic AS partner_bic
             FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
             WHERE i.company_id = ? AND i.direction = 'Fournisseur' AND i.status = 'Émise'
             ORDER BY COALESCE(i.due_date, i.issue_date), i.id",
            [self::ici()]
        );
        return array_map(static function (array $f): array {
            $f['total'] = self::ttc($f);
            $f['obstacle'] = match (true) {
                ($f['currency'] ?? 'EUR') !== 'EUR' => 'currency',
                $f['partner_id'] === null => 'no-partner',
                !self::ibanValide((string) $f['partner_iban']) => 'no-iban',
                !($f['total'] > 0) => 'amount',
                default => null,
            };
            return $f;
        }, $rows);
    }

    public static function aPrelever(): array
    {
        $rows = Db::all(
            "SELECT i.*, p.name AS partner_name,
               (SELECT m.id FROM sepa_mandates m WHERE m.partner_id = i.partner_id AND m.company_id = i.company_id
                  AND m.status = 'Actif' ORDER BY m.signed_on DESC, m.id DESC LIMIT 1) AS mandate_id
             FROM invoices i LEFT JOIN partners p ON p.id = i.partner_id
             WHERE i.company_id = ? AND i.direction = 'Client' AND i.status = 'Émise'
             ORDER BY COALESCE(i.due_date, i.issue_date), i.id",
            [self::ici()]
        );
        return array_map(static function (array $f): array {
            $f['total'] = self::ttc($f);
            $f['obstacle'] = match (true) {
                ($f['currency'] ?? 'EUR') !== 'EUR' => 'currency',
                $f['partner_id'] === null => 'no-partner',
                $f['mandate_id'] === null => 'no-mandate',
                !($f['total'] > 0) => 'amount',
                default => null,
            };
            return $f;
        }, $rows);
    }

    // ------------------------------------------------------------- fichiers

    private static function choisir(array $liste, array $ids): array
    {
        $voulus = array_map('intval', $ids);
        $retenues = array_values(array_filter($liste, static fn (array $f): bool => in_array((int) $f['id'], $voulus, true)));
        if ($retenues === []) {
            return ['ok' => false, 'reason' => 'none'];
        }
        foreach ($retenues as $f) {
            if ($f['obstacle'] !== null) {
                return ['ok' => false, 'reason' => 'blocked', 'reference' => $f['reference'] ?: $f['label'], 'obstacle' => $f['obstacle']];
            }
        }
        return ['ok' => true, 'retenues' => $retenues];
    }

    private static function enTete(string $racine, string $espace, string $msgId, int $n, float $somme, string $nom): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:' . $espace . '">' . "\n<$racine>\n"
            . '<GrpHdr><MsgId>' . self::xml($msgId) . '</MsgId><CreDtTm>' . gmdate('Y-m-d\TH:i:s') . '</CreDtTm><NbOfTxs>' . $n
            . '</NbOfTxs><CtrlSum>' . self::montant($somme) . '</CtrlSum><InitgPty><Nm>' . self::xml($nom) . '</Nm></InitgPty></GrpHdr>';
    }

    private static function finDeBout(array $f): string
    {
        $e2e = self::sepaTexte((string) ($f['reference'] ?: 'FAC-' . $f['id']), 35);
        return $e2e !== '' ? $e2e : 'FAC-' . $f['id'];
    }

    private static function motif(array $f): string
    {
        $m = self::sepaTexte(trim(($f['reference'] ?? '') . ' ' . ($f['label'] ?? '')), 140);
        return $m !== '' ? $m : 'Facture ' . $f['id'];
    }

    /** Le lot de virements d'une sélection de factures fournisseurs. */
    public static function virements(array $ids, string $date, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        if (self::manquesSociete() !== []) {
            return ['ok' => false, 'reason' => 'company'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date < $today) {
            return ['ok' => false, 'reason' => 'date'];
        }
        $choix = self::choisir(self::aVirer(), $ids);
        if (!$choix['ok']) {
            return $choix;
        }
        $s = self::societe();
        $nom = self::sepaTexte((string) $s['name'], 70);
        $total = array_sum(array_column($choix['retenues'], 'total'));
        $msgId = self::identifiant('VIR');
        $lignes = [];
        foreach ($choix['retenues'] as $i => $f) {
            $cdtrAgt = self::bicValide((string) $f['partner_bic']) ? '<CdtrAgt>' . self::agent((string) $f['partner_bic']) . "</CdtrAgt>\n" : '';
            $nomTiers = self::sepaTexte((string) $f['partner_name'], 70);
            $lignes[] = "<CdtTrfTxInf>\n<PmtId><EndToEndId>" . self::xml(self::finDeBout($f)) . "</EndToEndId></PmtId>\n"
                . '<Amt><InstdAmt Ccy="EUR">' . self::montant($f['total']) . "</InstdAmt></Amt>\n"
                . $cdtrAgt . '<Cdtr><Nm>' . self::xml($nomTiers !== '' ? $nomTiers : 'Fournisseur ' . ($i + 1)) . "</Nm></Cdtr>\n"
                . '<CdtrAcct><Id><IBAN>' . self::compacter((string) $f['partner_iban']) . "</IBAN></Id></CdtrAcct>\n"
                . '<RmtInf><Ustrd>' . self::xml(self::motif($f)) . "</Ustrd></RmtInf>\n</CdtTrfTxInf>";
        }
        $contenu = self::enTete('CstmrCdtTrfInitn', 'pain.001.001.03', $msgId, count($choix['retenues']), $total, $nom) . "\n"
            . '<PmtInf><PmtInfId>' . self::xml($msgId) . '-1</PmtInfId><PmtMtd>TRF</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>'
            . count($choix['retenues']) . '</NbOfTxs><CtrlSum>' . self::montant($total) . "</CtrlSum>\n"
            . "<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl></PmtTpInf><ReqdExctnDt>$date</ReqdExctnDt>\n"
            . '<Dbtr><Nm>' . self::xml($nom) . '</Nm></Dbtr><DbtrAcct><Id><IBAN>' . self::compacter((string) $s['iban'])
            . '</IBAN></Id></DbtrAcct><DbtrAgt>' . self::agent((string) $s['bic']) . "</DbtrAgt><ChrgBr>SLEV</ChrgBr>\n"
            . implode("\n", $lignes) . "\n</PmtInf>\n</CstmrCdtTrfInitn>\n</Document>\n";
        return ['ok' => true, 'xml' => $contenu, 'nom' => "$msgId.xml", 'nombre' => count($choix['retenues']), 'total' => round($total, 2)];
    }

    /** Le lot de prélèvements d'une sélection de factures clients — FRST pour un mandat neuf, RCUR ensuite. */
    public static function prelevements(array $ids, string $date, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        if (self::manquesSociete(true) !== []) {
            return ['ok' => false, 'reason' => 'company'];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date <= $today) {
            return ['ok' => false, 'reason' => 'date'];
        }
        $choix = self::choisir(self::aPrelever(), $ids);
        if (!$choix['ok']) {
            return $choix;
        }
        $s = self::societe();
        $nom = self::sepaTexte((string) $s['name'], 70);
        $msgId = self::identifiant('PRL');
        $groupes = ['FRST' => [], 'RCUR' => []];
        foreach ($choix['retenues'] as $f) {
            $f['mandat'] = self::mandatParId((int) $f['mandate_id']);
            $groupes[$f['mandat']['first_used_on'] === null ? 'FRST' : 'RCUR'][] = $f;
        }
        $total = array_sum(array_column($choix['retenues'], 'total'));
        $blocs = [];
        $rang = 0;
        foreach ($groupes as $sequence => $liste) {
            if ($liste === []) {
                continue;
            }
            $rang++;
            $somme = array_sum(array_column($liste, 'total'));
            $transactions = [];
            foreach ($liste as $i => $f) {
                $nomTiers = self::sepaTexte((string) $f['partner_name'], 70);
                $transactions[] = "<DrctDbtTxInf>\n<PmtId><EndToEndId>" . self::xml(self::finDeBout($f)) . "</EndToEndId></PmtId>\n"
                    . '<InstdAmt Ccy="EUR">' . self::montant($f['total']) . "</InstdAmt>\n"
                    . '<DrctDbtTx><MndtRltdInf><MndtId>' . self::xml($f['mandat']['rum']) . '</MndtId><DtOfSgntr>' . $f['mandat']['signed_on']
                    . "</DtOfSgntr></MndtRltdInf></DrctDbtTx>\n"
                    . '<DbtrAgt>' . self::agent((string) $f['mandat']['bic']) . "</DbtrAgt>\n"
                    . '<Dbtr><Nm>' . self::xml($nomTiers !== '' ? $nomTiers : 'Client ' . ($i + 1)) . "</Nm></Dbtr>\n"
                    . '<DbtrAcct><Id><IBAN>' . self::compacter((string) $f['mandat']['iban']) . "</IBAN></Id></DbtrAcct>\n"
                    . '<RmtInf><Ustrd>' . self::xml(self::motif($f)) . "</Ustrd></RmtInf>\n</DrctDbtTxInf>";
            }
            $blocs[] = '<PmtInf><PmtInfId>' . self::xml($msgId) . "-$rang</PmtInfId><PmtMtd>DD</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>"
                . count($liste) . '</NbOfTxs><CtrlSum>' . self::montant($somme) . "</CtrlSum>\n"
                . "<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><LclInstrm><Cd>CORE</Cd></LclInstrm><SeqTp>$sequence</SeqTp></PmtTpInf><ReqdColltnDt>$date</ReqdColltnDt>\n"
                . '<Cdtr><Nm>' . self::xml($nom) . '</Nm></Cdtr><CdtrAcct><Id><IBAN>' . self::compacter((string) $s['iban'])
                . '</IBAN></Id></CdtrAcct><CdtrAgt>' . self::agent((string) $s['bic']) . "</CdtrAgt><ChrgBr>SLEV</ChrgBr>\n"
                . '<CdtrSchmeId><Id><PrvtId><Othr><Id>' . self::compacter((string) $s['sepa_ics'])
                . "</Id><SchmeNm><Prtry>SEPA</Prtry></SchmeNm></Othr></PrvtId></Id></CdtrSchmeId>\n"
                . implode("\n", $transactions) . "\n</PmtInf>";
        }
        $contenu = self::enTete('CstmrDrctDbtInitn', 'pain.008.001.02', $msgId, count($choix['retenues']), $total, $nom) . "\n"
            . implode("\n", $blocs) . "\n</CstmrDrctDbtInitn>\n</Document>\n";
        foreach ($groupes['FRST'] as $f) {
            Db::run('UPDATE sepa_mandates SET first_used_on = ? WHERE id = ? AND company_id = ? AND first_used_on IS NULL',
                [$today, (int) $f['mandat']['id'], self::ici()]);
        }
        return ['ok' => true, 'xml' => $contenu, 'nom' => "$msgId.xml", 'nombre' => count($choix['retenues']), 'total' => round($total, 2)];
    }
}
