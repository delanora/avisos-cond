<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\TipoAvisoRepository;
use PDOException;
use Tests\AppHttpTestCase;

final class TipoAvisoRepositoryTest extends AppHttpTestCase
{
    private TipoAvisoRepository $repo;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TipoAvisoRepository($this->pdo);
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
    }

    private function aviso(int $condominio, string $tipo): void
    {
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, tipo, inicio_em, fim_em) VALUES ({$condominio}, 'x', '{$tipo}', now(), now() + interval '1 day')");
    }

    public function testListaOsCincoDoSistemaDepoisOsDoCondominioPorNome(): void
    {
        $this->repo->criar([$this->a], $this->a, 'zeladoria', 'Zeladoria', '#112233', false);
        $this->repo->criar([$this->a], $this->a, 'assuntos', 'Assuntos gerais', '#445566', true);

        $nomes = array_column($this->repo->listar([$this->a], $this->a), 'nome');

        $this->assertSame(['Comum', 'Fixo', 'Urgente', 'Manutenção', 'Assembleia', 'Assuntos gerais', 'Zeladoria'], $nomes);
    }

    public function testTipoDeUmCondominioNaoApareceNemSeResolveNoOutro(): void
    {
        $this->repo->criar([$this->a, $this->b], $this->a, 'exclusivo-alfa', 'Exclusivo Alfa', '#112233', false);

        $this->assertNotContains('Exclusivo Alfa', array_column($this->repo->listar([$this->a, $this->b], $this->b), 'nome'));
        $this->assertNull($this->repo->porChave([$this->a, $this->b], $this->b, 'exclusivo-alfa'));
        $this->assertNotNull($this->repo->porChave([$this->a, $this->b], $this->a, 'exclusivo-alfa'));
        $this->assertNotNull($this->repo->porChave([$this->a, $this->b], $this->b, 'urgente'), 'Os do sistema valem para todos.');
    }

    public function testCondominioForaDosPermitidosNaoListaNemCriaNemResolve(): void
    {
        $this->assertSame([], $this->repo->listar([$this->a], $this->b));
        $this->assertNull($this->repo->criar([$this->a], $this->b, 'invasor', 'Invasor', '#112233', false));
        $this->assertNull($this->repo->porChave([$this->a], $this->b, 'comum'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE chave = 'invasor'")->fetchColumn());
    }

    public function testEditarEApagarSoOsProprios(): void
    {
        $id = (int) $this->repo->criar([$this->a, $this->b], $this->a, 'meu', 'Meu', '#112233', false);
        $sistema = (int) $this->pdo->query("SELECT id FROM tipos_aviso WHERE chave = 'urgente'")->fetchColumn();

        $this->assertFalse($this->repo->atualizar([$this->b], $this->b, $id, 'Roubado', '#000000', true), 'Tipo do A pelo B.');
        $this->assertFalse($this->repo->atualizar([$this->a], $this->a, $sistema, 'Mudou', '#000000', false), 'Tipo do sistema não muda.');
        $this->assertNull($this->repo->proprioPorId([$this->b], $this->b, $id));
        $this->assertTrue($this->repo->atualizar([$this->a], $this->a, $id, 'Meu novo nome', '#AABBCC', true));
        $dep = $this->repo->proprioPorId([$this->a], $this->a, $id);
        $this->assertSame(['meu', 'Meu novo nome', '#AABBCC', true], [$dep['chave'], $dep['nome'], $dep['cor'], $dep['fixo']], 'A chave nunca muda.');

        $this->assertFalse($this->repo->excluir([$this->b], $this->b, $id));
        $this->assertFalse($this->repo->excluir([$this->a], $this->a, $sistema));
        $this->assertTrue($this->repo->excluir([$this->a], $this->a, $id));
    }

    public function testTipoEmUsoNaoEhApagadoEContaOsAvisos(): void
    {
        $id = (int) $this->repo->criar([$this->a], $this->a, 'meu', 'Meu', '#112233', false);
        $this->aviso($this->a, 'meu');
        $this->aviso($this->a, 'meu');

        $this->assertSame(2, $this->repo->proprioPorId([$this->a], $this->a, $id)['em_uso']);
        $this->assertFalse($this->repo->excluir([$this->a], $this->a, $id), 'Em uso: não apaga.');
        $this->pdo->exec("DELETE FROM avisos WHERE tipo = 'meu'");
        $this->assertTrue($this->repo->excluir([$this->a], $this->a, $id));
    }

    public function testNomeOuChaveEmUsoConsideraSistemaECondominioSemMaiusculas(): void
    {
        $id = (int) $this->repo->criar([$this->a], $this->a, 'zeladoria', 'Zeladoria', '#112233', false);

        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->a, 'URGENTE', 'outra-chave'), 'Nome de tipo do sistema.');
        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->a, 'Outro nome', 'comum'), 'Chave de tipo do sistema.');
        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->a, 'zeladoria', 'x'));
        $this->assertFalse($this->repo->nomeOuChaveEmUso($this->a, 'Zeladoria', 'zeladoria', $id), 'Ele mesmo não conta (edição).');
        $this->assertFalse($this->repo->nomeOuChaveEmUso($this->b, 'Zeladoria', 'zeladoria'), 'Outro condomínio pode ter o mesmo nome.');
    }

    public function testBancoRejeitaCorInvalidaChaveInvalidaEChaveRepetida(): void
    {
        foreach ([
            "INSERT INTO tipos_aviso (condominio_id, chave, nome, cor) VALUES ({$this->a}, 'ok', 'Ok', 'vermelho')",
            "INSERT INTO tipos_aviso (condominio_id, chave, nome) VALUES ({$this->a}, 'Com Espaço', 'X')",
            "INSERT INTO tipos_aviso (condominio_id, chave, nome, sistema) VALUES ({$this->a}, 'x', 'X', true)",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail("Deveria rejeitar: {$sql}");
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->repo->criar([$this->a], $this->a, 'repetida', 'Repetida', '#112233', false);
        $this->assertNull($this->tentarCriar($this->a, 'repetida'), 'Mesma chave no mesmo condomínio.');
        $this->assertNotNull($this->tentarCriar($this->b, 'repetida'), 'Mesma chave em outro condomínio é permitida.');
    }

    private function tentarCriar(int $condominio, string $chave): ?int
    {
        try {
            return $this->repo->criar([$condominio], $condominio, $chave, 'Nome ' . $condominio, '#112233', false);
        } catch (PDOException) {
            return null;
        }
    }

    public function testGatilhoImpedeAvisoComTipoDeOutroCondominio(): void
    {
        $this->repo->criar([$this->a], $this->a, 'so-do-alfa', 'Só do Alfa', '#112233', false);

        $this->aviso($this->a, 'so-do-alfa');
        $this->aviso($this->b, 'urgente');
        $this->expectException(PDOException::class);
        $this->aviso($this->b, 'so-do-alfa');
    }

    public function testGatilhoImpedeTrocarOAvisoDeCondominioLevandoTipoProprio(): void
    {
        $this->repo->criar([$this->a], $this->a, 'so-do-alfa', 'Só do Alfa', '#112233', false);
        $this->aviso($this->a, 'so-do-alfa');

        $this->expectException(PDOException::class);
        $this->pdo->exec("UPDATE avisos SET condominio_id = {$this->b} WHERE tipo = 'so-do-alfa'");
    }
}
