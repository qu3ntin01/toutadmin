<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;
use App\Core\Secrets;

/**
 * Webhooks sortants.
 *
 * Prévenir un outil tiers au moment où quelque chose se passe, plutôt que de
 * le faire interroger l'API toutes les minutes. Quatre précautions :
 *
 *   — chaque envoi est **signé** (HMAC-SHA256 du corps, avec le secret du
 *     webhook) : le destinataire peut vérifier que l'appel vient bien d'ici et
 *     n'a pas été modifié en route ;
 *   — le secret est **chiffré en base**, comme les autres ;
 *   — une URL **interne** (boucle locale, réseau privé) est refusée par défaut :
 *     faire émettre des requêtes à un serveur vers son propre réseau est une
 *     porte dérobée classique. L'autoriser se fait sciemment, case cochée ;
 *   — un échec n'est pas silencieux : il est **journalisé, réessayé**, et le
 *     webhook se désactive tout seul après une série d'échecs, plutôt que de
 *     faire croire que l'information passe.
 *
 * L'émission met la livraison en file ; l'envoi lui-même est fait par la tâche
 * planifiée, parce qu'un site PHP ne tourne qu'au moment d'une requête et
 * qu'une requête utilisateur ne doit pas attendre un serveur distant.
 */
final class Webhooks
{
    public const EVENTS = [
        ['key' => 'facture.creee', 'label' => 'Facture créée'],
        ['key' => 'facture.payee', 'label' => 'Facture payée'],
        ['key' => 'absence.approuvee', 'label' => 'Absence approuvée'],
        ['key' => 'membre.arrive', 'label' => 'Membre ajouté'],
        ['key' => 'membre.parti', 'label' => 'Membre désactivé'],
        ['key' => 'document.signe', 'label' => 'Document intégralement signé'],
        ['key' => 'ticket.ouvert', 'label' => 'Ticket de support ouvert'],
        ['key' => 'sauvegarde.echec', 'label' => "Échec d'externalisation de sauvegarde"],
    ];

    public const MAX_ATTEMPTS = 5;
    public const TIMEOUT = 8;
    /** Après cette série d'échecs consécutifs, le webhook s'éteint de lui-même. */
    public const FAILURE_LIMIT = 20;

    /** Un appel HTTP. Remplaçable pour que les tests se passent du réseau. */
    public static ?\Closure $transport = null;

    public static function eventKeys(): array
    {
        return array_column(self::EVENTS, 'key');
    }

    /** Une adresse interne ne se devine pas au nom : on regarde ce qu'il désigne. */
    public static function isPrivateHost(?string $hostname): bool
    {
        $host = mb_strtolower((string) $hostname);
        if ($host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            return true;
        }
        if ($host === '::1' || $host === '0.0.0.0') {
            return true;
        }

        $parts = explode('.', $host);
        if (count($parts) === 4) {
            foreach ($parts as $part) {
                if (!preg_match('/^\d{1,3}$/', $part)) {
                    return false;
                }
            }
            [$a, $b] = array_map('intval', $parts);
            if ($a === 127 || $a === 10 || $a === 0) {
                return true;
            }
            if ($a === 192 && $b === 168) {
                return true;
            }
            if ($a === 172 && $b >= 16 && $b <= 31) {
                return true;
            }
            if ($a === 169 && $b === 254) {
                return true;
            }
        }
        return false;
    }

    public static function checkUrl(?string $raw, bool $allowPrivate = false): array
    {
        $value = trim((string) $raw);
        $parts = $value === '' ? false : parse_url($value);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'message' => 'URL invalide.'];
        }

        $scheme = mb_strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return ['ok' => false, 'message' => 'Seules les adresses http(s) sont acceptées.'];
        }
        if ($scheme === 'http' && !$allowPrivate) {
            return ['ok' => false,
                    'message' => 'En clair (http), la charge utile et sa signature circulent lisibles : utilisez https.'];
        }
        if (self::isPrivateHost($parts['host']) && !$allowPrivate) {
            return ['ok' => false,
                    'message' => "Cette adresse désigne le réseau interne. Cochez la case correspondante si c'est voulu."];
        }
        return ['ok' => true, 'url' => $value];
    }

    public static function all(): array
    {
        return array_map(static function (array $row): array {
            $row['eventList'] = $row['events'] === '' ? [] : explode(',', (string) $row['events']);
            // Le secret ne ressort jamais : on n'en montre que l'existence.
            $row['secretSet'] = Secrets::decrypt($row['secret']) !== '';
            return $row;
        }, Db::all('SELECT * FROM webhooks ORDER BY active DESC, label COLLATE NOCASE'));
    }

    public static function byId(int $id): ?array
    {
        $row = Db::get('SELECT * FROM webhooks WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        $row['eventList'] = $row['events'] === '' ? [] : explode(',', (string) $row['events']);
        return $row;
    }

    public static function create(array $fields): array
    {
        $label = mb_substr(trim((string) ($fields['label'] ?? '')), 0, 120);
        if ($label === '') {
            return ['ok' => false, 'message' => 'Un intitulé est requis.'];
        }

        $kept = array_values(array_unique(array_filter(
            $fields['events'] ?? [],
            static fn (string $event): bool => in_array($event, self::eventKeys(), true)
        )));
        if ($kept === []) {
            return ['ok' => false, 'message' => 'Choisissez au moins un événement.'];
        }

        $allowPrivate = !empty($fields['allowPrivate']);
        $target = self::checkUrl($fields['url'] ?? '', $allowPrivate);
        if (!$target['ok']) {
            return $target;
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Db::insert(
            'INSERT INTO webhooks (label, url, secret, events, allow_private, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $label, $target['url'], Secrets::encrypt($secret), implode(',', $kept),
                $allowPrivate ? 1 : 0, $fields['createdBy'] ?? null,
            ]
        );

        // Le secret n'est rendu qu'ici : le destinataire doit le recevoir maintenant.
        return ['ok' => true, 'id' => $id, 'secret' => $secret];
    }

    public static function setActive(int $id, bool $active): bool
    {
        return Db::run(
            'UPDATE webhooks SET active = ?, failures = CASE WHEN ? THEN 0 ELSE failures END WHERE id = ?',
            [$active ? 1 : 0, $active ? 1 : 0, $id]
        ) > 0;
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM webhooks WHERE id = ?', [$id]);
    }

    public static function signature(string $secret, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /** Met un événement en file pour tous les webhooks qui l'écoutent. */
    public static function emit(string $event, array $payload = []): int
    {
        if (!in_array($event, self::eventKeys(), true)) {
            return 0;
        }

        $body = (string) json_encode(
            ['event' => $event, 'at' => gmdate('c'), 'data' => $payload],
            JSON_UNESCAPED_UNICODE
        );
        $targets = Db::all(
            "SELECT id FROM webhooks WHERE active = 1 AND (',' || events || ',') LIKE ?",
            ['%,' . $event . ',%']
        );

        foreach ($targets as $target) {
            Db::run(
                "INSERT INTO webhook_deliveries (webhook_id, event, payload, next_try_at)
                 VALUES (?, ?, ?, datetime('now'))",
                [(int) $target['id'], $event, $body]
            );
        }
        return count($targets);
    }

    public static function pending(int $limit = 50): array
    {
        return Db::all(
            "SELECT d.*, w.url, w.secret, w.label, w.active FROM webhook_deliveries d
             JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.status = 'En attente' AND (d.next_try_at IS NULL OR d.next_try_at <= datetime('now'))
             ORDER BY d.id LIMIT ?",
            [$limit]
        );
    }

    private static function backoffMinutes(int $attempts): int
    {
        return (int) min(60, 2 ** $attempts);
    }

    /** Envoie une livraison. Rend le verdict sans jamais lever d'exception. */
    public static function deliver(array $delivery): array
    {
        $secret = Secrets::decrypt($delivery['secret']);
        if ($secret === '') {
            return ['ok' => false, 'status' => 0, 'error' => "secret illisible (secret d'instance changé ?)"];
        }

        $headers = [
            'content-type: application/json',
            'x-toutadmin-event: ' . $delivery['event'],
            'x-toutadmin-signature: ' . self::signature($secret, (string) $delivery['payload']),
            'user-agent: Salarie-Member-Webhook/1',
        ];

        if (self::$transport !== null) {
            return (self::$transport)((string) $delivery['url'], $headers, (string) $delivery['payload']);
        }

        $handle = curl_init((string) $delivery['url']);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) $delivery['payload'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $status === 0) {
            return ['ok' => false, 'status' => 0, 'error' => $error !== '' ? $error : 'service injoignable'];
        }
        return $status >= 200 && $status < 300
            ? ['ok' => true, 'status' => $status]
            : ['ok' => false, 'status' => $status, 'error' => "réponse $status"];
    }

    public static function recordSuccess(array $delivery, int $status): void
    {
        Db::run(
            "UPDATE webhook_deliveries
             SET status = 'Livré', attempts = attempts + 1, delivered_at = datetime('now'), last_error = ''
             WHERE id = ?",
            [(int) $delivery['id']]
        );
        Db::run(
            "UPDATE webhooks SET last_status = ?, last_attempt_at = datetime('now'), failures = 0 WHERE id = ?",
            ["OK $status", (int) $delivery['webhook_id']]
        );
    }

    public static function recordFailure(array $delivery, string $error): void
    {
        $attempts = (int) $delivery['attempts'] + 1;
        $exhausted = $attempts >= self::MAX_ATTEMPTS;

        Db::run(
            "UPDATE webhook_deliveries
             SET status = ?, attempts = ?, last_error = ?, next_try_at = datetime('now', ?)
             WHERE id = ?",
            [
                $exhausted ? 'Abandonné' : 'En attente', $attempts, mb_substr($error, 0, 300),
                '+' . self::backoffMinutes($attempts) . ' minutes', (int) $delivery['id'],
            ]
        );

        $failures = (int) Db::value('SELECT failures FROM webhooks WHERE id = ?', [(int) $delivery['webhook_id']]) + 1;
        Db::run(
            "UPDATE webhooks SET last_status = ?, last_attempt_at = datetime('now'), failures = ? WHERE id = ?",
            [mb_substr($error, 0, 200), $failures, (int) $delivery['webhook_id']]
        );

        // Un webhook qui échoue depuis des jours ne doit pas continuer à faire
        // croire que l'information passe : il s'éteint, et l'administration le
        // voit éteint.
        if ($failures >= self::FAILURE_LIMIT) {
            Db::run('UPDATE webhooks SET active = 0 WHERE id = ?', [(int) $delivery['webhook_id']]);
        }
    }

    /** Vide la file d'attente. Appelée par la tâche planifiée. */
    public static function flush(int $limit = 50): array
    {
        $delivered = 0;
        $failed = 0;

        foreach (self::pending($limit) as $delivery) {
            $verdict = self::deliver($delivery);
            if (!empty($verdict['ok'])) {
                self::recordSuccess($delivery, (int) $verdict['status']);
                $delivered++;
            } else {
                self::recordFailure($delivery, (string) $verdict['error']);
                $failed++;
            }
        }
        return ['delivered' => $delivered, 'failed' => $failed];
    }

    public static function deliveries(?int $webhookId = null, int $limit = 100): array
    {
        $clause = $webhookId === null ? '' : 'WHERE d.webhook_id = ?';
        $params = $webhookId === null ? [$limit] : [$webhookId, $limit];
        return Db::all(
            "SELECT d.*, w.label FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
             $clause ORDER BY d.id DESC LIMIT ?",
            $params
        );
    }

    /** Purge les livraisons anciennes : la file est un journal, pas une archive. */
    public static function purge(int $days = 30): int
    {
        return Db::run(
            "DELETE FROM webhook_deliveries WHERE status != 'En attente' AND created_at < datetime('now', ?)",
            ['-' . $days . ' days']
        );
    }

    public static function summary(): array
    {
        return [
            'active' => (int) Db::value('SELECT COUNT(*) FROM webhooks WHERE active = 1'),
            'waiting' => (int) Db::value("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'En attente'"),
            'abandoned' => (int) Db::value("SELECT COUNT(*) FROM webhook_deliveries WHERE status = 'Abandonné'"),
        ];
    }
}
