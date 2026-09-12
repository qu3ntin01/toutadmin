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
        $regex = '#^' . preg_replace('/\{([a-z_]+)\}/', '(?P<$1>[^/]+)', str_replace('#', '\#', $pattern)) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'pattern' => $pattern];
    }

    public function match(Request $request): ?array
    {
        $allowedButWrongMethod = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }
            if ($route['method'] !== $request->method) {
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
