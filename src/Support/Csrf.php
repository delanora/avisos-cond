<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Token CSRF guardado na sessão. Deve ser enviado em todo POST/PUT/PATCH/DELETE,
 * no campo "_csrf" do formulário ou no cabeçalho X-CSRF-Token (HTMX).
 */
final class Csrf
{
    private const CHAVE = 'csrf_token';

    public function __construct(private readonly Session $sessao)
    {
    }

    public function token(): string
    {
        $this->sessao->start();

        $token = $this->sessao->get(self::CHAVE);
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->sessao->set(self::CHAVE, $token);
        }

        return $token;
    }

    public function valido(?string $informado): bool
    {
        if ($informado === null || $informado === '') {
            return false;
        }

        return hash_equals($this->token(), $informado);
    }
}
