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
    private int $ana;
    private int $beto;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->criarUsuario('ana@exemplo.com.br');
        $this->beto = $this->criarUsuario('beto@exemplo.com.br');
        $this->padroesDoGerente($this->ana);
        $this->padroesDoGerente($this->beto);
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
        $id = $this->servico()->criar($this->ana, $this->dados(['tipo' => 'assembleia']));

        $m = $this->servico()->paraUsar($this->ana, $id);

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
            'nome igual ao de um modelo dele' => [['nome' => 'assembleia'], 'nome'],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('invalidos')]
    public function testValidacoesNaoCriamNada(array $extra, string $campo): void
    {
        $antes = count($this->servico()->listar($this->ana));

        try {
            $this->servico()->criar($this->ana, $this->dados($extra));
            $this->fail('Deveria falhar.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }
        $this->assertCount($antes, $this->servico()->listar($this->ana));
    }

    public function testTipoDeOutroGerenteNaoServeParaOModelo(): void
    {
        $this->tipos()->criar($this->beto, ['nome' => 'So do Beto', 'cor' => '#112233']);

        $this->expectException(ValidacaoException::class);

        $this->servico()->criar($this->ana, $this->dados(['tipo' => 'so-do-beto']));
    }

    public function testModeloCujoTipoFoiApagadoComecaComOPrimeiroTipoQueOGerenteTem(): void
    {
        $tipo = $this->tipos()->criar($this->ana, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $id = $this->servico()->criar($this->ana, $this->dados(['tipo' => 'zeladoria']));
        $this->tipos()->excluir($this->ana, $tipo['id']);

        $this->assertSame('comum', $this->servico()->paraUsar($this->ana, $id)['tipo']);

        $comum = $this->tipos()->resolver($this->ana, 'comum');
        $this->tipos()->excluir($this->ana, $comum['id']);
        $this->assertSame('fixo', $this->servico()->paraUsar($this->ana, $id)['tipo'], 'Sem "comum", cai no primeiro que sobrou.');
    }

    public function testModeloDeOutroGerenteEh404EmTodasAsOperacoes(): void
    {
        $doBeto = $this->servico()->criar($this->beto, $this->dados());

        foreach ([fn () => $this->servico()->paraUsar($this->ana, $doBeto), fn () => $this->servico()->buscarProprio($this->ana, $doBeto),
                  fn () => $this->servico()->atualizar($this->ana, $doBeto, $this->dados()), fn () => $this->servico()->excluir($this->ana, $doBeto)] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertNotContains('Reunião do bloco', array_column($this->servico()->listar($this->ana), 'nome'));
    }

    public function testModeloDeFabricaDoGerenteEDeleEleEditaEApaga(): void
    {
        $falta = array_values(array_filter($this->servico()->listar($this->ana), static fn (array $m): bool => $m['nome'] === 'Falta de água'))[0];

        $this->assertSame('urgente', $this->servico()->paraUsar($this->ana, $falta['id'])['tipo']);
        $this->servico()->atualizar($this->ana, $falta['id'], $this->dados(['nome' => 'Sem água', 'titulo' => 'Sem água hoje']));
        $this->assertSame('Sem água hoje', $this->servico()->buscarProprio($this->ana, $falta['id'])['titulo']);

        $this->servico()->excluir($this->ana, $falta['id']);
        $this->assertNotContains('Sem água', array_column($this->servico()->listar($this->ana), 'nome'));
        $this->assertContains('Falta de água', array_column($this->servico()->listar($this->beto), 'nome'), 'O do Beto continua intacto.');
    }

    public function testAtualizarPermiteMesmoNomeEMudaOsCampos(): void
    {
        $id = $this->servico()->criar($this->ana, $this->dados());

        $this->servico()->atualizar($this->ana, $id, $this->dados(['titulo' => 'Novo título']));

        $this->assertSame('Novo título', $this->servico()->buscarProprio($this->ana, $id)['titulo']);
    }

    public function testCriarDoAvisoDeduplicaONomeEUsaOTitulo(): void
    {
        $this->servico()->criarDoAviso($this->ana, 'Obra no bloco B', 'Texto', 'comum');
        $this->servico()->criarDoAviso($this->ana, 'Obra no bloco B', 'Outro texto', 'comum');
        $this->servico()->criarDoAviso($this->ana, 'Assembleia', 'Texto', 'comum');

        $nomes = array_column($this->servico()->listar($this->ana), 'nome');
        $this->assertContains('Obra no bloco B', $nomes);
        $this->assertContains('Obra no bloco B (2)', $nomes);
        $this->assertContains('Assembleia (2)', $nomes, 'Nome igual ao de um modelo dele ganha sufixo.');
    }
}
