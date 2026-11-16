<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RateLimitRepository;

/**
 * Limite de tentativas (anti-bruteforce) com contadores no PostgreSQL.
 * Chaves com dados pessoais (e-mail) devem usar {@see self::hashDe()}.
 */
final class RateLimiter
{
    public function __construct(private readonly RateLimitRepository $repositorio)
    {
    }

    /** Segundos restantes de bloqueio (0 = liberado). */
    public function segundosBloqueado(string $chave): int
    {
        return $this->repositorio->segundosBloqueado($chave);
    }

    /**
     * Conta uma tentativa. Se passar do máximo dentro da janela, bloqueia a chave.
     *
     * @return bool true se a chave ficou (ou já estava) bloqueada
     */
    public function registrar(string $chave, int $maximo, int $janelaSegundos, int $bloqueioSegundos): bool
    {
        $tentativas = $this->repositorio->registrarTentativa($chave, $janelaSegundos);
        if ($tentativas > $maximo) {
            $this->repositorio->bloquear($chave, $bloqueioSegundos);

            return true;
        }

        return false;
    }

    public function limpar(string $chave): void
    {
        $this->repositorio->limpar($chave);
    }

    /** Identificador estável sem expor o dado original (ex.: e-mail) na tabela. */
    public static function hashDe(string $valor): string
    {
        return hash('sha256', mb_strtolower(trim($valor)));
    }
}
