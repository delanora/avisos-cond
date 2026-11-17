<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\RateLimitRepository;

/** Apaga contadores de tentativas (login, recuperação de senha, token de TV) antigos e sem bloqueio ativo. */
final class LimparRateLimits implements Tarefa
{
    private const IDADE_SEGUNDOS = 86400;

    public function __construct(private readonly RateLimitRepository $repositorio)
    {
    }

    public function nome(): string
    {
        return 'limpar_rate_limits';
    }

    public function intervaloSegundos(): int
    {
        return 3600;
    }

    public function executar(): string
    {
        $removidos = $this->repositorio->removerAntigos(self::IDADE_SEGUNDOS);

        return "{$removidos} contador(es) removido(s)";
    }
}
