<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Santé et sécurité au travail.
 *
 * Trois obligations distinctes, tenues ensemble parce qu'elles se répondent :
 * l'évaluation des risques (le document unique), le registre des accidents, et
 * le suivi de ce qui protège — équipements de protection et visites médicales.
 *
 * Le CMS tient le registre ; il ne remplace ni la déclaration à la caisse
 * d'assurance maladie, ni l'avis du médecin du travail.
 */
final class Safety
{
    public const INCIDENT_KINDS = ['Accident du travail', 'Accident de trajet', 'Presque-accident', 'Maladie professionnelle'];
    public const VISIT_KINDS = ["Visite d'embauche", 'Visite périodique', 'Visite de reprise', 'Visite à la demande'];
    public const SEVERITIES = [1, 2, 3, 4];
    public const LIKELIHOODS = [1, 2, 3, 4];

    // Au-delà de ce produit gravité × probabilité, le risque appelle une action.
    public const ACTION_THRESHOLD = 6;

    // ---------- Évaluation des risques ----------

    public static function riskScore(array $row): int
    {
        return (int) ($row['severity'] ?? 0) * (int) ($row['likelihood'] ?? 0);
    }

    public static function risks(): array
    {
        $rows = Db::all('SELECT * FROM risk_assessments ORDER BY unit COLLATE NOCASE, hazard COLLATE NOCASE');
        $rows = array_map(static function (array $row): array {
            $row['score'] = self::riskScore($row);
            $row['critical'] = $row['score'] >= self::ACTION_THRESHOLD;
            return $row;
        }, $rows);
        usort($rows, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $rows;
    }

    public static function createRisk(array $fields): int
    {
        return Db::insert(
            'INSERT INTO risk_assessments (unit, hazard, exposure, severity, likelihood, measures, reviewed_on, next_review)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['unit'], $fields['hazard'], $fields['exposure'] ?? '',
                $fields['severity'], $fields['likelihood'], $fields['measures'] ?? '',
                $fields['reviewedOn'] ?? null, $fields['nextReview'] ?? null,
            ]
        );
    }

    public static function updateRisk(int $id, array $fields): void
    {
        Db::run(
            'UPDATE risk_assessments SET unit = ?, hazard = ?, exposure = ?, severity = ?, likelihood = ?,
                    measures = ?, reviewed_on = ?, next_review = ? WHERE id = ?',
            [
                $fields['unit'], $fields['hazard'], $fields['exposure'] ?? '',
                $fields['severity'], $fields['likelihood'], $fields['measures'] ?? '',
                $fields['reviewedOn'] ?? null, $fields['nextReview'] ?? null, $id,
            ]
        );
    }

    public static function deleteRisk(int $id): void
    {
        Db::run('DELETE FROM risk_assessments WHERE id = ?', [$id]);
    }

    public static function riskById(int $id): ?array
    {
        return Db::get('SELECT * FROM risk_assessments WHERE id = ?', [$id]);
    }

    // ---------- Registre des accidents ----------

    public static function incidents(int $limit = 200): array
    {
        return Db::all(
            'SELECT i.*, u.first_name, u.last_name FROM workplace_incidents i
             LEFT JOIN users u ON u.id = i.user_id
             ORDER BY i.occurred_on DESC, i.id DESC LIMIT ?',
            [$limit]
        );
    }

    public static function createIncident(array $fields): int
    {
        return Db::insert(
            'INSERT INTO workplace_incidents (occurred_on, user_id, kind, location, description, days_off, declared_on, follow_up)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['occurredOn'], $fields['userId'] ?? null, $fields['kind'],
                $fields['location'] ?? '', $fields['description'] ?? '', $fields['daysOff'] ?? 0,
                $fields['declaredOn'] ?? null, $fields['followUp'] ?? '',
            ]
        );
    }

    public static function deleteIncident(int $id): void
    {
        Db::run('DELETE FROM workplace_incidents WHERE id = ?', [$id]);
    }

    /**
     * Indicateurs réglementaires sur douze mois : taux de fréquence et de gravité.
     * Les heures travaillées sont estimées à partir de l'effectif — la formule
     * légale les demande, et personne ne les saisit à la main.
     */
    public static function indicators(int $hoursPerYear = 1607): array
    {
        $headcount = (int) Db::value("SELECT COUNT(*) FROM users WHERE active = 1 AND role = 'employee'");
        $worked = $headcount * $hoursPerYear;

        $row = Db::get(
            "SELECT COUNT(*) AS n, COALESCE(SUM(days_off), 0) AS days
             FROM workplace_incidents
             WHERE kind IN ('Accident du travail', 'Accident de trajet')
               AND days_off > 0 AND occurred_on >= date('now', '-1 year')"
        ) ?? ['n' => 0, 'days' => 0];

        $accidents = (int) $row['n'];
        $days = (int) $row['days'];

        return [
            'headcount' => $headcount,
            'worked' => $worked,
            'accidents' => $accidents,
            'daysOff' => $days,
            // Fréquence : accidents avec arrêt par million d'heures travaillées.
            'frequency' => $worked ? round($accidents * 1e6 / $worked, 2) : 0.0,
            // Gravité : journées perdues par millier d'heures travaillées.
            'severity' => $worked ? round($days * 1e3 / $worked, 2) : 0.0,
        ];
    }

    // ---------- Équipements de protection ----------

    public static function ppeItems(): array
    {
        return Db::all('SELECT * FROM ppe_items ORDER BY category COLLATE NOCASE, name COLLATE NOCASE');
    }

    public static function createPpe(string $name, string $category, ?int $validityMonths): int
    {
        return Db::insert(
            'INSERT INTO ppe_items (name, category, validity_months) VALUES (?, ?, ?)',
            [$name, $category, $validityMonths]
        );
    }

    public static function deletePpe(int $id): void
    {
        Db::run('DELETE FROM ppe_items WHERE id = ?', [$id]);
    }

    public static function issuePpe(int $ppeId, int $userId, string $issuedOn): ?int
    {
        $item = Db::get('SELECT * FROM ppe_items WHERE id = ?', [$ppeId]);
        if ($item === null) {
            return null;
        }

        $expires = $item['validity_months'] !== null && $issuedOn !== ''
            ? Billing::addMonths($issuedOn, (int) $item['validity_months'])
            : null;

        return Db::insert(
            'INSERT INTO ppe_assignments (ppe_id, user_id, issued_on, expires_on) VALUES (?, ?, ?, ?)',
            [$ppeId, $userId, $issuedOn, $expires]
        );
    }

    public static function returnPpe(int $id): void
    {
        Db::run("UPDATE ppe_assignments SET returned_on = date('now') WHERE id = ? AND returned_on IS NULL", [$id]);
    }

    public static function ppeAssignments(bool $activeOnly = true): array
    {
        $clause = $activeOnly ? 'WHERE a.returned_on IS NULL' : '';
        return Db::all(
            "SELECT a.*, p.name, p.category, u.first_name, u.last_name
             FROM ppe_assignments a
             JOIN ppe_items p ON p.id = a.ppe_id
             JOIN users u ON u.id = a.user_id
             $clause
             ORDER BY a.expires_on IS NULL, a.expires_on"
        );
    }

    // ---------- Visites médicales ----------

    public static function visits(?int $userId = null): array
    {
        $clause = $userId !== null ? 'WHERE v.user_id = ?' : '';
        $params = $userId !== null ? [$userId] : [];
        return Db::all(
            "SELECT v.*, u.first_name, u.last_name FROM medical_visits v
             JOIN users u ON u.id = v.user_id
             $clause
             ORDER BY v.next_due IS NULL, v.next_due, v.scheduled_on DESC",
            $params
        );
    }

    public static function createVisit(array $fields): int
    {
        return Db::insert(
            'INSERT INTO medical_visits (user_id, kind, scheduled_on, done_on, verdict, next_due)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fields['userId'], $fields['kind'], $fields['scheduledOn'] ?? null,
                $fields['doneOn'] ?? null, $fields['verdict'] ?? '', $fields['nextDue'] ?? null,
            ]
        );
    }

    public static function deleteVisit(int $id): void
    {
        Db::run('DELETE FROM medical_visits WHERE id = ?', [$id]);
    }

    /** Ce qui arrive à échéance : visites dues, protections périmées. */
    public static function upcoming(int $withinDays = 60): array
    {
        $window = '+' . ($withinDays ?: 60) . ' days';

        return [
            'visits' => Db::all(
                "SELECT v.*, u.first_name, u.last_name FROM medical_visits v JOIN users u ON u.id = v.user_id
                 WHERE v.next_due IS NOT NULL AND v.next_due <= date('now', ?) AND u.active = 1
                 ORDER BY v.next_due",
                [$window]
            ),
            'ppe' => Db::all(
                "SELECT a.*, p.name, u.first_name, u.last_name FROM ppe_assignments a
                 JOIN ppe_items p ON p.id = a.ppe_id JOIN users u ON u.id = a.user_id
                 WHERE a.returned_on IS NULL AND a.expires_on IS NOT NULL AND a.expires_on <= date('now', ?)
                 ORDER BY a.expires_on",
                [$window]
            ),
            'risks' => Db::all(
                "SELECT * FROM risk_assessments
                 WHERE next_review IS NOT NULL AND next_review <= date('now', ?)
                 ORDER BY next_review",
                [$window]
            ),
        ];
    }
}
