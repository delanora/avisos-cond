<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\TarefaAgendadaRepository;
use App\Support\Config;
use App\Support\Db;
use PDO;
use Tests\BancoMigradoTestCase;

final class TarefaAgendadaRepositoryTest extends BancoMigradoTestCase
{
    private TarefaAgendadaRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TarefaAgendadaRepository($this->pdo);
    }

    private function outraConexao(): PDO
    {
        return Db::connect(Config::fromFile(dirname(__DIR__, 2) . '/.env'));
    }

    // ---------- Lock ----------

    public function testSoUmaConexaoPorVezConsegueOLock(): void
    {
        $outra = new TarefaAgendadaRepository($this->outraConexao());

        $this->assertTrue($this->repo->tentarTravar());
        try {
            $this->assertFalse($outra->tentarTravar(), 'Segunda execução simultânea não consegue o lock.');
        } finally {
            $this->repo->destravar();
        }

        $this->assertTrue($outra->tentarTravar(), 'Liberado o lock, outra execução consegue.');
        $outra->destravar();
    }

    public function testOLockEDaConexaoESoltaSozinhoQuandoOProcessoMorre(): void
    {
        // Um cron que travou ou foi morto não pode deixar o agendador preso: o PostgreSQL solta o lock ao fechar a conexão.
        $morta = $this->outraConexao();
        $this->assertTrue((new TarefaAgendadaRepository($morta))->tentarTravar());
        $this->assertFalse($this->repo->tentarTravar(), 'Enquanto a outra conexão vive, o lock é dela.');

        $morta = null; // fecha a conexão

        $this->assertTrue($this->repo->tentarTravar(), 'Conexão fechada: o lock foi solto.');
        $this->repo->destravar();
    }

    // ---------- Vencimento ----------

    public function testTarefaQueNuncaRodouEstaVencida(): void
    {
        $this->assertTrue($this->repo->vencida('limpar', 3600, 300));
    }

    public function testDepoisDeUmSucessoSoVenceQuandoOIntervaloPassa(): void
    {
        $this->repo->registrarSucesso('limpar', 12, '3 removidos');

        $this->assertFalse($this->repo->vencida('limpar', 3600, 300));

        $this->pdo->exec("UPDATE tarefas_agendadas SET ultima_execucao = now() - interval '59 minutes'");
        $this->assertFalse($this->repo->vencida('limpar', 3600, 300));

        $this->pdo->exec("UPDATE tarefas_agendadas SET ultima_execucao = now() - interval '61 minutes'");
        $this->assertTrue($this->repo->vencida('limpar', 3600, 300));
    }

    public function testDepoisDeUmaFalhaEsperaAntesDeTentarDeNovo(): void
    {
        $this->repo->registrarFalha('limpar', 'deu erro');

        $this->assertFalse($this->repo->vencida('limpar', 3600, 300), 'Falhou agora: espera.');

        $this->pdo->exec("UPDATE tarefas_agendadas SET ultima_falha_em = now() - interval '6 minutes'");
        $this->assertTrue($this->repo->vencida('limpar', 3600, 300), 'Passou a espera: tenta de novo, sem esperar o intervalo inteiro.');
    }

    public function testTarefasSaoIndependentes(): void
    {
        $this->repo->registrarSucesso('a', 1, 'ok');

        $this->assertFalse($this->repo->vencida('a', 3600, 300));
        $this->assertTrue($this->repo->vencida('b', 3600, 300));
    }

    // ---------- Histórico ----------

    public function testHistoricoContaExecucoesEFalhas(): void
    {
        $this->repo->registrarSucesso('limpar', 10, 'primeira');
        $this->repo->registrarSucesso('limpar', 20, 'segunda');
        $this->repo->registrarFalha('limpar', 'quebrou');

        $t = $this->repo->listar()[0];

        $this->assertSame('limpar', $t['nome']);
        $this->assertSame(2, (int) $t['execucoes']);
        $this->assertSame(1, (int) $t['falhas']);
        $this->assertSame('segunda', $t['ultimo_resultado']);
        $this->assertSame(20, (int) $t['ultima_duracao_ms']);
        $this->assertSame('quebrou', $t['ultimo_erro']);
        $this->assertSame(1, $this->contar('tarefas_agendadas'), 'Uma linha por tarefa (upsert).');
    }

    public function testSucessoLimpaOUltimoErro(): void
    {
        $this->repo->registrarFalha('limpar', 'quebrou');
        $this->repo->registrarSucesso('limpar', 5, 'voltou');

        $this->assertNull($this->repo->listar()[0]['ultimo_erro']);
    }

    public function testTextosLongosSaoCortados(): void
    {
        $this->repo->registrarFalha('limpar', str_repeat('x', 5000));
        $this->repo->registrarSucesso('outra', 1, str_repeat('y', 5000));

        $linhas = array_column($this->repo->listar(), null, 'nome');
        $this->assertSame(1000, mb_strlen($linhas['limpar']['ultimo_erro']));
        $this->assertSame(1000, mb_strlen($linhas['outra']['ultimo_resultado']));
    }

    private function contar(string $tabela): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM {$tabela}")->fetchColumn();
    }
}
