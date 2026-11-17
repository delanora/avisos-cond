<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Tarefas\Tarefa;
use Closure;

/** Tarefa de teste: o que ela faz é um Closure, e conta quantas vezes rodou. */
final class TarefaFalsa implements Tarefa
{
    public int $execucoes = 0;

    public function __construct(
        private readonly string $nome,
        private readonly int $intervalo = 60,
        private readonly ?Closure $acao = null,
    ) {
    }

    public function nome(): string
    {
        return $this->nome;
    }

    public function intervaloSegundos(): int
    {
        return $this->intervalo;
    }

    public function executar(): string
    {
        $this->execucoes++;
        if ($this->acao !== null) {
            ($this->acao)();
        }

        return "executada {$this->execucoes}x";
    }
}
