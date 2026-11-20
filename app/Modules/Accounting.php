<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Comptabilité en partie double.
 *
 * Une écriture n'est enregistrée que si elle est équilibrée : c'est la règle
 * qui fait la différence entre une comptabilité et une liste de montants. Tout
 * le reste — balance, grand livre, résultat — se déduit des écritures, et rien
 * n'est tenu à la main en double.
 *
 * Tenue de comptes interne : ni liasse fiscale, ni télétransmission. L'export
 * de la balance alimente l'expert-comptable.
 */
final class Accounting
{
    public const ACCOUNT_KINDS = ['Actif', 'Passif', 'Charge', 'Produit'];

    /** Le périmètre courant : le plan, les journaux et les écritures sont par société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    // Un plan et des journaux minimaux, posés à l'activation du module pour que
    // l'écran ne s'ouvre pas sur une page vide. Tout reste modifiable ensuite.
    private const DEFAULT_ACCOUNTS = [
        ['401', 'Fournisseurs', 'Passif'],
        ['411', 'Clients', 'Actif'],
        ['4456', 'TVA déductible', 'Actif'],
        ['4457', 'TVA collectée', 'Passif'],
        ['512', 'Banque', 'Actif'],
        ['606', 'Achats non stockés', 'Charge'],
        ['613', 'Locations', 'Charge'],
        ['641', 'Rémunérations du personnel', 'Charge'],
        ['645', 'Charges de sécurité sociale', 'Charge'],
        ['706', 'Prestations de services', 'Produit'],
    ];

    private const DEFAULT_JOURNALS = [
        ['VE', 'Ventes'],
        ['AC', 'Achats'],
        ['BQ', 'Banque'],
        ['OD', 'Opérations diverses'],
    ];

    /** Idempotent : réactiver le module ne duplique pas le plan. */
    public static function seedDefaults(): void
    {
        Db::transaction(static function (): void {
            /* Par société : chaque entité tient son plan, et activer le module
               dans la seconde ne doit pas la laisser devant une page vide parce
               que la première a déjà posé le sien. */
            foreach (self::DEFAULT_ACCOUNTS as $row) {
                Db::run(
                    'INSERT OR IGNORE INTO accounts (company_id, code, label, kind) VALUES (?, ?, ?, ?)',
                    [self::ici(), ...$row]
                );
            }
            foreach (self::DEFAULT_JOURNALS as $row) {
                Db::run(
                    'INSERT OR IGNORE INTO journals (company_id, code, label) VALUES (?, ?, ?)',
                    [self::ici(), ...$row]
                );
            }
        });
    }

    // ---------- Plan comptable ----------

    public static function accounts(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'AND active = 1' : '';
        return Db::all("SELECT * FROM accounts WHERE company_id = ? $where ORDER BY code", [self::ici()]);
    }

    public static function accountByCode(string $code): ?array
    {
        return Db::get('SELECT * FROM accounts WHERE code = ? AND company_id = ?', [$code, self::ici()]);
    }

    public static function createAccount(string $code, string $label, string $kind): array
    {
        if (!in_array($kind, self::ACCOUNT_KINDS, true)) {
            return ['ok' => false, 'reason' => 'bad-kind'];
        }
        if (self::accountByCode($code) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        Db::insert(
            'INSERT INTO accounts (company_id, code, label, kind) VALUES (?, ?, ?, ?)',
            [self::ici(), $code, $label, $kind]
        );
        return ['ok' => true];
    }

    /** Un compte mouvementé n'est pas supprimable : il est désactivé. */
    public static function deleteAccount(int $id): array
    {
        if (Db::get('SELECT id FROM accounts WHERE id = ? AND company_id = ?', [$id, self::ici()]) === null) {
            return ['ok' => false, 'deactivated' => false];
        }

        if (Db::get('SELECT 1 AS ok FROM entry_lines WHERE account_id = ? LIMIT 1', [$id]) !== null) {
            Db::run('UPDATE accounts SET active = 0 WHERE id = ? AND company_id = ?', [$id, self::ici()]);
            return ['ok' => true, 'deactivated' => true];
        }
        Db::run('DELETE FROM accounts WHERE id = ? AND company_id = ?', [$id, self::ici()]);
        return ['ok' => true, 'deactivated' => false];
    }

    public static function journals(): array
    {
        return Db::all('SELECT * FROM journals WHERE company_id = ? ORDER BY code', [self::ici()]);
    }

    public static function createJournal(string $code, string $label): array
    {
        if (Db::get('SELECT 1 AS ok FROM journals WHERE code = ? AND company_id = ?', [$code, self::ici()]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }
        Db::insert('INSERT INTO journals (company_id, code, label) VALUES (?, ?, ?)', [self::ici(), $code, $label]);
        return ['ok' => true];
    }

    // ---------- Écritures ----------

    public static function entries(int $limit = 200): array
    {
        return Db::all(
            'SELECT e.*, j.code AS journal_code, j.label AS journal_label,
                (SELECT r.id FROM entries r WHERE r.reverses_id = e.id LIMIT 1) AS reversed_by,
                (SELECT COALESCE(SUM(l.debit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_debit,
                (SELECT COALESCE(SUM(l.credit), 0) FROM entry_lines l WHERE l.entry_id = e.id) AS total_credit
             FROM entries e JOIN journals j ON j.id = e.journal_id
             WHERE e.company_id = ?
             ORDER BY e.entry_date DESC, e.id DESC
             LIMIT ?',
            [self::ici(), $limit]
        );
    }

    public static function linesOf(int $entryId): array
    {
        return Db::all(
            'SELECT l.*, a.code, a.label AS account_label
             FROM entry_lines l JOIN accounts a ON a.id = l.account_id
             JOIN entries e ON e.id = l.entry_id
             WHERE l.entry_id = ? AND e.company_id = ? ORDER BY l.id',
            [$entryId, self::ici()]
        );
    }

    /**
     * Une écriture n'est enregistrée que si elle est équilibrée. Les lignes
     * vides sont écartées avant le contrôle : un formulaire à dix lignes dont
     * trois sont servies reste une écriture à trois lignes.
     */
    public static function createEntry(array $data): array
    {
        $journalId = (int) $data['journalId'];
        if (Db::get('SELECT 1 AS ok FROM journals WHERE id = ? AND company_id = ?', [$journalId, self::ici()]) === null) {
            return ['ok' => false, 'reason' => 'no-journal'];
        }
        // Une période validée est close : on n'y écrit plus, pas même pour
        // corriger. La correction se passe à une date ouverte, par contrepassation.
        $verrou = self::lockedUntil();
        if ($verrou !== null && (string) $data['entryDate'] <= $verrou) {
            return ['ok' => false, 'reason' => 'locked', 'lockedUntil' => $verrou];
        }

        $clean = [];
        foreach ($data['lines'] as $line) {
            $entry = [
                'accountId' => (int) ($line['accountId'] ?? 0),
                'label' => mb_substr((string) ($line['label'] ?? ''), 0, 160),
                'debit' => round((float) ($line['debit'] ?? 0), 2),
                'credit' => round((float) ($line['credit'] ?? 0), 2),
            ];
            if ($entry['accountId'] !== 0 && ($entry['debit'] > 0 || $entry['credit'] > 0)) {
                $clean[] = $entry;
            }
        }

        if (count($clean) < 2) {
            return ['ok' => false, 'reason' => 'too-few-lines'];
        }
        foreach ($clean as $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                return ['ok' => false, 'reason' => 'both-sides'];
            }
            if ($line['debit'] < 0 || $line['credit'] < 0) {
                return ['ok' => false, 'reason' => 'negative'];
            }
        }

        $known = array_map(static fn (array $a): int => (int) $a['id'], self::accounts());
        foreach ($clean as $line) {
            if (!in_array($line['accountId'], $known, true)) {
                return ['ok' => false, 'reason' => 'no-account'];
            }
        }

        $totalDebit = round(array_sum(array_column($clean, 'debit')), 2);
        $totalCredit = round(array_sum(array_column($clean, 'credit')), 2);
        if ($totalDebit !== $totalCredit) {
            return ['ok' => false, 'reason' => 'unbalanced', 'totalDebit' => $totalDebit, 'totalCredit' => $totalCredit];
        }
        if ($totalDebit === 0.0) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        $societe = self::ici();
        $id = Db::transaction(static function () use ($data, $journalId, $clean, $societe): int {
            $entryId = Db::insert(
                'INSERT INTO entries (company_id, journal_id, entry_date, reference, label, invoice_id, created_by, reverses_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $societe, $journalId, $data['entryDate'], $data['reference'] ?? '', $data['label'],
                    $data['invoiceId'] ?? null, $data['createdBy'] ?? null, $data['reversesId'] ?? null,
                ]
            );
            foreach ($clean as $line) {
                Db::insert(
                    'INSERT INTO entry_lines (entry_id, account_id, label, debit, credit) VALUES (?, ?, ?, ?, ?)',
                    [$entryId, $line['accountId'], $line['label'], $line['debit'], $line['credit']]
                );
            }
            return $entryId;
        });
        return ['ok' => true, 'id' => $id];
    }

    /**
     * Supprimer une écriture du brouillard.
     *
     * Une écriture validée ne se supprime pas : c'est ce qui fait d'elle une
     * pièce comptable (article 420-5 du plan comptable général). Elle s'annule
     * par une contrepassation, qui laisse la trace des deux.
     *
     * @return array{ok: bool, reason?: string}
     */
    public static function deleteEntry(int $id): array
    {
        $entree = self::entryById($id);
        if ($entree === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($entree['validated_at'] !== null) {
            return ['ok' => false, 'reason' => 'validated'];
        }
        // Une ligne lettrée tient un lettrage équilibré : la supprimer le casserait
        // sans un mot. On délettre d'abord, en le voyant.
        if (Db::get("SELECT 1 AS ok FROM entry_lines WHERE entry_id = ? AND reconciliation <> ''", [$id]) !== null) {
            return ['ok' => false, 'reason' => 'lettered'];
        }
        Db::run('DELETE FROM entries WHERE id = ? AND company_id = ? AND validated_at IS NULL', [$id, self::ici()]);
        return ['ok' => true];
    }

    public static function entryById(int $id): ?array
    {
        return Db::get('SELECT * FROM entries WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    /** La date jusqu'à laquelle la comptabilité de la société courante est validée, ou null. */
    public static function lockedUntil(): ?string
    {
        $valeur = Db::value('SELECT locked_until FROM companies WHERE id = ?', [self::ici()]);
        return $valeur === null || $valeur === '' ? null : (string) $valeur;
    }

    /** Les écritures du brouillard, toutes dates. */
    public static function draftCount(): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM entries WHERE company_id = ? AND validated_at IS NULL', [self::ici()]);
    }

    /**
     * Contrepasser une écriture validée : la même, débits et crédits inversés,
     * à une date ouverte. Elle entre au brouillard, et se valide à son tour.
     */
    public static function reverseEntry(int $id, string $entryDate, ?int $createdBy = null): array
    {
        $entree = self::entryById($id);
        if ($entree === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($entree['validated_at'] === null) {
            return ['ok' => false, 'reason' => 'draft'];
        }
        if (Db::get('SELECT 1 AS ok FROM entries WHERE reverses_id = ? AND company_id = ?', [$id, self::ici()]) !== null) {
            return ['ok' => false, 'reason' => 'already-reversed'];
        }
        $lignes = Db::all('SELECT * FROM entry_lines WHERE entry_id = ? ORDER BY id', [$id]);
        return self::createEntry([
            'journalId' => (int) $entree['journal_id'],
            'entryDate' => $entryDate,
            'reference' => (string) $entree['reference'],
            'label' => mb_substr('Contrepassation n° ' . $entree['number'] . ' — ' . $entree['label'], 0, 160),
            'lines' => array_map(static fn (array $l): array => [
                'accountId' => (int) $l['account_id'], 'label' => (string) $l['label'],
                'debit' => (float) $l['credit'], 'credit' => (float) $l['debit'],
            ], $lignes),
            'createdBy' => $createdBy,
            'reversesId' => $id,
        ]);
    }

    /**
     * Valider le brouillard jusqu'à une date, et verrouiller la période.
     *
     * Les écritures du brouillard datées au plus tard ce jour-là reçoivent leur
     * numéro, dans l'ordre chronologique puis de saisie, à la suite du dernier
     * numéro de la société : une numérotation continue, sans trou, est ce que la
     * loi demande (article A47 A-1 du LPF) et ce que le FEC restitue. Ensuite,
     * plus rien ne s'écrit à ces dates-là. Une date à venir est refusée.
     */
    public static function validateUntil(string $date, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        if ($date > $today) {
            return ['ok' => false, 'reason' => 'future'];
        }
        $verrou = self::lockedUntil();
        if ($verrou !== null && $date < $verrou) {
            return ['ok' => false, 'reason' => 'before-lock', 'lockedUntil' => $verrou];
        }
        $societe = self::ici();
        $combien = Db::transaction(static function () use ($date, $societe): int {
            // Les à-nouveaux d'abord, à date égale : ils ouvrent l'exercice, et
            // le FEC doit les restituer en tête.
            $brouillard = Db::all(
                "SELECT e.id FROM entries e JOIN journals j ON j.id = e.journal_id
                 WHERE e.company_id = ? AND e.validated_at IS NULL AND e.entry_date <= ?
                 ORDER BY e.entry_date, CASE WHEN j.code = 'AN' THEN 0 ELSE 1 END, e.id",
                [$societe, $date]
            );
            $suivant = (int) Db::value('SELECT COALESCE(MAX(number), 0) FROM entries WHERE company_id = ?', [$societe]) + 1;
            $maintenant = gmdate('Y-m-d H:i:s');
            foreach ($brouillard as $ligne) {
                Db::run(
                    'UPDATE entries SET number = ?, validated_at = ? WHERE id = ? AND company_id = ? AND validated_at IS NULL',
                    [$suivant++, $maintenant, (int) $ligne['id'], $societe]
                );
            }
            Db::run('UPDATE companies SET locked_until = ? WHERE id = ?', [$date, $societe]);
            return count($brouillard);
        });
        return ['ok' => true, 'validated' => $combien, 'lockedUntil' => $date];
    }

    /**
     * Passe une facture en écriture : la vente débite le client et crédite le
     * produit et la TVA collectée ; l'achat fait l'inverse.
     */
    public static function entryFromInvoice(array $invoice, ?int $createdBy = null): array
    {
        if (Db::get(
            'SELECT 1 AS ok FROM entries WHERE invoice_id = ? AND company_id = ?',
            [$invoice['id'], self::ici()]
        ) !== null) {
            return ['ok' => false, 'reason' => 'already-posted'];
        }

        $isSale = $invoice['direction'] === 'Client';
        $journal = Db::get(
            'SELECT id FROM journals WHERE code = ? AND company_id = ?',
            [$isSale ? 'VE' : 'AC', self::ici()]
        );
        if ($journal === null) {
            return ['ok' => false, 'reason' => 'no-journal'];
        }

        // Une écriture se passe dans la devise de tenue des comptes : le montant
        // est converti au taux figé sur la facture, pas au taux du jour.
        $ht = round((float) $invoice['amount_ht'] * ((float) $invoice['exchange_rate'] ?: 1), 2);
        $vat = round($ht * ((float) $invoice['vat_rate'] / 100), 2);
        $ttc = round($ht + $vat, 2);

        $codes = $isSale
            ? ['third' => '411', 'income' => '706', 'vat' => '4457']
            : ['third' => '401', 'income' => '606', 'vat' => '4456'];

        $third = self::accountByCode($codes['third']);
        $result = self::accountByCode($codes['income']);
        $vatAccount = self::accountByCode($codes['vat']);
        if ($third === null || $result === null || $vatAccount === null) {
            return ['ok' => false, 'reason' => 'missing-accounts'];
        }

        $lines = $isSale
            ? [
                ['accountId' => $third['id'], 'label' => $invoice['label'], 'debit' => $ttc, 'credit' => 0],
                ['accountId' => $result['id'], 'label' => $invoice['label'], 'debit' => 0, 'credit' => $ht],
                ['accountId' => $vatAccount['id'], 'label' => 'TVA', 'debit' => 0, 'credit' => $vat],
            ]
            : [
                ['accountId' => $result['id'], 'label' => $invoice['label'], 'debit' => $ht, 'credit' => 0],
                ['accountId' => $vatAccount['id'], 'label' => 'TVA', 'debit' => $vat, 'credit' => 0],
                ['accountId' => $third['id'], 'label' => $invoice['label'], 'debit' => 0, 'credit' => $ttc],
            ];

        return self::createEntry([
            'journalId' => (int) $journal['id'],
            'entryDate' => $invoice['issue_date'],
            'reference' => $invoice['reference'],
            'label' => $invoice['label'],
            'invoiceId' => (int) $invoice['id'],
            'lines' => $lines,
            'createdBy' => $createdBy,
        ]);
    }

    // ---------- À-nouveaux ----------

    /*
     * L'exercice suit l'année civile. Le passer, c'est reporter au 1er janvier
     * les soldes des comptes de bilan, dans une écriture d'à-nouveaux (journal
     * « AN ») : sans elle, la banque et les tiers repartiraient de zéro.
     *
     * - les charges et les produits ne se reportent pas : leur solde fait le
     *   résultat, porté en 120 (bénéfice) ou 129 (perte) ;
     * - le résultat d'avant, resté en 12, et le report à nouveau (11) passent
     *   ensemble en 110 ou 119 : l'affectation se décide en assemblée, puis se
     *   passe en opérations diverses ;
     * - l'exercice qui se termine doit être validé jusqu'au 31 décembre.
     *
     * L'écriture entre au brouillard, se relit, et se valide en tête des
     * écritures du 1er janvier.
     */
    private const COMPTES_DE_CLOTURE = [
        '110' => ['Report à nouveau (solde créditeur)', 'Passif'],
        '119' => ['Report à nouveau (solde débiteur)', 'Passif'],
        '120' => ["Résultat de l'exercice (bénéfice)", 'Passif'],
        '129' => ["Résultat de l'exercice (perte)", 'Passif'],
    ];

    private static function compteDeCloture(string $code): int
    {
        if (self::accountByCode($code) === null) {
            self::createAccount($code, self::COMPTES_DE_CLOTURE[$code][0], self::COMPTES_DE_CLOTURE[$code][1]);
        }
        return (int) self::accountByCode($code)['id'];
    }

    /** L'écriture d'à-nouveaux d'une année, si elle existe. */
    public static function aNouveaux(int $annee): ?array
    {
        return Db::get(
            "SELECT e.* FROM entries e JOIN journals j ON j.id = e.journal_id
             WHERE e.company_id = ? AND j.code = 'AN' AND e.entry_date = ? ORDER BY e.id LIMIT 1",
            [self::ici(), sprintf('%04d-01-01', $annee)]
        );
    }

    public static function reporterANouveaux(int $annee, ?int $createdBy = null): array
    {
        if ($annee < 1900 || $annee > 9999) {
            return ['ok' => false, 'reason' => 'bad-year'];
        }
        $debut = sprintf('%04d-01-01', $annee);
        $fin = sprintf('%04d-12-31', $annee - 1);
        $verrou = self::lockedUntil();
        if ($verrou === null || $verrou < $fin) {
            return ['ok' => false, 'reason' => 'not-closed', 'fin' => $fin];
        }
        if ($verrou >= $debut) {
            return ['ok' => false, 'reason' => 'locked', 'lockedUntil' => $verrou];
        }
        if (self::aNouveaux($annee) !== null) {
            return ['ok' => false, 'reason' => 'already'];
        }

        // La fenêtre : depuis les derniers à-nouveaux, qui portent tout ce qui
        // précède, ou depuis le début s'il n'y en a jamais eu.
        $precedent = Db::value(
            "SELECT MAX(e.entry_date) FROM entries e JOIN journals j ON j.id = e.journal_id
             WHERE e.company_id = ? AND j.code = 'AN' AND e.entry_date < ?",
            [self::ici(), $debut]
        );
        $depuis = $precedent !== null && $precedent !== false ? (string) $precedent : null;

        $soldes = array_values(array_filter(self::balance($depuis, $fin), static fn (array $r): bool => in_array($r['kind'], ['Actif', 'Passif'], true)));
        $report = 0.0;
        $lignes = [];
        foreach ($soldes as $r) {
            $solde = (float) $r['balance'];
            if (preg_match('/^1[12]/', (string) $r['code']) === 1) {
                $report += $solde;
                continue;
            }
            if ($solde != 0.0) {
                $lignes[] = ['accountId' => (int) $r['id'], 'label' => (string) $r['label'],
                    'debit' => $solde > 0 ? $solde : 0, 'credit' => $solde < 0 ? -$solde : 0];
            }
        }
        $report = round($report, 2);
        $resultat = (float) self::income($depuis, $fin)['result'];
        if ($report != 0.0) {
            $lignes[] = ['accountId' => self::compteDeCloture($report < 0 ? '110' : '119'), 'label' => 'Report à nouveau',
                'debit' => $report > 0 ? $report : 0, 'credit' => $report < 0 ? -$report : 0];
        }
        if ($resultat != 0.0) {
            $lignes[] = ['accountId' => self::compteDeCloture($resultat > 0 ? '120' : '129'), 'label' => 'Résultat ' . ($annee - 1),
                'debit' => $resultat < 0 ? -$resultat : 0, 'credit' => $resultat > 0 ? $resultat : 0];
        }
        if (count($lignes) < 2) {
            return ['ok' => false, 'reason' => 'empty'];
        }

        if (Db::get("SELECT 1 AS ok FROM journals WHERE code = 'AN' AND company_id = ?", [self::ici()]) === null) {
            self::createJournal('AN', 'À-nouveaux');
        }
        $journal = (int) Db::value("SELECT id FROM journals WHERE code = 'AN' AND company_id = ?", [self::ici()]);
        $result = self::createEntry([
            'journalId' => $journal, 'entryDate' => $debut, 'reference' => "AN-$annee",
            'label' => "À-nouveaux de l'exercice $annee", 'lines' => $lignes, 'createdBy' => $createdBy,
        ]);
        return $result['ok'] ? ['ok' => true, 'id' => $result['id'], 'lignes' => count($lignes), 'resultat' => $resultat] : $result;
    }

    // ---------- Lettrage ----------

    /*
     * Lettrer, c'est rapprocher sur un compte de tiers les lignes qui se
     * soldent : la facture d'un client et son règlement, deux acomptes et la
     * facture qu'ils couvrent. Les lignes rapprochées reçoivent le même code
     * (A, B… Z, AA…), et ce qui reste non lettré est ce qui reste dû.
     *
     * Le code et sa date sont ceux que le FEC restitue (EcritureLet, DateLet).
     * Le lettrage ne touche ni aux montants ni aux comptes : il se pose et se
     * retire même sur une période validée.
     */

    /** Les comptes qui se lettrent : ceux de tiers, la classe 4. */
    public static function lettrables(): array
    {
        return array_values(array_filter(self::accounts(true), static fn (array $a): bool => str_starts_with((string) $a['code'], '4')));
    }

    /** Le rang d'un code — A = 1, Z = 26, AA = 27 — et l'inverse. */
    public static function rangDuCode(string $code): int
    {
        $n = 0;
        foreach (str_split($code) as $c) {
            $n = $n * 26 + (ord($c) - 64);
        }
        return $n;
    }

    public static function codeDuRang(int $rang): string
    {
        $code = '';
        for ($n = $rang; $n > 0; $n = intdiv($n - 1, 26)) {
            $code = chr(65 + (($n - 1) % 26)) . $code;
        }
        return $code;
    }

    private static function compteLettrable(int $accountId): ?array
    {
        foreach (self::lettrables() as $a) {
            if ((int) $a['id'] === $accountId) {
                return $a;
            }
        }
        return null;
    }

    /** Les lignes d'un compte, lettrées ou non, avec le tiers de la facture quand il y en a un. */
    private static function lignesDuCompte(int $accountId): array
    {
        return Db::all(
            'SELECT l.id, l.label, l.debit, l.credit, l.reconciliation, l.lettered_at,
                    e.id AS entry_id, e.entry_date, e.number, e.reference, e.label AS entry_label,
                    j.code AS journal_code, p.name AS partner_name
             FROM entry_lines l
             JOIN entries e ON e.id = l.entry_id
             JOIN journals j ON j.id = e.journal_id
             LEFT JOIN invoices i ON i.id = e.invoice_id
             LEFT JOIN partners p ON p.id = i.partner_id
             WHERE l.account_id = ? AND e.company_id = ?
             ORDER BY e.entry_date, l.id',
            [$accountId, self::ici()]
        );
    }

    /** Ce que l'écran montre : les lignes à lettrer, et les lettrages faits. */
    public static function lettrage(int $accountId): ?array
    {
        if (self::compteLettrable($accountId) === null) {
            return null;
        }
        $ouvertes = [];
        $groupes = [];
        foreach (self::lignesDuCompte($accountId) as $l) {
            $code = (string) $l['reconciliation'];
            if ($code === '') {
                $ouvertes[] = $l;
                continue;
            }
            $groupes[$code] ??= ['code' => $code, 'date' => $l['lettered_at'], 'lignes' => 0, 'debit' => 0.0, 'credit' => 0.0];
            $groupes[$code]['lignes']++;
            $groupes[$code]['debit'] = round($groupes[$code]['debit'] + (float) $l['debit'], 2);
            $groupes[$code]['credit'] = round($groupes[$code]['credit'] + (float) $l['credit'], 2);
        }
        $groupes = array_values($groupes);
        usort($groupes, static fn (array $a, array $b): int => self::rangDuCode($b['code']) <=> self::rangDuCode($a['code']));
        $solde = 0.0;
        foreach ($ouvertes as $l) {
            $solde += (float) $l['debit'] - (float) $l['credit'];
        }
        return ['ouvertes' => $ouvertes, 'solde' => round($solde, 2), 'lettrages' => $groupes];
    }

    /**
     * Lettrer une sélection de lignes : toutes du compte, toutes libres, au
     * moins deux, et qui se soldent au centime. Un lettrage partiel n'existe
     * pas ici — il mentirait sur ce qui reste dû.
     */
    public static function lettrer(int $accountId, array $lineIds, ?string $today = null): array
    {
        $today ??= gmdate('Y-m-d');
        $compte = self::compteLettrable($accountId);
        if ($compte === null) {
            return ['ok' => false, 'reason' => 'account'];
        }
        $voulues = array_values(array_unique(array_filter(array_map('intval', $lineIds))));
        $lignes = array_values(array_filter(self::lignesDuCompte($accountId), static fn (array $l): bool => in_array((int) $l['id'], $voulues, true)));
        if (count($lignes) < 2 || count($lignes) !== count($voulues)) {
            return ['ok' => false, 'reason' => 'selection'];
        }
        $ecart = 0.0;
        foreach ($lignes as $l) {
            if ((string) $l['reconciliation'] !== '') {
                return ['ok' => false, 'reason' => 'already'];
            }
            $ecart += (float) $l['debit'] - (float) $l['credit'];
        }
        $ecart = round($ecart, 2);
        if ($ecart != 0.0) {
            return ['ok' => false, 'reason' => 'unbalanced', 'ecart' => $ecart];
        }

        $code = Db::transaction(static function () use ($accountId, $lignes, $today): string {
            $codes = array_column(Db::all(
                "SELECT DISTINCT l.reconciliation AS code FROM entry_lines l JOIN entries e ON e.id = l.entry_id
                 WHERE l.account_id = ? AND e.company_id = ? AND l.reconciliation <> ''",
                [$accountId, self::ici()]
            ), 'code');
            $max = 0;
            foreach ($codes as $c) {
                $max = max($max, self::rangDuCode((string) $c));
            }
            $code = self::codeDuRang($max + 1);
            foreach ($lignes as $l) {
                Db::run("UPDATE entry_lines SET reconciliation = ?, lettered_at = ? WHERE id = ? AND account_id = ? AND reconciliation = ''",
                    [$code, $today, (int) $l['id'], $accountId]);
            }
            return $code;
        });
        return ['ok' => true, 'code' => $code, 'lignes' => count($lignes)];
    }

    /** Retirer un lettrage : ses lignes redeviennent dues. */
    public static function delettrer(int $accountId, string $code): array
    {
        if (self::compteLettrable($accountId) === null || preg_match('/^[A-Z]{1,3}$/', $code) !== 1) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        $n = Db::run(
            "UPDATE entry_lines SET reconciliation = '', lettered_at = NULL
             WHERE account_id = ? AND reconciliation = ?
               AND entry_id IN (SELECT id FROM entries WHERE company_id = ?)",
            [$accountId, $code, self::ici()]
        );
        return $n > 0 ? ['ok' => true, 'lignes' => $n] : ['ok' => false, 'reason' => 'not-found'];
    }

    // ---------- Restitutions ----------

    /** Balance générale : un solde par compte mouvementé, sur une période. */
    public static function balance(?string $from = null, ?string $to = null): array
    {
        $clauses = [];
        $params = [];
        if ($from !== null) {
            $clauses[] = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'e.entry_date <= ?';
            $params[] = $to;
        }
        $where = $clauses === [] ? '' : 'AND ' . implode(' AND ', $clauses);
        array_unshift($params, self::ici());

        $rows = Db::all(
            "SELECT a.id, a.code, a.label, a.kind,
                COALESCE(SUM(l.debit), 0) AS debit,
                COALESCE(SUM(l.credit), 0) AS credit
             FROM accounts a
             JOIN entry_lines l ON l.account_id = a.id
             JOIN entries e ON e.id = l.entry_id
             WHERE a.company_id = ? $where
             GROUP BY a.id
             ORDER BY a.code",
            $params
        );
        return array_map(static function (array $row): array {
            $row['debit'] = round((float) $row['debit'], 2);
            $row['credit'] = round((float) $row['credit'], 2);
            $row['balance'] = round($row['debit'] - $row['credit'], 2);
            return $row;
        }, $rows);
    }

    /** Grand livre d'un compte : le détail qui explique son solde. */
    public static function ledger(int $accountId, ?string $from = null, ?string $to = null): array
    {
        // Le périmètre se pose ici, et non à l'appel : un numéro de compte venu
        // de l'adresse ne doit pas ouvrir le grand livre d'une autre société.
        $clauses = ['l.account_id = ?', 'e.company_id = ?'];
        $params = [$accountId, self::ici()];
        if ($from !== null) {
            $clauses[] = 'e.entry_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'e.entry_date <= ?';
            $params[] = $to;
        }

        $rows = Db::all(
            'SELECT l.*, e.entry_date, e.label AS entry_label, e.reference, j.code AS journal_code
             FROM entry_lines l
             JOIN entries e ON e.id = l.entry_id
             JOIN journals j ON j.id = e.journal_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY e.entry_date, l.id',
            $params
        );

        $running = 0.0;
        return array_map(static function (array $row) use (&$running): array {
            $running = round($running + (float) $row['debit'] - (float) $row['credit'], 2);
            $row['running'] = $running;
            return $row;
        }, $rows);
    }

    /** Le résultat de l'exercice : produits moins charges. */
    public static function income(?string $from = null, ?string $to = null): array
    {
        $rows = self::balance($from, $to);
        $sum = static function (string $kind) use ($rows): float {
            $total = 0.0;
            foreach ($rows as $row) {
                if ($row['kind'] !== $kind) {
                    continue;
                }
                $total += $kind === 'Produit'
                    ? $row['credit'] - $row['debit']
                    : $row['debit'] - $row['credit'];
            }
            return round($total, 2);
        };
        $revenue = $sum('Produit');
        $expenses = $sum('Charge');
        return ['revenue' => $revenue, 'expenses' => $expenses, 'result' => round($revenue - $expenses, 2)];
    }

    /** Export CSV de la balance, pour l'expert-comptable. */
    public static function balanceCsv(?string $from = null, ?string $to = null): string
    {
        $lines = ['Compte;Libellé;Type;Débit;Crédit;Solde'];
        foreach (self::balance($from, $to) as $row) {
            $lines[] = implode(';', [
                $row['code'], str_replace(';', ',', (string) $row['label']), $row['kind'],
                number_format($row['debit'], 2, '.', ''),
                number_format($row['credit'], 2, '.', ''),
                number_format($row['balance'], 2, '.', ''),
            ]);
        }
        return implode("\n", $lines);
    }
}
