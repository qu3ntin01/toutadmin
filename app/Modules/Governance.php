<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Gouvernance : réunions, décisions et risques de l'entreprise.
 *
 * Trois manques qui se répondent. Une réunion sans relevé s'oublie ; une
 * décision sans registre se rejoue tous les six mois ; un risque connu de deux
 * personnes n'est pas un risque géré. Le dirigeant doit pouvoir répondre à
 * « qui a décidé quoi, quand, et où en est-on ? » sans fouiller sa messagerie.
 *
 * Le registre des risques est volontairement distinct du document unique, qui
 * ne traite que la santé des personnes : la dépendance à un client ou la panne
 * du système d'information n'y ont pas leur place, et se perdraient au milieu.
 */
final class Governance
{
    public const MEETING_KINDS = ['Comité de direction', "Réunion d'équipe", 'Revue de projet', 'Comité social', 'Revue de direction', 'Autre'];
    public const MEETING_STATUSES = ['Planifiée', 'Tenue', 'Annulée'];
    public const ATTENDANCES = ['Attendu', 'Présent', 'Excusé', 'Absent'];
    public const DECISION_SCOPES = ['Entreprise', 'Service', 'Équipe', 'Projet'];
    public const DECISION_STATUSES = ['En vigueur', 'En cours', 'Suspendue', 'Abandonnée'];
    public const ACTION_STATUSES = ['À faire', 'En cours', 'Faite', 'Abandonnée'];

    public const RISK_CATEGORIES = ['Stratégique', 'Financier', 'Opérationnel', 'Juridique et conformité', 'Informatique', 'Ressources humaines', 'Réputation', 'Environnement'];
    public const TREATMENTS = ['Éviter', 'Réduire', 'Transférer', 'Accepter'];
    public const RISK_STATUSES = ['Ouvert', 'Maîtrisé', 'Clos'];
    public const SCALE = [1, 2, 3, 4, 5];

    // Au-delà de ce produit probabilité × impact, le risque remonte à la direction.
    public const CRITICAL_THRESHOLD = 12;

    /** Le périmètre courant : les réunions de gouvernance appartiennent à une société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    // ---------- Réunions ----------

    public static function meetings(?string $status = null, int $limit = 200): array
    {
        $clause = $status !== null ? 'AND m.status = ?' : '';
        $params = $status !== null ? [self::ici(), $status, $limit] : [self::ici(), $limit];

        return Db::all(
            "SELECT m.*, u.first_name AS chair_first, u.last_name AS chair_last,
                    (SELECT COUNT(*) FROM meeting_attendees a WHERE a.meeting_id = m.id) AS attendee_count,
                    (SELECT COUNT(*) FROM decisions d WHERE d.meeting_id = m.id) AS decision_count,
                    (SELECT COUNT(*) FROM meeting_actions x WHERE x.meeting_id = m.id AND x.status NOT IN ('Faite','Abandonnée')) AS open_actions
             FROM meetings m
             LEFT JOIN users u ON u.id = m.chair_id
             WHERE m.company_id = ? $clause
             ORDER BY m.held_on DESC, m.id DESC LIMIT ?",
            $params
        );
    }

    public static function meetingById(int $id): ?array
    {
        return Db::get('SELECT * FROM meetings WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createMeeting(array $fields): int
    {
        return Db::insert(
            'INSERT INTO meetings (company_id, title, kind, held_on, starts_at, ends_at, location, agenda, chair_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['title'], $fields['kind'], $fields['heldOn'], $fields['startsAt'] ?? '',
                $fields['endsAt'] ?? '', $fields['location'] ?? '', $fields['agenda'] ?? '',
                $fields['chairId'] ?? null, $fields['createdBy'] ?? null,
            ]
        );
    }

    public static function updateMeeting(int $id, array $fields): void
    {
        Db::run(
            'UPDATE meetings SET title = ?, kind = ?, held_on = ?, starts_at = ?, ends_at = ?,
                    location = ?, agenda = ?, chair_id = ?, status = ? WHERE id = ? AND company_id = ?',
            [
                $fields['title'], $fields['kind'], $fields['heldOn'], $fields['startsAt'] ?? '',
                $fields['endsAt'] ?? '', $fields['location'] ?? '', $fields['agenda'] ?? '',
                $fields['chairId'] ?? null, $fields['status'], $id, self::ici(),
            ]
        );
    }

    /** Le compte rendu se saisit après coup : il ne suit pas le sort de l'ordre du jour. */
    public static function setMinutes(int $id, string $minutes): void
    {
        Db::run(
            "UPDATE meetings SET minutes = ?, status = 'Tenue' WHERE id = ? AND company_id = ?",
            [$minutes, $id, self::ici()]
        );
    }

    public static function deleteMeeting(int $id): void
    {
        Db::run('DELETE FROM meetings WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function attendees(int $meetingId): array
    {
        return Db::all(
            'SELECT a.*, u.first_name, u.last_name, u.grade FROM meeting_attendees a
             JOIN users u ON u.id = a.user_id WHERE a.meeting_id = ?
             ORDER BY u.last_name COLLATE NOCASE',
            [$meetingId]
        );
    }

    public static function invite(int $meetingId, int $userId): bool
    {
        return Db::run(
            'INSERT OR IGNORE INTO meeting_attendees (meeting_id, user_id) VALUES (?, ?)',
            [$meetingId, $userId]
        ) > 0;
    }

    public static function setAttendance(int $meetingId, int $userId, string $attendance): bool
    {
        if (!in_array($attendance, self::ATTENDANCES, true)) {
            return false;
        }
        return Db::run(
            'UPDATE meeting_attendees SET attendance = ? WHERE meeting_id = ? AND user_id = ?',
            [$attendance, $meetingId, $userId]
        ) > 0;
    }

    public static function removeAttendee(int $meetingId, int $userId): bool
    {
        if (Db::run('DELETE FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?', [$meetingId, $userId]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Décisions ----------

    public static function decisions(array $filters = []): array
    {
        $clauses = ['d.company_id = ?'];
        $params = [self::ici()];
        if (!empty($filters['meetingId'])) {
            $clauses[] = 'd.meeting_id = ?';
            $params[] = (int) $filters['meetingId'];
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'd.status = ?';
            $params[] = $filters['status'];
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);
        $params[] = (int) ($filters['limit'] ?? 300);

        return Db::all(
            "SELECT d.*, u.first_name, u.last_name, m.title AS meeting_title, m.held_on AS meeting_date,
                    (SELECT COUNT(*) FROM meeting_actions x WHERE x.decision_id = d.id AND x.status NOT IN ('Faite','Abandonnée')) AS open_actions
             FROM decisions d
             LEFT JOIN users u ON u.id = d.decided_by
             LEFT JOIN meetings m ON m.id = d.meeting_id
             $where
             ORDER BY d.decided_on DESC, d.id DESC LIMIT ?",
            $params
        );
    }

    public static function createDecision(array $fields): int
    {
        return Db::insert(
            'INSERT INTO decisions (company_id, meeting_id, title, body, rationale, decided_on, decided_by, scope, review_on)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['meetingId'] ?? null, $fields['title'], $fields['body'] ?? '', $fields['rationale'] ?? '',
                $fields['decidedOn'], $fields['decidedBy'] ?? null, $fields['scope'], $fields['reviewOn'] ?? null,
            ]
        );
    }

    public static function setDecisionStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::DECISION_STATUSES, true)) {
            return false;
        }
        return Db::run(
            'UPDATE decisions SET status = ? WHERE id = ? AND company_id = ?',
            [$status, $id, self::ici()]
        ) > 0;
    }

    public static function deleteDecision(int $id): bool
    {
        if (Db::run('DELETE FROM decisions WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Actions ----------

    public static function actions(array $filters = []): array
    {
        $clauses = ['a.company_id = ?'];
        $params = [self::ici()];
        if (!empty($filters['meetingId'])) {
            $clauses[] = 'a.meeting_id = ?';
            $params[] = (int) $filters['meetingId'];
        }
        if (!empty($filters['assigneeId'])) {
            $clauses[] = 'a.assignee_id = ?';
            $params[] = (int) $filters['assigneeId'];
        }
        if (!empty($filters['openOnly'])) {
            $clauses[] = "a.status NOT IN ('Faite','Abandonnée')";
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);

        return Db::all(
            "SELECT a.*, u.first_name, u.last_name, m.title AS meeting_title, d.title AS decision_title
             FROM meeting_actions a
             LEFT JOIN users u ON u.id = a.assignee_id
             LEFT JOIN meetings m ON m.id = a.meeting_id
             LEFT JOIN decisions d ON d.id = a.decision_id
             $where
             ORDER BY a.due_date IS NULL, a.due_date, a.id",
            $params
        );
    }

    public static function createAction(array $fields): int
    {
        return Db::insert(
            'INSERT INTO meeting_actions (company_id, meeting_id, decision_id, label, assignee_id, due_date)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['meetingId'] ?? null, $fields['decisionId'] ?? null, $fields['label'],
                $fields['assigneeId'] ?? null, $fields['dueDate'] ?? null,
            ]
        );
    }

    public static function setActionStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::ACTION_STATUSES, true)) {
            return false;
        }
        $done = $status === 'Faite' ? gmdate('Y-m-d') : null;
        return Db::run(
            'UPDATE meeting_actions SET status = ?, done_on = ? WHERE id = ? AND company_id = ?',
            [$status, $done, $id, self::ici()]
        ) > 0;
    }

    public static function deleteAction(int $id): bool
    {
        if (Db::run('DELETE FROM meeting_actions WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
    }

    // ---------- Registre des risques ----------

    public static function score(?int $likelihood, ?int $impact): int
    {
        return (int) $likelihood * (int) $impact;
    }

    /**
     * La criticité résiduelle est celle qui compte : elle dit ce qu'il reste une
     * fois le traitement en place. Tant qu'elle n'est pas cotée, on retient la
     * criticité brute plutôt que de faire comme si le risque était traité.
     */
    private static function decorate(array $row): array
    {
        $gross = self::score((int) $row['likelihood'], (int) $row['impact']);
        $hasResidual = !empty($row['residual_likelihood']) && !empty($row['residual_impact']);
        $residual = $hasResidual
            ? self::score((int) $row['residual_likelihood'], (int) $row['residual_impact'])
            : null;
        $retained = $residual ?? $gross;

        $row['gross'] = $gross;
        $row['residual'] = $residual;
        $row['retained'] = $retained;
        $row['critical'] = $retained >= self::CRITICAL_THRESHOLD;
        return $row;
    }

    public static function risks(?string $status = null): array
    {
        $clause = $status !== null ? 'AND r.status = ?' : '';
        $params = $status !== null ? [self::ici(), $status] : [self::ici()];

        $rows = array_map([self::class, 'decorate'], Db::all(
            "SELECT r.*, u.first_name, u.last_name FROM enterprise_risks r
             LEFT JOIN users u ON u.id = r.owner_id
             WHERE r.company_id = ? $clause",
            $params
        ));
        usort($rows, static fn (array $a, array $b): int =>
            $b['retained'] <=> $a['retained'] ?: strcmp($a['title'], $b['title']));
        return $rows;
    }

    public static function riskById(int $id): ?array
    {
        $row = Db::get('SELECT * FROM enterprise_risks WHERE id = ? AND company_id = ?', [$id, self::ici()]);
        return $row === null ? null : self::decorate($row);
    }

    public static function createRisk(array $fields): int
    {
        return Db::insert(
            'INSERT INTO enterprise_risks (company_id, reference, category, title, description, likelihood, impact, owner_id,
                                           treatment, action_plan, residual_likelihood, residual_impact, identified_on, next_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['reference'] ?? '', $fields['category'], $fields['title'], $fields['description'] ?? '',
                $fields['likelihood'], $fields['impact'], $fields['ownerId'] ?? null, $fields['treatment'],
                $fields['actionPlan'] ?? '', $fields['residualLikelihood'] ?? null, $fields['residualImpact'] ?? null,
                $fields['identifiedOn'] ?? gmdate('Y-m-d'), $fields['nextReview'] ?? null,
            ]
        );
    }

    public static function updateRisk(int $id, array $fields): void
    {
        Db::run(
            'UPDATE enterprise_risks SET reference = ?, category = ?, title = ?, description = ?, likelihood = ?, impact = ?,
                    owner_id = ?, treatment = ?, action_plan = ?, residual_likelihood = ?, residual_impact = ?,
                    status = ?, next_review = ? WHERE id = ? AND company_id = ?',
            [
                $fields['reference'] ?? '', $fields['category'], $fields['title'], $fields['description'] ?? '',
                $fields['likelihood'], $fields['impact'], $fields['ownerId'] ?? null, $fields['treatment'],
                $fields['actionPlan'] ?? '', $fields['residualLikelihood'] ?? null, $fields['residualImpact'] ?? null,
                $fields['status'], $fields['nextReview'] ?? null, $id, self::ici(),
            ]
        );
    }

    public static function deleteRisk(int $id): void
    {
        Db::run('DELETE FROM enterprise_risks WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    /** La matrice probabilité × impact : ce qu'on montre au comité, d'un coup d'œil. */
    public static function matrix(): array
    {
        $grid = [];
        foreach (self::SCALE as $ignored) {
            $grid[] = array_fill(0, count(self::SCALE), []);
        }

        foreach (array_merge(self::risks('Ouvert'), self::risks('Maîtrisé')) as $risk) {
            $likelihood = (int) ($risk['residual_likelihood'] ?: $risk['likelihood']);
            $impact = (int) ($risk['residual_impact'] ?: $risk['impact']);
            $line = count(self::SCALE) - $likelihood;
            if (isset($grid[$line][$impact - 1])) {
                $grid[$line][$impact - 1][] = $risk;
            }
        }
        return $grid;
    }

    public static function summary(): array
    {
        $open = self::risks('Ouvert');
        $openActions = self::actions(['openOnly' => true]);
        $today = gmdate('Y-m-d');
        $overdue = array_filter(
            $openActions,
            static fn (array $a): bool => !empty($a['due_date']) && $a['due_date'] < $today
        );

        return [
            'meetings' => (int) Db::value(
                "SELECT COUNT(*) FROM meetings WHERE company_id = ? AND held_on >= date('now', '-90 days')",
                [self::ici()]
            ),
            'decisions' => (int) Db::value(
                "SELECT COUNT(*) FROM decisions WHERE company_id = ? AND status = 'En vigueur'",
                [self::ici()]
            ),
            'openActions' => count($openActions),
            'overdueActions' => count($overdue),
            'risks' => count($open),
            'criticalRisks' => count(array_filter($open, static fn (array $r): bool => $r['critical'])),
        ];
    }
}
