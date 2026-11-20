<?php

declare(strict_types=1);

namespace App\Api;

use App\Modules\ApiTokens;

/**
 * Le document OpenAPI 3.1, déduit du manifeste.
 *
 * Il n'est pas écrit à la main et ne peut donc pas vieillir : il se recalcule à
 * chaque appel depuis les mêmes déclarations que les routes elles-mêmes. Ce
 * qu'un générateur de client en tire est, par construction, ce que l'API sert.
 */
final class OpenApi
{
    private const TYPES = [
        'entier' => ['type' => 'integer'],
        'texte' => ['type' => 'string'],
        'nombre' => ['type' => 'number'],
        'date' => ['type' => 'string', 'format' => 'date'],
        'booleen' => ['type' => 'boolean'],
    ];

    private static function schemaDe(?string $type): array
    {
        return self::TYPES[$type ?? 'texte'] ?? self::TYPES['texte'];
    }

    /** Les paramètres de chemin et de requête. Le corps se décrit à part. */
    private static function parametres(array $fonction): array
    {
        $sortie = [];
        foreach ($fonction['parametres'] as $p) {
            if (($p['dans'] ?? '') === 'corps') {
                continue;
            }
            $schema = self::schemaDe($p['type'] ?? null);
            if (array_key_exists('defaut', $p)) {
                $schema['default'] = $p['defaut'];
            }
            $sortie[] = [
                'name' => $p['nom'],
                'in' => ($p['dans'] ?? '') === 'chemin' ? 'path' : 'query',
                'required' => ($p['dans'] ?? '') === 'chemin' ? true : (bool) ($p['requis'] ?? false),
                'description' => $p['description'] ?? '',
                'schema' => $schema,
            ];
        }
        return $sortie;
    }

    private static function corps(array $fonction): ?array
    {
        $proprietes = [];
        $requis = [];
        foreach ($fonction['parametres'] as $p) {
            if (($p['dans'] ?? '') !== 'corps') {
                continue;
            }
            $proprietes[$p['nom']] = self::schemaDe($p['type'] ?? null) + ['description' => $p['description'] ?? ''];
            if (!empty($p['requis'])) {
                $requis[] = $p['nom'];
            }
        }
        if ($proprietes === []) {
            return null;
        }

        $schema = ['type' => 'object', 'properties' => $proprietes];
        if ($requis !== []) {
            $schema['required'] = $requis;
        }
        return [
            'required' => $requis !== [],
            'content' => ['application/json' => ['schema' => $schema]],
        ];
    }

    /**
     * Les réponses.
     *
     * Les codes d'erreur ne sont pas énumérés fonction par fonction : ils sont
     * les mêmes partout, et les répéter soixante fois dans le document aurait
     * surtout garanti qu'ils finissent par différer.
     */
    private static function reponses(array $fonction): array
    {
        $succes = $fonction['ecrit'] && $fonction['methode'] === 'POST' ? '201' : '200';
        return [
            $succes => [
                'description' => $fonction['rend'] === []
                    ? 'Succès.'
                    : 'Champs rendus : ' . implode(', ', $fonction['rend']) . '.',
                'content' => ['application/json' => ['schema' => ['type' => 'object']]],
            ],
            '401' => ['$ref' => '#/components/responses/SansJeton'],
            '403' => ['$ref' => '#/components/responses/PorteeManquante'],
            '404' => ['$ref' => '#/components/responses/Absent'],
            '429' => ['$ref' => '#/components/responses/TropDeRequetes'],
        ];
    }

    public static function document(string $version = '1', string $serveur = '/'): array
    {
        $paths = [];
        foreach (Manifeste::toutes() as $fonction) {
            $chemin = Manifeste::cheminComplet($fonction);
            $paths[$chemin] ??= [];
            $paths[$chemin][strtolower((string) $fonction['methode'])] = array_filter([
                'tags' => [$fonction['groupe']],
                'summary' => $fonction['resume'],
                'description' => trim(($fonction['details'] ?? '') . ($fonction['ecrit']
                    ? "\n\nÉcriture : consignée au journal d'audit sous l'intitulé du jeton."
                    : '')),
                'operationId' => strtolower((string) $fonction['methode'])
                    . preg_replace_callback('/[^A-Za-z0-9]+(.)/', static fn (array $m): string => strtoupper($m[1]), $fonction['chemin']),
                'security' => [['jeton' => [$fonction['portee']]]],
                'parameters' => self::parametres($fonction),
                'requestBody' => self::corps($fonction),
                'responses' => self::reponses($fonction),
            ], static fn ($valeur): bool => $valeur !== null && $valeur !== '' && $valeur !== []);
        }

        $erreur = ['type' => 'object', 'properties' => ['error' => ['type' => 'string']], 'required' => ['error']];
        $reponse = static fn (string $texte): array => [
            'description' => $texte,
            'content' => ['application/json' => ['schema' => $erreur]],
        ];

        $portees = [];
        foreach (ApiTokens::SCOPES as $s) {
            $portees[] = ['cle' => $s['key'], 'intitule' => $s['label'], 'ecrit' => false, 'note' => $s['hint']];
        }
        foreach (ApiTokens::WRITE_SCOPES as $s) {
            $portees[] = ['cle' => $s['key'], 'intitule' => $s['label'], 'ecrit' => true, 'lit' => $s['reads'], 'note' => $s['hint']];
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Toutadmin — API v1',
                'version' => $version,
                'description' => implode("\n", [
                    "Une API de lecture, plus des écritures que l'administrateur accorde une à une.",
                    '',
                    "L'authentification tient dans l'en-tête `Authorization: Bearer …`. Il n'y a ni cookie, ni session, ni jeton CSRF.",
                    '',
                    "Une portée d'écriture s'écrit `domaine:ecriture` et emporte la lecture du même domaine. L'inverse est faux.",
                ]),
            ],
            'servers' => [['url' => $serveur]],
            'tags' => array_map(
                static fn (array $g): array => ['name' => $g['cle'], 'description' => $g['resume']],
                Manifeste::groupes()
            ),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'jeton' => ['type' => 'http', 'scheme' => 'bearer', 'description' => "Un jeton créé dans Intégrations → Jetons d'API."],
                ],
                'schemas' => ['Erreur' => $erreur],
                'responses' => [
                    'SansJeton' => $reponse('Jeton absent, invalide ou expiré.'),
                    'PorteeManquante' => $reponse("Le jeton n'a pas la portée exigée."),
                    'Absent' => $reponse('Ressource introuvable.'),
                    'TropDeRequetes' => $reponse('Plafond atteint. Les écritures ont le leur, distinct des lectures.'),
                ],
            ],
            'x-portees' => $portees,
        ];
    }
}
