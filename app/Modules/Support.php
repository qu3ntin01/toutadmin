<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Audit;
use App\Core\Db;

/**
 * Tickets et base de connaissances.
 *
 * Un même mécanisme sert les demandes internes (informatique, RH, moyens
 * généraux) et les demandes clients : ce qui change est l'origine et le
 * demandeur, pas le circuit. Le délai de traitement se calcule à partir de la
 * priorité, ce qui rend visible ce qui dérape sans qu'on ait à le déclarer.
 */
final class Support
{
    public const CATEGORIES = ['Informatique', 'Ressources humaines', 'Moyens généraux', 'Client', 'Autre'];
    public const PRIORITIES = ['Basse', 'Normale', 'Haute', 'Critique'];
    public const STATUSES = ['Ouvert', 'En cours', 'En attente', 'Résolu', 'Clos'];
    public const ORIGINS = ['Interne', 'Client'];
    public const OPEN_STATUSES = ['Ouvert', 'En cours', 'En attente'];

    /**
     * Une demande RH parle de paie, de contrat, parfois de santé : elle ne se
     * traite pas par la même file que le remplacement d'un écran.
     */
    public const RESTRICTED_CATEGORY = 'Ressources humaines';

    /** Délai de première réponse attendu, en heures, selon la priorité. */
    public const RESPONSE_HOURS = ['Critique' => 2, 'Haute' => 8, 'Normale' => 24, 'Basse' => 72];

    public const KB_VISIBILITIES = ['Entreprise', 'Service', 'Équipe', 'Administration'];

    /**
     * Les catégories qu'une personne peut traiter. Un manager d'équipe n'a pas
     * à lire les demandes RH de toute l'entreprise ; les RH, si.
     */
    public static function agentCategories(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        if ($user['role'] === 'admin' || (int) ($user['is_hr'] ?? 0) === 1) {
            return self::CATEGORIES;
        }
        if ((int) ($user['is_finance'] ?? 0) === 1) {
            return array_values(array_filter(self::CATEGORIES, static fn (string $c): bool => $c !== self::RESTRICTED_CATEGORY));
        }
        return [];
    }

    public static function reference(int $id): string
    {
        return 'T-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function dueFor(string $priority, ?int $from = null): string
    {
        $hours = self::RESPONSE_HOURS[$priority] ?? 24;
        return gmdate('c', ($from ?? time()) + $hours * 3600);
    }

    public static function create(array $fields): int
    {
        $id = Db::insert(
            'INSERT INTO tickets (subject, body, category, priority, origin, requester_id, partner_id, due_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $fields['subject'], $fields['body'] ?? '', $fields['category'], $fields['priority'],
                $fields['origin'], $fields['requesterId'] ?? null, $fields['partnerId'] ?? null,
                self::dueFor($fields['priority']),
            ]
        );
        Db::run('UPDATE tickets SET reference = ? WHERE id = ?', [self::reference($id), $id]);
        Audit::log('ticket.ouvert', 'tickets', $id, ['sujet' => $fields['subject']]);
        Webhooks::emit('ticket.ouvert', [
            'id' => $id, 'reference' => self::reference($id), 'sujet' => $fields['subject'],
            'priorite' => $fields['priority'], 'origine' => $fields['origin'],
        ]);
        return $id;
    }

    public static function byId(int $id): ?array
    {
        return Db::get(
            'SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name, r.email AS requester_email,
                    a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
                    p.name AS partner_name
             FROM tickets tk
             LEFT JOIN users r ON r.id = tk.requester_id
             LEFT JOIN users a ON a.id = tk.assignee_id
             LEFT JOIN partners p ON p.id = tk.partner_id
             WHERE tk.id = ?',
            [$id]
        );
    }

    public static function list(array $filters = []): array
    {
        $clauses = [];
        $params = [];
        // Restriction par catégorie : posée en SQL, pas retirée à l'affichage.
        if (array_key_exists('categories', $filters) && $filters['categories'] !== null) {
            if ($filters['categories'] === []) {
                return [];
            }
            $clauses[] = 'tk.category IN (' . implode(',', array_fill(0, count($filters['categories']), '?')) . ')';
            $params = array_merge($params, $filters['categories']);
        }
        if (!empty($filters['status'])) {
            $clauses[] = 'tk.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['openOnly'])) {
            $clauses[] = 'tk.status IN (' . implode(',', array_fill(0, count(self::OPEN_STATUSES), '?')) . ')';
            $params = array_merge($params, self::OPEN_STATUSES);
        }
        if (!empty($filters['category'])) {
            $clauses[] = 'tk.category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['assigneeId'])) {
            $clauses[] = 'tk.assignee_id = ?';
            $params[] = $filters['assigneeId'];
        }
        if (!empty($filters['requesterId'])) {
            $clauses[] = 'tk.requester_id = ?';
            $params[] = $filters['requesterId'];
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        return Db::all(
            "SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name,
                    a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
                    p.name AS partner_name,
                    (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = tk.id) AS message_count
             FROM tickets tk
             LEFT JOIN users r ON r.id = tk.requester_id
             LEFT JOIN users a ON a.id = tk.assignee_id
             LEFT JOIN partners p ON p.id = tk.partner_id
             $where
             ORDER BY tk.status IN ('Résolu','Clos'),
                      CASE tk.priority WHEN 'Critique' THEN 0 WHEN 'Haute' THEN 1 WHEN 'Normale' THEN 2 ELSE 3 END,
                      tk.due_at",
            $params
        );
    }

    public static function messages(int $ticketId, bool $includeInternal = true): array
    {
        $clause = $includeInternal ? '' : 'AND m.internal = 0';
        return Db::all(
            "SELECT m.*, u.first_name, u.last_name
             FROM ticket_messages m LEFT JOIN users u ON u.id = m.author_id
             WHERE m.ticket_id = ? $clause
             ORDER BY m.id",
            [$ticketId]
        );
    }

    /** Répondre marque la première réponse : c'est elle que mesure le délai. */
    public static function reply(int $ticketId, ?int $authorId, string $body, bool $internal = false): void
    {
        Db::insert(
            'INSERT INTO ticket_messages (ticket_id, author_id, body, internal) VALUES (?, ?, ?, ?)',
            [$ticketId, $authorId, $body, $internal ? 1 : 0]
        );
        if (!$internal) {
            Db::run("UPDATE tickets SET first_reply_at = COALESCE(first_reply_at, datetime('now')) WHERE id = ?", [$ticketId]);
        }
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $closing = in_array($status, ['Résolu', 'Clos'], true);
        Db::run('UPDATE tickets SET status = ?, closed_at = ? WHERE id = ?', [$status, $closing ? gmdate('c') : null, $id]);
        return true;
    }

    public static function assign(int $id, ?int $userId): void
    {
        Db::run('UPDATE tickets SET assignee_id = ? WHERE id = ?', [$userId ?: null, $id]);
    }

    public static function setPriority(int $id, string $priority): bool
    {
        if (!in_array($priority, self::PRIORITIES, true)) {
            return false;
        }
        $ticket = Db::get('SELECT created_at FROM tickets WHERE id = ?', [$id]);
        if ($ticket === null) {
            return false;
        }
        // Le délai suit la priorité : il se recalcule depuis l'ouverture, pas
        // depuis maintenant — sinon changer la priorité rendrait un retard nul.
        $from = strtotime((string) $ticket['created_at'] . ' UTC') ?: time();
        Db::run('UPDATE tickets SET priority = ?, due_at = ? WHERE id = ?', [$priority, self::dueFor($priority, $from), $id]);
        return true;
    }

    /** Un ticket est en retard s'il n'a pas reçu de réponse dans son délai. */
    public static function isOverdue(array $ticket, ?int $now = null): bool
    {
        if (empty($ticket['due_at']) || !empty($ticket['first_reply_at'])) {
            return false;
        }
        if (!in_array($ticket['status'], self::OPEN_STATUSES, true)) {
            return false;
        }
        return strtotime((string) $ticket['due_at']) < ($now ?? time());
    }

    public static function summary(): array
    {
        $placeholders = implode(',', array_fill(0, count(self::OPEN_STATUSES), '?'));
        return [
            'open' => (int) Db::value("SELECT COUNT(*) FROM tickets WHERE status IN ($placeholders)", self::OPEN_STATUSES),
            'unassigned' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets WHERE assignee_id IS NULL AND status IN ($placeholders)",
                self::OPEN_STATUSES
            ),
            'overdue' => count(array_filter(self::list(['openOnly' => true]), static fn (array $t): bool => self::isOverdue($t))),
            'closedThisMonth' => (int) Db::value("SELECT COUNT(*) FROM tickets WHERE closed_at >= date('now', 'start of month')"),
        ];
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM tickets WHERE id = ?', [$id]);
        Audit::log('ticket.supprime', 'tickets', $id);
    }

    // ---------- Base de connaissances ----------

    public static function articles(string $category = '', string $query = '', ?array $visibleTo = null): array
    {
        $clauses = ['published = 1'];
        $params = [];
        if ($category !== '') {
            $clauses[] = 'category = ?';
            $params[] = $category;
        }
        if ($query !== '') {
            $clauses[] = '(title LIKE ? OR body LIKE ? OR category LIKE ?)';
            $like = '%' . $query . '%';
            $params = array_merge($params, [$like, $like, $like]);
        }
        $rows = Db::all(
            'SELECT a.*, u.first_name, u.last_name FROM kb_articles a
             LEFT JOIN users u ON u.id = a.author_id
             WHERE ' . implode(' AND ', $clauses) . '
             ORDER BY a.category COLLATE NOCASE, a.title COLLATE NOCASE',
            $params
        );
        if ($visibleTo === null) {
            return $rows;
        }
        return array_values(array_filter($rows, static fn (array $row): bool => self::canRead($row, $visibleTo)));
    }

    /**
     * Un article n'est lisible que dans sa portée. « Administration » couvre
     * les procédures internes qu'un salarié n'a pas à voir.
     */
    public static function canRead(array $article, array $user): bool
    {
        if ($user['role'] === 'admin') {
            return true;
        }
        return match ($article['visibility']) {
            'Administration' => false,
            'Service' => (int) $article['scope_id'] === (int) ($user['department_id'] ?? 0),
            'Équipe' => (int) $article['scope_id'] === (int) ($user['team_id'] ?? 0),
            default => true,
        };
    }

    public static function articleById(int $id): ?array
    {
        return Db::get('SELECT * FROM kb_articles WHERE id = ?', [$id]);
    }

    public static function createArticle(array $fields): int
    {
        return Db::insert(
            'INSERT INTO kb_articles (title, category, body, visibility, scope_id, author_id) VALUES (?, ?, ?, ?, ?, ?)',
            [
                $fields['title'], $fields['category'] ?: 'Général', $fields['body'] ?? '',
                $fields['visibility'] ?: 'Entreprise', $fields['scopeId'] ?? null, $fields['authorId'] ?? null,
            ]
        );
    }

    public static function updateArticle(int $id, array $fields): void
    {
        Db::run(
            "UPDATE kb_articles SET title = ?, category = ?, body = ?, visibility = ?, scope_id = ?, published = ?,
                    updated_at = datetime('now')
             WHERE id = ?",
            [
                $fields['title'], $fields['category'], $fields['body'], $fields['visibility'],
                $fields['scopeId'] ?? null, !empty($fields['published']) ? 1 : 0, $id,
            ]
        );
    }

    public static function deleteArticle(int $id): void
    {
        Db::run('DELETE FROM kb_articles WHERE id = ?', [$id]);
    }

    public static function noteRead(int $id): void
    {
        Db::run('UPDATE kb_articles SET views = views + 1 WHERE id = ?', [$id]);
    }

    public static function categories(): array
    {
        return array_column(Db::all('SELECT DISTINCT category FROM kb_articles ORDER BY category COLLATE NOCASE'), 'category');
    }
}
