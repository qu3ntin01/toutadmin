<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validate;
use App\Core\View;
use App\Modules\Hr;
use App\Modules\Org;
use App\Modules\Users;

/**
 * Espace RH.
 *
 * Ouvert à l'administration et aux personnes à qui elle a donné l'accès RH —
 * jamais à un manager du seul fait qu'il encadre : les congés et les fiches de
 * paie ne sont pas des informations d'équipe.
 */
final class HrController
{
    public static function canAccess(?array $user): bool
    {
        return $user !== null && ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1);
    }

    public static function home(Request $request): Response
    {
        $employees = array_values(array_filter(Users::employees(), static fn (array $e): bool => Hr::isEligible($e)));
        $requests = Hr::allRequests();
        $pending = array_values(array_filter($requests, static fn (array $r): bool => $r['status'] === 'En attente'));

        return Response::html(View::page('hr/index', [
            'title' => t('nav.hrSpace') . ' — ' . t('app.name'),
            'panelLabel' => t('nav.hrSpace'),
            'headerTitle' => t('hr.title'),
            'headerSubtitle' => t('hr.subtitle'),
            'navItems' => [
                ['tab' => 'demandes', 'label' => t('nav.requests'), 'badge' => count($pending) ?: null],
                ['tab' => 'personnel', 'label' => t('admin.staffMembers')],
                ['tab' => 'paie', 'label' => t('nav.payroll')],
            ],
            'footLinks' => [
                ['href' => '/mon-espace', 'label' => t('nav.mySpace')],
                ['href' => '/annuaire', 'label' => t('nav.directory')],
            ],
            'scripts' => ['/js/admin.js', '/js/confirm.js'],
            'employees' => $employees,
            'requests' => $requests,
            'pendingCount' => count($pending),
            'payslips' => Hr::allPayslips(),
            'types' => Hr::REQUEST_TYPES,
        ]));
    }

    // ---------- Demandes ----------

    private static function decide(Request $request, array $params, string $action): Response
    {
        $session = Session::get('user');
        $note = mb_substr($request->input('note'), 0, 500);
        $id = (int) $params['id'];
        $reviewer = (int) $session['id'];

        $result = match ($action) {
            'approve' => Hr::approve($id, $reviewer, $note),
            'reject' => Hr::reject($id, $reviewer, $note),
            default => Hr::revoke($id, $reviewer, $note),
        };
        $messages = [
            'approve' => ['Demande approuvée.', "Cette demande n'est plus en attente."],
            'reject' => ['Demande refusée.', "Cette demande n'est plus en attente."],
            'revoke' => ['Demande annulée, solde recrédité si nécessaire.', 'Cette demande ne peut plus être annulée.'],
        ];
        Flash::set($result['ok'] ? 'success' : 'error', $messages[$action][$result['ok'] ? 0 : 1]);
        return Response::redirect('/rh#demandes');
    }

    public static function approve(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'approve');
    }

    public static function reject(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'reject');
    }

    public static function revoke(Request $request, array $params): Response
    {
        return self::decide($request, $params, 'revoke');
    }

    // ---------- Solde ----------

    public static function adjustBalance(Request $request, array $params): Response
    {
        $id = (int) $params['id'];
        $employee = Users::employeeById($id);
        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/rh#personnel');
        };
        if ($employee === null || !Hr::isEligible($employee)) {
            return $fail('Membre introuvable ou non éligible.');
        }
        $raw = str_replace(',', '.', $request->input('amount'));
        if (!is_numeric($raw)) {
            return $fail('Ajustement invalide.');
        }
        $amount = round((float) $raw, 2);
        if ($amount === 0.0 || abs($amount) > 365) {
            return $fail('Ajustement invalide.');
        }

        Hr::adjustBalance($id, $amount, mb_substr($request->input('reason'), 0, 300), (int) Session::get('user')['id']);
        $sign = $amount > 0 ? '+' : '';
        Flash::set('success', "Solde de {$employee['first_name']} {$employee['last_name']} ajusté de $sign$amount j.");
        return Response::redirect('/rh#personnel');
    }

    // ---------- Fiches de paie ----------

    public static function createPayslip(Request $request): Response
    {
        $fail = static function (string $message): Response {
            Flash::set('error', $message);
            return Response::redirect('/rh#paie');
        };
        $employee = Users::employeeById((int) $request->input('employee_id'));
        if ($employee === null || !Hr::isEligible($employee)) {
            return $fail('Membre introuvable ou non éligible.');
        }
        $period = $request->input('period');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
            return $fail('Période invalide (format attendu : AAAA-MM).');
        }
        $gross = str_replace(',', '.', $request->input('gross_amount'));
        $net = str_replace(',', '.', $request->input('net_amount'));
        if (!is_numeric($gross) || !is_numeric($net) || (float) $gross < 0 || (float) $net < 0 || (float) $net > (float) $gross) {
            return $fail('Montants invalides (le net ne peut pas dépasser le brut).');
        }

        Hr::createPayslip(
            (int) $employee['id'],
            $period,
            round((float) $gross, 2),
            round((float) $net, 2),
            mb_substr($request->input('note'), 0, 300),
            (int) Session::get('user')['id']
        );
        Flash::set('success', "Fiche de paie $period créée pour {$employee['first_name']} {$employee['last_name']}.");
        return Response::redirect('/rh#paie');
    }

    public static function markPayslipPaid(Request $request, array $params): Response
    {
        Hr::markPayslipPaid((int) $params['id']);
        Flash::set('success', 'Fiche de paie marquée comme payée.');
        return Response::redirect('/rh#paie');
    }

    public static function deletePayslip(Request $request, array $params): Response
    {
        Hr::deletePayslip((int) $params['id']);
        Flash::set('success', 'Fiche de paie supprimée.');
        return Response::redirect('/rh#paie');
    }
}
