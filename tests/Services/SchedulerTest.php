<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\TarefaAgendadaRepository;
use App\Services\Scheduler;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use InvalidArgumentException;
use RuntimeException;
use Tests\BancoMigradoTestCase;
use Tests\Support\TarefaFalsa;

final class SchedulerTest extends BancoMigradoTestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = tempnam(sys_get_temp_dir(), 'log');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    /** @param list<TarefaFalsa> $tarefas */
    private function scheduler(array $tarefas, int $espera = 300): Scheduler
    {
        return new Scheduler(new TarefaAgendadaRepository($this->pdo), new Logger($this->log), $tarefas, $espera);
    }

    public function testExecutaTodasAsTarefasVencidasNaPrimeiraVez(): void
    {
        $a = new TarefaFalsa('a');
        $b = new TarefaFalsa('b');

        $r = $this->scheduler([$a, $b])->executar();

        $this->assertFalse($r['bloqueado']);
        $this->assertSame(['a', 'b'], array_column($r['tarefas'], 'tarefa'));
        $this->assertSame(['ok', 'ok'], array_column($r['tarefas'], 'situacao'));
        $this->assertSame([1, 1], [$a->execucoes, $b->execucoes]);
        $this->assertSame('executada 1x', $r['tarefas'][0]['resumo']);
    }

    public function testRodarDeNovoLogoEmSeguidaNaoFazNada(): void
    {
        $a = new TarefaFalsa('a', 3600);
        $scheduler = $this->scheduler([$a]);

        $scheduler->executar();
        $segunda = $scheduler->executar();

        $this->assertSame([], $segunda['tarefas'], 'Ainda não venceu: nada roda (idempotente).');
        $this->assertSame(1, $a->execucoes);
    }

    public function testCadaTarefaTemOSeuProprioIntervalo(): void
    {
        $rapida = new TarefaFalsa('rapida', 60);
        $lenta = new TarefaFalsa('lenta', 86400);
        $scheduler = $this->scheduler([$rapida, $lenta]);
        $scheduler->executar();

        // Passam 2 minutos: só a rápida venceu.
        $this->pdo->exec("UPDATE tarefas_agendadas SET ultima_execucao = now() - interval '2 minutes'");
        $r = $scheduler->executar();

        $this->assertSame(['rapida'], array_column($r['tarefas'], 'tarefa'));
        $this->assertSame([2, 1], [$rapida->execucoes, $lenta->execucoes]);
    }

    public function testForcarRodaTudoMesmoSemVencer(): void
    {
        $a = new TarefaFalsa('a', 86400);
        $scheduler = $this->scheduler([$a]);
        $scheduler->executar();

        $r = $scheduler->executar(forcar: true);

        $this->assertCount(1, $r['tarefas']);
        $this->assertSame(2, $a->execucoes);
    }

    public function testFalhaNumaTarefaNaoImpedeAsOutrasEFicaRegistrada(): void
    {
        $antes = new TarefaFalsa('antes');
        $quebra = new TarefaFalsa('quebra', 60, static function (): void {
            throw new RuntimeException('erro-interno-xyz');
        });
        $depois = new TarefaFalsa('depois');

        $r = $this->scheduler([$antes, $quebra, $depois])->executar();

        $this->assertSame(['ok', 'falhou', 'ok'], array_column($r['tarefas'], 'situacao'));
        $this->assertSame('erro-interno-xyz', $r['tarefas'][1]['resumo']);
        $this->assertSame(1, $depois->execucoes, 'As tarefas seguintes rodam normalmente.');

        $historico = array_column((new TarefaAgendadaRepository($this->pdo))->listar(), null, 'nome');
        $this->assertSame(1, (int) $historico['quebra']['falhas']);
        $this->assertSame('erro-interno-xyz', $historico['quebra']['ultimo_erro']);
        $this->assertSame(1, (int) $historico['depois']['execucoes']);

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('Tarefa agendada falhou: quebra', $log);
        $this->assertStringContainsString('erro-interno-xyz', $log);
    }

    public function testTarefaQueFalhouEsperaAntesDeTentarDeNovoERetomaDepois(): void
    {
        $tentativas = 0;
        $instavel = new TarefaFalsa('instavel', 3600, static function () use (&$tentativas): void {
            if (++$tentativas === 1) {
                throw new RuntimeException('falhou uma vez');
            }
        });
        $scheduler = $this->scheduler([$instavel], 300);

        $this->assertSame('falhou', $scheduler->executar()['tarefas'][0]['situacao']);
        $this->assertSame([], $scheduler->executar()['tarefas'], 'Dentro da espera: não repete a cada minuto.');

        $this->pdo->exec("UPDATE tarefas_agendadas SET ultima_falha_em = now() - interval '6 minutes'");
        $this->assertSame('ok', $scheduler->executar()['tarefas'][0]['situacao'], 'Passada a espera, tenta de novo (sem esperar o intervalo de 1 h).');
        $this->assertSame([], $scheduler->executar()['tarefas'], 'E agora o sucesso vale pelo intervalo normal.');
    }

    public function testDuasExecucoesSimultaneasUmaDeslistaSemFazerNada(): void
    {
        $a = new TarefaFalsa('a');
        $outra = new TarefaAgendadaRepository(Db::connect(Config::fromFile(dirname(__DIR__, 2) . '/.env')));
        $this->assertTrue($outra->tentarTravar(), 'Outra execução do cron está rodando.');

        try {
            $r = $this->scheduler([$a])->executar();
        } finally {
            $outra->destravar();
        }

        $this->assertTrue($r['bloqueado']);
        $this->assertSame([], $r['tarefas']);
        $this->assertSame(0, $a->execucoes, 'Quem não consegue o lock não roda nada.');
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tarefas_agendadas')->fetchColumn());
    }

    public function testOLockEhLiberadoAoTerminarMesmoComTarefasFalhando(): void
    {
        $quebra = new TarefaFalsa('quebra', 60, static function (): void {
            throw new RuntimeException('x');
        });
        $scheduler = $this->scheduler([$quebra]);
        $scheduler->executar();

        $outra = new TarefaAgendadaRepository(Db::connect(Config::fromFile(dirname(__DIR__, 2) . '/.env')));
        $this->assertTrue($outra->tentarTravar(), 'O lock foi solto no fim da execução.');
        $outra->destravar();
    }

    public function testNomesRepetidosSaoRejeitados(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->scheduler([new TarefaFalsa('mesma'), new TarefaFalsa('mesma')]);
    }

    public function testSemTarefasNaoFazNada(): void
    {
        $this->assertSame(['bloqueado' => false, 'tarefas' => []], $this->scheduler([])->executar());
    }
}
