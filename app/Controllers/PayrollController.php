<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Modules\Payroll;

/**
 * Moteur de paie.
 *
 * Module optionnel, et sujet RH : la paie suit les mêmes droits que le reste du
 * dossier salarié. Un salaire brut et un bulletin ne regardent ni la gestion,
 * ni le manager.
 */
final class PayrollController
{
    public static function canAccess(?array $user): bool
    {
        return HrController::canAccess($user);
    }

    private static function back(string $anchor, string $type, string $message): Response
    {
        Flash::set($type, $message);
        return Response::redirect('/paie#' . $anchor);
    }

    private static function amount(string $raw): ?float
    {
        $value = str_replace([' ', ','], ['', '.'], trim($raw));
        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private static function isPeriod(string $period): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1;
    }

    public static function index(Request $request): Response
    {
        $requested = $request->input('periode');
        $period = self::isPeriod($requested) ? $requested : gmdate('Y-m');
        $simulationGross = self::amount($request->input('brut'));

        return Response::html(View::page('payroll/index', [
            'title' => t('pay.panel') . ' — ' . t('app.name'),
            'panelLabel' => t('pay.panel'),
            'headerTitle' => t('pay.panel'),
            'headerSubtitle' => t('pay.headerSub'),
            'navItems' => [
                ['tab' => 'baremes', 'label' => t('pay.tabScales')],
                ['tab' => 'salaires', 'label' => t('pay.tabSalaries')],
                ['tab' => 'bulletins', 'label' => t('pay.tabPayslips')],
            ],
            'footLinks' => [
                ['href' => '/rh', 'label' => t('nav.hrSpace')],
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'period' => $period,
            'staff' => Payroll::staff(),
            'rates' => Payroll::rates(),
            'rateBases' => Payroll::RATE_BASES,
            'ceiling' => Payroll::ceiling(),
            'payslips' => Payroll::detailedPayslips(),
            'cost' => Payroll::payrollCost($period),
            'simulationGross' => $simulationGross,
            'simulation' => $simulationGross === null || $simulationGross <= 0 ? null : Payroll::compute($simulationGross),
        ]));
    }

    // ---------- Barèmes ----------

    public static function createRate(Request $request): Response
    {
        $label = mb_substr($request->input('label'), 0, 120);
        $base = $request->input('base');
        $employeeRate = self::amount($request->input('employee_rate', '0'));
        $employerRate = self::amount($request->input('employer_rate', '0'));

        if ($label === '') {
            return self::back('baremes', 'error', "L'intitulé de la cotisation est obligatoire.");
        }
        if ($employeeRate === null || $employerRate === null) {
            return self::back('baremes', 'error', 'Taux invalide (0 à 100 %).');
        }

        $result = Payroll::createRate($label, $base, $employeeRate, $employerRate, (int) $request->input('sort_order'));
        if (!$result['ok']) {
            $messages = ['bad-base' => 'Base invalide.', 'bad-rate' => 'Taux invalide (0 à 100 %).'];
            return self::back('baremes', 'error', $messages[$result['reason']] ?? 'Création impossible.');
        }
        return self::back('baremes', 'success', 'Cotisation ajoutée au barème.');
    }

    public static function toggleRate(Request $request, array $params): Response
    {
        if (!Payroll::toggleRate((int) $params['id'])) {
            return self::back('baremes', 'error', 'Cotisation introuvable.');
        }
        return self::back('baremes', 'success', 'Cotisation mise à jour.');
    }

    public static function deleteRate(Request $request, array $params): Response
    {
        Payroll::deleteRate((int) $params['id']);
        return self::back('baremes', 'success', 'Cotisation supprimée.');
    }

    public static function setCeiling(Request $request): Response
    {
        $value = self::amount($request->input('ceiling'));
        if ($value === null || $value <= 0 || $value > 1000000) {
            return self::back('baremes', 'error', 'Plafond invalide.');
        }
        Payroll::setCeiling($value);
        return self::back('baremes', 'success', 'Plafond mis à jour.');
    }

    // ---------- Salaires et bulletins ----------

    public static function setGrossSalary(Request $request, array $params): Response
    {
        $gross = self::amount($request->input('gross_salary'));
        if ($gross === null || $gross < 0 || $gross > 1000000) {
            return self::back('salaires', 'error', 'Salaire brut invalide.');
        }
        Payroll::setGrossSalary((int) $params['id'], $gross);
        return self::back('salaires', 'success', 'Salaire brut enregistré.');
    }

    public static function createPayslip(Request $request): Response
    {
        $period = $request->input('period');
        if (!self::isPeriod($period)) {
            return self::back('bulletins', 'error', 'Période invalide (AAAA-MM attendu).');
        }

        $result = Payroll::generatePayslip([
            'employeeId' => (int) $request->input('employee_id'),
            'period' => $period,
            'grossSalary' => self::amount($request->input('gross_salary')) ?? 0.0,
            'note' => mb_substr($request->input('note'), 0, 300),
            'createdBy' => (int) Session::get('user')['id'],
        ]);

        if (!$result['ok']) {
            $messages = [
                'no-employee' => 'Membre introuvable.',
                'freelance' => "Un freelance est facturé, pas salarié : son bulletin n'a pas lieu d'être.",
                'bad-gross' => 'Salaire brut invalide.',
                'duplicate' => 'Un bulletin existe déjà pour ce membre sur cette période.',
            ];
            return self::back('bulletins', 'error', $messages[$result['reason']] ?? 'Calcul impossible.');
        }
        return self::back('bulletins', 'success', sprintf(
            'Bulletin calculé : net à payer %.2f, coût employeur %.2f.',
            $result['result']['net'],
            $result['result']['employerCost']
        ));
    }

    /** Génère en une fois les bulletins de tous ceux dont le brut est renseigné. */
    public static function createPayslipBatch(Request $request): Response
    {
        $period = $request->input('period');
        if (!self::isPeriod($period)) {
            return self::back('bulletins', 'error', 'Période invalide (AAAA-MM attendu).');
        }

        $created = 0;
        $skipped = 0;
        foreach (Payroll::staff(true) as $employee) {
            $result = Payroll::generatePayslip([
                'employeeId' => (int) $employee['id'],
                'period' => $period,
                'grossSalary' => (float) $employee['gross_salary'],
                'note' => 'Génération en lot',
                'createdBy' => (int) Session::get('user')['id'],
            ]);
            $result['ok'] ? $created++ : $skipped++;
        }

        if ($created === 0 && $skipped === 0) {
            return self::back('bulletins', 'error', 'Aucun salarié avec un brut renseigné.');
        }
        return self::back('bulletins', 'success', $created . ' bulletin(s) calculé(s)'
            . ($skipped > 0 ? ", $skipped ignoré(s) (déjà émis)" : '') . '.');
    }
}
