<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\ModeloAvisoRepository;
use App\Repositories\TipoAvisoRepository;
use App\Services\ModeloAvisoService;
use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AppHttpTestCase;

final class ModeloAvisoServiceTest extends AppHttpTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
    }

    private function tipos(): TipoAvisoService
    {
        return new TipoAvisoService(new TipoAvisoRepository($this->pdo));
    }

    private function servico(): ModeloAvisoService
    {
        return new ModeloAvisoService(new ModeloAvisoRepository($this->pdo), $this->tipos());
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return $extra + ['nome' => 'Reunião do bloco', 'titulo' => 'Reunião de moradores', 'corpo' => 'Dia [data], às [hora].', 'tipo' => 'comum'];
    }

    public function testCriaModeloEUsaParaPreencherAviso(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(['tipo' => 'assembleia']));

        $m = $this->servico()->paraUsar([$this->a], $this->a, $id);

        $this->assertSame(['Reunião de moradores', 'Dia [data], às [hora].', 'assembleia'], [$m['titulo'], $m['corpo'], $m['tipo']]);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidos(): array
    {
        return [
            'sem nome' => [['nome' => ''], 'nome'],
            'nome longo' => [['nome' => str_repeat('n', 61)], 'nome'],
            'sem título' => [['titulo' => ''], 'titulo'],
            'título longo' => [['titulo' => str_repeat('t', 151)], 'titulo'],
            'texto longo' => [['corpo' => str_repeat('c', 2001)], 'corpo'],
            'tipo desconhecido' => [['tipo' => 'propaganda'], 'tipo'],
            'nome igual ao de um modelo do sistema' => [['nome' => 'assembleia'], 'nome'],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('invalidos')]
    public function testValidacoesNaoCriamNada(array $extra, string $campo): void
    {
        try {
            $this->servico()->criar([$this->a], $this->a, $this->dados($extra));
            $this->fail('Deveria falhar.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM modelos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testTipoDeOutroCondominioNaoServeParaOModelo(): void
    {
        $this->tipos()->criar([$this->a, $this->b], $this->b, ['nome' => 'So do Beta', 'cor' => '#112233']);

        $this->expectException(ValidacaoException::class);

        $this->servico()->criar([$this->a, $this->b], $this->a, $this->dados(['tipo' => 'so-do-beta']));
    }

    public function testModeloCujoTipoSumiuComecaComoComum(): void
    {
        $tipo = $this->tipos()->criar([$this->a], $this->a, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados(['tipo' => 'zeladoria']));
        $this->tipos()->excluir([$this->a], $this->a, $tipo['id']);

        $this->assertSame('comum', $this->servico()->paraUsar([$this->a], $this->a, $id)['tipo']);
    }

    public function testModeloDeOutroCondominioOuCondominioForaDosPermitidosEh404(): void
    {
        $doB = $this->servico()->criar([$this->a, $this->b], $this->b, $this->dados());

        foreach ([fn () => $this->servico()->paraUsar([$this->a, $this->b], $this->a, $doB), fn () => $this->servico()->buscarProprio([$this->a, $this->b], $this->a, $doB),
                  fn () => $this->servico()->atualizar([$this->a, $this->b], $this->a, $doB, $this->dados()), fn () => $this->servico()->excluir([$this->a, $this->b], $this->a, $doB),
                  fn () => $this->servico()->criar([$this->a], $this->b, $this->dados()), fn () => $this->servico()->listar([$this->a], $this->b)] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testModeloDoSistemaPodeSerUsadoMasNaoEditadoNemApagado(): void
    {
        $sistema = (int) $this->pdo->query("SELECT id FROM modelos_aviso WHERE nome = 'Falta de água'")->fetchColumn();

        $this->assertSame('urgente', $this->servico()->paraUsar([$this->a], $this->a, $sistema)['tipo']);
        foreach ([fn () => $this->servico()->atualizar([$this->a], $this->a, $sistema, $this->dados()), fn () => $this->servico()->excluir([$this->a], $this->a, $sistema)] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAtualizarPermiteMesmoNomeEMudaOsCampos(): void
    {
        $id = $this->servico()->criar([$this->a], $this->a, $this->dados());

        $this->servico()->atualizar([$this->a], $this->a, $id, $this->dados(['titulo' => 'Novo título']));

        $this->assertSame('Novo título', $this->servico()->buscarProprio([$this->a], $this->a, $id)['titulo']);
    }

    public function testCriarDoAvisoDeduplicaONomeEUsaOTitulo(): void
    {
        $this->servico()->criarDoAviso([$this->a], $this->a, 'Obra no bloco B', 'Texto', 'comum');
        $this->servico()->criarDoAviso([$this->a], $this->a, 'Obra no bloco B', 'Outro texto', 'comum');
        $this->servico()->criarDoAviso([$this->a], $this->a, 'Assembleia', 'Texto', 'comum');

        $nomes = array_column($this->servico()->listar([$this->a], $this->a), 'nome');
        $this->assertContains('Obra no bloco B', $nomes);
        $this->assertContains('Obra no bloco B (2)', $nomes);
        $this->assertContains('Assembleia (2)', $nomes, 'Nome igual ao de um do sistema ganha sufixo.');
    }
}
