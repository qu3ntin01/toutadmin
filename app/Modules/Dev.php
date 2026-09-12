<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Espace développement : le référentiel des services applicatifs et le registre
 * des livraisons.
 *
 * Deux questions reviennent sans cesse dans une équipe technique, et aucun outil
 * de gestion classique n'y répond : « qu'est-ce qui tourne, et qui en répond ? »
 * et « qu'est-ce qui est parti en production, quand, et est-ce que ça a tenu ? ».
 * Le reste — le code, l'intégration continue — vit ailleurs et doit y rester ;
 * ce module tient la mémoire, pas la machinerie.
 */
final class Dev
{
    public const SERVICE_STATUSES = ['En construction', 'En service', 'Retiré'];
    public const CRITICALITIES = ['Vitale', 'Importante', 'Secondaire'];
    public const ENVIRONMENTS = ['Développement', 'Recette', 'Préproduction', 'Production'];
    public const RELEASE_STATUSES = ['Planifiée', 'Livrée', 'Échouée', 'Retirée'];

    private const SERVICE_COLUMNS = "
        s.*,
        u.first_name AS lead_first_name, u.last_name AS lead_last_name,
        p.name AS project_name,
        (SELECT COUNT(*) FROM releases r WHERE r.service_id = s.id) AS release_count,
        (SELECT r.version FROM releases r
          WHERE r.service_id = s.id AND r.environment = 'Production' AND r.status = 'Livrée'
          ORDER BY r.released_on DESC, r.id DESC LIMIT 1) AS live_version,
        (SELECT r.released_on FROM releases r
          WHERE r.service_id = s.id AND r.environment = 'Production' AND r.status = 'Livrée'
          ORDER BY r.released_on DESC, r.id DESC LIMIT 1) AS live_since,
        (SELECT COUNT(*) FROM it_incidents i WHERE i.service_id = s.id AND i.status NOT IN ('Résolu','Clos')) AS open_incidents
    ";

    // ---------------------------------------------------------------- services

    public static function services(bool $includeRetired = false): array
    {
        $where = $includeRetired ? '' : "WHERE s.status != 'Retiré'";
        return Db::all(
            'SELECT ' . self::SERVICE_COLUMNS . "
             FROM app_services s
             LEFT JOIN users u ON u.id = s.lead_id
             LEFT JOIN projects p ON p.id = s.project_id
             $where
             ORDER BY s.name COLLATE NOCASE"
        );
    }

    public static function serviceById(int $id): ?array
    {
        return Db::get(
            'SELECT ' . self::SERVICE_COLUMNS . '
             FROM app_services s
             LEFT JOIN users u ON u.id = s.lead_id
             LEFT JOIN projects p ON p.id = s.project_id
             WHERE s.id = ?',
            [$id]
        );
    }

    public static function createService(array $fields): int
    {
        return Db::insert(
            'INSERT INTO app_services (name, code, description, repository, documentation, stack, criticality,
                                       lead_id, project_id, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['name'], $fields['code'] ?? '', $fields['description'] ?? '', $fields['repository'] ?? '',
                $fields['documentation'] ?? '', $fields['stack'] ?? '', $fields['criticality'] ?? 'Importante',
                $fields['leadId'] ?? null, $fields['projectId'] ?? null, $fields['status'] ?? 'En service',
            ]
        );
    }

    public static function updateService(int $id, array $fields): void
    {
        Db::run(
            'UPDATE app_services
             SET name = ?, code = ?, description = ?, repository = ?, documentation = ?, stack = ?,
                 criticality = ?, lead_id = ?, project_id = ?, status = ?
             WHERE id = ?',
            [
                $fields['name'], $fields['code'] ?? '', $fields['description'] ?? '', $fields['repository'] ?? '',
                $fields['documentation'] ?? '', $fields['stack'] ?? '', $fields['criticality'] ?? 'Importante',
                $fields['leadId'] ?? null, $fields['projectId'] ?? null, $fields['status'], $id,
            ]
        );
    }

    public static function removeService(int $id): void
    {
        Db::run('DELETE FROM app_services WHERE id = ?', [$id]);
    }

    // ---------------------------------------------------------------- livraisons

    public static function releases(?int $serviceId = null, int $limit = 100): array
    {
        $clause = $serviceId !== null ? 'WHERE r.service_id = ?' : '';
        $params = $serviceId !== null ? [$serviceId, $limit] : [$limit];

        return Db::all(
            "SELECT r.*, s.name AS service_name, s.code AS service_code,
                    u.first_name, u.last_name, i.title AS incident_title
             FROM releases r
             JOIN app_services s ON s.id = r.service_id
             LEFT JOIN users u ON u.id = r.author_id
             LEFT JOIN it_incidents i ON i.id = r.incident_id
             $clause
             ORDER BY COALESCE(r.released_on, r.planned_on) DESC, r.id DESC LIMIT ?",
            $params
        );
    }

    public static function releaseById(int $id): ?array
    {
        return Db::get('SELECT * FROM releases WHERE id = ?', [$id]);
    }

    public static function createRelease(array $fields): int
    {
        return Db::insert(
            'INSERT INTO releases (service_id, version, environment, planned_on, released_on, status, changelog, author_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['serviceId'], $fields['version'], $fields['environment'], $fields['plannedOn'] ?? null,
                $fields['releasedOn'] ?? null, $fields['status'] ?? 'Planifiée',
                $fields['changelog'] ?? '', $fields['authorId'] ?? null,
            ]
        );
    }

    public static function updateRelease(int $id, array $fields): void
    {
        Db::run(
            'UPDATE releases
             SET version = ?, environment = ?, planned_on = ?, released_on = ?, status = ?, changelog = ?, incident_id = ?
             WHERE id = ?',
            [
                $fields['version'], $fields['environment'], $fields['plannedOn'] ?? null,
                $fields['releasedOn'] ?? null, $fields['status'], $fields['changelog'] ?? '',
                $fields['incidentId'] ?? null, $id,
            ]
        );
    }

    public static function removeRelease(int $id): void
    {
        Db::run('DELETE FROM releases WHERE id = ?', [$id]);
    }

    /**
     * Indicateurs de livraison, sur la fenêtre demandée.
     *
     * Le taux d'échec compte les livraisons échouées et celles qu'il a fallu
     * retirer : une livraison annulée en catastrophe a coûté autant qu'une panne,
     * et l'oublier reviendrait à ne mesurer que les bons jours. Le délai de
     * rétablissement, lui, vient des incidents — il n'y a qu'une seule source de
     * vérité pour cette durée-là, et elle est du côté du service informatique.
     */
    public static function deliveryStats(int $days = 90): array
    {
        $from = gmdate('Y-m-d', strtotime("-$days days"));

        $rows = Db::all(
            "SELECT status FROM releases
             WHERE environment = 'Production' AND status != 'Planifiée' AND released_on IS NOT NULL AND released_on >= ?",
            [$from]
        );

        $failed = count(array_filter(
            $rows,
            static fn (array $r): bool => $r['status'] === 'Échouée' || $r['status'] === 'Retirée'
        ));
        $delivered = count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'Livrée'));
        $planned = (int) Db::value("SELECT COUNT(*) FROM releases WHERE status = 'Planifiée'");

        return [
            'days' => $days,
            'delivered' => $delivered,
            'failed' => $failed,
            'planned' => $planned,
            'attempts' => count($rows),
            // Livraisons par semaine, arrondi au dixième : « 0,4 » se lit mieux que « 5 sur 90 jours ».
            'perWeek' => $rows === [] ? 0.0 : round($delivered / ($days / 7), 1),
            'failureRate' => $rows === [] ? 0 : (int) round($failed / count($rows) * 100),
        ];
    }

    public static function summary(): array
    {
        return [
            'live' => (int) Db::value("SELECT COUNT(*) FROM app_services WHERE status = 'En service'"),
            'vital' => (int) Db::value(
                "SELECT COUNT(*) FROM app_services WHERE status = 'En service' AND criticality = 'Vitale'"
            ),
            'orphan' => (int) Db::value(
                "SELECT COUNT(*) FROM app_services WHERE status != 'Retiré' AND lead_id IS NULL"
            ),
            'delivery' => self::deliveryStats(),
        ];
    }
}
