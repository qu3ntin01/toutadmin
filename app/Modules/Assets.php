<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Parc matériel.
 *
 * Un équipement est détenu ou il ne l'est pas ; « Affecté » n'est donc pas un
 * statut qu'on pose à la main, c'est la conséquence d'une affectation ouverte.
 * L'historique des détenteurs reste, même après une reprise : c'est lui qui
 * répond à « qui avait ce portable l'an dernier ».
 */
final class Assets
{
    public const STATUSES = ['Disponible', 'Affecté', 'En maintenance', 'Réformé'];
    public const CATEGORIES = ['Informatique', 'Téléphonie', 'Mobilier', 'Véhicule', 'Outillage', 'Autre'];

    public static function all(): array
    {
        return Db::all(
            'SELECT a.*,
                    u.id AS holder_id, u.first_name AS holder_first_name, u.last_name AS holder_last_name
             FROM assets a
             LEFT JOIN asset_assignments aa ON aa.asset_id = a.id AND aa.returned_at IS NULL
             LEFT JOIN users u ON u.id = aa.employee_id
             ORDER BY a.category COLLATE NOCASE, a.name COLLATE NOCASE'
        );
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM assets WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        return Db::insert(
            'INSERT INTO assets (name, category, reference, serial_number, purchase_date, warranty_end, value, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'], $data['category'] ?? '', $data['reference'] ?? '', $data['serialNumber'] ?? '',
                $data['purchaseDate'] ?? null, $data['warrantyEnd'] ?? null, $data['value'] ?? null, $data['notes'] ?? '',
            ]
        );
    }

    public static function setStatus(int $id, string $status): array
    {
        if (!in_array($status, self::STATUSES, true)) {
            return ['ok' => false, 'reason' => 'bad-status'];
        }
        $asset = self::byId($id);
        if ($asset === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        // « Affecté » découle d'une affectation, il ne se déclare pas.
        if ($status === 'Affecté') {
            return ['ok' => false, 'reason' => 'assign-instead'];
        }
        if ($asset['status'] === 'Affecté') {
            return ['ok' => false, 'reason' => 'return-first'];
        }
        Db::run('UPDATE assets SET status = ? WHERE id = ?', [$status, $id]);
        return ['ok' => true];
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM assets WHERE id = ?', [$id]);
    }

    public static function assign(int $assetId, int $employeeId, string $note = ''): array
    {
        $asset = self::byId($assetId);
        if ($asset === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ($asset['status'] === 'Réformé') {
            return ['ok' => false, 'reason' => 'retired'];
        }
        if (Db::get('SELECT id FROM asset_assignments WHERE asset_id = ? AND returned_at IS NULL', [$assetId]) !== null) {
            return ['ok' => false, 'reason' => 'already-assigned'];
        }
        if (Db::get("SELECT id FROM users WHERE id = ? AND role = 'employee'", [$employeeId]) === null) {
            return ['ok' => false, 'reason' => 'no-employee'];
        }

        Db::transaction(static function () use ($assetId, $employeeId, $note): void {
            Db::insert('INSERT INTO asset_assignments (asset_id, employee_id, note) VALUES (?, ?, ?)', [$assetId, $employeeId, $note]);
            Db::run("UPDATE assets SET status = 'Affecté' WHERE id = ?", [$assetId]);
        });
        return ['ok' => true];
    }

    public static function takeBack(int $assetId): array
    {
        $open = Db::get('SELECT id FROM asset_assignments WHERE asset_id = ? AND returned_at IS NULL', [$assetId]);
        if ($open === null) {
            return ['ok' => false, 'reason' => 'not-assigned'];
        }
        Db::transaction(static function () use ($open, $assetId): void {
            Db::run("UPDATE asset_assignments SET returned_at = date('now') WHERE id = ?", [$open['id']]);
            Db::run("UPDATE assets SET status = 'Disponible' WHERE id = ?", [$assetId]);
        });
        return ['ok' => true];
    }

    /** Ce qu'une personne détient aujourd'hui : son matériel, dans son espace. */
    public static function of(int $employeeId): array
    {
        return Db::all(
            'SELECT a.*, aa.assigned_at, aa.note
             FROM asset_assignments aa JOIN assets a ON a.id = aa.asset_id
             WHERE aa.employee_id = ? AND aa.returned_at IS NULL
             ORDER BY a.name COLLATE NOCASE',
            [$employeeId]
        );
    }

    public static function history(int $assetId): array
    {
        return Db::all(
            'SELECT aa.*, u.first_name, u.last_name
             FROM asset_assignments aa JOIN users u ON u.id = aa.employee_id
             WHERE aa.asset_id = ? ORDER BY aa.assigned_at DESC',
            [$assetId]
        );
    }
}
