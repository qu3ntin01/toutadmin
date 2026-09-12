<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Service informatique : le parc logiciel, les accès qu'il ouvre, et les
 * incidents du système d'information.
 *
 * Un logiciel n'est pas un équipement. Il n'a pas de numéro de série à coller
 * sur un capot : il a des sièges qu'on paie, une date de renouvellement qui
 * tombe, et une liste de personnes qui entrent dedans. Ces trois choses-là
 * échappent aux inventaires classiques, et ce sont exactement celles qui
 * coûtent — en argent quand un abonnement se reconduit pour rien, en risque
 * quand un compte reste ouvert après un départ.
 */
final class It
{
    public const LICENCE_KINDS = ['Abonnement', 'Licence perpétuelle', 'Logiciel libre', 'Développement interne'];
    public const CRITICALITIES = ['Vitale', 'Importante', 'Secondaire'];
    public const LICENCE_STATUSES = ['Actif', 'En test', 'Retiré'];
    public const ACCESS_LEVELS = ['Utilisateur', 'Gestionnaire', 'Administrateur'];
    public const SEVERITIES = ['Critique', 'Majeur', 'Mineur'];
    public const INCIDENT_STATUSES = ['Ouvert', 'En cours', 'Résolu', 'Clos'];

    // Au-delà, un accès n'a plus été regardé depuis assez longtemps pour qu'on
    // puisse dire qu'il est encore justifié.
    public const REVIEW_MONTHS = 12;

    private const LICENCE_COLUMNS = '
        l.*,
        u.first_name AS owner_first_name, u.last_name AS owner_last_name,
        (SELECT COUNT(*) FROM software_accesses a WHERE a.licence_id = l.id AND a.revoked_on IS NULL) AS seats_used
    ';

    // ---------------------------------------------------------------- logiciels

    public static function licences(bool $includeRetired = false): array
    {
        $where = $includeRetired ? '' : "WHERE l.status != 'Retiré'";
        return Db::all(
            'SELECT ' . self::LICENCE_COLUMNS . "
             FROM software_licences l LEFT JOIN users u ON u.id = l.owner_id
             $where
             ORDER BY l.name COLLATE NOCASE"
        );
    }

    public static function licenceById(int $id): ?array
    {
        return Db::get(
            'SELECT ' . self::LICENCE_COLUMNS . '
             FROM software_licences l LEFT JOIN users u ON u.id = l.owner_id
             WHERE l.id = ?',
            [$id]
        );
    }

    public static function createLicence(array $fields): int
    {
        return Db::insert(
            'INSERT INTO software_licences (name, publisher, kind, seats, unit_cost, billing_period, renewal_date,
                                            owner_id, criticality, personal_data, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['publisher'] ?? '', $fields['kind'], $fields['seats'] ?? 0,
                $fields['unitCost'] ?? null, $fields['billingPeriod'] ?? 'Annuel',
                $fields['renewalDate'] ?? null, $fields['ownerId'] ?? null,
                $fields['criticality'] ?? 'Importante', !empty($fields['personalData']) ? 1 : 0,
                $fields['status'] ?? 'Actif', $fields['notes'] ?? '',
            ]
        );
    }

    public static function updateLicence(int $id, array $fields): void
    {
        Db::run(
            'UPDATE software_licences
             SET name = ?, publisher = ?, kind = ?, seats = ?, unit_cost = ?, billing_period = ?,
                 renewal_date = ?, owner_id = ?, criticality = ?, personal_data = ?, status = ?, notes = ?
             WHERE id = ?',
            [
                $fields['name'], $fields['publisher'] ?? '', $fields['kind'], $fields['seats'] ?? 0,
                $fields['unitCost'] ?? null, $fields['billingPeriod'] ?? 'Annuel',
                $fields['renewalDate'] ?? null, $fields['ownerId'] ?? null,
                $fields['criticality'] ?? 'Importante', !empty($fields['personalData']) ? 1 : 0,
                $fields['status'], $fields['notes'] ?? '', $id,
            ]
        );
    }

    public static function removeLicence(int $id): void
    {
        Db::run('DELETE FROM software_licences WHERE id = ?', [$id]);
    }

    /** Le coût annualisé d'un abonnement, tous sièges confondus. */
    public static function yearlyCost(array $licence): ?float
    {
        if ($licence['unit_cost'] === null) {
            return null;
        }
        $perYear = ['Ponctuel' => 0, 'Mensuel' => 12, 'Trimestriel' => 4, 'Annuel' => 1][$licence['billing_period']] ?? null;
        if ($perYear === null) {
            return null;
        }
        $seats = (int) $licence['seats'] > 0
            ? (int) $licence['seats']
            : max((int) ($licence['seats_used'] ?? 0), 1);
        return round((float) $licence['unit_cost'] * $perYear * $seats, 2);
    }

    // ---------------------------------------------------------------- accès

    public static function accesses(int $licenceId, bool $includeRevoked = true): array
    {
        $clause = $includeRevoked ? '' : 'AND a.revoked_on IS NULL';
        return Db::all(
            "SELECT a.*, u.first_name, u.last_name, u.email, u.active,
                    g.first_name AS by_first_name, g.last_name AS by_last_name
             FROM software_accesses a
             JOIN users u ON u.id = a.user_id
             LEFT JOIN users g ON g.id = a.granted_by
             WHERE a.licence_id = ? $clause
             ORDER BY a.revoked_on IS NOT NULL, u.last_name COLLATE NOCASE",
            [$licenceId]
        );
    }

    /** Les logiciels ouverts à une personne : ce qu'un départ laisse derrière lui. */
    public static function accessesFor(int $userId): array
    {
        return Db::all(
            'SELECT a.*, l.name, l.criticality
             FROM software_accesses a JOIN software_licences l ON l.id = a.licence_id
             WHERE a.user_id = ? AND a.revoked_on IS NULL
             ORDER BY l.name COLLATE NOCASE',
            [$userId]
        );
    }

    /**
     * Ouvre un accès. Le nombre de sièges est une contrainte, pas une indication :
     * dépasser ce qui est payé met l'entreprise en défaut de licence, et personne
     * ne s'en aperçoit avant l'audit de l'éditeur.
     */
    public static function grantAccess(array $fields): array
    {
        $licenceId = (int) $fields['licenceId'];
        $userId = (int) $fields['userId'];
        $licence = self::licenceById($licenceId);
        if ($licence === null) {
            return ['ok' => false, 'reason' => 'introuvable'];
        }
        $already = Db::get(
            'SELECT id FROM software_accesses WHERE licence_id = ? AND user_id = ? AND revoked_on IS NULL',
            [$licenceId, $userId]
        );
        if ($already !== null) {
            return ['ok' => false, 'reason' => 'deja'];
        }
        if ((int) $licence['seats'] > 0 && (int) $licence['seats_used'] >= (int) $licence['seats']) {
            return ['ok' => false, 'reason' => 'complet', 'seats' => (int) $licence['seats']];
        }

        $id = Db::insert(
            'INSERT INTO software_accesses (licence_id, user_id, level, granted_by, note) VALUES (?, ?, ?, ?, ?)',
            [
                $licenceId, $userId, $fields['level'] ?? 'Utilisateur',
                $fields['grantedBy'] ?? null, mb_substr((string) ($fields['note'] ?? ''), 0, 300),
            ]
        );
        return ['ok' => true, 'id' => $id];
    }

    public static function revokeAccess(int $id): bool
    {
        return Db::run(
            "UPDATE software_accesses SET revoked_on = date('now') WHERE id = ? AND revoked_on IS NULL",
            [$id]
        ) > 0;
    }

    /** Marquer un accès revu, c'est dire « je l'ai regardé, il est encore justifié ». */
    public static function markReviewed(int $id): bool
    {
        return Db::run(
            "UPDATE software_accesses SET reviewed_on = date('now') WHERE id = ? AND revoked_on IS NULL",
            [$id]
        ) > 0;
    }

    /**
     * La revue des accès. Elle ne juge pas à la place de l'exploitant : elle
     * remonte ce qui mérite un regard, avec le motif. Un compte fermé qui garde un
     * accès applicatif est le premier de la liste — c'est la faille la plus banale
     * et la plus exploitée : le départ a été traité côté RH, jamais côté SI.
     */
    public static function accessReview(): array
    {
        $rows = Db::all(
            'SELECT a.id, a.level, a.granted_on, a.reviewed_on, a.licence_id,
                    l.name AS licence_name, l.criticality,
                    u.id AS user_id, u.first_name, u.last_name, u.active, u.contract_end_date
             FROM software_accesses a
             JOIN software_licences l ON l.id = a.licence_id
             JOIN users u ON u.id = a.user_id
             WHERE a.revoked_on IS NULL
             ORDER BY l.name COLLATE NOCASE, u.last_name COLLATE NOCASE'
        );

        $staleBefore = gmdate('Y-m-d', strtotime('-' . self::REVIEW_MONTHS . ' months'));

        $flagged = [];
        foreach ($rows as $row) {
            // Un compte désactivé qui conserve un accès : à révoquer, sans discussion.
            if ((int) $row['active'] === 0) {
                $flagged[] = $row + ['reason' => 'inactif', 'severity' => 'Critique'];
            } elseif ($row['level'] === 'Administrateur') {
                $flagged[] = $row + ['reason' => 'admin', 'severity' => 'Majeur'];
            } elseif (($row['reviewed_on'] ?: $row['granted_on']) < $staleBefore) {
                $flagged[] = $row + ['reason' => 'ancien', 'severity' => 'Mineur'];
            }
        }
        return ['total' => count($rows), 'flagged' => $flagged];
    }

    // ---------------------------------------------------------------- incidents

    public static function incidents(bool $includeClosed = true, int $limit = 200): array
    {
        $clause = $includeClosed ? '' : "WHERE i.status NOT IN ('Résolu','Clos')";
        return Db::all(
            "SELECT i.*, s.name AS service_name, u.first_name, u.last_name
             FROM it_incidents i
             LEFT JOIN app_services s ON s.id = i.service_id
             LEFT JOIN users u ON u.id = i.declared_by
             $clause
             ORDER BY i.started_at DESC, i.id DESC LIMIT ?",
            [$limit]
        );
    }

    public static function incidentById(int $id): ?array
    {
        return Db::get('SELECT * FROM it_incidents WHERE id = ?', [$id]);
    }

    public static function createIncident(array $fields): int
    {
        return Db::insert(
            'INSERT INTO it_incidents (reference, title, service_id, severity, started_at, detected_at, impact, declared_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['reference'] ?? '', $fields['title'], $fields['serviceId'] ?? null,
                $fields['severity'], $fields['startedAt'], $fields['detectedAt'] ?? null,
                $fields['impact'] ?? '', $fields['declaredBy'] ?? null,
            ]
        );
    }

    public static function updateIncident(int $id, array $fields): void
    {
        Db::run(
            'UPDATE it_incidents
             SET title = ?, service_id = ?, severity = ?, started_at = ?, detected_at = ?, resolved_at = ?,
                 impact = ?, cause = ?, remediation = ?, status = ?
             WHERE id = ?',
            [
                $fields['title'], $fields['serviceId'] ?? null, $fields['severity'], $fields['startedAt'],
                $fields['detectedAt'] ?? null, $fields['resolvedAt'] ?? null, $fields['impact'] ?? '',
                $fields['cause'] ?? '', $fields['remediation'] ?? '', $fields['status'], $id,
            ]
        );
    }

    public static function removeIncident(int $id): void
    {
        Db::run('DELETE FROM it_incidents WHERE id = ?', [$id]);
    }

    /** Durée d'un incident en minutes, ou null tant qu'il n'est pas rétabli. */
    public static function downtimeMinutes(array $incident): ?int
    {
        if (empty($incident['resolved_at']) || empty($incident['started_at'])) {
            return null;
        }
        $start = strtotime(str_replace(' ', 'T', $incident['started_at']) . 'Z');
        $end = strtotime(str_replace(' ', 'T', $incident['resolved_at']) . 'Z');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        return (int) round(($end - $start) / 60);
    }

    /**
     * Le délai moyen de rétablissement, sur la fenêtre demandée. Calculé sur les
     * incidents effectivement rétablis : un incident encore ouvert n'a pas de durée,
     * et le compter à zéro flatterait l'indicateur au pire moment.
     */
    public static function incidentStats(int $days = 90): array
    {
        $since = gmdate('Y-m-d', strtotime("-$days days"));
        $rows = Db::all('SELECT * FROM it_incidents WHERE started_at >= ?', [$since]);

        $durations = array_values(array_filter(
            array_map([self::class, 'downtimeMinutes'], $rows),
            static fn (?int $d): bool => $d !== null
        ));
        $open = (int) Db::value("SELECT COUNT(*) FROM it_incidents WHERE status NOT IN ('Résolu','Clos')");

        return [
            'days' => $days,
            'total' => count($rows),
            'open' => $open,
            'critical' => count(array_filter($rows, static fn (array $r): bool => $r['severity'] === 'Critique')),
            'resolved' => count($durations),
            'meanMinutes' => $durations === [] ? null : (int) round(array_sum($durations) / count($durations)),
        ];
    }

    public static function summary(): array
    {
        $cost = 0.0;
        foreach (self::licences() as $licence) {
            $cost += self::yearlyCost($licence) ?? 0.0;
        }

        return [
            'licences' => (int) Db::value("SELECT COUNT(*) FROM software_licences WHERE status != 'Retiré'"),
            'accesses' => (int) Db::value('SELECT COUNT(*) FROM software_accesses WHERE revoked_on IS NULL'),
            'flagged' => count(self::accessReview()['flagged']),
            'yearlyCost' => round($cost, 2),
            'incidents' => self::incidentStats(),
        ];
    }
}
