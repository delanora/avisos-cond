<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\DispositivoRepository;
use App\Services\DispositivoService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use Tests\BancoMigradoTestCase;

final class DispositivoServiceTest extends BancoMigradoTestCase
{
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new CondominioRepository($this->pdo);
        $this->a = $repo->criar('Alfa', null, true);
        $this->b = $repo->criar('Beta', null, true);
    }

    private function servico(): DispositivoService
    {
        return new DispositivoService(new DispositivoRepository($this->pdo), new CondominioRepository($this->pdo));
    }

    public function testCriaComTokenGeradoValido(): void
    {
        $id = $this->servico()->criar(['nome' => '  Elevador social ', 'condominio_id' => (string) $this->a]);

        $d = $this->servico()->buscar($id);
        $this->assertSame('Elevador social', $d['nome']);
        $this->assertSame($this->a, $d['condominio_id']);
        $this->assertTrue($d['ativo']);
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{12}$/', $d['token']);
    }

    public function testNovoDispositivoNasceHorizontal(): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);

        $this->assertSame('horizontal', $this->servico()->buscar($id)['orientacao']);
    }

    public function testDefineOrientacaoDeUmDispositivoSemMexerNosOutros(): void
    {
        $um = $this->servico()->criar(['nome' => 'Um', 'condominio_id' => (string) $this->a]);
        $dois = $this->servico()->criar(['nome' => 'Dois', 'condominio_id' => (string) $this->a]);

        $this->servico()->definirOrientacao($um, 'vertical');

        $this->assertSame('vertical', $this->servico()->buscar($um)['orientacao']);
        $this->assertSame('horizontal', $this->servico()->buscar($dois)['orientacao']);
        $this->servico()->definirOrientacao($um, 'horizontal');
        $this->assertSame('horizontal', $this->servico()->buscar($um)['orientacao']);
    }

    /** @return array<string, array{string}> */
    public static function orientacoesInvalidas(): array
    {
        return ['vazio' => [''], 'outra' => ['diagonal'], 'maiúscula' => ['Vertical'], 'sql' => ["vertical'; DROP TABLE dispositivos;--"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orientacoesInvalidas')]
    public function testOrientacaoInvalidaNaoAlteraNada(string $valor): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);

        try {
            $this->servico()->definirOrientacao($id, $valor);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (\App\Services\ValidacaoException $e) {
            $this->assertArrayHasKey('orientacao', $e->erros);
        }
        $this->assertSame('horizontal', $this->servico()->buscar($id)['orientacao']);
    }

    public function testOrientacaoDeDispositivoInexistenteDa404(): void
    {
        $this->expectException(\App\Support\NaoEncontradoException::class);
        $this->servico()->definirOrientacao(987654, 'vertical');
    }

    public function testGerenteSoMudaOrientacaoDeDispositivoDoProprioCondominio(): void
    {
        $meu = $this->servico()->criar(['nome' => 'Meu', 'condominio_id' => (string) $this->a]);
        $alheio = $this->servico()->criar(['nome' => 'Alheio', 'condominio_id' => (string) $this->b]);

        $this->servico()->definirOrientacaoDoGerente([$this->a], $meu, 'vertical');
        $this->assertSame('vertical', $this->servico()->buscar($meu)['orientacao']);

        foreach ([[[$this->a], $alheio], [[], $meu], [[$this->a], 987654]] as [$permitidos, $id]) {
            try {
                $this->servico()->definirOrientacaoDoGerente($permitidos, $id, 'vertical');
                $this->fail('Era esperada NaoEncontradoException.');
            } catch (\App\Support\NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('horizontal', $this->servico()->buscar($alheio)['orientacao']);
    }

    public function testGerenteRepetirAMesmaOrientacaoNaoDa404(): void
    {
        $meu = $this->servico()->criar(['nome' => 'Meu', 'condominio_id' => (string) $this->a]);

        $this->servico()->definirOrientacaoDoGerente([$this->a], $meu, 'horizontal');

        $this->assertSame('horizontal', $this->servico()->buscar($meu)['orientacao']);
    }

    public function testBancoRecusaOrientacaoInvalida(): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);

        $this->expectException(\PDOException::class);
        $this->pdo->exec("UPDATE dispositivos SET orientacao = 'diagonal' WHERE id = {$id}");
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidos(): array
    {
        return [
            'sem nome' => [['nome' => '', 'condominio_id' => '1'], 'nome'],
            'nome longo demais' => [['nome' => 'x', 'condominio_id' => ''], 'condominio_id'],
            'condomínio não numérico' => [['nome' => 'TV', 'condominio_id' => 'abc'], 'condominio_id'],
            'condomínio inexistente' => [['nome' => 'TV', 'condominio_id' => '987654'], 'condominio_id'],
        ];
    }

    /** @param array<string, mixed> $dados */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidos')]
    public function testValidacoesNaoCriamNada(array $dados, string $campo): void
    {
        try {
            $this->servico()->criar($dados);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey($campo, $e->erros);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM dispositivos')->fetchColumn());
    }

    public function testNomeLongoDemaisEhRejeitado(): void
    {
        $this->expectException(ValidacaoException::class);
        $this->servico()->criar(['nome' => str_repeat('x', 101), 'condominio_id' => (string) $this->a]);
    }

    public function testAtualizarMoveDeCondominioERevoga(): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);

        $this->servico()->atualizar($id, ['nome' => 'TV nova', 'condominio_id' => (string) $this->b]);

        $d = $this->servico()->buscar($id);
        $this->assertSame('TV nova', $d['nome']);
        $this->assertSame($this->b, $d['condominio_id']);
        $this->assertFalse($d['ativo'], 'Sem o checkbox "ativo" marcado, o dispositivo fica revogado.');

        $this->servico()->atualizar($id, ['nome' => 'TV nova', 'condominio_id' => (string) $this->b, 'ativo' => '1']);
        $this->assertTrue($this->servico()->buscar($id)['ativo']);
    }

    public function testTrocarTokenDevolveOsNovoEOAntigoDeixaDeExistir(): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);
        $antigo = $this->servico()->buscar($id)['token'];

        $novo = $this->servico()->trocarToken($id);

        $this->assertNotSame($antigo, $novo);
        $this->assertSame($novo, $this->servico()->buscar($id)['token']);
        $this->assertNull((new DispositivoRepository($this->pdo))->porToken($antigo));
    }

    public function testExcluir(): void
    {
        $id = $this->servico()->criar(['nome' => 'TV', 'condominio_id' => (string) $this->a]);

        $this->servico()->excluir($id);

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM dispositivos')->fetchColumn());
    }

    public function testDispositivoInexistente(): void
    {
        foreach ([
            fn () => $this->servico()->buscar(987654),
            fn () => $this->servico()->excluir(987654),
            fn () => $this->servico()->trocarToken(987654),
            fn () => $this->servico()->atualizar(987654, ['nome' => 'x', 'condominio_id' => (string) $this->a]),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Era esperada uma NaoEncontradoException.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
