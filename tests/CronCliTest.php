<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\TarefaAgendadaRepository;
use App\Support\Config;
use App\Support\Db;

/**
 * Executa o bin/cron.php de verdade (subprocesso) contra o banco de teste. A pasta de uploads é SEMPRE uma
 * pasta temporária: a limpeza de órfãos nunca pode ver os arquivos reais do sistema.
 */
final class CronCliTest extends BancoMigradoTestCase
{
    private string $uploads;
    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . '/cron_cli_' . bin2hex(random_bytes(5));
        $this->uploads = $base . '/uploads';
        $this->logs = $base . '/logs';
        mkdir($this->uploads, 0750, true);
        mkdir($this->logs, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach ([$this->uploads, $this->logs] as $pasta) {
            foreach (glob($pasta . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($pasta);
        }
        @rmdir(dirname($this->uploads));
    }

    /**
     * @param list<string>          $opcoes
     * @param array<string, string> $ambiente variáveis extras (sobrescrevem as do .env)
     * @return array{saida: string, erro: string, codigo: int}
     */
    private function rodar(array $opcoes = [], array $ambiente = []): array
    {
        $comando = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/cron.php'], $opcoes);
        $processo = proc_open($comando, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $ambiente + ['UPLOAD_DIR' => $this->uploads, 'LOG_DIR' => $this->logs] + getenv());

        $saida = (string) stream_get_contents($pipes[1]);
        $erro = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['saida' => $saida, 'erro' => $erro, 'codigo' => proc_close($processo)];
    }

    public function testPrimeiraExecucaoRodaTodasAsTarefasEPassaOCodigoZero(): void
    {
        $r = $this->rodar();

        $this->assertSame(0, $r['codigo'], $r['erro'] . $r['saida']);
        foreach (['limpar_rate_limits', 'limpar_resets_de_senha', 'limpar_arquivos_orfaos'] as $tarefa) {
            $this->assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT[\d:]+Z\] ' . $tarefa . ': ok - .+ \(\d+ ms\)$/m', $r['saida'], $tarefa);
        }
        $this->assertSame(3, (int) $this->pdo->query('SELECT count(*) FROM tarefas_agendadas')->fetchColumn());
        $this->assertSame('', $r['erro']);
    }

    public function testSegundaExecucaoLogoEmSeguidaNaoImprimeNadaNemFazNada(): void
    {
        $this->rodar();

        $r = $this->rodar();

        $this->assertSame(0, $r['codigo']);
        $this->assertSame('', $r['saida'], 'O cron roda todo minuto: sem tarefas vencidas, o log fica quieto.');
        $this->assertSame(3, (int) $this->pdo->query('SELECT sum(execucoes) FROM tarefas_agendadas')->fetchColumn(), 'Nenhuma tarefa rodou de novo.');
    }

    public function testForcarRodaTudoDeNovo(): void
    {
        $this->rodar();

        $r = $this->rodar(['--forcar']);

        $this->assertSame(0, $r['codigo']);
        $this->assertSame(3, substr_count($r['saida'], ': ok - '));
        $this->assertSame(6, (int) $this->pdo->query('SELECT sum(execucoes) FROM tarefas_agendadas')->fetchColumn());
    }

    public function testStatusMostraOHistorico(): void
    {
        $this->rodar();

        $r = $this->rodar(['--status']);

        $this->assertSame(0, $r['codigo']);
        $this->assertStringContainsString('limpar_arquivos_orfaos', $r['saida']);
        $this->assertStringContainsString('execuções: 1 | falhas: 0', $r['saida']);
        $this->assertSame(3, substr_count($r['saida'], 'última execução:'));
    }

    public function testComOutraExecucaoEmAndamentoSaiSemFazerNadaECodigoZero(): void
    {
        $outra = new TarefaAgendadaRepository(Db::connect(Config::fromFile(dirname(__DIR__) . '/.env')));
        $this->assertTrue($outra->tentarTravar());

        try {
            $r = $this->rodar();
        } finally {
            $outra->destravar();
        }

        $this->assertSame(0, $r['codigo'], 'Não é erro: a outra execução cuida do trabalho.');
        $this->assertStringContainsString('Outra execução do agendador está em andamento', $r['saida']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tarefas_agendadas')->fetchColumn());
    }

    public function testTarefaQueFalhaDaCodigoUmMasAsOutrasRodam(): void
    {
        $this->pdo->exec('DROP TABLE password_resets');

        $r = $this->rodar();

        $this->assertSame(1, $r['codigo']);
        $this->assertMatchesRegularExpression('/limpar_resets_de_senha: falhou/', $r['saida']);
        $this->assertMatchesRegularExpression('/limpar_rate_limits: ok/', $r['saida']);
        $this->assertMatchesRegularExpression('/limpar_arquivos_orfaos: ok/', $r['saida']);
        $this->assertStringContainsString('Tarefa agendada falhou: limpar_resets_de_senha', (string) @file_get_contents($this->logs . '/app.log'));
        $falhas = (int) $this->pdo->query("SELECT falhas FROM tarefas_agendadas WHERE nome = 'limpar_resets_de_senha'")->fetchColumn();
        $this->assertSame(1, $falhas);
    }

    public function testDisjuntorDaLimpezaDeArquivosAbortaSemApagarNada(): void
    {
        for ($i = 0; $i < 30; $i++) {
            file_put_contents($this->uploads . '/' . bin2hex(random_bytes(16)) . '.png', 'x');
        }
        foreach (glob($this->uploads . '/*') as $f) {
            touch($f, time() - 3 * 86400);
        }

        $r = $this->rodar();

        $this->assertSame(1, $r['codigo']);
        $this->assertStringContainsString('limpar_arquivos_orfaos: falhou - Disjuntor', $r['saida']);
        $this->assertCount(30, glob($this->uploads . '/*'), 'Nenhum arquivo foi apagado.');
    }

    public function testNaoIniciaComBancoInacessivelECodigoUm(): void
    {
        $r = $this->rodar([], ['DB_PASS' => 'senha-errada-de-proposito', 'DB_HOST' => '127.0.0.1']);

        $this->assertSame(1, $r['codigo']);
        $this->assertStringContainsString('Erro ao iniciar o agendador', $r['erro']);
        $this->assertStringNotContainsString('senha-errada-de-proposito', $r['erro'] . $r['saida'], 'A senha não vai para a saída.');
    }

    public function testNaoMexeNosUploadsReaisDoSistema(): void
    {
        // O .env aponta para a pasta real; o teste a substitui, e a execução nunca deve olhar para ela.
        $real = Config::fromFile(dirname(__DIR__) . '/.env')->string('UPLOAD_DIR');
        $antes = glob($real . '/*') ?: [];

        $this->rodar(['--forcar']);

        $this->assertSame($antes, glob($real . '/*') ?: []);
    }
}
