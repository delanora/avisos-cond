<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\TipoAvisoRepository;
use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AppHttpTestCase;

final class TipoAvisoServiceTest extends AppHttpTestCase
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

    private function servico(): TipoAvisoService
    {
        return new TipoAvisoService(new TipoAvisoRepository($this->pdo));
    }

    /** @return array<string, array{string, string}> */
    public static function nomes(): array
    {
        return [
            'simples' => ['Reunião do Conselho', 'reuniao-do-conselho'],
            'acentos e cedilha' => ['Atenção: Mudança!', 'atencao-mudanca'],
            'espaços e símbolos' => ['  --Obras & Reformas--  ', 'obras-reformas'],
            'só símbolos' => ['!!!', 'tipo'],
            'maiúsculas e números' => ['Bloco 2 B', 'bloco-2-b'],
        ];
    }

    #[DataProvider('nomes')]
    public function testChaveDoNome(string $nome, string $esperada): void
    {
        $this->assertSame($esperada, TipoAvisoService::chaveDoNome($nome));
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $esperada);
    }

    public function testCriaTipoComChaveDoNomeECorEmMaiusculo(): void
    {
        $tipo = $this->servico()->criar($this->ana, ['nome' => '  Reunião   do Conselho ', 'cor' => '#2f6fed', 'fixo' => '1']);

        $this->assertSame(['reuniao-do-conselho', 'Reunião do Conselho', '#2F6FED', true, false], [$tipo['chave'], $tipo['nome'], $tipo['cor'], $tipo['fixo'], $tipo['sistema']]);
        $this->assertSame($this->ana, $tipo['usuario_id']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidos(): array
    {
        return [
            'nome vazio' => [['nome' => '', 'cor' => '#112233'], 'nome'],
            'nome de 1 letra' => [['nome' => 'A', 'cor' => '#112233'], 'nome'],
            'nome longo demais' => [['nome' => str_repeat('x', 31), 'cor' => '#112233'], 'nome'],
            'nome igual ao de um do sistema' => [['nome' => 'urgente', 'cor' => '#112233'], 'nome'],
            'cor inválida' => [['nome' => 'Valido', 'cor' => 'azul'], 'cor'],
            'cor curta' => [['nome' => 'Valido', 'cor' => '#FFF'], 'cor'],
            'sem cor' => [['nome' => 'Valido'], 'cor'],
            'cor com injeção de css' => [['nome' => 'Valido', 'cor' => '#112233; } body { display: none'], 'cor'],
        ];
    }

    /** @param array<string, mixed> $dados */
    #[DataProvider('invalidos')]
    public function testValidacoesNaoCriamNada(array $dados, string $campo): void
    {
        try {
            $this->servico()->criar($this->ana, $dados);
            $this->fail('Deveria falhar.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }
        $this->assertSame(5, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE usuario_id = {$this->ana}")->fetchColumn(), 'Só os cinco de fábrica.');
    }

    public function testNomeRepetidoParaOMesmoGerenteEhRecusadoMasParaOutroEhPermitido(): void
    {
        $this->servico()->criar($this->ana, ['nome' => 'Zeladoria', 'cor' => '#112233']);

        $this->assertNotNull($this->servico()->criar($this->beto, ['nome' => 'Zeladoria', 'cor' => '#112233']), 'O Beto pode ter o mesmo nome.');
        $this->expectException(ValidacaoException::class);
        $this->servico()->criar($this->ana, ['nome' => 'ZELADORIA', 'cor' => '#445566']);
    }

    public function testAtualizarMantemAChaveEPermiteMesmoNome(): void
    {
        $tipo = $this->servico()->criar($this->ana, ['nome' => 'Zeladoria', 'cor' => '#112233']);

        $this->servico()->atualizar($this->ana, $tipo['id'], ['nome' => 'Zeladoria', 'cor' => '#AABBCC', 'fixo' => '1']);
        $this->servico()->atualizar($this->ana, $tipo['id'], ['nome' => 'Zeladoria geral', 'cor' => '#AABBCC']);

        $depois = $this->servico()->buscarProprio($this->ana, $tipo['id']);
        $this->assertSame(['zeladoria', 'Zeladoria geral', '#AABBCC', false], [$depois['chave'], $depois['nome'], $depois['cor'], $depois['fixo']]);
    }

    public function testBuscarEditarOuApagarTipoDeOutroGerenteOuInexistenteEh404(): void
    {
        $daAna = $this->servico()->criar($this->ana, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $matriz = $this->tipoDeFabrica('comum');

        foreach ([
            fn () => $this->servico()->buscarProprio($this->beto, $daAna['id']),
            fn () => $this->servico()->atualizar($this->beto, $daAna['id'], ['nome' => 'Roubado', 'cor' => '#000000']),
            fn () => $this->servico()->excluir($this->beto, $daAna['id']),
            fn () => $this->servico()->buscarProprio($this->ana, $matriz),
            fn () => $this->servico()->buscarProprio($this->ana, 999999),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Deveria ser 404.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('Zeladoria', $this->servico()->buscarProprio($this->ana, $daAna['id'])['nome']);
    }

    public function testTipoDeFabricaDoGerentePodeSerEditadoEApagadoPorEle(): void
    {
        $urgente = $this->servico()->resolver($this->ana, 'urgente');

        $this->servico()->atualizar($this->ana, $urgente['id'], ['nome' => 'Emergência', 'cor' => '#000000', 'fixo' => '1']);
        $this->assertSame('Emergência', $this->servico()->resolver($this->ana, 'urgente')['nome']);

        $manutencao = $this->servico()->resolver($this->ana, 'manutencao');
        $this->servico()->excluir($this->ana, $manutencao['id']);
        $this->assertNull($this->servico()->resolver($this->ana, 'manutencao'));
        $this->assertNotNull($this->servico()->resolver($this->beto, 'manutencao'), 'O do Beto continua.');
        $this->assertSame('Urgente', $this->servico()->resolver($this->beto, 'urgente')['nome'], 'E a edição da Ana não chega no Beto.');
    }

    public function testNaoApagaOUltimoTipo(): void
    {
        foreach ($this->servico()->listar($this->ana) as $i => $t) {
            if ($i < 4) {
                $this->servico()->excluir($this->ana, $t['id']);
            }
        }
        $ultimo = $this->servico()->listar($this->ana)[0];

        try {
            $this->servico()->excluir($this->ana, $ultimo['id']);
            $this->fail('Deveria manter pelo menos um tipo.');
        } catch (ValidacaoException $e) {
            $this->assertStringContainsString('pelo menos um tipo', $e->erros['tipo']);
        }
        $this->assertCount(1, $this->servico()->listar($this->ana));
    }

    public function testExcluirTipoEmUsoDizQuantosAvisosEExcluirLivreFunciona(): void
    {
        $tipo = $this->servico()->criar($this->ana, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $cond = $this->criarCondominio('Alfa');
        $this->vincular($this->ana, $cond);
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, tipo_id, inicio_em, fim_em) VALUES ({$cond}, 'x', {$tipo['id']}, now(), now() + interval '1 day')");

        try {
            $this->servico()->excluir($this->ana, $tipo['id']);
            $this->fail('Em uso não pode ser apagado.');
        } catch (ValidacaoException $e) {
            $this->assertStringContainsString('usado por 1 aviso', $e->erros['tipo']);
        }

        $this->pdo->exec('DELETE FROM avisos');
        $this->servico()->excluir($this->ana, $tipo['id']);
        $this->assertSame(5, (int) $this->pdo->query("SELECT count(*) FROM tipos_aviso WHERE usuario_id = {$this->ana}")->fetchColumn(), 'Só os cinco de fábrica.');
    }

    public function testCssSoTemRegrasDeTiposComCorEComValoresValidados(): void
    {
        $tipos = $this->servico()->listar($this->ana);
        $css = TipoAvisoService::css($tipos);

        $urgente = array_values(array_filter($tipos, static fn (array $t): bool => $t['chave'] === 'urgente'))[0];
        $comum = array_values(array_filter($tipos, static fn (array $t): bool => $t['chave'] === 'comum'))[0];
        $this->assertStringContainsString(".etiqueta-tipo-t{$urgente['id']} { color: #FFFFFF; background: #B42318; }", $css);
        $this->assertStringContainsString(".tv-t{$urgente['id']} { background: #", $css);
        $this->assertStringNotContainsString("t{$comum['id']} ", $css, 'Sem cor: visual neutro, sem regra.');
        $this->assertDoesNotMatchRegularExpression('/[^\n]*[<>@][^\n]*/', $css);
    }
}
