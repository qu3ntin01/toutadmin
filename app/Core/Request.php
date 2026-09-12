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

    /**
     * Un fichier reçu, lu en mémoire et rendu sous une forme stable :
     * ['bytes' => …, 'mime' => …, 'name' => …]. Rien n'est écrit sur le disque
     * ici — c'est au module qui l'accepte de décider, après ses contrôles.
     */
    public function file(string $key): ?array
    {
        $entry = $this->files[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        // Le tableau posé par un test porte déjà ses octets ; celui de PHP
        // porte un chemin temporaire.
        if (isset($entry['bytes'])) {
            return $entry['bytes'] === '' ? null : [
                'bytes' => (string) $entry['bytes'],
                'mime' => (string) ($entry['mime'] ?? ''),
                'name' => (string) ($entry['name'] ?? ''),
            ];
        }
        if (($entry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $entry['tmp_name'])) {
            return null;
        }
        return [
            'bytes' => (string) file_get_contents((string) $entry['tmp_name']),
            'mime' => (string) ($entry['type'] ?? ''),
            'name' => (string) ($entry['name'] ?? ''),
        ];
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
