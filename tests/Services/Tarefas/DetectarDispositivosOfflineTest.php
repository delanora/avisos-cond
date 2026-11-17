<?php

declare(strict_types=1);

namespace Tests\Services\Tarefas;

use App\Repositories\DispositivoRepository;
use App\Services\Tarefas\DetectarDispositivosOffline;
use Tests\AppHttpTestCase;

final class DetectarDispositivosOfflineTest extends AppHttpTestCase
{
    public function testNomeIntervaloEResumo(): void
    {
        $repo = new DispositivoRepository($this->pdo);
        $tarefa = new DetectarDispositivosOffline($repo);
        $condominio = $this->criarCondominio('Alfa');
        $id = (int) $repo->criar($condominio, 'Elevador', 'ABCDEFGH2345');
        $this->pdo->exec("UPDATE dispositivos SET ultimo_ping = now() - interval '10 minutes' WHERE id = {$id}");

        $this->assertSame('detectar_dispositivos_offline', $tarefa->nome());
        $this->assertSame(60, $tarefa->intervaloSegundos());
        $this->assertSame('1 ficou(aram) offline, 0 voltou(aram)', $tarefa->executar());
        $this->assertSame('0 ficou(aram) offline, 0 voltou(aram)', $tarefa->executar(), 'Segunda execução não repete.');

        $this->pdo->exec("UPDATE dispositivos SET ultimo_ping = now() WHERE id = {$id}");
        $this->assertSame('0 ficou(aram) offline, 1 voltou(aram)', $tarefa->executar());
    }
}
