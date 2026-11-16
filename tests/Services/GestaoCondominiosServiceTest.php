<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Services\GestaoCondominiosService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use App\Support\Transaction;
use Tests\BancoMigradoTestCase;

final class GestaoCondominiosServiceTest extends BancoMigradoTestCase
{
    private function servico(): GestaoCondominiosService
    {
        return new GestaoCondominiosService(
            new CondominioRepository($this->pdo),
            new UsuarioRepository($this->pdo),
            new VinculoRepository($this->pdo),
            new Transaction($this->pdo)
        );
    }

    public function testCriaCondominioComGerentesVinculados(): void
    {
        $g1 = $this->criarUsuario('g1@exemplo.com.br');
        $g2 = $this->criarUsuario('g2@exemplo.com.br');

        $id = $this->servico()->criar([
            'nome' => '  Residencial Sol ', 'endereco' => 'Rua A, 10', 'ativo' => '1', 'gerentes' => [(string) $g2, (string) $g1],
        ]);

        $c = $this->servico()->buscar($id);
        $this->assertSame('Residencial Sol', $c['nome']);
        $this->assertSame('Rua A, 10', $c['endereco']);
        $this->assertTrue($c['ativo']);
        $this->assertSame([$g1, $g2], $c['gerentes']);
    }

    public function testEnderecoVazioViraNuloEAtivoDesmarcadoFicaInativo(): void
    {
        $id = $this->servico()->criar(['nome' => 'Condomínio', 'endereco' => '   ', 'gerentes' => []]);

        $c = $this->servico()->buscar($id);
        $this->assertNull($c['endereco']);
        $this->assertFalse($c['ativo']);
    }

    public function testValidaNomeEEndereco(): void
    {
        try {
            $this->servico()->criar(['nome' => '', 'endereco' => str_repeat('x', 256), 'gerentes' => []]);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertEqualsCanonicalizing(['nome', 'endereco'], array_keys($e->erros));
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM condominios')->fetchColumn());
    }

    public function testSoGerentesExistentesPodemSerVinculados(): void
    {
        $admin = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        foreach ([[(string) $admin], ['987654']] as $gerentes) {
            try {
                $this->servico()->criar(['nome' => 'X', 'ativo' => '1', 'gerentes' => $gerentes]);
                $this->fail('Era esperada uma ValidacaoException.');
            } catch (ValidacaoException $e) {
                $this->assertArrayHasKey('gerentes', $e->erros);
            }
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM condominios')->fetchColumn());
    }

    public function testAtualizarTrocaDadosEGerentes(): void
    {
        $g1 = $this->criarUsuario('g1@exemplo.com.br');
        $g2 = $this->criarUsuario('g2@exemplo.com.br');
        $id = $this->servico()->criar(['nome' => 'Antigo', 'ativo' => '1', 'gerentes' => [(string) $g1]]);

        $this->servico()->atualizar($id, ['nome' => 'Novo', 'endereco' => 'Av. B', 'gerentes' => [(string) $g2]]);

        $c = $this->servico()->buscar($id);
        $this->assertSame('Novo', $c['nome']);
        $this->assertFalse($c['ativo']);
        $this->assertSame([$g2], $c['gerentes']);
    }

    public function testAtualizarComErroNaoAlteraNada(): void
    {
        $id = $this->servico()->criar(['nome' => 'Original', 'ativo' => '1', 'gerentes' => []]);

        try {
            $this->servico()->atualizar($id, ['nome' => '', 'gerentes' => []]);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException) {
            $this->assertSame('Original', $this->servico()->buscar($id)['nome']);
        }
    }

    public function testExcluirApagaOCondominioMasMantemAsContasDosGerentes(): void
    {
        $g = $this->criarUsuario('g@exemplo.com.br');
        $id = $this->servico()->criar(['nome' => 'A', 'ativo' => '1', 'gerentes' => [(string) $g]]);

        $this->assertSame(['avisos' => 0, 'dispositivos' => 0, 'gerentes' => 1], $this->servico()->dependencias($id));
        $this->servico()->excluir($id);

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM condominios')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM usuario_condominio')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM usuarios')->fetchColumn());
    }

    public function testCondominioInexistente(): void
    {
        foreach ([
            fn () => $this->servico()->buscar(987654),
            fn () => $this->servico()->excluir(987654),
            fn () => $this->servico()->dependencias(987654),
            fn () => $this->servico()->atualizar(987654, ['nome' => 'X', 'gerentes' => []]),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Era esperada uma NaoEncontradoException.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGerentesDisponiveisNaoIncluemSuperadmins(): void
    {
        $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $g = $this->criarUsuario('g@exemplo.com.br');

        $ids = array_column($this->servico()->gerentesDisponiveis(), 'id');

        $this->assertSame([$g], $ids);
    }
}
