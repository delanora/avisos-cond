<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Controle do agendador: lock entre execuções e histórico de cada tarefa. */
final class TarefaAgendadaRepository
{
    // Número fixo que identifica o lock do agendador no PostgreSQL.
    private const LOCK_ID = 7_351_002;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tenta ser a única execução do agendador. O lock é da CONEXÃO: o PostgreSQL o solta sozinho se o
     * processo morrer, então um cron que travou nunca deixa o agendador preso para sempre.
     */
    public function tentarTravar(): bool
    {
        $stmt = $this->pdo->prepare('SELECT pg_try_advisory_lock(:id)');
        $stmt->execute(['id' => self::LOCK_ID]);

        return (bool) $stmt->fetchColumn();
    }

    public function destravar(): void
    {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:id)');
        $stmt->execute(['id' => self::LOCK_ID]);
    }

    /**
     * A tarefa está vencida? Nunca rodou, ou o último sucesso é mais antigo que o intervalo, e a última
     * falha (se houver) foi há mais tempo que a espera (assim uma tarefa quebrada não repete a cada minuto).
     */
    public function vencida(string $nome, int $intervaloSegundos, int $esperaAposFalhaSegundos): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT NOT EXISTS (
                SELECT 1 FROM tarefas_agendadas
                WHERE nome = :nome AND (
                    ultima_execucao > now() - make_interval(secs => :intervalo)
                    OR ultima_falha_em > now() - make_interval(secs => :espera)
                )
            )'
        );
        $stmt->execute(['nome' => $nome, 'intervalo' => $intervaloSegundos, 'espera' => $esperaAposFalhaSegundos]);

        return (bool) $stmt->fetchColumn();
    }

    public function registrarSucesso(string $nome, int $duracaoMs, string $resumo): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tarefas_agendadas (nome, ultima_execucao, ultima_duracao_ms, ultimo_resultado, execucoes)
             VALUES (:nome, now(), :duracao, :resumo, 1)
             ON CONFLICT (nome) DO UPDATE SET
                 ultima_execucao = now(), ultima_duracao_ms = :duracao, ultimo_resultado = :resumo,
                 ultimo_erro = NULL, execucoes = tarefas_agendadas.execucoes + 1'
        );
        $stmt->execute(['nome' => $nome, 'duracao' => $duracaoMs, 'resumo' => mb_substr($resumo, 0, 1000)]);
    }

    public function registrarFalha(string $nome, string $erro): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tarefas_agendadas (nome, ultima_falha_em, ultimo_erro, falhas)
             VALUES (:nome, now(), :erro, 1)
             ON CONFLICT (nome) DO UPDATE SET
                 ultima_falha_em = now(), ultimo_erro = :erro, falhas = tarefas_agendadas.falhas + 1'
        );
        $stmt->execute(['nome' => $nome, 'erro' => mb_substr($erro, 0, 1000)]);
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->pdo->query(
            'SELECT nome, extract(epoch FROM ultima_execucao)::bigint AS ultima_execucao_epoch,
                    extract(epoch FROM ultima_falha_em)::bigint AS ultima_falha_epoch,
                    ultima_duracao_ms, ultimo_resultado, ultimo_erro, execucoes, falhas
             FROM tarefas_agendadas ORDER BY nome'
        )->fetchAll();
    }
}
