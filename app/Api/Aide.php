<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Db;
use App\Core\Request;

/**
 * Les gestes que chaque fonction de l'API refait : pagination, enveloppe de
 * réponse, lecture du corps, refus.
 *
 * Ils tiennent ici pour une raison simple — une pagination qui se recopie
 * d'une route à l'autre finit par différer d'une route à l'autre, et c'est
 * l'appelant qui découvre la différence.
 */
final class Aide
{
    public const MAX_PAGE = 200;

    /** `?limite=` et `?depuis=`, bornés. Au-delà de 200, on rend 200 sans le dire. */
    public static function page(Request $request): array
    {
        $limit = min(self::MAX_PAGE, max(1, (int) $request->input('limite') ?: 50));
        $offset = max(0, (int) $request->input('depuis'));
        return ['limit' => $limit, 'offset' => $offset];
    }

    /** L'enveloppe de toute liste. Le total dit s'il reste des pages à demander. */
    public static function wrap(array $rows, array $page, ?int $total = null): array
    {
        $enveloppe = [
            'count' => count($rows),
            'limit' => $page['limit'],
            'offset' => $page['offset'],
        ];
        if ($total !== null) {
            $enveloppe['total'] = $total;
        }
        $enveloppe['data'] = array_values($rows);
        return $enveloppe;
    }

    /** Une seule forme d'erreur, pour que l'appelant n'ait qu'un cas à traiter. */
    public static function erreur(int $statut, string $message, array $extra = []): array
    {
        return ['statut' => $statut, 'corps' => array_merge(['error' => $message], $extra)];
    }

    /** 404 nommé : « facture 12 » se cherche mieux que « ressource inconnue ». */
    public static function absent(string $quoi): array
    {
        return self::erreur(404, "$quoi introuvable.");
    }

    public static function ok(mixed $corps, int $statut = 200): array
    {
        return ['statut' => $statut, 'corps' => $corps];
    }

    /**
     * Le corps de la requête, quel que soit son emballage.
     *
     * PHP ne remplit `$_POST` que pour un formulaire. Un appelant qui envoie du
     * JSON — c'est-à-dire la plupart — se serait donc fait répondre « champ
     * requis » sur des champs qu'il avait bien envoyés. On lit le flux brut
     * quand le type l'annonce, et on retombe sur le corps de formulaire sinon.
     */
    public static function corps(Request $request): array
    {
        $type = strtolower($request->header('content-type'));
        if (str_contains($type, 'json')) {
            $brut = (string) file_get_contents('php://input');
            $lu = json_decode($brut, true);
            return is_array($lu) ? $lu : [];
        }
        return $request->body;
    }

    /**
     * Un champ du corps, en texte. Une chaîne vide et un champ absent sont la
     * même chose : l'appelant qui envoie `""` voulait dire « rien ».
     */
    public static function texte(array $corps, string $nom, int $max = 200): string
    {
        $valeur = $corps[$nom] ?? '';
        if (!is_scalar($valeur)) {
            return '';
        }
        return mb_substr(trim((string) $valeur), 0, $max);
    }

    /** Un entier du corps ou du chemin, ou null si ce n'en est pas un. */
    public static function entier(mixed $valeur): ?int
    {
        if (is_int($valeur)) {
            return $valeur;
        }
        if (is_string($valeur) && preg_match('/^-?\d+$/', trim($valeur)) === 1) {
            return (int) trim($valeur);
        }
        return null;
    }

    /** Une date ISO, ou '' : une date approximative vaut moins que pas de date. */
    public static function date(mixed $valeur): string
    {
        $texte = is_scalar($valeur) ? trim((string) $valeur) : '';
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texte) === 1 ? $texte : '';
    }

    /** Le nombre de lignes d'une table, pour le `total` des listes paginées. */
    public static function compter(string $sql, array $params = []): int
    {
        return (int) Db::value($sql, $params);
    }
}
