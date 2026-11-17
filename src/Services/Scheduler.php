<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\TarefaAgendadaRepository;
use App\Services\Tarefas\Tarefa;
use App\Support\Logger;
use InvalidArgumentException;
use Throwable;

/**
 * Agendador (bin/cron.php roda a cada minuto). Garante:
 *  - uma única execução por vez (lock no PostgreSQL); quem não consegue o lock sai sem fazer nada;
 *  - cada tarefa só roda quando está vencida (intervalo próprio), então rodar o cron várias vezes é inofensivo;
 *  - falha numa tarefa é registrada (log e histórico) e NÃO impede as demais;
 *  - tarefa que falhou só é tentada de novo depois de uma espera, para não repetir o erro a cada minuto.
 */
final class Scheduler
{
    /** @var list<Tarefa> */
    private readonly array $tarefas;

    /** @param list<Tarefa> $tarefas */
    public function __construct(
        private readonly TarefaAgendadaRepository $repositorio,
        private readonly Logger $logger,
        array $tarefas,
        private readonly int $esperaAposFalhaSegundos = 300,
    ) {
        $nomes = array_map(static fn (Tarefa $t): string => $t->nome(), $tarefas);
        if (count($nomes) !== count(array_unique($nomes))) {
            throw new InvalidArgumentException('Há tarefas agendadas com o mesmo nome.');
        }
        $this->tarefas = $tarefas;
    }

    /**
     * @param bool $forcar roda todas as tarefas agora, mesmo as que ainda não venceram (uso manual)
     * @return array{bloqueado: bool, tarefas: list<array{tarefa: string, situacao: string, resumo: string, duracao_ms: int}>}
     */
    public function executar(bool $forcar = false): array
    {
        if (!$this->repositorio->tentarTravar()) {
            return ['bloqueado' => true, 'tarefas' => []];
        }

        try {
            $resultados = [];
            foreach ($this->tarefas as $tarefa) {
                if ($forcar || $this->repositorio->vencida($tarefa->nome(), $tarefa->intervaloSegundos(), $this->esperaAposFalhaSegundos)) {
                    $resultados[] = $this->rodar($tarefa);
                }
            }

            return ['bloqueado' => false, 'tarefas' => $resultados];
        } finally {
            $this->repositorio->destravar();
        }
    }

    /** @return array{tarefa: string, situacao: string, resumo: string, duracao_ms: int} */
    private function rodar(Tarefa $tarefa): array
    {
        $inicio = microtime(true);

        try {
            $resumo = $tarefa->executar();
            $duracao = (int) round((microtime(true) - $inicio) * 1000);
            $this->repositorio->registrarSucesso($tarefa->nome(), $duracao, $resumo);

            return ['tarefa' => $tarefa->nome(), 'situacao' => 'ok', 'resumo' => $resumo, 'duracao_ms' => $duracao];
        } catch (Throwable $erro) {
            $duracao = (int) round((microtime(true) - $inicio) * 1000);
            $this->logger->error('Tarefa agendada falhou: ' . $tarefa->nome(), $erro);
            try {
                $this->repositorio->registrarFalha($tarefa->nome(), $erro->getMessage());
            } catch (Throwable $erroAoRegistrar) {
                $this->logger->error('Não foi possível registrar a falha da tarefa ' . $tarefa->nome(), $erroAoRegistrar);
            }

            return ['tarefa' => $tarefa->nome(), 'situacao' => 'falhou', 'resumo' => $erro->getMessage(), 'duracao_ms' => $duracao];
        }
    }
}
