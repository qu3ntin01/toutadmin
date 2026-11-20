<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Api\Manifeste;
use App\Api\OpenApi;
use App\Core\Audit;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Security;
use App\Modules\ApiTokens;
use App\Modules\Societes;
use App\Modules\Updates;

/**
 * API, version 1.
 *
 * Ce contrôleur ne contient aucune fonction métier : il pose l'authentification,
 * les plafonds et les deux routes qui décrivent l'API elle-même, puis exécute
 * ce que le manifeste (app/Api/) déclare. Les fonctions sont là-bas, une par
 * entrée, groupées par domaine.
 *
 * Ce que le jeton peut faire est décidé par sa portée, et par elle seule :
 *
 *   — la lecture est la règle, et suffit à la plupart des usages ;
 *   — l'écriture est une portée à part (« domaine:ecriture »), que
 *     l'administrateur coche exprès. Elle emporte la lecture du même domaine,
 *     jamais l'inverse ;
 *   — toute écriture réussie entre au journal d'audit sous l'intitulé du
 *     jeton. Le journal ne dit jamais sa valeur.
 *
 * Pas de cookie, donc pas de session, donc pas de jeton CSRF : l'authentification
 * tient entièrement dans l'en-tête « Authorization: Bearer … ».
 */
final class ApiController
{
    /**
     * Inscrit chaque fonction du manifeste au routeur.
     *
     * Rien ne se monte ici à la main : ajouter une fonction, c'est l'ajouter au
     * manifeste, et elle apparaît du même coup dans la découverte, dans
     * l'OpenAPI et dans la documentation. Le chemin inverse — une route montée
     * sans entrée — n'existe pas.
     */
    public static function enregistrer(Router $router): void
    {
        $router->get(Manifeste::BASE, self::racine(...));
        $router->get(Manifeste::BASE . '/openapi.json', self::openapi(...));

        foreach (Manifeste::toutes() as $fonction) {
            $router->add(
                (string) $fonction['methode'],
                Manifeste::cheminComplet($fonction),
                static fn (Request $request, array $params): Response => self::servir($fonction, $request, $params)
            );
        }
    }

    private static function json(mixed $payload, int $status = 200, array $headers = []): Response
    {
        return Response::text((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status)
            ->withHeaders(array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
                // Une réponse d'API ne se met pas en cache : elle porte des
                // données nominatives.
                'Cache-Control' => 'no-store',
            ], $headers));
    }

    private static function unauthorized(): Response
    {
        // On ne dit pas si le jeton est inconnu, révoqué ou périmé : cela renseignerait.
        return self::json(['error' => 'Jeton absent, invalide ou expiré.'], 401, [
            'WWW-Authenticate' => 'Bearer realm="Toutadmin"',
        ]);
    }

    /** Le jeton présenté, ou null. Chaque appel compte, et le dernier usage est daté. */
    private static function token(Request $request): ?array
    {
        $header = (string) $request->header('authorization');
        $bearer = str_starts_with(strtolower($header), 'bearer ') ? trim(substr($header, 7)) : '';
        $presented = $bearer !== '' ? $bearer : trim((string) $request->header('x-api-key'));
        if ($presented === '') {
            return null;
        }

        $token = ApiTokens::resolve($presented);
        if ($token === null) {
            return null;
        }
        ApiTokens::touch(
            (int) $token['id'],
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            $token['company_id'] === null ? null : (int) $token['company_id'],
        );
        return $token;
    }

    /**
     * Le plafond, différent selon qu'on lit ou qu'on écrit.
     *
     * Une écriture coûte plus cher qu'une lecture, et se répare moins bien. Le
     * plafond des écritures est donc à part, et bien plus bas : trente par
     * minute suffisent à un outil qui synchronise, et pas à un jeton volé qui
     * vide un annuaire.
     */
    private static function plafondAtteint(bool $ecrit): bool
    {
        return $ecrit
            ? Security::tooManyAttempts('api-write', (int) Config::get('api_write_rate_limit', 30), 60)
            : Security::tooManyAttempts('api', (int) Config::get('api_rate_limit', 120), 60);
    }

    /** Le garde de portée. Le refus dit la portée manquante : c'est réparable. */
    private static function refusDePortee(array $token, string $portee): Response
    {
        return self::json([
            'error' => "Ce jeton n'a pas la portée « $portee ».",
            // « scopes » était déjà là et des appelants le lisent : on l'ajoute,
            // on ne le renomme pas.
            'scopes' => $token['scopeList'],
            'requise' => $portee,
            'accordees' => $token['scopeList'],
        ], 403);
    }

    /** Exécute une fonction du manifeste, une fois le jeton et la portée vérifiés. */
    private static function servir(array $fonction, Request $request, array $params): Response
    {
        if (self::plafondAtteint((bool) $fonction['ecrit'])) {
            return self::json([
                'error' => $fonction['ecrit']
                    ? "Trop d'écritures. Ce plafond est distinct de celui des lectures."
                    : 'Trop de requêtes.',
            ], 429);
        }

        $token = self::token($request);
        if ($token === null) {
            return self::unauthorized();
        }
        if (!ApiTokens::allows($token, (string) $fonction['portee'])) {
            return self::refusDePortee($token, (string) $fonction['portee']);
        }

        /*
         * Le jeton porte son périmètre, et la fonction travaille dedans.
         *
         * C'est ce qui distingue un jeton d'une tâche de fond : une tâche n'a pas
         * de société et retombe sur celle qui mène, un jeton en désigne une. Sans
         * cela, un jeton créé depuis une filiale lirait les données de la maison
         * mère — ce qui, sur une instance à plusieurs sociétés, serait une fuite
         * servie par l'API elle-même. Le périmètre est borné à l'appel : « dans »
         * le rend comme il était, même si la fonction lève.
         */
        try {
            $resultat = Societes::dans(
                (int) $token['company_id'],
                static fn (): array => ($fonction['faire'])($request, $params),
            );
        } catch (\Throwable $erreur) {
            // Un programme qui reçoit une page d'erreur HTML au lieu de JSON ne
            // sait pas la lire.
            error_log('api: ' . $erreur->getMessage());
            return self::json(['error' => "L'instance n'a pas pu répondre."], 500);
        }

        $statut = (int) ($resultat['statut'] ?? 200);
        // La trace se pose après coup : on ne consigne pas une écriture refusée
        // comme si elle avait eu lieu.
        if ($fonction['ecrit'] && !empty($fonction['journal']) && $statut >= 200 && $statut < 300) {
            Audit::logAs(
                'jeton « ' . $token['label'] . ' »',
                (string) $fonction['journal'],
                (string) $fonction['groupe'],
                isset($resultat['corps']['id']) ? (int) $resultat['corps']['id'] : null,
                ['voie' => 'api', 'fonction' => Manifeste::cle($fonction), 'jeton' => (int) $token['id']]
            );
        }

        return self::json($resultat['corps'], $statut);
    }

    /**
     * La racine récite le catalogue, filtré par ce que le jeton peut faire.
     *
     * Un jeton qui n'a que l'annuaire n'apprend pas ici quelles routes de paie
     * existent : la découverte se tait sur ce qu'elle refuserait de servir.
     */
    private static function racine(Request $request, array $params): Response
    {
        if (self::plafondAtteint(false)) {
            return self::json(['error' => 'Trop de requêtes.'], 429);
        }
        $token = self::token($request);
        if ($token === null) {
            return self::unauthorized();
        }

        $peutEcrire = ApiTokens::canWrite($token);
        $groupes = Manifeste::pourJeton($token);
        $endpoints = [];
        foreach ($groupes as $groupe) {
            foreach ($groupe['fonctions'] as $fonction) {
                $endpoints[] = [
                    'path' => $fonction['chemin'],
                    'method' => $fonction['methode'],
                    'scope' => $fonction['portee'],
                ];
            }
        }

        return self::json([
            'version' => 1,
            // Ce champ existait quand l'API était en lecture seule. Il reste, et
            // dit la vérité pour ce jeton-ci : un appelant qui le lisait n'est
            // pas trompé.
            'readOnly' => !$peutEcrire,
            'token' => [
                'label' => $token['label'],
                'scopes' => $token['scopeList'],
                'expiresAt' => $token['expires_at'],
                'peutEcrire' => $peutEcrire,
            ],
            'openapi' => Manifeste::BASE . '/openapi.json',
            'groupes' => $groupes,
            /*
             * « endpoints » date de l'API à neuf fonctions, et des appelants le
             * lisent. Il reste : la liste à plat de ce que ce jeton-ci peut
             * appeler, déduite des mêmes groupes. Un champ retiré parce qu'un
             * plus riche est arrivé casse du code qui marchait, et ne rend rien
             * en échange.
             */
            'endpoints' => $endpoints,
        ]);
    }

    /**
     * Le document OpenAPI, entier.
     *
     * Il n'est pas filtré par la portée du jeton, lui : un générateur de client
     * a besoin du contrat complet pour engendrer les appels que l'utilisateur
     * obtiendra le jour où on lui accordera la portée. Il ne contient que des
     * noms de routes, jamais de données.
     */
    private static function openapi(Request $request, array $params): Response
    {
        if (self::plafondAtteint(false)) {
            return self::json(['error' => 'Trop de requêtes.'], 429);
        }
        if (self::token($request) === null) {
            return self::unauthorized();
        }

        $hote = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        // « empty » prenait le « HTTPS=off » d'IIS pour du chiffré, et le
        // document annonçait alors des adresses en https que le serveur ne
        // sert pas. La question se pose au même endroit que partout ailleurs.
        $schema = \App\Core\Security::isSecure() ? 'https' : 'http';
        return self::json(OpenApi::document(Updates::currentVersion(), "$schema://$hote"));
    }
}
