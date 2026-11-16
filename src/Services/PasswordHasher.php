<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Senhas com Argon2id (nunca em texto puro). Os parâmetros seguem a recomendação da OWASP
 * (19 MiB de memória, 2 iterações, 1 thread). Em testes use parâmetros menores, para ser rápido.
 */
final class PasswordHasher
{
    /** @param array{memory_cost: int, time_cost: int, threads: int} $opcoes */
    public function __construct(
        private readonly array $opcoes = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1],
    ) {
    }

    public function hash(string $senha): string
    {
        return password_hash($senha, PASSWORD_ARGON2ID, $this->opcoes);
    }

    public function verificar(string $senha, string $hash): bool
    {
        return password_verify($senha, $hash);
    }

    /** True quando o hash guardado usa parâmetros antigos e deve ser refeito no próximo login. */
    public function precisaRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_ARGON2ID, $this->opcoes);
    }
}
