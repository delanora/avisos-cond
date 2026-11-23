<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\PareamentoRepository;

/** Apaga pareamentos de TV com mais de 1 dia (códigos vencem em 10 minutos; o resto é só histórico de poucas horas). */
final class LimparPareamentos implements Tarefa
{
    private const IDADE_SEGUNDOS = 86400;

    public function __construct(private readonly PareamentoRepository $repositorio)
    {
    }

    public function nome(): string
    {
        return 'limpar_pareamentos';
    }

    public function intervaloSegundos(): int
    {
        return 3600;
    }

    public function executar(): string
    {
        $removidos = $this->repositorio->removerAntigos(self::IDADE_SEGUNDOS);

        return "{$removidos} pareamento(s) antigo(s) removido(s)";
    }
}
