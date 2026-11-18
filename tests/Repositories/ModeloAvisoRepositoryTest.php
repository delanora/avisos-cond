<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\ModeloAvisoRepository;
use PDOException;
use Tests\AppHttpTestCase;

final class ModeloAvisoRepositoryTest extends AppHttpTestCase
{
    private ModeloAvisoRepository $repo;
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ModeloAvisoRepository($this->pdo);
        $this->ana = $this->criarUsuario('ana@exemplo.com.br');
        $this->beto = $this->criarUsuario('beto@exemplo.com.br');
    }

    public function testGerenteRecebeOsQuatroModelosDeFabricaEOutroNaoVeOsDele(): void
    {
        $this->padroesDoGerente($this->ana);

        $this->assertSame(['Falta de água', 'Manutenção do elevador', 'Dedetização', 'Assembleia'], array_column($this->repo->listar($this->ana), 'nome'));
        $this->assertSame([], $this->repo->listar($this->beto), 'O Beto ainda não recebeu os dele.');
    }

    public function testListaNaOrdemDeCriacaoComOsDeFabricaPrimeiro(): void
    {
        $this->padroesDoGerente($this->ana);
        $this->repo->criar($this->ana, 'Zeladoria', 'T', 'x', 'comum');
        $this->repo->criar($this->ana, 'Assuntos gerais', 'T', 'x', 'comum');

        $nomes = array_column($this->repo->listar($this->ana), 'nome');

        $this->assertSame(['Zeladoria', 'Assuntos gerais'], array_slice($nomes, 4));
    }

    public function testModeloDeUmGerenteNaoApareceNemSeUsaPeloOutro(): void
    {
        $id = $this->repo->criar($this->ana, 'Só da Ana', 'T', 'x', 'comum');

        $this->assertNotContains('Só da Ana', array_column($this->repo->listar($this->beto), 'nome'));
        $this->assertNull($this->repo->porId($this->beto, $id));
        $this->assertNotNull($this->repo->porId($this->ana, $id));
    }

    public function testEditarEApagarSoOsProprios(): void
    {
        $id = $this->repo->criar($this->ana, 'Meu', 'T', 'x', 'comum');

        $this->assertFalse($this->repo->atualizar($this->beto, $id, 'Roubado', 'T', 'x', 'comum'), 'Modelo da Ana pelo Beto.');
        $this->assertFalse($this->repo->excluir($this->beto, $id));
        $this->assertTrue($this->repo->atualizar($this->ana, $id, 'Meu novo', 'Novo título', 'Novo texto', 'urgente'));
        $m = $this->repo->porId($this->ana, $id);
        $this->assertSame(['Meu novo', 'Novo título', 'Novo texto', 'urgente'], [$m['nome'], $m['titulo'], $m['corpo'], $m['tipo']]);
        $this->assertTrue($this->repo->excluir($this->ana, $id));
    }

    public function testModelosDeFabricaPodemSerApagadosECadaGerenteApagaSoOsSeus(): void
    {
        $this->padroesDoGerente($this->ana);
        $this->padroesDoGerente($this->beto);
        $dedetizacaoDaAna = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE usuario_id = {$this->ana} AND nome = 'Dedetização'")->fetchColumn();

        $this->assertTrue($this->repo->excluir($this->ana, $dedetizacaoDaAna));

        $this->assertNotContains('Dedetização', array_column($this->repo->listar($this->ana), 'nome'));
        $this->assertContains('Dedetização', array_column($this->repo->listar($this->beto), 'nome'), 'O do Beto continua.');
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE sistema AND nome = \'Dedetização\'')->fetchColumn(), 'A matriz também.');
    }

    public function testMatrizDeFabricaNaoEhAcessivelPorNenhumGerente(): void
    {
        $matriz = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE sistema AND nome = 'Assembleia'")->fetchColumn();

        $this->assertNull($this->repo->porId($this->ana, $matriz));
        $this->assertFalse($this->repo->atualizar($this->ana, $matriz, 'X', 'X', 'X', 'comum'));
        $this->assertFalse($this->repo->excluir($this->ana, $matriz));
    }

    public function testNomeEmUsoSoConsideraOsDoMesmoGerenteSemMaiusculas(): void
    {
        $id = $this->repo->criar($this->ana, 'Zeladoria', 'T', 'x', 'comum');

        $this->assertTrue($this->repo->nomeEmUso($this->ana, 'zeladoria'));
        $this->assertFalse($this->repo->nomeEmUso($this->ana, 'Zeladoria', $id), 'Ele mesmo não conta (edição).');
        $this->assertFalse($this->repo->nomeEmUso($this->beto, 'Zeladoria'), 'Outro gerente pode ter o mesmo nome.');
    }

    public function testBancoRejeitaMatrizComDonoENomeRepetidoDoMesmoGerente(): void
    {
        $this->repo->criar($this->ana, 'Zeladoria', 'T', 'x', 'comum');

        foreach ([
            "INSERT INTO modelos_aviso (usuario_id, nome, titulo, sistema) VALUES ({$this->ana}, 'X', 'T', true)",
            "INSERT INTO modelos_aviso (usuario_id, nome, titulo) VALUES ({$this->ana}, 'ZELADORIA', 'T')",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail("Deveria rejeitar: {$sql}");
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModelosSaemJuntoComOGerente(): void
    {
        $this->repo->criar($this->beto, 'Do Beto', 'T', 'x', 'comum');

        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$this->beto}");

        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM modelos_aviso WHERE nome = 'Do Beto'")->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE sistema')->fetchColumn(), 'As matrizes ficam.');
    }
}
