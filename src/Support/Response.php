<?php

declare(strict_types=1);

namespace App\Support;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed> $dados */
    public static function json(array $dados, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($dados, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, '', ['Location' => $url]);
    }

    public function withHeader(string $nome, string $valor): self
    {
        $headers = $this->headers;
        foreach (array_keys($headers) as $existente) {
            if (strcasecmp($existente, $nome) === 0) {
                unset($headers[$existente]);
            }
        }
        $headers[$nome] = $valor;

        return new self($this->status, $this->body, $headers);
    }

    public function hasHeader(string $nome): bool
    {
        foreach (array_keys($this->headers) as $existente) {
            if (strcasecmp($existente, $nome) === 0) {
                return true;
            }
        }

        return false;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $nome => $valor) {
            header($nome . ': ' . $valor);
        }
        echo $this->body;
    }
}
