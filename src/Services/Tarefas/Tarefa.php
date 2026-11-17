<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

/**
 * Uma tarefa do agendador (bin/cron.php). Toda tarefa deve ser IDEMPOTENTE: rodar duas vezes (ou depois de
 * uma execução interrompida) não pode duplicar nem estragar nada. Para criar uma nova, implemente esta
 * interface e registre-a em App\CronFactory.
 */
interface Tarefa
{
    /** Nome único (minúsculas e "_"); é a chave do histórico em tarefas_agendadas. */
    public function nome(): string;

    /** De quanto em quanto tempo a tarefa deve rodar (o cron roda a cada minuto; só as vencidas executam). */
    public function intervaloSegundos(): int;

    /**
     * Executa e devolve um resumo curto do que fez (vai para o log do cron e para o histórico).
     * Falhas devem lançar exceção: o agendador registra e segue com as demais tarefas.
     */
    public function executar(): string;
}
