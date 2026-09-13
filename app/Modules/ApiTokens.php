<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Db;

/**
 * Jetons d'accès à l'API.
 *
 * Un jeton ouvre une porte sans mot de passe ni double authentification : il
 * est donc traité comme un secret de première catégorie.
 *
 *   — il n'est **jamais conservé en clair**. Seule son empreinte SHA-256 vit
 *     en base, avec le préfixe qui permet de le reconnaître dans une liste.
 *     Perdu, il se révoque et se recrée ; il ne se relit pas ;
 *   — sa **portée est explicite** : un jeton donné à un outil de paie n'a rien
 *     à faire dans les projets ;
 *   — il **expire**, et se révoque d'un clic ;
 *   — l'API qu'il ouvre est en **lecture seule**. Un jeton volé permet de lire,
 *     jamais d'écrire, de supprimer ou de payer.
 *
 * Le hachage est un SHA-256 simple, non un bcrypt : un jeton de 256 bits tiré
 * au hasard n'a pas de dictionnaire à lui opposer, et l'API doit répondre vite.
 */
final class ApiTokens
{
    public const SCOPES = [
        ['key' => 'annuaire', 'label' => 'Annuaire et organisation', 'hint' => 'Collaborateurs visibles, services, équipes.'],
        ['key' => 'rh', 'label' => 'Ressources humaines', 'hint' => 'Absences approuvées, effectifs, contrats.'],
        ['key' => 'gestion', 'label' => 'Gestion et facturation', 'hint' => 'Tiers, factures, abonnements.'],
        ['key' => 'projets', 'label' => 'Projets', 'hint' => 'Projets, tâches, temps passé.'],
        ['key' => 'pilotage', 'label' => 'Indicateurs de direction', 'hint' => 'Chiffres consolidés du tableau de bord.'],
    ];

    public const PREFIX = 'sm_';
    public const DEFAULT_DAYS = 365;

    public static function scopeKeys(): array
    {
        return array_column(self::SCOPES, 'key');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function all(): array
    {
        $today = gmdate('Y-m-d');
        return array_map(static function (array $row) use ($today): array {
            $row['scopeList'] = $row['scopes'] === '' ? [] : explode(',', (string) $row['scopes']);
            $row['expired'] = !empty($row['expires_at']) && $row['expires_at'] < $today;
            $row['revoked'] = $row['revoked_at'] !== null;
            return $row;
        }, Db::all(
            'SELECT t.*, u.first_name, u.last_name FROM api_tokens t
             LEFT JOIN users u ON u.id = t.created_by
             ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC'
        ));
    }

    /**
     * Crée un jeton. La valeur en clair n'est rendue qu'ici, une seule fois :
     * c'est le seul moment où elle existe hors de la mémoire de l'appelant.
     */
    public static function create(array $fields): array
    {
        $label = mb_substr(trim((string) ($fields['label'] ?? '')), 0, 120);
        if ($label === '') {
            return ['ok' => false, 'message' => 'Un intitulé est requis.'];
        }

        $kept = array_values(array_unique(array_filter(
            $fields['scopes'] ?? [],
            static fn (string $scope): bool => in_array($scope, self::scopeKeys(), true)
        )));
        if ($kept === []) {
            return ['ok' => false, 'message' => 'Choisissez au moins une portée.'];
        }

        $lifetime = $fields['days'] ?? self::DEFAULT_DAYS;
        if (!is_int($lifetime) || $lifetime < 1 || $lifetime > 3650) {
            return ['ok' => false, 'message' => 'La durée de vie tient entre 1 et 3650 jours.'];
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = self::PREFIX . $secret;
        $expires = gmdate('Y-m-d', time() + $lifetime * 86400);

        $id = Db::insert(
            'INSERT INTO api_tokens (label, prefix, token_hash, scopes, created_by, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $label, substr($token, 0, 11), self::hash($token), implode(',', $kept),
                $fields['createdBy'] ?? null, $expires,
            ]
        );

        return ['ok' => true, 'id' => $id, 'token' => $token, 'expiresAt' => $expires, 'scopes' => $kept];
    }

    public static function revoke(int $id): bool
    {
        return Db::run(
            "UPDATE api_tokens SET revoked_at = datetime('now') WHERE id = ? AND revoked_at IS NULL",
            [$id]
        ) > 0;
    }

    public static function remove(int $id): void
    {
        Db::run('DELETE FROM api_tokens WHERE id = ?', [$id]);
    }

    /**
     * Résout un jeton présenté. Rend la ligne, ou null : le motif n'est pas dit à
     * l'appelant — un jeton révoqué et un jeton inexistant se ressemblent, et c'est
     * bien ainsi.
     */
    public static function resolve(?string $token): ?array
    {
        $value = (string) $token;
        if (!str_starts_with($value, self::PREFIX)) {
            return null;
        }

        $row = Db::get('SELECT * FROM api_tokens WHERE token_hash = ?', [self::hash($value)]);
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        if (!empty($row['expires_at']) && $row['expires_at'] < gmdate('Y-m-d')) {
            return null;
        }

        $row['scopeList'] = $row['scopes'] === '' ? [] : explode(',', (string) $row['scopes']);
        return $row;
    }

    public static function touch(int $id, string $ip = ''): void
    {
        Db::run(
            "UPDATE api_tokens SET last_used_at = datetime('now'), last_ip = ?, calls = calls + 1 WHERE id = ?",
            [mb_substr($ip, 0, 60), $id]
        );
    }

    public static function allows(?array $token, string $scope): bool
    {
        return $token !== null && in_array($scope, $token['scopeList'] ?? [], true);
    }
}
