<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Congés, absences, soldes et fiches de paie.
 *
 * Un principe traverse tout le module : le solde n'est débité qu'à
 * l'approbation, et recrédité si l'approbation est révoquée. Une demande en
 * attente ne réserve rien — sans quoi un refus laisserait des jours bloqués
 * que personne ne pense à rendre.
 */
final class Hr
{
    public const DEFAULT_ANNUAL_LEAVE = 25;

    public const REQUEST_TYPES = ['Congés payés', 'RTT', 'Absence maladie', 'Télétravail', 'Autre'];
    /** Seuls les congés payés décomptent le solde. */
    public const BALANCE_IMPACTING = ['Congés payés'];

    public const STATUSES = ['En attente', 'Approuvée', 'Refusée', 'Annulée'];

    public static function affectsBalance(string $type): bool
    {
        return in_array($type, self::BALANCE_IMPACTING, true);
    }

    /**
     * Un freelance n'a ni congés ni fiche de paie : il facture. Lui proposer
     * un formulaire de congés serait une promesse que le contrat ne tient pas.
     */
    public static function isEligible(array $employee): bool
    {
        return $employee['role'] === 'employee' && $employee['contract_type'] !== 'Freelance';
    }

    /** Jours ouvrés entre deux dates, bornes comprises. */
    public static function countBusinessDays(string $start, string $end): ?int
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('UTC'));
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, new \DateTimeZone('UTC'));
        if ($from === false || $to === false || $to < $from) {
            return null;
        }
        $count = 0;
        for ($day = $from; $day <= $to; $day = $day->modify('+1 day')) {
            if (!in_array((int) $day->format('N'), [6, 7], true)) {
                $count++;
            }
        }
        return $count;
    }

    // ---------- Demandes ----------

    public static function createRequest(int $employeeId, string $type, string $start, string $end, int $days, string $reason): int
    {
        $id = Db::insert(
            'INSERT INTO hr_requests (employee_id, type, start_date, end_date, days, reason) VALUES (?, ?, ?, ?, ?, ?)',
            [$employeeId, $type, $start, $end, $days, $reason]
        );
        Audit::log('demande.creee', 'hr_requests', $id, ['type' => $type, 'jours' => $days]);
        return $id;
    }

    public static function requestById(int $id): ?array
    {
        return Db::get('SELECT * FROM hr_requests WHERE id = ?', [$id]);
    }

    public static function requestsFor(int $employeeId, int $limit = 100): array
    {
        return Db::all('SELECT * FROM hr_requests WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?', [$employeeId, $limit]);
    }

    public static function allRequests(?string $status = null): array
    {
        $where = $status === null ? '' : 'WHERE r.status = ?';
        return Db::all(
            "SELECT r.*, u.first_name, u.last_name, u.email
             FROM hr_requests r JOIN users u ON u.id = r.employee_id
             $where ORDER BY r.created_at DESC",
            $status === null ? [] : [$status]
        );
    }

    public static function approve(int $id, int $reviewerId, string $note): array
    {
        $request = self::requestById($id);
        if ($request === null || $request['status'] !== 'En attente') {
            return ['ok' => false, 'reason' => 'not-pending'];
        }
        Db::transaction(static function () use ($request, $id, $reviewerId, $note): void {
            if (self::affectsBalance($request['type'])) {
                Db::run('UPDATE users SET leave_balance = leave_balance - ? WHERE id = ?', [$request['days'], $request['employee_id']]);
            }
            Db::run(
                "UPDATE hr_requests SET status = 'Approuvée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?",
                [$reviewerId, $note, gmdate('c'), $id]
            );
        });
        Audit::log('demande.approuvee', 'hr_requests', $id);
        // Les outils de planification ont besoin de le savoir tout de suite ;
        // le motif, lui, ne sort pas de l'entreprise.
        Webhooks::emit('absence.approuvee', [
            'id' => $id,
            'type' => $request['type'],
            'du' => $request['start_date'],
            'au' => $request['end_date'],
            'jours' => $request['days'],
        ]);
        return ['ok' => true];
    }

    public static function reject(int $id, int $reviewerId, string $note): array
    {
        $request = self::requestById($id);
        if ($request === null || $request['status'] !== 'En attente') {
            return ['ok' => false, 'reason' => 'not-pending'];
        }
        Db::run(
            "UPDATE hr_requests SET status = 'Refusée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?",
            [$reviewerId, $note, gmdate('c'), $id]
        );
        Audit::log('demande.refusee', 'hr_requests', $id);
        return ['ok' => true];
    }

    /** Annulation par la personne : seulement tant que rien n'est décidé. */
    public static function cancelOwn(int $id, int $employeeId): array
    {
        $request = self::requestById($id);
        if ($request === null || (int) $request['employee_id'] !== $employeeId || $request['status'] !== 'En attente') {
            return ['ok' => false, 'reason' => 'not-cancellable'];
        }
        Db::run("UPDATE hr_requests SET status = 'Annulée', reviewed_at = ? WHERE id = ?", [gmdate('c'), $id]);
        Audit::log('demande.annulee', 'hr_requests', $id);
        return ['ok' => true];
    }

    /** Annulation côté RH : possible après approbation, et le solde revient. */
    public static function revoke(int $id, int $reviewerId, string $note): array
    {
        $request = self::requestById($id);
        if ($request === null || !in_array($request['status'], ['En attente', 'Approuvée'], true)) {
            return ['ok' => false, 'reason' => 'not-revocable'];
        }
        Db::transaction(static function () use ($request, $id, $reviewerId, $note): void {
            if ($request['status'] === 'Approuvée' && self::affectsBalance($request['type'])) {
                Db::run('UPDATE users SET leave_balance = leave_balance + ? WHERE id = ?', [$request['days'], $request['employee_id']]);
            }
            Db::run(
                "UPDATE hr_requests SET status = 'Annulée', reviewed_by = ?, review_note = ?, reviewed_at = ? WHERE id = ?",
                [$reviewerId, $note, gmdate('c'), $id]
            );
        });
        Audit::log('demande.revoquee', 'hr_requests', $id);
        return ['ok' => true];
    }

    // ---------- Solde ----------

    public static function adjustBalance(int $employeeId, float $amount, string $reason, int $createdBy): void
    {
        Db::transaction(static function () use ($employeeId, $amount, $reason, $createdBy): void {
            Db::run('UPDATE users SET leave_balance = leave_balance + ? WHERE id = ?', [$amount, $employeeId]);
            Db::run(
                'INSERT INTO leave_adjustments (employee_id, amount, reason, created_by) VALUES (?, ?, ?, ?)',
                [$employeeId, $amount, $reason, $createdBy]
            );
        });
        Audit::log('solde.ajuste', 'users', $employeeId, ['jours' => $amount]);
    }

    public static function adjustments(int $employeeId, int $limit = 50): array
    {
        return Db::all('SELECT * FROM leave_adjustments WHERE employee_id = ? ORDER BY created_at DESC LIMIT ?', [$employeeId, $limit]);
    }

    // ---------- Fiches de paie ----------

    public static function createPayslip(int $employeeId, string $period, float $gross, float $net, string $note, int $createdBy): int
    {
        $id = Db::insert(
            'INSERT INTO payslips (employee_id, period, gross_amount, net_amount, note, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$employeeId, $period, $gross, $net, $note, $createdBy]
        );
        Audit::log('paie.fiche_creee', 'payslips', $id, ['periode' => $period]);
        return $id;
    }

    public static function payslipsFor(int $employeeId, int $limit = 60): array
    {
        return Db::all('SELECT * FROM payslips WHERE employee_id = ? ORDER BY period DESC LIMIT ?', [$employeeId, $limit]);
    }

    public static function allPayslips(int $limit = 300): array
    {
        return Db::all(
            'SELECT p.*, u.first_name, u.last_name FROM payslips p
             JOIN users u ON u.id = p.employee_id
             ORDER BY p.period DESC, u.last_name COLLATE NOCASE LIMIT ?',
            [$limit]
        );
    }

    public static function markPayslipPaid(int $id): void
    {
        Db::run("UPDATE payslips SET status = 'Payée' WHERE id = ?", [$id]);
        Audit::log('paie.fiche_payee', 'payslips', $id);
    }

    public static function deletePayslip(int $id): void
    {
        Db::run('DELETE FROM payslips WHERE id = ?', [$id]);
        Audit::log('paie.fiche_supprimee', 'payslips', $id);
    }
}
