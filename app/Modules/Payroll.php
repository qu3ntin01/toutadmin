<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Settings;

/**
 * Moteur de paie.
 *
 * Chaque cotisation s'applique sur le brut ou sur la part plafonnée, et produit
 * une part salariale et une part patronale. Le net à payer est le brut moins
 * les seules cotisations salariales ; le coût employeur, le brut plus les
 * seules cotisations patronales.
 *
 * Les taux sont ceux que le gestionnaire de paie saisit : ceux d'origine sont
 * pré-remplis à titre indicatif et doivent être vérifiés. Aucune DSN n'est
 * produite.
 */
final class Payroll
{
    public const RATE_BASES = ['Brut', 'Plafond'];

    // Plafond mensuel de la sécurité sociale : une valeur de paramétrage, pas
    // une constante du code — elle change chaque année.
    public const DEFAULT_CEILING = 3925.0;

    /**
     * Barèmes pré-remplis à l'activation, à titre indicatif. Ce sont des ordres
     * de grandeur du régime général français : ils doivent être vérifiés et
     * ajustés par le gestionnaire de paie avant tout usage réel.
     */
    private const DEFAULT_RATES = [
        ['Sécurité sociale — maladie', 'Brut', 0, 13, 10],
        ['Sécurité sociale — vieillesse plafonnée', 'Plafond', 6.9, 8.55, 20],
        ['Sécurité sociale — vieillesse déplafonnée', 'Brut', 0.4, 2.02, 30],
        ['Allocations familiales', 'Brut', 0, 5.25, 40],
        ['Retraite complémentaire T1', 'Plafond', 3.15, 4.72, 50],
        ['Assurance chômage', 'Brut', 0, 4.05, 60],
        ['CSG déductible', 'Brut', 6.8, 0, 70],
        ['CSG/CRDS non déductible', 'Brut', 2.9, 0, 80],
    ];

    public static function ceiling(): float
    {
        $value = (float) Settings::get('payroll_ceiling');
        return $value > 0 ? $value : self::DEFAULT_CEILING;
    }

    public static function setCeiling(float $value): void
    {
        Settings::set('payroll_ceiling', (string) $value);
    }

    /** Idempotent : réactiver le module ne duplique pas les barèmes. */
    public static function seedDefaults(): void
    {
        if ((int) Db::value('SELECT COUNT(*) FROM payroll_rates') > 0) {
            return;
        }
        Db::transaction(static function (): void {
            foreach (self::DEFAULT_RATES as $row) {
                Db::insert(
                    'INSERT INTO payroll_rates (label, base, employee_rate, employer_rate, sort_order) VALUES (?, ?, ?, ?, ?)',
                    $row
                );
            }
        });
    }

    // ---------- Barèmes ----------

    public static function rates(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';
        return Db::all("SELECT * FROM payroll_rates $where ORDER BY sort_order, id");
    }

    public static function createRate(string $label, string $base, float $employeeRate, float $employerRate, int $sortOrder = 0): array
    {
        if (!in_array($base, self::RATE_BASES, true)) {
            return ['ok' => false, 'reason' => 'bad-base'];
        }
        foreach ([$employeeRate, $employerRate] as $rate) {
            if ($rate < 0 || $rate > 100) {
                return ['ok' => false, 'reason' => 'bad-rate'];
            }
        }
        Db::insert(
            'INSERT INTO payroll_rates (label, base, employee_rate, employer_rate, sort_order) VALUES (?, ?, ?, ?, ?)',
            [$label, $base, $employeeRate, $employerRate, $sortOrder]
        );
        return ['ok' => true];
    }

    public static function toggleRate(int $id): bool
    {
        $rate = Db::get('SELECT * FROM payroll_rates WHERE id = ?', [$id]);
        if ($rate === null) {
            return false;
        }
        Db::run('UPDATE payroll_rates SET active = ? WHERE id = ?', [(int) $rate['active'] === 1 ? 0 : 1, $id]);
        return true;
    }

    public static function deleteRate(int $id): void
    {
        Db::run('DELETE FROM payroll_rates WHERE id = ?', [$id]);
    }

    // ---------- Calcul ----------

    /** Décompose un brut mensuel, cotisation par cotisation. */
    public static function compute(float $grossSalary, ?array $rateList = null): array
    {
        $gross = round($grossSalary, 2);
        $cap = self::ceiling();
        $applicable = $rateList ?? self::rates(true);

        $lines = [];
        $employeeTotal = 0.0;
        $employerTotal = 0.0;
        foreach ($applicable as $index => $rate) {
            $base = $rate['base'] === 'Plafond' ? min($gross, $cap) : $gross;
            $employeeAmount = round($base * ((float) $rate['employee_rate'] / 100), 2);
            $employerAmount = round($base * ((float) $rate['employer_rate'] / 100), 2);
            $lines[] = [
                'label' => $rate['label'],
                'baseAmount' => round($base, 2),
                'employeeRate' => (float) $rate['employee_rate'],
                'employerRate' => (float) $rate['employer_rate'],
                'employeeAmount' => $employeeAmount,
                'employerAmount' => $employerAmount,
                'sortOrder' => $rate['sort_order'] ?? $index,
            ];
            $employeeTotal += $employeeAmount;
            $employerTotal += $employerAmount;
        }

        $employeeTotal = round($employeeTotal, 2);
        $employerTotal = round($employerTotal, 2);
        return [
            'gross' => $gross,
            'ceiling' => $cap,
            'lines' => $lines,
            'employeeTotal' => $employeeTotal,
            'employerTotal' => $employerTotal,
            'net' => round($gross - $employeeTotal, 2),
            'employerCost' => round($gross + $employerTotal, 2),
        ];
    }

    // ---------- Bulletins ----------

    /** Le bulletin calculé rejoint les fiches de paie déjà connues du salarié. */
    public static function generatePayslip(array $data): array
    {
        $employeeId = (int) $data['employeeId'];
        $period = (string) $data['period'];
        $gross = (float) $data['grossSalary'];

        $employee = Db::get("SELECT * FROM users WHERE id = ? AND role = 'employee'", [$employeeId]);
        if ($employee === null) {
            return ['ok' => false, 'reason' => 'no-employee'];
        }
        if ($employee['contract_type'] === 'Freelance') {
            return ['ok' => false, 'reason' => 'freelance'];
        }
        if ($gross <= 0 || $gross > 1000000) {
            return ['ok' => false, 'reason' => 'bad-gross'];
        }
        if (Db::get('SELECT 1 AS ok FROM payslips WHERE employee_id = ? AND period = ?', [$employeeId, $period]) !== null) {
            return ['ok' => false, 'reason' => 'duplicate'];
        }

        $result = self::compute($gross);
        $id = Db::transaction(static function () use ($employeeId, $period, $result, $data): int {
            $payslipId = Db::insert(
                'INSERT INTO payslips (employee_id, period, gross_amount, net_amount, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$employeeId, $period, $result['gross'], $result['net'], $data['note'] ?? '', $data['createdBy'] ?? null]
            );
            foreach ($result['lines'] as $line) {
                Db::insert(
                    'INSERT INTO payslip_lines (payslip_id, label, base_amount, employee_rate, employer_rate, employee_amount, employer_amount, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $payslipId, $line['label'], $line['baseAmount'], $line['employeeRate'], $line['employerRate'],
                        $line['employeeAmount'], $line['employerAmount'], $line['sortOrder'],
                    ]
                );
            }
            return $payslipId;
        });

        return ['ok' => true, 'id' => $id, 'result' => $result];
    }

    public static function payslipLines(int $payslipId): array
    {
        return Db::all('SELECT * FROM payslip_lines WHERE payslip_id = ? ORDER BY sort_order, id', [$payslipId]);
    }

    /** Un bulletin détaillé porte des lignes ; les fiches saisies à la main n'en ont pas. */
    public static function detailedPayslips(int $limit = 200): array
    {
        return array_map(static function (array $row): array {
            $row['employer_cost'] = round((float) $row['gross_amount'] + (float) $row['employer_total'], 2);
            return $row;
        }, Db::all(
            'SELECT p.*, u.first_name, u.last_name,
                (SELECT COUNT(*) FROM payslip_lines l WHERE l.payslip_id = p.id) AS line_count,
                (SELECT COALESCE(SUM(l.employer_amount), 0) FROM payslip_lines l WHERE l.payslip_id = p.id) AS employer_total
             FROM payslips p JOIN users u ON u.id = p.employee_id
             ORDER BY p.period DESC, u.last_name COLLATE NOCASE
             LIMIT ?',
            [$limit]
        ));
    }

    public static function setGrossSalary(int $employeeId, float $grossSalary): void
    {
        Db::run('UPDATE users SET gross_salary = ? WHERE id = ?', [$grossSalary, $employeeId]);
    }

    /** Masse salariale du mois : ce que la paie coûte réellement à l'entreprise. */
    public static function payrollCost(string $period): array
    {
        $row = Db::get(
            'SELECT COALESCE(SUM(p.gross_amount), 0) AS gross,
                    COALESCE(SUM(p.net_amount), 0) AS net,
                    COALESCE((SELECT SUM(l.employer_amount) FROM payslip_lines l
                              JOIN payslips q ON q.id = l.payslip_id WHERE q.period = ?), 0) AS employer
             FROM payslips p WHERE p.period = ?',
            [$period, $period]
        );
        $gross = round((float) $row['gross'], 2);
        $employer = round((float) $row['employer'], 2);
        return [
            'gross' => $gross,
            'net' => round((float) $row['net'], 2),
            'employer' => $employer,
            'cost' => round($gross + $employer, 2),
        ];
    }

    /** Les salariés que la paie concerne : un freelance est facturé, pas salarié. */
    public static function staff(bool $payableOnly = false): array
    {
        $extra = $payableOnly ? 'AND active = 1 AND gross_salary > 0' : '';
        return Db::all(
            "SELECT * FROM users WHERE role = 'employee' AND contract_type != 'Freelance' $extra
             ORDER BY last_name COLLATE NOCASE"
        );
    }
}
