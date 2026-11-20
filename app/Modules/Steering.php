<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Pilotage : les chiffres qu'une direction regarde, et les objectifs qu'elle
 * s'est fixés.
 *
 * Rien n'est recalculé ici de ce que les espaces savent déjà faire : ce module
 * agrège, il ne réinvente pas. Ce qui manque dans une instance (un module
 * éteint, une donnée non saisie) rend null plutôt que zéro — un zéro se lit
 * comme une information, un null comme une absence.
 */
final class Steering
{
    /** Le périmètre courant : les chiffres d'une société, pas ceux du groupe. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }
    public const SCOPES = ['Entreprise', 'Service', 'Équipe'];
    public const OBJECTIVE_STATUSES = ['En cours', 'Atteint', 'Abandonné'];

    public static function headcount(): array
    {
        $rows = Db::all(
            "SELECT contract_type, COUNT(*) AS n FROM users
             WHERE active = 1 AND role = 'employee' GROUP BY contract_type"
        );
        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['n'];
        }
        return ['total' => $total, 'byContract' => $rows];
    }

    public static function payrollMass(): ?array
    {
        $row = Db::get(
            "SELECT COALESCE(SUM(gross_salary), 0) AS total, COUNT(gross_salary) AS known
             FROM users WHERE active = 1 AND role = 'employee'"
        );
        return (int) $row['known'] === 0
            ? null
            : ['monthly' => round((float) $row['total'], 2), 'known' => (int) $row['known']];
    }

    /**
     * Les bornes d'un exercice.
     *
     * Il commence au mois réglé dans la section « Pilotage ». À janvier — le
     * cas de la plupart des sociétés françaises — c'est l'année civile, et rien
     * ne change. Ailleurs, l'exercice 2026 d'une société qui clôture en
     * septembre va d'octobre 2025 à septembre 2026 : compter son chiffre
     * d'affaires sur l'année civile lui donnerait un total qu'aucun de ses
     * documents comptables ne confirme.
     *
     * L'exercice porte le millésime de l'année où il se termine, comme il se dit.
     *
     * @return array{debut:string,fin:string}
     */
    public static function exerciceBornes(int $year): array
    {
        $mois = \App\Core\SectionsReglages::nombre('pilotage', 'exercice_mois');
        if ($mois === 1) {
            return ['debut' => "$year-01-01", 'fin' => "$year-12-31"];
        }
        return [
            'debut' => gmdate('Y-m-d', (int) gmmktime(0, 0, 0, $mois, 1, $year - 1)),
            'fin' => gmdate('Y-m-d', (int) gmmktime(0, 0, 0, $mois, 0, $year)),
        ];
    }

    public static function revenue(int $year): array
    {
        $bornes = self::exerciceBornes($year);
        $row = Db::get(
            // Chaque facture est ramenée en devise de référence par le taux figé à
            // son émission : un total qui mêle des devises ne veut rien dire.
            "SELECT
               COALESCE(SUM(CASE WHEN direction = 'Client' THEN amount_ht * exchange_rate ELSE 0 END), 0) AS sales,
               COALESCE(SUM(CASE WHEN direction = 'Fournisseur' THEN amount_ht * exchange_rate ELSE 0 END), 0) AS purchases
             FROM invoices WHERE company_id = ? AND issue_date BETWEEN ? AND ?",
            [self::ici(), $bornes['debut'], $bornes['fin']]
        );
        $sales = round((float) $row['sales'], 2);
        $purchases = round((float) $row['purchases'], 2);
        return ['sales' => $sales, 'purchases' => $purchases, 'margin' => round($sales - $purchases, 2)];
    }

    public static function unpaid(): array
    {
        $row = Db::get(
            "SELECT COUNT(*) AS n, COALESCE(SUM(amount_ht * (1 + vat_rate / 100.0) * exchange_rate), 0) AS total
             FROM invoices WHERE company_id = ? AND direction = 'Client' AND status != 'Payée'",
            [self::ici()]
        );
        return ['count' => (int) $row['n'], 'total' => round((float) $row['total'], 2)];
    }

    public static function absences(): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM hr_requests
             WHERE company_id = ? AND status = 'Approuvée'
               AND date(start_date) <= date('now') AND date(end_date) >= date('now')",
            [self::ici()]
        );
    }

    public static function projectHealth(): array
    {
        $today = gmdate('Y-m-d');
        $rows = array_map(static function (array $project) use ($today): array {
            $rate = $project['hourly_rate'];
            $budget = (float) ($project['budget_amount'] ?? 0);
            $cost = $rate === null ? null : round((float) $project['hours'] * (float) $rate, 2);
            $project['cost'] = $cost;
            $project['consumed'] = ($cost === null || $budget <= 0) ? null : (int) round($cost / $budget * 100);
            $project['late'] = !empty($project['due_date']) && $project['due_date'] < $today;
            return $project;
        }, Db::all(
            "SELECT p.id, p.name, p.budget_amount, p.hourly_rate, p.due_date, p.status,
                    (SELECT COALESCE(SUM(hours), 0) FROM project_time WHERE project_id = p.id) AS hours
             FROM projects p
             WHERE p.company_id = ? AND p.archived = 0 AND p.status NOT IN ('Livré', 'Clôturé')",
            [self::ici()]
        ));

        usort($rows, static fn (array $a, array $b): int => ($b['consumed'] ?? 0) <=> ($a['consumed'] ?? 0));
        return $rows;
    }

    public static function ticketLoad(): array
    {
        return [
            'open' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets
                 WHERE company_id = ? AND status IN ('Ouvert','En cours','En attente')",
                [self::ici()]
            ),
            'month' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets
                 WHERE company_id = ? AND created_at >= date('now', 'start of month')",
                [self::ici()]
            ),
        ];
    }

    public static function overview(?int $year = null): array
    {
        $year ??= (int) gmdate('Y');
        $hasAccounts = (int) Db::value(
            'SELECT COUNT(*) FROM bank_accounts WHERE company_id = ? AND active = 1',
            [self::ici()]
        ) > 0;
        $impayes = self::unpaid();
        /*
         * Le seuil au-delà duquel les impayés se signalent, réglé dans la
         * section « Pilotage ». À zéro il ne signale rien — un compteur
         * d'impayés qui rougit dès le premier euro ne rougit bientôt plus pour
         * personne.
         */
        $seuil = \App\Core\SectionsReglages::nombre('pilotage', 'seuil_impayes');

        return [
            'year' => $year,
            'headcount' => self::headcount(),
            'payroll' => self::payrollMass(),
            'revenue' => self::revenue($year),
            'exercice' => self::exerciceBornes($year),
            'unpaid' => $impayes,
            'unpaidAlert' => $seuil > 0 && $impayes['total'] > $seuil,
            'absentToday' => self::absences(),
            'projects' => self::projectHealth(),
            'tickets' => self::ticketLoad(),
            'treasury' => $hasAccounts ? Treasury::totalBalance() : null,
        ];
    }

    // ---------- Objectifs et résultats clés ----------

    public static function objectives(array $filters = []): array
    {
        $clauses = [];
        $params = [self::ici()];
        if (!empty($filters['scope'])) {
            $clauses[] = 'o.scope = ?';
            $params[] = $filters['scope'];
        }
        if (!empty($filters['period'])) {
            $clauses[] = 'o.period = ?';
            $params[] = $filters['period'];
        }
        $where = $clauses === [] ? '' : 'AND ' . implode(' AND ', $clauses);

        return array_map(static function (array $objective): array {
            $results = self::keyResults((int) $objective['id']);
            $objective['results'] = $results;
            $objective['progress'] = self::progressOf($results);
            return $objective;
        }, Db::all(
            "SELECT o.*, u.first_name, u.last_name FROM objectives o
             LEFT JOIN users u ON u.id = o.owner_id
             WHERE o.company_id = ? $where
             ORDER BY o.status != 'En cours', o.period DESC, o.id DESC",
            $params
        ));
    }

    /**
     * L'avancement d'un objectif est la moyenne de ses résultats clés, chacun
     * ramené sur sa propre échelle : un compteur qui part de 40 et vise 60 est à
     * moitié quand il atteint 50, pas à 83 %.
     */
    public static function progressOf(array $results): int
    {
        if ($results === []) {
            return 0;
        }
        $sum = 0.0;
        foreach ($results as $result) {
            $span = (float) $result['target_value'] - (float) $result['start_value'];
            if ($span === 0.0) {
                $sum += (float) $result['current_value'] >= (float) $result['target_value'] ? 1 : 0;
                continue;
            }
            $ratio = ((float) $result['current_value'] - (float) $result['start_value']) / $span;
            $sum += max(0.0, min(1.0, $ratio));
        }
        return (int) round($sum / count($results) * 100);
    }

    public static function keyResults(int $objectiveId): array
    {
        return Db::all('SELECT * FROM key_results WHERE objective_id = ? ORDER BY id', [$objectiveId]);
    }

    public static function objectiveById(int $id): ?array
    {
        return Db::get('SELECT * FROM objectives WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createObjective(array $fields): int
    {
        return Db::insert(
            'INSERT INTO objectives (company_id, title, description, scope, scope_id, owner_id, period)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['title'], $fields['description'] ?? '', $fields['scope'],
                $fields['scopeId'] ?? null, $fields['ownerId'] ?? null, $fields['period'] ?? '',
            ]
        );
    }

    public static function setObjectiveStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::OBJECTIVE_STATUSES, true)) {
            return false;
        }
        Db::run('UPDATE objectives SET status = ? WHERE id = ? AND company_id = ?', [$status, $id, self::ici()]);
        return true;
    }

    public static function deleteObjective(int $id): bool
    {
        if (Db::run('DELETE FROM objectives WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    public static function addKeyResult(array $fields): int
    {
        return Db::insert(
            'INSERT INTO key_results (objective_id, title, start_value, target_value, current_value, unit)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $fields['objectiveId'], $fields['title'], $fields['startValue'],
                $fields['targetValue'], $fields['currentValue'], $fields['unit'] ?? '',
            ]
        );
    }

    public static function updateKeyResult(int $id, float $currentValue): ?int
    {
        if (!\App\Core\Rattachees::visible('key_results', $id)) {
            return null;
        }
        $row = Db::get('SELECT objective_id FROM key_results WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        Db::run('UPDATE key_results SET current_value = ? WHERE id = ?', [$currentValue, $id]);
        return (int) $row['objective_id'];
    }

    public static function deleteKeyResult(int $id): ?int
    {
        if (!\App\Core\Rattachees::visible('key_results', $id)) {
            return null;
        }
        $row = Db::get('SELECT objective_id FROM key_results WHERE id = ?', [$id]);
        Db::run('DELETE FROM key_results WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['objective_id'];
    }
}
