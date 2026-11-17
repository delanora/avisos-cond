<?php

declare(strict_types=1);

namespace App;

use App\Repositories\ArquivoRepository;
use App\Repositories\DispositivoRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\TarefaAgendadaRepository;
use App\Services\Scheduler;
use App\Services\Tarefas\DetectarDispositivosOffline;
use App\Services\Tarefas\LimparArquivosOrfaos;
use App\Services\Tarefas\LimparRateLimits;
use App\Services\Tarefas\LimparResetsDeSenha;
use App\Services\UploadService;
use App\Support\Config;
use App\Support\Logger;
use PDO;

/** Monta o agendador com a lista de tarefas. Para criar uma tarefa nova, registre-a aqui. */
final class CronFactory
{
    public static function criar(Config $config, PDO $pdo, Logger $logger): Scheduler
    {
        // A pasta de uploads é só leitura/limpeza aqui: nenhum arquivo é movido para dentro dela.
        $upload = new UploadService($config->obrigatorio('UPLOAD_DIR'), max(1, $config->int('UPLOAD_MAX_MB', 10)) * 1048576);

        return new Scheduler(
            new TarefaAgendadaRepository($pdo),
            $logger,
            [
                new LimparRateLimits(new RateLimitRepository($pdo)),
                new LimparResetsDeSenha(new PasswordResetRepository($pdo)),
                new LimparArquivosOrfaos($upload, new ArquivoRepository($pdo)),
                new DetectarDispositivosOffline(new DispositivoRepository($pdo)),
            ]
        );
    }
}
