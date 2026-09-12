<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Notifications sortantes.
 *
 * L'émission met la livraison en file ; l'envoi lui-même est fait par une
 * tâche planifiée (`php tools/worker.php`), parce qu'un site PHP ne tourne
 * qu'au moment d'une requête et qu'une requête utilisateur ne doit pas
 * attendre un serveur distant.
 */
final class Webhooks
{
    public const EVENTS = [
        'absence.approuvee', 'demande.creee', 'facture.emise', 'facture.payee',
        'membre.cree', 'membre.desactive', 'incident.ouvert', 'alerte.deposee',
    ];

    public static function emit(string $event, array $payload = []): int
    {
        if (!in_array($event, self::EVENTS, true)) {
            return 0;
        }
        $body = json_encode(['event' => $event, 'at' => gmdate('c'), 'data' => $payload], JSON_UNESCAPED_UNICODE);
        $targets = Db::all(
            "SELECT id FROM webhooks WHERE active = 1 AND (',' || events || ',') LIKE ?",
            ['%,' . $event . ',%']
        );
        foreach ($targets as $target) {
            Db::run(
                "INSERT INTO webhook_deliveries (webhook_id, event, payload, next_try_at) VALUES (?, ?, ?, datetime('now'))",
                [$target['id'], $event, $body]
            );
        }
        return count($targets);
    }

    public static function pending(int $limit = 50): array
    {
        return Db::all(
            "SELECT d.*, w.url, w.secret FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.delivered_at IS NULL AND d.attempts < 8 AND d.next_try_at <= datetime('now')
             ORDER BY d.id LIMIT ?",
            [$limit]
        );
    }
}
