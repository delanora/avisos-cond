<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Services\GestaoUsuariosService;
use App\Services\UsuarioService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use App\Support\Transaction;
use Tests\BancoMigradoTestCase;

final class GestaoUsuariosServiceTest extends BancoMigradoTestCase
{
    private function servico(): GestaoUsuariosService
    {
        $usuarios = new UsuarioRepository($this->pdo);
        $hasher = $this->hasherRapido();

        return new GestaoUsuariosService(
            new UsuarioService($usuarios, $hasher),
            $usuarios,
            new CondominioRepository($this->pdo),
            new VinculoRepository($this->pdo),
            $hasher,
            new Transaction($this->pdo)
        );
    }

    private function condominio(string $nome = 'A'): int
    {
        return (new CondominioRepository($this->pdo))->criar($nome, null, true);
    }

    /** @return array<string, mixed> */
    private function dadosGerente(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Maria Gerente',
            'email' => 'maria@exemplo.com.br',
            'papel' => 'gerente',
            'senha' => 'senha-bem-longa-1',
            'confirmacao' => 'senha-bem-longa-1',
            'condominios' => [],
        ], $extra);
    }

    private function vinculos(int $usuarioId): array
    {
        return (new VinculoRepository($this->pdo))->condominiosDoUsuario($usuarioId);
    }

    public function testCriaGerenteComVinculosESenhaArgon2id(): void
    {
        $a = $this->condominio('A');
        $b = $this->condominio('B');

        $id = $this->servico()->criar($this->dadosGerente(['condominios' => [(string) $b, (string) $a]]));

        $this->assertSame([$a, $b], $this->vinculos($id));
        $u = $this->servico()->buscar($id);
        $this->assertSame('gerente', $u['papel']);
        $this->assertTrue($u['ativo']);
        $this->assertArrayNotHasKey('senha_hash', $u, 'O hash não é exposto ao formulário.');
        $hash = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(password_verify('senha-bem-longa-1', $hash));
    }

    public function testSuperadminIgnoraVinculosEnviados(): void
    {
        $a = $this->condominio();

        $id = $this->servico()->criar($this->dadosGerente([
            'papel' => 'superadmin', 'email' => 'admin@exemplo.com.br', 'condominios' => [(string) $a],
        ]));

        $this->assertSame([], $this->vinculos($id));
    }

    public function testCriarComErrosNaoGravaNada(): void
    {
        try {
            $this->servico()->criar([
                'nome' => '', 'email' => 'x', 'papel' => 'zelador', 'senha' => 'curta', 'confirmacao' => 'outra',
                'condominios' => [],
            ]);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertEqualsCanonicalizing(['nome', 'email', 'papel', 'senha'], array_keys($e->erros));
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM usuarios')->fetchColumn());
    }

    public function testCondominioInexistenteNaSelecaoEhRejeitadoESemGravarOUsuario(): void
    {
        try {
            $this->servico()->criar($this->dadosGerente(['condominios' => ['987654']]));
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('condominios', $e->erros);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM usuarios')->fetchColumn());
    }

    public function testEmailDuplicadoEhRejeitado(): void
    {
        $this->servico()->criar($this->dadosGerente());

        try {
            $this->servico()->criar($this->dadosGerente(['email' => 'MARIA@exemplo.com.br']));
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertSame('Este e-mail já está cadastrado.', $e->erros['email']);
        }
    }

    public function testAtualizarDadosEVinculos(): void
    {
        $a = $this->condominio('A');
        $b = $this->condominio('B');
        $id = $this->servico()->criar($this->dadosGerente(['condominios' => [(string) $a]]));
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->servico()->atualizar($id, [
            'nome' => 'Maria Silva', 'email' => 'maria.silva@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1',
            'condominios' => [(string) $b],
        ], $ator);

        $u = $this->servico()->buscar($id);
        $this->assertSame('Maria Silva', $u['nome']);
        $this->assertSame('maria.silva@exemplo.com.br', $u['email']);
        $this->assertSame([$b], $u['condominios']);
    }

    public function testAtualizarSemInformarSenhaMantemASenhaAtual(): void
    {
        $id = $this->servico()->criar($this->dadosGerente());
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $antes = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();

        $this->servico()->atualizar($id, ['nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1', 'senha' => '', 'confirmacao' => ''], $ator);

        $depois = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertSame($antes, $depois);
        $this->assertNull($this->pdo->query("SELECT senha_alterada_em FROM usuarios WHERE id = {$id}")->fetchColumn() ?: null);
    }

    public function testRedefinirSenhaPeloAdminTrocaOHashERegistraAAlteracao(): void
    {
        $id = $this->servico()->criar($this->dadosGerente());
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->servico()->atualizar($id, [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1',
            'senha' => 'outra-senha-bem-longa', 'confirmacao' => 'outra-senha-bem-longa',
        ], $ator);

        $hash = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertTrue(password_verify('outra-senha-bem-longa', $hash));
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertNotNull($this->pdo->query("SELECT senha_alterada_em FROM usuarios WHERE id = {$id}")->fetchColumn());
    }

    public function testSenhaNovaFracaEhRejeitadaESemAlterarNada(): void
    {
        $id = $this->servico()->criar($this->dadosGerente(['nome' => 'Nome Antigo']));
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        try {
            $this->servico()->atualizar($id, [
                'nome' => 'Nome Novo', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1',
                'senha' => 'curta', 'confirmacao' => 'curta',
            ], $ator);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('senha', $e->erros);
        }

        $this->assertSame('Nome Antigo', $this->servico()->buscar($id)['nome']);
    }

    public function testDesativarContaEPromoverParaSuperadminLimpaVinculos(): void
    {
        $a = $this->condominio();
        $id = $this->servico()->criar($this->dadosGerente(['condominios' => [(string) $a]]));
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->servico()->atualizar($id, [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'superadmin',
            'condominios' => [(string) $a],
        ], $ator);

        $u = $this->servico()->buscar($id);
        $this->assertSame('superadmin', $u['papel']);
        $this->assertFalse($u['ativo'], 'Sem o checkbox "ativo" marcado, a conta fica inativa.');
        $this->assertSame([], $u['condominios']);
    }

    public function testAdminNaoPodeAlterarOProprioPapelNemSeDesativar(): void
    {
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        foreach ([
            ['papel' => 'gerente', 'ativo' => '1'],
            ['papel' => 'superadmin'],
        ] as $mudanca) {
            try {
                $this->servico()->atualizar($ator, array_merge(['nome' => 'Admin', 'email' => 'admin@exemplo.com.br'], $mudanca), $ator);
                $this->fail('Era esperada uma ValidacaoException.');
            } catch (ValidacaoException $e) {
                $this->assertArrayHasKey('geral', $e->erros);
            }
        }

        $u = $this->servico()->buscar($ator);
        $this->assertSame('superadmin', $u['papel']);
        $this->assertTrue($u['ativo']);
    }

    public function testAdminPodeEditarOutrosDadosDeSiMesmo(): void
    {
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->servico()->atualizar($ator, ['nome' => 'Novo Nome', 'email' => 'admin@exemplo.com.br', 'papel' => 'superadmin', 'ativo' => '1'], $ator);

        $this->assertSame('Novo Nome', $this->servico()->buscar($ator)['nome']);
    }

    public function testExcluirRemoveOUsuarioEOsVinculos(): void
    {
        $a = $this->condominio();
        $id = $this->servico()->criar($this->dadosGerente(['condominios' => [(string) $a]]));
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->servico()->excluir($id, $ator);

        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM usuarios WHERE id = {$id}")->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM usuario_condominio')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM condominios')->fetchColumn());
    }

    public function testNaoPodeExcluirASiMesmo(): void
    {
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        $this->expectException(ValidacaoException::class);
        try {
            $this->servico()->excluir($ator, $ator);
        } finally {
            $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM usuarios')->fetchColumn());
        }
    }

    public function testUsuarioInexistente(): void
    {
        $ator = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');

        foreach ([
            fn () => $this->servico()->buscar(987654),
            fn () => $this->servico()->excluir(987654, $ator),
            fn () => $this->servico()->atualizar(987654, [], $ator),
        ] as $acao) {
            try {
                $acao();
                $this->fail('Era esperada uma NaoEncontradoException.');
            } catch (NaoEncontradoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testListarNaoExpoeHashDeSenha(): void
    {
        $this->criarUsuario('a@exemplo.com.br');

        foreach ($this->servico()->listar() as $u) {
            $this->assertArrayNotHasKey('senha_hash', $u);
        }
    }
}
