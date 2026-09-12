<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Notifications personnelles.
 *
 * Une même échéance ne doit alerter qu'une fois : chaque notification porte une
 * clé de déduplication, et l'index unique en base fait le reste. Le balayage
 * peut donc tourner toutes les heures sans jamais empiler de doublons.
 */
final class Notifications
{
    public static function push(int $userId, string $title, array $options = []): bool
    {
        if ($userId === 0 || $title === '') {
            return false;
        }
        // L'index unique est partiel (il ignore les clés vides) : la cible du
        // conflit doit reprendre sa condition, sinon SQLite ne la reconnaît pas.
        return Db::run(
            "INSERT INTO notifications (user_id, kind, title, body, link, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, dedupe_key) WHERE dedupe_key != '' DO NOTHING",
            [
                $userId,
                mb_substr($options['kind'] ?? 'echeance', 0, 40),
                mb_substr($title, 0, 200),
                mb_substr($options['body'] ?? '', 0, 500),
                mb_substr($options['link'] ?? '', 0, 200),
                mb_substr($options['dedupeKey'] ?? '', 0, 160),
            ]
        ) > 0;
    }

    public static function forUser(int $userId, int $limit = 50, bool $unreadOnly = false): array
    {
        $clause = $unreadOnly ? 'AND read_at IS NULL' : '';
        return Db::all(
            "SELECT * FROM notifications WHERE user_id = ? $clause
             ORDER BY read_at IS NOT NULL, id DESC LIMIT ?",
            [$userId, $limit]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    public static function markRead(int $id, int $userId): bool
    {
        return Db::run(
            "UPDATE notifications SET read_at = datetime('now') WHERE id = ? AND user_id = ? AND read_at IS NULL",
            [$id, $userId]
        ) > 0;
    }

    public static function markAllRead(int $userId): int
    {
        return Db::run("UPDATE notifications SET read_at = datetime('now') WHERE user_id = ? AND read_at IS NULL", [$userId]);
    }

    public static function remove(int $id, int $userId): bool
    {
        return Db::run('DELETE FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]) > 0;
    }

    /** Les notifications lues d'un certain âge s'effacent : la boîte ne gonfle pas sans fin. */
    public static function purgeRead(int $days = 60): int
    {
        return Db::run("DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < datetime('now', ?)", ['-' . $days . ' days']);
    }
}
