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
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
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
        $tipo = $this->servico()->criar([$this->a], $this->a, ['nome' => '  Reunião   do Conselho ', 'cor' => '#2f6fed', 'fixo' => '1']);

        $this->assertSame(['reuniao-do-conselho', 'Reunião do Conselho', '#2F6FED', true, false], [$tipo['chave'], $tipo['nome'], $tipo['cor'], $tipo['fixo'], $tipo['sistema']]);
        $this->assertSame($this->a, $tipo['condominio_id']);
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
            $this->servico()->criar([$this->a], $this->a, $dados);
            $this->fail('Deveria falhar.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testNomeRepetidoNoCondominioEhRecusadoMasEmOutroEhPermitido(): void
    {
        $this->servico()->criar([$this->a, $this->b], $this->a, ['nome' => 'Zeladoria', 'cor' => '#112233']);

        $this->expectException(ValidacaoException::class);
        try {
            $this->servico()->criar([$this->a, $this->b], $this->a, ['nome' => 'ZELADORIA', 'cor' => '#445566']);
        } finally {
            $this->assertNotNull($this->servico()->criar([$this->a, $this->b], $this->b, ['nome' => 'Zeladoria', 'cor' => '#112233']));
        }
    }

    public function testCondominioForaDosPermitidosEh404(): void
    {
        $this->expectException(NaoEncontradoException::class);

        $this->servico()->criar([$this->a], $this->b, ['nome' => 'Invasor', 'cor' => '#112233']);
    }

    public function testAtualizarMantemAChaveEPermiteMesmoNome(): void
    {
        $tipo = $this->servico()->criar([$this->a], $this->a, ['nome' => 'Zeladoria', 'cor' => '#112233']);

        $this->servico()->atualizar([$this->a], $this->a, $tipo['id'], ['nome' => 'Zeladoria', 'cor' => '#AABBCC', 'fixo' => '1']);
        $this->servico()->atualizar([$this->a], $this->a, $tipo['id'], ['nome' => 'Zeladoria geral', 'cor' => '#AABBCC']);

        $depois = $this->servico()->buscarProprio([$this->a], $this->a, $tipo['id']);
        $this->assertSame(['zeladoria', 'Zeladoria geral', '#AABBCC', false], [$depois['chave'], $depois['nome'], $depois['cor'], $depois['fixo']]);
    }

    public function testBuscarEEditarTipoDoSistemaOuDeOutroCondominioEh404(): void
    {
        $meu = $this->servico()->criar([$this->a, $this->b], $this->a, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $sistema = (int) $this->pdo->query("SELECT id FROM tipos_aviso WHERE chave = 'comum'")->fetchColumn();

        foreach ([[$this->b, $meu['id']], [$this->a, $sistema], [$this->a, 999999]] as [$cond, $id]) {
            try {
                $this->servico()->buscarProprio([$this->a, $this->b], $cond, $id);
                $this->fail('Deveria ser 404.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testExcluirTipoEmUsoDizQuantosAvisosEExcluirLivreFunciona(): void
    {
        $tipo = $this->servico()->criar([$this->a], $this->a, ['nome' => 'Zeladoria', 'cor' => '#112233']);
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, tipo, inicio_em, fim_em) VALUES ({$this->a}, 'x', 'zeladoria', now(), now() + interval '1 day')");

        try {
            $this->servico()->excluir([$this->a], $this->a, $tipo['id']);
            $this->fail('Em uso não pode ser apagado.');
        } catch (ValidacaoException $e) {
            $this->assertStringContainsString('usado por 1 aviso', $e->erros['tipo']);
        }

        $this->pdo->exec('DELETE FROM avisos');
        $this->servico()->excluir([$this->a], $this->a, $tipo['id']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM tipos_aviso WHERE NOT sistema')->fetchColumn());
    }

    public function testCssSoTemRegrasDeTiposComCorEComValoresValidados(): void
    {
        $tipos = $this->servico()->listar([$this->a], $this->a);
        $css = TipoAvisoService::css($tipos);

        $urgente = array_values(array_filter($tipos, static fn (array $t): bool => $t['chave'] === 'urgente'))[0];
        $comum = array_values(array_filter($tipos, static fn (array $t): bool => $t['chave'] === 'comum'))[0];
        $this->assertStringContainsString(".etiqueta-tipo-t{$urgente['id']} { color: #FFFFFF; background: #B42318; }", $css);
        $this->assertStringContainsString(".tv-t{$urgente['id']} { background: #", $css);
        $this->assertStringNotContainsString("t{$comum['id']} ", $css, 'Sem cor: visual neutro, sem regra.');
        $this->assertDoesNotMatchRegularExpression('/[^\n]*[<>@][^\n]*/', $css);
    }
}
