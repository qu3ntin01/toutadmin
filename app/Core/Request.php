<?php

declare(strict_types=1);

namespace App\Core;

/** Une requête entrante, lisible sans toucher aux superglobales partout. */
final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $files = [],
        public readonly array $headers = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = (string) $value;
            }
        }
        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            '/' . trim((string) $path, '/'),
            $_GET,
            $_POST,
            $_FILES,
            $headers,
        );
    }

    public function input(string $key, string $fallback = ''): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? $fallback;
        return is_scalar($value) ? trim((string) $value) : $fallback;
    }

    /**
     * Un champ envoyé en plusieurs exemplaires (« account_id[] »), rendu comme
     * une liste de chaînes. Une valeur simple devient une liste d'un élément :
     * l'appelant n'a pas à savoir combien de lignes le formulaire portait.
     */
    public function inputs(string $key): array
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? [];
        if (is_scalar($value)) {
            return [trim((string) $value)];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value
        ));
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }

    public function header(string $name, string $fallback = ''): string
    {
        return $this->headers[strtolower($name)] ?? $fallback;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }
}
