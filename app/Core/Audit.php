<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Journal d'audit scellé.
 *
 * Chaque entrée porte l'empreinte de la précédente : modifier une ligne, ou en
 * retirer une du milieu, casse la chaîne à cet endroit précis et la
 * vérification le dit. Cela n'empêche pas la réécriture — qui tient le fichier
 * de la base peut tout recalculer — mais rend l'altération visible, ce qui est
 * ce qu'on attend d'un journal.
 *
 * L'empreinte suit exactement la formule de l'édition Node : une base déplacée
 * de l'une à l'autre reste vérifiable.
 */
final class Audit
{
    private const ENTRIES_PER_PAGE = 50;

    public static function log(string $action, string $entity = '', ?int $entityId = null, mixed $detail = null): void
    {
        self::write($action, $entity, $entityId, $detail, false);
    }

    /**
     * Une entrée que le produit écrit pour lui-même, sans nommer personne.
     * Le dispositif d'alerte s'en sert : le journal retient qu'un signalement
     * est arrivé, jamais de qui — l'anonymat ne doit pas être rattrapé par la
     * trace.
     */
    public static function logSystem(string $action, string $entity = '', ?int $entityId = null, mixed $detail = null): void
    {
        self::write($action, $entity, $entityId, $detail, true);
    }

    private static function write(string $action, string $entity, ?int $entityId, mixed $detail, bool $system): void
    {
        try {
            $user = $system ? null : Session::get('user');
            $row = [
                'occurred_at' => gmdate('Y-m-d H:i:s'),
                'actor_id' => is_array($user) ? (int) $user['id'] : null,
                'actor_label' => self::label($user),
                'action' => mb_substr($action, 0, 120),
                'entity' => mb_substr($entity, 0, 60),
                'entity_id' => $entityId,
                'detail' => $detail === null ? '' : mb_substr((string) json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 2000),
                'ip' => self::ip(),
            ];
            $previous = (string) (Db::value('SELECT hash FROM audit_log ORDER BY id DESC LIMIT 1') ?? '');
            $hash = self::fingerprint($row, $previous);

            Db::run(
                'INSERT INTO audit_log (occurred_at, actor_id, actor_label, action, entity, entity_id, detail, ip, prev_hash, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $row['occurred_at'], $row['actor_id'], $row['actor_label'], $row['action'],
                    $row['entity'], $row['entity_id'], $row['detail'], $row['ip'], $previous, $hash,
                ]
            );
        } catch (\Throwable $error) {
            // Le journal ne doit jamais empêcher l'action elle-même.
            error_log('audit: ' . $error->getMessage());
        }
    }

    public static function fingerprint(array $row, string $previous): string
    {
        return hash('sha256', implode("\0", [
            $previous,
            $row['occurred_at'],
            $row['actor_id'] === null ? '' : (string) $row['actor_id'],
            $row['actor_label'],
            $row['action'],
            $row['entity'],
            $row['entity_id'] === null ? '' : (string) $row['entity_id'],
            $row['detail'],
            $row['ip'],
        ]));
    }

    /**
     * Relit la chaîne du début. Renvoie la première rupture rencontrée, ou null
     * si le journal est intact.
     */
    public static function verify(): ?array
    {
        $previous = '';
        foreach (Db::all('SELECT * FROM audit_log ORDER BY id') as $row) {
            $expected = self::fingerprint($row, $previous);
            if ($row['prev_hash'] !== $previous || $row['hash'] !== $expected) {
                return ['id' => (int) $row['id'], 'occurred_at' => $row['occurred_at'], 'action' => $row['action']];
            }
            $previous = (string) $row['hash'];
        }
        return null;
    }

    /** Une page du journal, filtrée. Cinquante lignes : de quoi lire, pas scruter. */
    public static function list(array $filters = []): array
    {
        $clauses = [];
        $params = [];
        if (!empty($filters['action'])) {
            $clauses[] = 'action LIKE ?';
            $params[] = $filters['action'] . '%';
        }
        if (!empty($filters['actorId'])) {
            $clauses[] = 'actor_id = ?';
            $params[] = (int) $filters['actorId'];
        }
        if (!empty($filters['entity'])) {
            $clauses[] = 'entity = ?';
            $params[] = $filters['entity'];
        }
        if (!empty($filters['from'])) {
            $clauses[] = 'occurred_at >= ?';
            $params[] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $clauses[] = 'occurred_at <= ?';
            $params[] = $filters['to'] . ' 23:59:59';
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);

        $total = (int) Db::value("SELECT COUNT(*) FROM audit_log $where", $params);
        $pages = max(1, (int) ceil($total / self::ENTRIES_PER_PAGE));
        $current = min(max(1, (int) ($filters['page'] ?? 1)), $pages);

        $rows = Db::all(
            "SELECT * FROM audit_log $where ORDER BY id DESC LIMIT ? OFFSET ?",
            [...$params, self::ENTRIES_PER_PAGE, ($current - 1) * self::ENTRIES_PER_PAGE]
        );
        return ['rows' => $rows, 'total' => $total, 'page' => $current, 'pages' => $pages];
    }

    /** Les actions déjà rencontrées, pour alimenter le filtre sans les coder en dur. */
    public static function knownActions(): array
    {
        return array_column(Db::all('SELECT DISTINCT action FROM audit_log ORDER BY action'), 'action');
    }

    public static function toCsv(array $rows): string
    {
        $escape = static fn (mixed $value): string => '"' . str_replace('"', '""', (string) ($value ?? '')) . '"';
        $lines = [implode(';', array_map($escape, ['Date', 'Auteur', 'Action', 'Objet', 'Identifiant', 'Détail', 'IP']))];
        foreach ($rows as $row) {
            $lines[] = implode(';', array_map($escape, [
                $row['occurred_at'], $row['actor_label'], $row['action'],
                $row['entity'], $row['entity_id'], $row['detail'], $row['ip'],
            ]));
        }
        return implode("\n", $lines);
    }

    /**
     * Purge des entrées trop anciennes : le journal ne se conserve pas
     * indéfiniment.
     *
     * Une purge retire le début de la chaîne, ce qui est légitime — mais ne doit
     * pas pouvoir se confondre avec un effacement discret. Elle laisse donc sa
     * propre entrée, scellée comme les autres, disant combien de lignes sont
     * parties et jusqu'à quand.
     */
    public static function purgeOlderThan(int $days): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $removed = Db::run('DELETE FROM audit_log WHERE occurred_at < ?', [$cutoff]);
        if ($removed > 0) {
            self::log('journal.purge', 'audit_log', null, ['supprimees' => $removed, 'avant' => $cutoff]);
        }
        return $removed;
    }

    /**
     * Vérifie le scellement, et dit où il casse plutôt que de rendre un simple
     * non.
     *
     * La chaîne est lue depuis l'entrée la plus ancienne encore présente : son
     * empreinte précédente désigne une ligne purgée, et n'est donc pas
     * contrôlée. Autrement dit, la vérification garantit que rien n'a été altéré
     * ni retiré *entre* la plus ancienne entrée conservée et la plus récente.
     */
    public static function verifySeal(int $limit = 100000): array
    {
        $rows = Db::all('SELECT * FROM audit_log ORDER BY id LIMIT ?', [$limit]);
        if ($rows === []) {
            return ['ok' => true, 'checked' => 0, 'sealed' => 0, 'unsealed' => 0, 'broken' => null];
        }

        $previous = null;
        $sealed = 0;
        $unsealed = 0;
        foreach ($rows as $row) {
            // Les entrées écrites avant la mise en place du scellement n'ont pas
            // d'empreinte : elles sont comptées à part, pas déclarées fausses.
            if (empty($row['hash'])) {
                $unsealed++;
                $previous = null;
                continue;
            }
            if ($previous !== null && $row['prev_hash'] !== $previous['hash']) {
                return ['ok' => false, 'checked' => count($rows), 'sealed' => $sealed, 'unsealed' => $unsealed,
                        'broken' => ['row' => $row, 'reason' => 'chaine', 'previous' => $previous]];
            }
            if (self::fingerprint($row, (string) $row['prev_hash']) !== $row['hash']) {
                return ['ok' => false, 'checked' => count($rows), 'sealed' => $sealed, 'unsealed' => $unsealed,
                        'broken' => ['row' => $row, 'reason' => 'contenu', 'previous' => $previous]];
            }
            $sealed++;
            $previous = $row;
        }
        return ['ok' => true, 'checked' => count($rows), 'sealed' => $sealed, 'unsealed' => $unsealed, 'broken' => null];
    }

    private static function label(mixed $user): string
    {
        if (!is_array($user)) {
            return 'système';
        }
        $name = trim(($user['firstName'] ?? $user['first_name'] ?? '') . ' ' . ($user['lastName'] ?? $user['last_name'] ?? ''));
        $email = (string) ($user['email'] ?? '');
        $label = $name !== '' ? "$name <$email>" : ($email !== '' ? $email : '#' . ($user['id'] ?? '?'));
        return mb_substr($label, 0, 200);
    }

    private static function ip(): string
    {
        if (Config::get('trust_proxy') && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $first = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
            return substr($first, 0, 64);
        }
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
    }
}
