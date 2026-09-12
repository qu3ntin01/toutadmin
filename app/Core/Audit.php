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
    public static function log(string $action, string $entity = '', ?int $entityId = null, mixed $detail = null): void
    {
        try {
            $user = Session::get('user');
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
