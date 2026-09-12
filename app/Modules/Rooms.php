<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Salles et réservations.
 *
 * Deux réservations se chevauchent dès qu'elles partagent un instant de la même
 * salle : le contrôle est fait en base, à l'écriture, pas dans l'affichage — un
 * planning qui montre un créneau libre et l'accepte deux fois ne sert à rien.
 */
final class Rooms
{
    private const TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public static function all(bool $activeOnly = false): array
    {
        $where = $activeOnly ? 'WHERE active = 1' : '';
        return Db::all("SELECT * FROM rooms $where ORDER BY name COLLATE NOCASE");
    }

    public static function byId(int $id): ?array
    {
        return Db::get('SELECT * FROM rooms WHERE id = ?', [$id]);
    }

    public static function create(array $data): int
    {
        $id = Db::insert(
            'INSERT INTO rooms (name, location, capacity, equipment) VALUES (?, ?, ?, ?)',
            [$data['name'], $data['location'] ?? '', $data['capacity'] ?? 0, $data['equipment'] ?? '']
        );
        Audit::log('salle.creee', 'rooms', $id, ['nom' => $data['name']]);
        return $id;
    }

    public static function toggle(int $id): bool
    {
        $room = self::byId($id);
        if ($room === null) {
            return false;
        }
        Db::run('UPDATE rooms SET active = ? WHERE id = ?', [(int) $room['active'] === 1 ? 0 : 1, $id]);
        return true;
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM rooms WHERE id = ?', [$id]);
        Audit::log('salle.supprimee', 'rooms', $id);
    }

    public static function bookings(?string $from = null, ?string $to = null, ?int $roomId = null): array
    {
        $clauses = [];
        $params = [];
        if ($from !== null) {
            $clauses[] = 'b.booking_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $clauses[] = 'b.booking_date <= ?';
            $params[] = $to;
        }
        if ($roomId !== null) {
            $clauses[] = 'b.room_id = ?';
            $params[] = $roomId;
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return Db::all(
            "SELECT b.*, r.name AS room_name, r.location, u.first_name, u.last_name
             FROM room_bookings b
             JOIN rooms r ON r.id = b.room_id
             JOIN users u ON u.id = b.user_id
             $where
             ORDER BY b.booking_date, b.start_time",
            $params
        );
    }

    public static function book(int $roomId, int $userId, string $title, string $date, string $start, string $end): array
    {
        $room = self::byId($roomId);
        if ($room === null) {
            return ['ok' => false, 'reason' => 'not-found'];
        }
        if ((int) $room['active'] !== 1) {
            return ['ok' => false, 'reason' => 'inactive'];
        }
        if (!preg_match(self::TIME, $start) || !preg_match(self::TIME, $end)) {
            return ['ok' => false, 'reason' => 'bad-time'];
        }
        if ($end <= $start) {
            return ['ok' => false, 'reason' => 'bad-range'];
        }

        // Chevauchement : la salle est prise dès qu'un créneau existant empiète.
        $clash = Db::get(
            'SELECT b.id, u.first_name, u.last_name, b.start_time, b.end_time
             FROM room_bookings b JOIN users u ON u.id = b.user_id
             WHERE b.room_id = ? AND b.booking_date = ? AND b.start_time < ? AND b.end_time > ?
             LIMIT 1',
            [$roomId, $date, $end, $start]
        );
        if ($clash !== null) {
            return ['ok' => false, 'reason' => 'clash', 'clash' => $clash];
        }

        Db::insert(
            'INSERT INTO room_bookings (room_id, user_id, title, booking_date, start_time, end_time) VALUES (?, ?, ?, ?, ?, ?)',
            [$roomId, $userId, $title, $date, $start, $end]
        );
        return ['ok' => true];
    }

    /** Chacun annule sa réservation ; la gestion peut annuler n'importe laquelle. */
    public static function cancel(int $id, int $userId, bool $force = false): bool
    {
        return $force
            ? Db::run('DELETE FROM room_bookings WHERE id = ?', [$id]) > 0
            : Db::run('DELETE FROM room_bookings WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }
}
