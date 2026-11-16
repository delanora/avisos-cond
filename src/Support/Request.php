<?php

declare(strict_types=1);

namespace App\Support;

final class Request
{
    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $body
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $attributes dados anexados pelos middlewares (ex.: usuário autenticado)
     * @param array<string, mixed>  $files $_FILES (uploads)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
        public readonly array $attributes = [],
        public readonly array $files = [],
    ) {
    }

    public function withAttribute(string $nome, mixed $valor): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->server,
            $this->cookies,
            [...$this->attributes, $nome => $valor],
            $this->files,
        );
    }

    public function attribute(string $nome, mixed $padrao = null): mixed
    {
        return $this->attributes[$nome] ?? $padrao;
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path === '' ? '/' : $path,
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
            [],
            $_FILES,
        );
    }

    /**
     * IP do cliente. Vem só de REMOTE_ADDR: atrás do proxy, o Nginx da VM já o
     * substitui pelo IP real (real_ip). Nunca lemos X-Forwarded-For aqui, pois
     * seria forjável (AGENTS.md 6.9).
     */
    public function ip(): string
    {
        return $this->server['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function header(string $nome): ?string
    {
        $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));

        return $this->server[$chave] ?? null;
    }
}
