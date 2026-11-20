<?php

declare(strict_types=1);

namespace App\Modules;

use App\Core\Cloison;
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
 *   — il **lit** par défaut. L'écriture est une portée à part, que
 *     l'administrateur coche exprès, jamais offerte avec la lecture : un jeton
 *     donné à un tableau de bord n'a aucune raison de pouvoir supprimer un
 *     salarié, et la case décochée est ce qui le garantit.
 *
 * Une portée d'écriture s'écrit « domaine:ecriture » et **emporte la lecture du
 * même domaine** : on n'a jamais eu besoin d'écrire sans pouvoir se relire, et
 * exiger les deux cases n'aurait produit que des jetons à moitié cochés qui
 * répondent 403 sans qu'on comprenne pourquoi.
 *
 * Le hachage est un SHA-256 simple, non un bcrypt : un jeton de 256 bits tiré
 * au hasard n'a pas de dictionnaire à lui opposer, et l'API doit répondre vite.
 */
final class ApiTokens
{
    /**
     * Le périmètre courant.
     *
     * Un jeton porte la société dans laquelle il travaille : il est créé depuis
     * l'écran d'une société, et c'est celle-là qu'il voit. L'écran qui les liste
     * et les révoque ne montre donc que les siens.
     *
     * Sa résolution est la seule exception, et elle est nommée : on reconnaît un
     * jeton par son empreinte, avant qu'aucun périmètre ne soit connu — c'est le
     * jeton lui-même qui dit lequel appliquer ensuite.
     */
    private static function ici(): ?int
    {
        return Societes::courant();
    }

    public const SCOPES = [
        ['key' => 'annuaire', 'label' => 'Annuaire et organisation', 'hint' => 'Collaborateurs visibles, services, équipes.'],
        ['key' => 'rh', 'label' => 'Ressources humaines', 'hint' => 'Absences approuvées, effectifs, contrats.'],
        ['key' => 'gestion', 'label' => 'Gestion et facturation', 'hint' => 'Tiers, factures, abonnements, achats, trésorerie.'],
        ['key' => 'projets', 'label' => 'Projets', 'hint' => 'Projets, tâches, jalons, temps passé.'],
        ['key' => 'pilotage', 'label' => 'Indicateurs de direction', 'hint' => 'Chiffres consolidés du tableau de bord.'],
        ['key' => 'documents', 'label' => 'Documents et bases', 'hint' => 'Annonces, base de connaissances, salles, matériel.'],
        ['key' => 'exploitation', 'label' => "Exploitation de l'instance", 'hint' => 'Version, licence, modules, journaux, sauvegardes.'],
    ];

    /**
     * Le suffixe qui distingue une portée d'écriture.
     *
     * Les portées d'écriture ne figurent pas dans SCOPES : l'écran les montre
     * dans un bloc séparé, décochées, sous un avertissement. Une case qu'on
     * coche par inadvertance parce qu'elle est voisine d'une autre n'est pas
     * un choix.
     */
    public const WRITE_SUFFIX = ':ecriture';

    public const WRITE_SCOPES = [
        ['key' => 'annuaire:ecriture', 'reads' => 'annuaire', 'label' => 'Annuaire — écriture', 'hint' => "Créer un service ou une équipe, y déplacer quelqu'un."],
        ['key' => 'rh:ecriture', 'reads' => 'rh', 'label' => 'Ressources humaines — écriture', 'hint' => "Déposer une demande d'absence pour un salarié."],
        ['key' => 'gestion:ecriture', 'reads' => 'gestion', 'label' => 'Gestion — écriture', 'hint' => 'Créer un tiers, émettre une facture, enregistrer un règlement.'],
        ['key' => 'projets:ecriture', 'reads' => 'projets', 'label' => 'Projets — écriture', 'hint' => 'Créer un projet, une tâche, pointer du temps.'],
        ['key' => 'documents:ecriture', 'reads' => 'documents', 'label' => 'Documents — écriture', 'hint' => 'Publier une annonce, réserver une salle, affecter du matériel.'],
    ];

    public const PREFIX = 'sm_';
    public const DEFAULT_DAYS = 365;

    public static function scopeKeys(): array
    {
        return array_merge(array_column(self::SCOPES, 'key'), array_column(self::WRITE_SCOPES, 'key'));
    }

    /** Le domaine qu'une portée d'écriture donne aussi à lire, ou null. */
    public static function readOf(string $scope): ?string
    {
        foreach (self::WRITE_SCOPES as $write) {
            if ($write['key'] === $scope) {
                return $write['reads'];
            }
        }
        return null;
    }

    /** Vrai pour une portée qui autorise à modifier la base. */
    public static function isWrite(string $scope): bool
    {
        return self::readOf($scope) !== null;
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
             WHERE t.company_id = ?
             ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC',
            [self::ici()]
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
            'INSERT INTO api_tokens (company_id, label, prefix, token_hash, scopes, created_by, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                self::ici(), $label, substr($token, 0, 11), self::hash($token), implode(',', $kept),
                $fields['createdBy'] ?? null, $expires,
            ]
        );

        return ['ok' => true, 'id' => $id, 'token' => $token, 'expiresAt' => $expires, 'scopes' => $kept];
    }

    public static function revoke(int $id): bool
    {
        return Db::run(
            "UPDATE api_tokens SET revoked_at = datetime('now')
             WHERE id = ? AND company_id = ? AND revoked_at IS NULL",
            [$id, self::ici()]
        ) > 0;
    }

    public static function remove(int $id): bool
    {
        if (Db::run('DELETE FROM api_tokens WHERE id = ? AND company_id = ?', [$id, self::ici()]) === 0) {
            return false;
        }

        return true;
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

        /*
         * Hors périmètre, et c'est la seule exception : la requête n'a pas encore
         * de société, puisque c'est le jeton qui va la donner. Ce qui autorise
         * ici, c'est l'empreinte — et elle est unique dans toute l'instance.
         */
        $row = Cloison::sansPerimetre(static fn (): mixed => Db::get(
            'SELECT * FROM api_tokens WHERE token_hash = ?',
            [self::hash($value)]
        ));
        if ($row === null || $row['revoked_at'] !== null) {
            return null;
        }
        if (!empty($row['expires_at']) && $row['expires_at'] < gmdate('Y-m-d')) {
            return null;
        }

        $row['scopeList'] = $row['scopes'] === '' ? [] : explode(',', (string) $row['scopes']);
        return $row;
    }

    /**
     * Compte un appel. Appelée juste après la résolution, donc avant que le
     * périmètre du jeton soit posé : la société vient de la ligne résolue, pas du
     * contexte.
     */
    public static function touch(int $id, string $ip = '', ?int $companyId = null): void
    {
        Db::run(
            "UPDATE api_tokens SET last_used_at = datetime('now'), last_ip = ?, calls = calls + 1
             WHERE id = ? AND company_id = ?",
            [mb_substr($ip, 0, 60), $id, $companyId]
        );
    }

    /**
     * Ce jeton a-t-il cette portée ?
     *
     * « gestion:ecriture » répond oui à « gestion » : écrire sans pouvoir se
     * relire n'a jamais servi personne, et le contraire est faux — « gestion »
     * ne répond jamais oui à « gestion:ecriture ».
     */
    public static function allows(?array $token, string $scope): bool
    {
        if ($token === null) {
            return false;
        }
        $held = $token['scopeList'] ?? [];
        if (in_array($scope, $held, true)) {
            return true;
        }
        foreach ($held as $one) {
            if (self::readOf((string) $one) === $scope) {
                return true;
            }
        }
        return false;
    }

    /** Ce jeton peut-il modifier quoi que ce soit ? Sert au journal et à l'écran. */
    public static function canWrite(?array $token): bool
    {
        foreach ($token['scopeList'] ?? [] as $one) {
            if (self::isWrite((string) $one)) {
                return true;
            }
        }
        return false;
    }
}
