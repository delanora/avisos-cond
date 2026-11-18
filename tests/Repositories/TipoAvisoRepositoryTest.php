<?php

declare(strict_types=1);

namespace Tests\Repositories;

use App\Repositories\PadroesDoGerenteRepository;
use App\Repositories\TipoAvisoRepository;
use PDOException;
use Tests\AppHttpTestCase;

final class TipoAvisoRepositoryTest extends AppHttpTestCase
{
    private TipoAvisoRepository $repo;
    private int $ana;
    private int $beto;
    private int $cond;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new TipoAvisoRepository($this->pdo);
        $this->ana = $this->criarUsuario('ana@exemplo.com.br');
        $this->beto = $this->criarUsuario('beto@exemplo.com.br');
        $this->cond = $this->criarCondominio('Alfa');
        $this->vincular($this->ana, $this->cond);
        $this->vincular($this->beto, $this->cond);
    }

    private function aviso(int $condominio, int $tipoId): void
    {
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, tipo_id, inicio_em, fim_em) VALUES ({$condominio}, 'x', {$tipoId}, now(), now() + interval '1 day')");
    }

    public function testGerenteNovoSemPadroesNaoTemTipoNenhum(): void
    {
        $this->assertSame([], $this->repo->listar($this->ana));
    }

    public function testPadroesSaoCopiadosUmaSoVezECadaGerenteRecebeOsSeus(): void
    {
        $padroes = new PadroesDoGerenteRepository($this->pdo);

        $this->assertTrue($padroes->garantir($this->ana));
        $this->assertFalse($padroes->garantir($this->ana), 'Segunda chamada não copia de novo.');

        $nomes = array_column($this->repo->listar($this->ana), 'nome');
        $this->assertSame(['Comum', 'Fixo', 'Urgente', 'Manutenção', 'Assembleia'], $nomes);
        $this->assertSame([], $this->repo->listar($this->beto), 'O outro gerente ainda não recebeu.');
        $this->assertSame(5, $this->contarMatrizes(), 'As matrizes continuam intactas.');
    }

    public function testApagarUmPadraoNaoFazEleVoltar(): void
    {
        $this->padroesDoGerente($this->ana);
        $manutencao = $this->repo->porChave($this->ana, 'manutencao');

        $this->assertTrue($this->repo->excluir($this->ana, $manutencao['id']));
        (new PadroesDoGerenteRepository($this->pdo))->garantir($this->ana);

        $this->assertNull($this->repo->porChave($this->ana, 'manutencao'), 'Apagado de vez.');
        $this->assertSame(4, $this->repo->contar($this->ana));
    }

    public function testPadroesNaoSaoCriadosParaQuemNaoEGerente(): void
    {
        $admin = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->assertFalse((new PadroesDoGerenteRepository($this->pdo))->garantir($admin));
        $this->assertSame([], $this->repo->listar($admin));
    }

    public function testTipoDeUmGerenteNaoApareceNemSeResolveParaOOutro(): void
    {
        $this->padroesDoGerente($this->ana);
        $this->padroesDoGerente($this->beto);
        $id = $this->repo->criar($this->ana, 'so-da-ana', 'Só da Ana', '#112233', false);

        $this->assertNotContains('Só da Ana', array_column($this->repo->listar($this->beto), 'nome'));
        $this->assertNull($this->repo->porChave($this->beto, 'so-da-ana'));
        $this->assertNull($this->repo->porId($this->beto, $id));
        $this->assertNotNull($this->repo->porId($this->ana, $id));
    }

    public function testEditarEApagarSoOsProprios(): void
    {
        $id = $this->repo->criar($this->ana, 'meu', 'Meu', '#112233', false);

        $this->assertFalse($this->repo->atualizar($this->beto, $id, 'Roubado', '#000000', true), 'Tipo da Ana pelo Beto.');
        $this->assertFalse($this->repo->excluir($this->beto, $id));
        $this->assertTrue($this->repo->atualizar($this->ana, $id, 'Meu novo nome', '#AABBCC', true));
        $depois = $this->repo->porId($this->ana, $id);
        $this->assertSame(['meu', 'Meu novo nome', '#AABBCC', true], [$depois['chave'], $depois['nome'], $depois['cor'], $depois['fixo']], 'A chave nunca muda.');
        $this->assertTrue($this->repo->excluir($this->ana, $id));
    }

    public function testMatrizDeFabricaNaoEhAcessivelPorNenhumGerente(): void
    {
        $matriz = $this->tipoDeFabrica('urgente');

        $this->assertNull($this->repo->porId($this->ana, $matriz));
        $this->assertFalse($this->repo->atualizar($this->ana, $matriz, 'Mudou', '#000000', false));
        $this->assertFalse($this->repo->excluir($this->ana, $matriz));
        $this->assertSame('Urgente', $this->pdo->query("SELECT nome FROM tipos_aviso WHERE id = {$matriz}")->fetchColumn());
    }

    public function testTipoEmUsoNaoEhApagadoEContaOsAvisos(): void
    {
        $id = $this->repo->criar($this->ana, 'meu', 'Meu', '#112233', false);
        $this->aviso($this->cond, $id);
        $this->aviso($this->cond, $id);

        $this->assertSame(2, $this->repo->porId($this->ana, $id)['em_uso']);
        $this->assertFalse($this->repo->excluir($this->ana, $id), 'Em uso: não apaga.');
        $this->pdo->exec("DELETE FROM avisos WHERE tipo_id = {$id}");
        $this->assertTrue($this->repo->excluir($this->ana, $id));
    }

    public function testNomeOuChaveEmUsoSoConsideraOsDoMesmoGerente(): void
    {
        $this->padroesDoGerente($this->ana);
        $id = $this->repo->criar($this->ana, 'zeladoria', 'Zeladoria', '#112233', false);

        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->ana, 'URGENTE', 'outra-chave'), 'Nome de um dos tipos dele.');
        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->ana, 'Outro nome', 'comum'));
        $this->assertTrue($this->repo->nomeOuChaveEmUso($this->ana, 'zeladoria', 'x'));
        $this->assertFalse($this->repo->nomeOuChaveEmUso($this->ana, 'Zeladoria', 'zeladoria', $id), 'Ele mesmo não conta (edição).');
        $this->assertFalse($this->repo->nomeOuChaveEmUso($this->beto, 'Zeladoria', 'zeladoria'), 'Outro gerente pode ter o mesmo nome.');
    }

    public function testBancoRejeitaCorInvalidaChaveInvalidaEChaveRepetidaDoMesmoGerente(): void
    {
        foreach ([
            "INSERT INTO tipos_aviso (usuario_id, chave, nome, cor) VALUES ({$this->ana}, 'ok', 'Ok', 'vermelho')",
            "INSERT INTO tipos_aviso (usuario_id, chave, nome) VALUES ({$this->ana}, 'Com Espaço', 'X')",
            "INSERT INTO tipos_aviso (usuario_id, chave, nome, sistema) VALUES ({$this->ana}, 'x', 'X', true)",
        ] as $sql) {
            try {
                $this->pdo->exec($sql);
                $this->fail("Deveria rejeitar: {$sql}");
            } catch (PDOException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->repo->criar($this->ana, 'repetida', 'Repetida', '#112233', false);
        $this->assertNull($this->tentarCriar($this->ana, 'repetida'), 'Mesma chave para o mesmo gerente.');
        $this->assertNotNull($this->tentarCriar($this->beto, 'repetida'), 'Mesma chave para outro gerente é permitida.');
    }

    private function tentarCriar(int $usuario, string $chave): ?int
    {
        try {
            return $this->repo->criar($usuario, $chave, 'Nome ' . $usuario, '#112233', false);
        } catch (PDOException) {
            return null;
        }
    }

    public function testGatilhoImpedeAvisoComTipoDeGerenteNaoVinculadoAoCondominio(): void
    {
        $outraCondominio = $this->criarCondominio('Beta');
        $this->vincular($this->beto, $outraCondominio);
        $tipoDoBeto = $this->repo->criar($this->beto, 'do-beto', 'Do Beto', '#112233', false);

        $this->aviso($outraCondominio, $tipoDoBeto);
        $this->aviso($this->cond, $this->tipoDeFabrica('urgente'));
        $this->expectException(PDOException::class);
        $this->pdo->exec("DELETE FROM usuario_condominio WHERE usuario_id = {$this->beto} AND condominio_id = {$this->cond}");
        $this->aviso($this->cond, $tipoDoBeto);
    }

    public function testGatilhoImpedeTrocarOAvisoDeCondominioLevandoTipoDeGerenteDeLa(): void
    {
        $outra = $this->criarCondominio('Beta');
        $caio = $this->criarUsuario('caio@exemplo.com.br');
        $this->vincular($caio, $outra);
        $tipoDoCaio = $this->repo->criar($caio, 'do-caio', 'Do Caio', '#112233', false);
        $this->aviso($outra, $tipoDoCaio);

        $this->expectException(PDOException::class);
        $this->pdo->exec("UPDATE avisos SET condominio_id = {$this->cond} WHERE tipo_id = {$tipoDoCaio}");
    }

    public function testApagarOGerenteDeixaOTipoSemDonoENaoApagaOsAvisos(): void
    {
        $id = $this->repo->criar($this->ana, 'meu', 'Meu', '#112233', false);
        $this->aviso($this->cond, $id);

        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$this->ana}");

        $this->assertSame(1, $this->contar('avisos'));
        $this->assertNull($this->pdo->query("SELECT usuario_id FROM tipos_aviso WHERE id = {$id}")->fetchColumn() ?: null, 'Sem dono, mas o aviso continua com a cor e o nome do tipo.');
    }

    public function testParaCoresInclueOsDoGerenteEOsUsadosPelosAvisosDoCondominioMasNaoOsDeFora(): void
    {
        $meu = $this->repo->criar($this->ana, 'meu', 'Meu', '#112233', false);
        $doBeto = $this->repo->criar($this->beto, 'do-beto', 'Do Beto', '#445566', false);
        $naoUsadoDoBeto = $this->repo->criar($this->beto, 'nao-usado', 'Não usado', '#778899', false);
        $this->aviso($this->cond, $doBeto);

        $ids = array_column($this->repo->paraCores($this->ana, [$this->cond], $this->cond), 'id');

        $this->assertContains($meu, $ids);
        $this->assertContains($doBeto, $ids, 'Aviso compartilhado do condomínio: a etiqueta dele precisa de cor.');
        $this->assertNotContains($naoUsadoDoBeto, $ids, 'Tipo do outro gerente que nenhum aviso usa não vaza.');
        $this->assertNotContains($doBeto, array_column($this->repo->paraCores($this->ana, [], $this->cond), 'id'), 'Condomínio fora dos permitidos: só os dele.');
    }

    private function contarMatrizes(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE sistema')->fetchColumn();
    }
}
