#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\CronFactory;
use App\Repositories\TarefaAgendadaRepository;
use App\Support\Config;
use App\Support\DataHora;
use App\Support\Db;
use App\Support\Logger;

/*
 * Agendador. Roda a cada minuto pelo cron do sistema (/etc/cron.d/avisos-cond) e executa só as tarefas vencidas.
 *
 *   php bin/cron.php            executa as tarefas vencidas
 *   php bin/cron.php --forcar   executa todas agora, vencidas ou não
 *   php bin/cron.php --status   mostra o histórico de cada tarefa
 *
 * Saída: uma linha por tarefa executada (nada quando não há o que fazer). Código de saída: 0 = ok (ou outra
 * execução em andamento), 1 = alguma tarefa falhou ou o agendador não conseguiu iniciar.
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$raiz = dirname(__DIR__);
$opcoes = array_slice($argv, 1);
$agora = static fn (): string => gmdate('Y-m-d\TH:i:s\Z');

try {
    $config = Config::fromFile($raiz . '/.env');
    $pdo = Db::connect($config);
    // LOG_DIR é opcional (os testes usam uma pasta temporária); o padrão é storage/logs.
    $logger = new Logger(rtrim($config->string('LOG_DIR', $raiz . '/storage/logs'), '/') . '/app.log');

    if (in_array('--status', $opcoes, true)) {
        foreach ((new TarefaAgendadaRepository($pdo))->listar() as $t) {
            $ultima = $t['ultima_execucao_epoch'] === null ? 'nunca' : DataHora::exibir((int) $t['ultima_execucao_epoch']);
            printf("%-28s última execução: %s | execuções: %d | falhas: %d%s\n", $t['nome'], $ultima, $t['execucoes'], $t['falhas'], $t['ultimo_erro'] !== null ? ' | último erro: ' . $t['ultimo_erro'] : '');
        }
        exit(0);
    }

    $relatorio = CronFactory::criar($config, $pdo, $logger)->executar(in_array('--forcar', $opcoes, true));
} catch (Throwable $erro) {
    fwrite(STDERR, '[' . $agora() . '] Erro ao iniciar o agendador: ' . $erro->getMessage() . PHP_EOL);
    exit(1);
}

if ($relatorio['bloqueado']) {
    echo '[' . $agora() . '] Outra execução do agendador está em andamento; nada a fazer.' . PHP_EOL;
    exit(0);
}

$falhou = false;
foreach ($relatorio['tarefas'] as $t) {
    printf("[%s] %s: %s - %s (%d ms)\n", $agora(), $t['tarefa'], $t['situacao'], $t['resumo'], $t['duracao_ms']);
    $falhou = $falhou || $t['situacao'] !== 'ok';
}

exit($falhou ? 1 : 0);
