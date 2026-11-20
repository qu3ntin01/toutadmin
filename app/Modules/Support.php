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
    /** Le périmètre courant : une demande et une file appartiennent à une société. */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

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
    /*
     * Les valeurs d'origine. Chaque instance les règle dans la section
     * « Relations » : un délai de deux heures n'a pas le même sens pour un
     * service interne de trois personnes et pour une hotline client.
     */
    public const RESPONSE_HOURS = ['Critique' => 2, 'Haute' => 8, 'Normale' => 24, 'Basse' => 72];

    /** Le champ qui porte chaque priorité dans les réglages de la section. */
    private const CHAMP_DELAI = [
        'Critique' => 'delai_critique',
        'Haute' => 'delai_haute',
        'Normale' => 'delai_normale',
        'Basse' => 'delai_basse',
    ];

    /** Les heures accordées à une priorité, telles que cette instance les règle. */
    public static function responseHours(string $priority): int
    {
        $champ = self::CHAMP_DELAI[$priority] ?? null;
        if ($champ === null) {
            return self::RESPONSE_HOURS['Normale'];
        }
        return \App\Core\SectionsReglages::nombre('relation', $champ);
    }

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

    /**
     * Les catégories qu'une personne peut traiter, rôles ET groupes réunis.
     *
     * C'est la fonction que les écrans appellent. « agentCategories » reste ce
     * que les rôles seuls donnent, et sert à afficher d'où vient un accès : sans
     * cette distinction, l'écran « qui a accès à quoi » ne pourrait pas dire à
     * l'administration si retirer quelqu'un d'un groupe lui ferme vraiment la
     * porte.
     */
    public static function categoriesFor(?array $user): array
    {
        if ($user === null) {
            return [];
        }
        $parRole = self::agentCategories($user);
        if (count($parRole) === count(self::CATEGORIES)) {
            return self::CATEGORIES;
        }
        $parGroupe = isset($user['id']) ? self::groupCategoriesOf((int) $user['id']) : [];
        $tout = array_merge($parRole, $parGroupe);
        return array_values(array_filter(self::CATEGORIES, static fn (string $c): bool => in_array($c, $tout, true)));
    }

    public static function reference(int $id): string
    {
        return 'T-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    }

    public static function dueFor(string $priority, ?int $from = null): string
    {
        $hours = self::responseHours($priority);
        return gmdate('c', ($from ?? time()) + $hours * 3600);
    }

    public static function create(array $fields): int
    {
        /*
         * Le groupe est posé à l'ouverture, pas calculé à la lecture.
         *
         * Une demande déposée aujourd'hui garde sa file même si l'administration
         * change demain les catégories d'un groupe : c'est la différence entre
         * une file et une vue, et un agent qui retrouve la demande d'hier là où
         * il l'a laissée n'a pas à se demander si le produit l'a perdue.
         */
        $groupe = array_key_exists('groupId', $fields)
            ? ((int) $fields['groupId'] ?: null)
            : self::groupForCategory((string) $fields['category']);

        $id = Db::insert(
            'INSERT INTO tickets (company_id, subject, body, category, priority, origin, requester_id, partner_id, group_id, due_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['subject'], $fields['body'] ?? '', $fields['category'], $fields['priority'],
                $fields['origin'], $fields['requesterId'] ?? null, $fields['partnerId'] ?? null,
                $groupe, self::dueFor($fields['priority']),
            ]
        );
        Db::run(
            'UPDATE tickets SET reference = ? WHERE id = ? AND company_id = ?',
            [self::reference($id), $id, self::ici()]
        );
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
                    p.name AS partner_name, g.name AS group_name
             FROM tickets tk
             LEFT JOIN users r ON r.id = tk.requester_id
             LEFT JOIN users a ON a.id = tk.assignee_id
             LEFT JOIN partners p ON p.id = tk.partner_id
             LEFT JOIN support_groups g ON g.id = tk.group_id
             WHERE tk.id = ? AND tk.company_id = ?',
            [$id, self::ici()]
        );
    }

    public static function list(array $filters = []): array
    {
        $clauses = ['tk.company_id = ?'];
        $params = [self::ici()];
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
        if (!empty($filters['groupId'])) {
            $clauses[] = 'tk.group_id = ?';
            $params[] = (int) $filters['groupId'];
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);

        return Db::all(
            "SELECT tk.*, r.first_name AS requester_first_name, r.last_name AS requester_last_name,
                    a.first_name AS assignee_first_name, a.last_name AS assignee_last_name,
                    p.name AS partner_name, g.name AS group_name,
                    (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = tk.id) AS message_count
             FROM tickets tk
             LEFT JOIN users r ON r.id = tk.requester_id
             LEFT JOIN users a ON a.id = tk.assignee_id
             LEFT JOIN partners p ON p.id = tk.partner_id
             LEFT JOIN support_groups g ON g.id = tk.group_id
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
            Db::run(
                "UPDATE tickets SET first_reply_at = COALESCE(first_reply_at, datetime('now'))
                 WHERE id = ? AND company_id = ?",
                [$ticketId, self::ici()]
            );
        }
    }

    public static function setStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }
        $closing = in_array($status, ['Résolu', 'Clos'], true);
        Db::run(
            'UPDATE tickets SET status = ?, closed_at = ? WHERE id = ? AND company_id = ?',
            [$status, $closing ? gmdate('c') : null, $id, self::ici()]
        );
        return true;
    }

    public static function assign(int $id, ?int $userId): void
    {
        Db::run(
            'UPDATE tickets SET assignee_id = ? WHERE id = ? AND company_id = ?',
            [$userId ?: null, $id, self::ici()]
        );
    }

    public static function setPriority(int $id, string $priority): bool
    {
        if (!in_array($priority, self::PRIORITIES, true)) {
            return false;
        }
        $ticket = Db::get('SELECT created_at FROM tickets WHERE id = ? AND company_id = ?', [$id, self::ici()]);
        if ($ticket === null) {
            return false;
        }
        // Le délai suit la priorité : il se recalcule depuis l'ouverture, pas
        // depuis maintenant — sinon changer la priorité rendrait un retard nul.
        $from = strtotime((string) $ticket['created_at'] . ' UTC') ?: time();
        Db::run(
            'UPDATE tickets SET priority = ?, due_at = ? WHERE id = ? AND company_id = ?',
            [$priority, self::dueFor($priority, $from), $id, self::ici()]
        );
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
        $perimetre = [self::ici()];
        return [
            'open' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets WHERE company_id = ? AND status IN ($placeholders)",
                [...$perimetre, ...self::OPEN_STATUSES]
            ),
            'unassigned' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets
                 WHERE company_id = ? AND assignee_id IS NULL AND status IN ($placeholders)",
                [...$perimetre, ...self::OPEN_STATUSES]
            ),
            'overdue' => count(array_filter(self::list(['openOnly' => true]), static fn (array $t): bool => self::isOverdue($t))),
            'closedThisMonth' => (int) Db::value(
                "SELECT COUNT(*) FROM tickets
                 WHERE company_id = ? AND closed_at >= date('now', 'start of month')",
                $perimetre
            ),
        ];
    }

    public static function remove(int $id): bool
    {
        if (Db::run('DELETE FROM tickets WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        Audit::log('ticket.supprime', 'tickets', $id);

        return true;
    }

    // ---------- Les groupes de support ----------

    /*
     * Qui traite quoi, décidé par l'entreprise et non par le code.
     *
     * Un groupe porte un nom, des gens, et les catégories dont il a la charge.
     * L'administration le compose. Les rôles continuent de donner ce qu'ils
     * donnaient — un groupe ajoute, il ne retire jamais : sans cette règle,
     * créer un premier groupe fermerait d'un coup la file aux RH et à
     * l'administration, et l'entreprise découvrirait le jour même que plus
     * personne ne répond.
     */

    /** Les groupes, avec leurs catégories et le nombre de membres. */
    public static function groups(): array
    {
        $rows = Db::all(
            'SELECT g.*,
                    (SELECT COUNT(*) FROM support_group_members m WHERE m.group_id = g.id) AS member_count
             FROM support_groups g WHERE g.company_id = ? ORDER BY g.name COLLATE NOCASE',
            [self::ici()],
        );
        foreach ($rows as &$groupe) {
            $groupe['categories'] = self::groupCategories((int) $groupe['id']);
        }
        return $rows;
    }

    public static function groupById(int $id): ?array
    {
        $groupe = Db::get('SELECT * FROM support_groups WHERE id = ? AND company_id = ?', [$id, self::ici()]);
        if ($groupe === null) {
            return null;
        }
        $groupe['categories'] = self::groupCategories($id);
        $groupe['members'] = self::groupMembers($id);
        return $groupe;
    }

    public static function groupCategories(int $groupId): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['category'],
            Db::all('SELECT category FROM support_group_categories WHERE group_id = ? ORDER BY category', [$groupId]),
        );
    }

    public static function groupMembers(int $groupId): array
    {
        return Db::all(
            'SELECT u.id, u.first_name, u.last_name, u.email, u.active
             FROM support_group_members m JOIN users u ON u.id = m.user_id
             WHERE m.group_id = ? ORDER BY u.last_name COLLATE NOCASE, u.first_name COLLATE NOCASE',
            [$groupId],
        );
    }

    public static function createGroup(string $name, string $description = ''): ?int
    {
        $propre = trim($name);
        if ($propre === '') {
            return null;
        }
        return Db::insert(
            'INSERT INTO support_groups (company_id, name, description, created_at) VALUES (?, ?, ?, ?)',
            [self::ici(), $propre, trim($description), gmdate('Y-m-d H:i:s')],
        );
    }

    public static function renameGroup(int $id, string $name, string $description = ''): bool
    {
        $propre = trim($name);
        if ($propre === '') {
            return false;
        }
        /*
         * Le témoin vient de la lecture, pas du nombre de lignes modifiées :
         * MySQL compte les lignes CHANGÉES, si bien qu'un renommage à
         * l'identique se rendrait comme « groupe introuvable ».
         */
        if (Db::value('SELECT id FROM support_groups WHERE id = ? AND company_id = ?', [$id, self::ici()]) === null) {
            return false;
        }
        Db::run(
            'UPDATE support_groups SET name = ?, description = ? WHERE id = ? AND company_id = ?',
            [$propre, trim($description), $id, self::ici()]
        );
        return true;
    }

    /**
     * Supprimer un groupe.
     *
     * Les tickets qu'il avait en charge ne disparaissent pas avec lui : leur
     * groupe repasse à nul, et ils redeviennent visibles par les rôles. Un
     * ticket orphelin qu'on ne verrait plus nulle part serait le pire des deux
     * mondes.
     */
    public static function deleteGroup(int $id): bool
    {
        if (Db::value('SELECT id FROM support_groups WHERE id = ? AND company_id = ?', [$id, self::ici()]) === null) {
            return false;
        }
        $societe = self::ici();
        Db::transaction(static function () use ($id, $societe): void {
            Db::run('UPDATE tickets SET group_id = NULL WHERE group_id = ? AND company_id = ?', [$id, $societe]);
            Db::run('DELETE FROM support_group_categories WHERE group_id = ?', [$id]);
            Db::run('DELETE FROM support_group_members WHERE group_id = ?', [$id]);
            Db::run('DELETE FROM support_groups WHERE id = ? AND company_id = ?', [$id, $societe]);
        });
        return true;
    }

    /** Les catégories d'un groupe, remplacées d'un bloc : une case décochée retire. */
    public static function setGroupCategories(int $id, array $categories): bool
    {
        if (Db::value('SELECT id FROM support_groups WHERE id = ? AND company_id = ?', [$id, self::ici()]) === null) {
            return false;
        }
        $voulues = array_values(array_unique(array_filter(
            array_map('strval', $categories),
            static fn (string $c): bool => in_array($c, self::CATEGORIES, true),
        )));
        Db::transaction(static function () use ($id, $voulues): void {
            Db::run('DELETE FROM support_group_categories WHERE group_id = ?', [$id]);
            foreach ($voulues as $categorie) {
                Db::run('INSERT INTO support_group_categories (group_id, category) VALUES (?, ?)', [$id, $categorie]);
            }
        });
        return true;
    }

    public static function addGroupMember(int $groupId, int $userId): bool
    {
        if (Db::value('SELECT id FROM support_groups WHERE id = ? AND company_id = ?', [$groupId, self::ici()]) === null) {
            return false;
        }
        if (Db::value('SELECT id FROM users WHERE id = ? AND active = 1', [$userId]) === null) {
            return false;
        }
        // Deux fois la même personne n'est pas une erreur à signaler : le
        // résultat voulu est atteint, et c'est tout ce que l'appelant demande.
        if (Db::value('SELECT 1 FROM support_group_members WHERE group_id = ? AND user_id = ?', [$groupId, $userId]) === null) {
            Db::run('INSERT INTO support_group_members (group_id, user_id) VALUES (?, ?)', [$groupId, $userId]);
        }
        return true;
    }

    public static function removeGroupMember(int $groupId, int $userId): bool
    {
        return Db::run('DELETE FROM support_group_members WHERE group_id = ? AND user_id = ?',
            [$groupId, $userId]) > 0;
    }

    /** Les groupes d'une personne. */
    public static function groupsOf(int $userId): array
    {
        $rows = Db::all(
            'SELECT g.* FROM support_group_members m JOIN support_groups g ON g.id = m.group_id
             WHERE m.user_id = ? AND g.company_id = ? ORDER BY g.name COLLATE NOCASE',
            [$userId, self::ici()],
        );
        foreach ($rows as &$groupe) {
            $groupe['categories'] = self::groupCategories((int) $groupe['id']);
        }
        return $rows;
    }

    /** Les catégories que les groupes d'une personne lui ouvrent. */
    public static function groupCategoriesOf(int $userId): array
    {
        $rows = Db::all(
            'SELECT DISTINCT c.category
             FROM support_group_members m
             JOIN support_group_categories c ON c.group_id = m.group_id
             WHERE m.user_id = ?',
            [$userId],
        );
        return array_values(array_filter(
            array_map(static fn (array $r): string => (string) $r['category'], $rows),
            static fn (string $c): bool => in_array($c, self::CATEGORIES, true),
        ));
    }

    /**
     * Le groupe à qui adresser un ticket d'une catégorie donnée.
     *
     * Le premier par ordre alphabétique quand plusieurs la couvrent. Choisir le
     * premier plutôt que rien laisse la demande dans une file au lieu de la
     * laisser dans l'entre-deux, et le transfert existe pour la déplacer.
     */
    public static function groupForCategory(string $category): ?int
    {
        $ligne = Db::get(
            'SELECT g.id FROM support_group_categories c JOIN support_groups g ON g.id = c.group_id
             WHERE c.category = ? AND g.company_id = ? ORDER BY g.name COLLATE NOCASE LIMIT 1',
            [$category, self::ici()],
        );
        return $ligne === null ? null : (int) $ligne['id'];
    }

    public static function setTicketGroup(int $ticketId, int $groupId): bool
    {
        if (Db::value('SELECT id FROM tickets WHERE id = ? AND company_id = ?', [$ticketId, self::ici()]) === null) {
            return false;
        }
        if ($groupId > 0
            && Db::value('SELECT id FROM support_groups WHERE id = ? AND company_id = ?', [$groupId, self::ici()]) === null) {
            return false;
        }
        Db::run(
            'UPDATE tickets SET group_id = ? WHERE id = ? AND company_id = ?',
            [$groupId > 0 ? $groupId : null, $ticketId, self::ici()]
        );
        return true;
    }

    /**
     * Qui a accès à quoi — la vue que cherche l'administration.
     *
     * Une catégorie, et pour elle : ce que les rôles ouvrent, et les groupes qui
     * la prennent en charge avec leurs membres. C'est la réponse à « si Claire
     * part demain, qui lit les demandes informatiques ? », posée une fois au
     * lieu d'être reconstituée de tête à partir de trois écrans.
     */
    public static function accessMap(): array
    {
        $carte = [];
        foreach (self::CATEGORIES as $categorie) {
            $roles = [];
            if (in_array($categorie, self::agentCategories(['role' => 'admin']), true)) {
                $roles[] = 'admin';
            }
            if (in_array($categorie, self::agentCategories(['role' => 'employee', 'is_hr' => 1]), true)) {
                $roles[] = 'hr';
            }
            if (in_array($categorie, self::agentCategories(['role' => 'employee', 'is_finance' => 1]), true)) {
                $roles[] = 'finance';
            }

            $groupes = Db::all(
                'SELECT g.id, g.name FROM support_group_categories c JOIN support_groups g ON g.id = c.group_id
                 WHERE c.category = ? ORDER BY g.name COLLATE NOCASE',
                [$categorie],
            );
            $nommees = 0;
            foreach ($groupes as &$groupe) {
                $groupe['members'] = self::groupMembers((int) $groupe['id']);
                foreach ($groupe['members'] as $membre) {
                    if ((int) $membre['active'] === 1) {
                        $nommees++;
                    }
                }
            }
            unset($groupe);

            $carte[] = [
                'category' => $categorie,
                'roles' => $roles,
                'groups' => $groupes,
                'named' => $nommees,
                'restricted' => $categorie === self::RESTRICTED_CATEGORY,
            ];
        }
        return $carte;
    }

    // ---------- Base de connaissances ----------

    public static function articles(string $category = '', string $query = '', ?array $visibleTo = null): array
    {
        $clauses = ['a.company_id = ?', 'published = 1'];
        $params = [self::ici()];
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
        return Db::get('SELECT * FROM kb_articles WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function createArticle(array $fields): int
    {
        return Db::insert(
            'INSERT INTO kb_articles (company_id, title, category, body, visibility, scope_id, author_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $fields['title'], $fields['category'] ?: 'Général', $fields['body'] ?? '',
                $fields['visibility'] ?: 'Entreprise', $fields['scopeId'] ?? null, $fields['authorId'] ?? null,
            ]
        );
    }

    public static function updateArticle(int $id, array $fields): void
    {
        Db::run(
            "UPDATE kb_articles SET title = ?, category = ?, body = ?, visibility = ?, scope_id = ?, published = ?,
                    updated_at = datetime('now')
             WHERE id = ? AND company_id = ?",
            [
                $fields['title'], $fields['category'], $fields['body'], $fields['visibility'],
                $fields['scopeId'] ?? null, !empty($fields['published']) ? 1 : 0, $id, self::ici(),
            ]
        );
    }

    public static function deleteArticle(int $id): void
    {
        Db::run('DELETE FROM kb_articles WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function noteRead(int $id): void
    {
        Db::run('UPDATE kb_articles SET views = views + 1 WHERE id = ? AND company_id = ?', [$id, self::ici()]);
    }

    public static function categories(): array
    {
        return array_column(Db::all(
            'SELECT DISTINCT category FROM kb_articles WHERE company_id = ?
             ORDER BY category COLLATE NOCASE',
            [self::ici()]
        ), 'category');
    }
}
