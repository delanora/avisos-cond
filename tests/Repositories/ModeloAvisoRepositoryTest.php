<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\ModeloAvisoRepository;
use PDOException;
use Tests\AppHttpTestCase;

final class ModeloAvisoRepositoryTest extends AppHttpTestCase
{
    private ModeloAvisoRepository $repo;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ModeloAvisoRepository($this->pdo);
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
    }

    public function testListaOsQuatroDoSistemaDepoisOsDoCondominio(): void
    {
        $this->repo->criar([$this->a], $this->a, 'Zeladoria', 'Título Z', 'Texto', 'comum');
        $this->repo->criar([$this->a], $this->a, 'Assuntos gerais', 'Título A', 'Texto', 'comum');

        $nomes = array_column($this->repo->listar([$this->a], $this->a), 'nome');

        $this->assertSame(['Falta de água', 'Manutenção do elevador', 'Dedetização', 'Assembleia', 'Assuntos gerais', 'Zeladoria'], $nomes);
    }

    public function testModeloDeUmCondominioNaoApareceNemSeUsaNoOutro(): void
    {
        $id = (int) $this->repo->criar([$this->a, $this->b], $this->a, 'Só do Alfa', 'T', 'x', 'comum');

        $this->assertNotContains('Só do Alfa', array_column($this->repo->listar([$this->a, $this->b], $this->b), 'nome'));
        $this->assertNull($this->repo->utilizavelPorId([$this->a, $this->b], $this->b, $id));
        $this->assertNull($this->repo->proprioPorId([$this->a, $this->b], $this->b, $id));
        $this->assertNotNull($this->repo->utilizavelPorId([$this->a, $this->b], $this->a, $id));
    }

    public function testCondominioForaDosPermitidosNaoListaNemCria(): void
    {
        $this->assertSame([], $this->repo->listar([$this->a], $this->b));
        $this->assertNull($this->repo->criar([$this->a], $this->b, 'Invasor', 'T', 'x', 'comum'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM modelos_aviso WHERE nome = 'Invasor'")->fetchColumn());
    }

    public function testModeloDoSistemaValeParaTodosMasNaoSeEditaNemApaga(): void
    {
        $sistema = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE nome = 'Dedetização'")->fetchColumn();

        $this->assertNotNull($this->repo->utilizavelPorId([$this->b], $this->b, $sistema));
        $this->assertNull($this->repo->proprioPorId([$this->a], $this->a, $sistema));
        $this->assertFalse($this->repo->atualizar([$this->a], $this->a, $sistema, 'X', 'X', 'X', 'comum'));
        $this->assertFalse($this->repo->excluir([$this->a], $this->a, $sistema));
        $this->assertSame('Dedetização', $this->repo->utilizavelPorId([$this->a], $this->a, $sistema)['nome']);
    }

    public function testEditarEApagarSoOsProprios(): void
    {
        $id = (int) $this->repo->criar([$this->a, $this->b], $this->a, 'Meu', 'T', 'x', 'comum');

        $this->assertFalse($this->repo->atualizar([$this->b], $this->b, $id, 'Roubado', 'T', 'x', 'comum'), 'Modelo do A pelo B.');
        $this->assertFalse($this->repo->excluir([$this->b], $this->b, $id));
        $this->assertTrue($this->repo->atualizar([$this->a], $this->a, $id, 'Meu novo', 'Novo título', 'Novo texto', 'urgente'));
        $m = $this->repo->proprioPorId([$this->a], $this->a, $id);
        $this->assertSame(['Meu novo', 'Novo título', 'Novo texto', 'urgente'], [$m['nome'], $m['titulo'], $m['corpo'], $m['tipo']]);
        $this->assertTrue($this->repo->excluir([$this->a], $this->a, $id));
    }

    public function testNomeEmUsoConsideraSistemaECondominioSemMaiusculas(): void
    {
        $id = (int) $this->repo->criar([$this->a], $this->a, 'Zeladoria', 'T', 'x', 'comum');

        $this->assertTrue($this->repo->nomeEmUso($this->a, 'ASSEMBLEIA'), 'Nome de modelo do sistema.');
        $this->assertTrue($this->repo->nomeEmUso($this->a, 'zeladoria'));
        $this->assertFalse($this->repo->nomeEmUso($this->a, 'Zeladoria', $id), 'Ele mesmo não conta (edição).');
        $this->assertFalse($this->repo->nomeEmUso($this->b, 'Zeladoria'), 'Outro condomínio pode ter o mesmo nome.');
    }

    public function testBancoRejeitaSistemaComCondominioENomeRepetido(): void
    {
        $this->repo->criar([$this->a], $this->a, 'Zeladoria', 'T', 'x', 'comum');

        foreach ([
            "INSERT INTO modelos_aviso (condominio_id, nome, titulo, sistema) VALUES ({$this->a}, 'X', 'T', true)",
            "INSERT INTO modelos_aviso (condominio_id, nome, titulo) VALUES ({$this->a}, 'ZELADORIA', 'T')",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail("Deveria rejeitar: {$sql}");
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModelosSaemJuntoComOCondominio(): void
    {
        $this->repo->criar([$this->b], $this->b, 'Do Beta', 'T', 'x', 'comum');

        $this->pdo->exec("DELETE FROM condominios WHERE id = {$this->b}");

        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM modelos_aviso WHERE nome = 'Do Beta'")->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE sistema')->fetchColumn(), 'Os do sistema ficam.');
    }
}
