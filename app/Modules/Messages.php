<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Messagerie interne.
 *
 * Un message ne se lit et ne s'efface que par ceux qu'il concerne : l'auteur et
 * le destinataire. La marque « lu » n'est posée que lorsque le destinataire
 * ouvre — pas quand l'auteur relit son propre envoi.
 */
final class Messages
{
    public static function inbox(int $userId, int $limit = 100): array
    {
        return Db::all(
            'SELECT m.*, u.first_name, u.last_name, u.email, u.avatar_file
             FROM messages m JOIN users u ON u.id = m.sender_id
             WHERE m.recipient_id = ?
             ORDER BY m.created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public static function sent(int $userId, int $limit = 100): array
    {
        return Db::all(
            'SELECT m.*, u.first_name, u.last_name, u.email, u.avatar_file
             FROM messages m JOIN users u ON u.id = m.recipient_id
             WHERE m.sender_id = ?
             ORDER BY m.created_at DESC LIMIT ?',
            [$userId, $limit]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Db::value('SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND read_at IS NULL', [$userId]);
    }

    /** Ouvre un message si le lecteur y a part, et le marque lu s'il le reçoit. */
    public static function open(int $id, int $userId): ?array
    {
        $message = Db::get(
            'SELECT m.*, s.first_name AS sender_first, s.last_name AS sender_last, s.email AS sender_email,
                    r.first_name AS recipient_first, r.last_name AS recipient_last
             FROM messages m
             JOIN users s ON s.id = m.sender_id
             JOIN users r ON r.id = m.recipient_id
             WHERE m.id = ? AND (m.recipient_id = ? OR m.sender_id = ?)',
            [$id, $userId, $userId]
        );
        if ($message === null) {
            return null;
        }
        if ((int) $message['recipient_id'] === $userId && $message['read_at'] === null) {
            $now = gmdate('c');
            Db::run('UPDATE messages SET read_at = ? WHERE id = ?', [$now, $id]);
            $message['read_at'] = $now;
        }
        return $message;
    }

    public static function send(int $senderId, int $recipientId, string $subject, string $body, ?int $parentId = null): array
    {
        $recipient = Db::get('SELECT id FROM users WHERE id = ? AND active = 1', [$recipientId]);
        if ($recipient === null || $recipientId === $senderId) {
            return ['ok' => false, 'message' => 'Destinataire invalide.'];
        }
        if (trim($subject) === '') {
            return ['ok' => false, 'message' => "L'objet du message est obligatoire."];
        }
        if (trim($body) === '') {
            return ['ok' => false, 'message' => 'Le message ne peut pas être vide.'];
        }
        $id = Db::insert(
            'INSERT INTO messages (sender_id, recipient_id, subject, body, parent_id) VALUES (?, ?, ?, ?, ?)',
            [$senderId, $recipientId, mb_substr($subject, 0, 200), mb_substr($body, 0, 5000), $parentId]
        );
        Notifications::push($recipientId, 'Nouveau message', [
            'kind' => 'message',
            'body' => mb_substr($subject, 0, 300),
            'link' => '/messagerie?message=' . $id,
            'dedupeKey' => 'message:' . $id,
        ]);
        return ['ok' => true, 'id' => $id];
    }

    /** Chacun n'efface que les messages qui le concernent. */
    public static function remove(int $id, int $userId): bool
    {
        return Db::run('DELETE FROM messages WHERE id = ? AND (recipient_id = ? OR sender_id = ?)', [$id, $userId, $userId]) > 0;
    }

    public static function contacts(int $excludeId): array
    {
        return Db::all(
            'SELECT id, first_name, last_name, email FROM users WHERE active = 1 AND id != ?
             ORDER BY last_name COLLATE NOCASE',
            [$excludeId]
        );
    }
}
