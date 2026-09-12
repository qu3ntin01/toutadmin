<?php

declare(strict_types=1);

namespace App\Core;

/** Une réponse : un état, des en-têtes, un corps. Envoyée une seule fois. */
final class Response
{
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = ['Content-Type' => 'text/html; charset=UTF-8'],
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body);
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self($status, '', ['Location' => $location]);
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function withHeaders(array $headers): self
    {
        $this->headers = array_merge($this->headers, $headers);
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }
}
