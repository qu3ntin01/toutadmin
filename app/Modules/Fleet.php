<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Flotte de véhicules.
 *
 * Un véhicule d'entreprise porte trois échéances qui ne pardonnent pas : le
 * contrôle technique, l'assurance et l'entretien. Elles sont donc des colonnes
 * du véhicule, pas des événements à retrouver dans un historique.
 */
final class Fleet
{
    public const KINDS = ['Voiture', 'Utilitaire', 'Camion', 'Deux-roues', 'Engin'];
    public const STATUSES = ['En service', 'En réparation', 'Immobilisé', 'Cédé'];
    public const EVENT_KINDS = ['Entretien', 'Réparation', 'Contrôle technique', 'Sinistre', 'Carburant', 'Assurance'];

    public static function vehicles(bool $includeDisposed = false): array
    {
        $where = $includeDisposed ? '' : "WHERE v.status != 'Cédé'";
        return Db::all(
            "SELECT v.*, u.first_name, u.last_name,
                    (SELECT COALESCE(SUM(cost), 0) FROM vehicle_events WHERE vehicle_id = v.id) AS total_cost
             FROM vehicles v LEFT JOIN users u ON u.id = v.assigned_to
             $where
             ORDER BY v.registration COLLATE NOCASE"
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM vehicles WHERE id = ?', [$id]);
    }

    public static function create(array $fields): int
    {
        return Db::insert(
            'INSERT INTO vehicles (registration, brand, model, kind, acquired_on, mileage, assigned_to,
                                   insurance_due, inspection_due, service_due, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['registration'], $fields['brand'] ?? '', $fields['model'] ?? '', $fields['kind'],
                $fields['acquiredOn'] ?? null, $fields['mileage'] ?? 0, $fields['assignedTo'] ?? null,
                $fields['insuranceDue'] ?? null, $fields['inspectionDue'] ?? null, $fields['serviceDue'] ?? null,
                $fields['status'] ?? 'En service',
            ]
        );
    }

    public static function update(int $id, array $fields): void
    {
        Db::run(
            'UPDATE vehicles SET brand = ?, model = ?, kind = ?, acquired_on = ?, mileage = ?, assigned_to = ?,
                    insurance_due = ?, inspection_due = ?, service_due = ?, status = ? WHERE id = ?',
            [
                $fields['brand'] ?? '', $fields['model'] ?? '', $fields['kind'], $fields['acquiredOn'] ?? null,
                $fields['mileage'] ?? 0, $fields['assignedTo'] ?? null, $fields['insuranceDue'] ?? null,
                $fields['inspectionDue'] ?? null, $fields['serviceDue'] ?? null, $fields['status'], $id,
            ]
        );
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM vehicles WHERE id = ?', [$id]);
    }

    public static function events(int $vehicleId): array
    {
        return Db::all(
            'SELECT * FROM vehicle_events WHERE vehicle_id = ? ORDER BY occurred_on DESC, id DESC',
            [$vehicleId]
        );
    }

    /**
     * Un événement peut faire avancer le compteur : le kilométrage du véhicule suit
     * le relevé le plus élevé, jamais un chiffre inférieur saisi par erreur.
     */
    public static function addEvent(array $fields): int
    {
        $mileage = (int) ($fields['mileage'] ?? 0);
        $id = Db::insert(
            'INSERT INTO vehicle_events (vehicle_id, kind, occurred_on, mileage, cost, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fields['vehicleId'], $fields['kind'], $fields['occurredOn'],
                $mileage ?: null, $fields['cost'] ?? null, $fields['note'] ?? '',
            ]
        );

        if ($mileage > 0) {
            Db::run('UPDATE vehicles SET mileage = MAX(mileage, ?) WHERE id = ?', [$mileage, $fields['vehicleId']]);
        }
        return $id;
    }

    public static function deleteEvent(int $id): ?int
    {
        $row = Db::get('SELECT vehicle_id FROM vehicle_events WHERE id = ?', [$id]);
        Db::run('DELETE FROM vehicle_events WHERE id = ?', [$id]);
        return $row === null ? null : (int) $row['vehicle_id'];
    }

    /** Les échéances : ce qui est dépassé d'abord, puis ce qui approche. */
    public static function deadlines(int $withinDays = 60): array
    {
        $window = '+' . ($withinDays ?: 60) . ' days';
        $rows = Db::all(
            "SELECT * FROM vehicles WHERE status != 'Cédé' AND (
               (insurance_due IS NOT NULL AND insurance_due <= date('now', ?)) OR
               (inspection_due IS NOT NULL AND inspection_due <= date('now', ?)) OR
               (service_due IS NOT NULL AND service_due <= date('now', ?))
             )",
            [$window, $window, $window]
        );

        $today = gmdate('Y-m-d');
        $limit = gmdate('Y-m-d', strtotime("+$withinDays days"));
        $out = [];
        foreach ($rows as $vehicle) {
            foreach ([
                ['insurance_due', 'Assurance'],
                ['inspection_due', 'Contrôle technique'],
                ['service_due', 'Entretien'],
            ] as [$field, $label]) {
                $due = $vehicle[$field];
                if (empty($due)) {
                    continue;
                }
                $out[] = ['vehicle' => $vehicle, 'label' => $label, 'due' => $due, 'overdue' => $due < $today];
            }
        }
        $out = array_values(array_filter($out, static fn (array $row): bool => $row['due'] <= $limit));
        usort($out, static fn (array $a, array $b): int => strcmp($a['due'], $b['due']));
        return $out;
    }

    public static function summary(): array
    {
        $overdue = count(array_filter(self::deadlines(), static fn (array $d): bool => $d['overdue']));

        return [
            'active' => (int) Db::value("SELECT COUNT(*) FROM vehicles WHERE status != 'Cédé'"),
            'unassigned' => (int) Db::value(
                "SELECT COUNT(*) FROM vehicles WHERE status = 'En service' AND assigned_to IS NULL"
            ),
            'cost' => round((float) Db::value(
                "SELECT COALESCE(SUM(cost), 0) FROM vehicle_events WHERE occurred_on >= date('now', '-1 year')"
            ), 2),
            'overdue' => $overdue,
        ];
    }
}
