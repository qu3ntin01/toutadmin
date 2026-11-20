<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Routage.
 *
 * Des motifs simples, « /rh/salaries/{id} », convertis en expression
 * régulière. Rien de plus : l'application n'a pas de routes dynamiques
 * arbitraires, et un routeur qu'on lit d'un coup d'œil vaut mieux qu'un
 * routeur qui sait tout faire.
 */
final class Router
{
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function add(string $method, string $pattern, callable $handler): void
    {
        /*
         * « {stepId} » est un paramètre comme « {id} ».
         *
         * Le motif ne reconnaissait que « [a-z_] », et six routes du produit
         * portent un nom en bosses : « {stepId} », « {userId} », « {skillId} »,
         * « {questionId} ». Leur accolade restait littérale dans l'expression
         * régulière, donc aucune adresse réelle ne la rencontrait jamais : retirer
         * une étape d'un circuit, retirer une compétence à quelqu'un, retirer un
         * membre d'un projet, pointer une présence, retirer un participant,
         * supprimer une question de sondage — six boutons qui rendaient 404,
         * dans cette édition seulement. C'est un groupe nommé de PCRE : il
         * accepte les majuscules et les chiffres, et l'édition Node les accepte
         * depuis toujours.
         */
        $regex = '#^' . preg_replace(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            '(?P<$1>[^/]+)',
            str_replace('#', '\#', $pattern)
        ) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'pattern' => $pattern];
    }

    /**
     * HEAD est un GET dont on jette le corps.
     *
     * La règle est celle de HTTP, et ce n'est pas une subtilité d'école : les
     * robots d'indexation, les vérificateurs de liens et les surveillances de
     * disponibilité demandent HEAD avant tout le reste. Sans cette ligne,
     * chaque adresse du service leur répondait 404.
     */
    public function match(Request $request): ?array
    {
        $methode = $request->method === 'HEAD' ? 'GET' : $request->method;

        $allowedButWrongMethod = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $methode) {
                $allowedButWrongMethod = true;
                continue;
            }
            $params = array_filter($matches, static fn ($key) => !is_int($key), ARRAY_FILTER_USE_KEY);
            return ['handler' => $route['handler'], 'params' => $params];
        }
        return $allowedButWrongMethod ? ['handler' => null, 'params' => []] : null;
    }

    public function patterns(): array
    {
        return array_column($this->routes, 'pattern');
    }
}
