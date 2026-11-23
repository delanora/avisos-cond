<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\UsuarioRepository;
use App\Services\UsuarioService;
use App\Services\ValidacaoException;
use Tests\BancoMigradoTestCase;

final class UsuarioServiceTest extends BancoMigradoTestCase
{
    private function servico(): UsuarioService
    {
        return new UsuarioService(new UsuarioRepository($this->pdo), $this->hasherRapido());
    }

    public function testSenhaFicaProtegidaComArgon2idNuncaEmTextoPuro(): void
    {
        $id = $this->servico()->criar('Maria', 'maria@exemplo.com.br', 'uma-senha-bem-longa', 'gerente');

        $hash = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertStringNotContainsString('uma-senha-bem-longa', $hash);
        $this->assertTrue(password_verify('uma-senha-bem-longa', $hash));
    }

    public function testNenhumaColunaDoBancoGuardaASenhaEmTextoPuro(): void
    {
        $this->servico()->criar('Maria', 'maria@exemplo.com.br', 'uma-senha-bem-longa', 'gerente');

        $linha = $this->pdo->query('SELECT row_to_json(u)::text FROM usuarios u')->fetchColumn();

        $this->assertStringNotContainsString('uma-senha-bem-longa', (string) $linha);
    }

    public function testNormalizaEmailParaMinusculas(): void
    {
        $id = $this->servico()->criar('Maria', '  Maria@Exemplo.COM.br ', 'uma-senha-bem-longa', 'gerente');

        $this->assertSame('maria@exemplo.com.br', $this->pdo->query("SELECT email FROM usuarios WHERE id = {$id}")->fetchColumn());
    }

    public function testRejeitaEmailDuplicadoSemDistinguirCaixa(): void
    {
        $this->servico()->criar('Maria', 'maria@exemplo.com.br', 'uma-senha-bem-longa', 'gerente');

        try {
            $this->servico()->criar('Outra', 'MARIA@exemplo.com.br', 'outra-senha-bem-longa', 'gerente');
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertSame('Este e-mail já está cadastrado.', $e->erros['email']);
        }
    }

    public function testValidaSenhaMinimaMaximaEOutrosCampos(): void
    {
        try {
            $this->servico()->criar('', 'nao-e-email', 'curta', 'zelador');
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertEqualsCanonicalizing(['nome', 'email', 'senha', 'papel'], array_keys($e->erros));
            $this->assertStringContainsString('no mínimo 8', $e->erros['senha']);
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM usuarios')->fetchColumn());
    }

    public function testRejeitaSenhaGrandeDemais(): void
    {
        $this->expectException(ValidacaoException::class);
        $this->servico()->criar('Maria', 'maria@exemplo.com.br', str_repeat('a', UsuarioService::SENHA_MAX + 1), 'gerente');
    }

    public function testValidarSenha(): void
    {
        $this->assertNull(UsuarioService::validarSenha('uma-senha-longa', 'uma-senha-longa'));
        $this->assertSame('Informe a senha.', UsuarioService::validarSenha('', ''));
        $this->assertStringContainsString('coincide', (string) UsuarioService::validarSenha('uma-senha-longa', 'outra-senha-longa'));
    }

    public function testSenhaMinimaEDeOitoCaracteres(): void
    {
        $this->assertSame(8, UsuarioService::SENHA_MIN);
        $this->assertNotNull(UsuarioService::validarSenha('1234567', '1234567'), '7 caracteres: recusada.');
        $this->assertNull(UsuarioService::validarSenha('12345678', '12345678'), '8 caracteres: aceita.');
        $this->assertNull(UsuarioService::validarSenha('áéíóúçãõ', 'áéíóúçãõ'), 'Conta caracteres, não bytes (8 letras acentuadas).');
        $this->assertNotNull(UsuarioService::validarSenha('áéíóúçã', 'áéíóúçã'), '7 letras acentuadas: recusada.');
        $this->assertNull(UsuarioService::validarSenha(str_repeat('a', 128), str_repeat('a', 128)));
        $this->assertNotNull(UsuarioService::validarSenha(str_repeat('a', 129), str_repeat('a', 129)));
        $this->assertNull(UsuarioService::validarSenha('  abcdef ', '  abcdef '), 'Espaços fazem parte da senha (8 no total).');
    }

    public function testCriarUsuarioComSenhaDeOitoCaracteres(): void
    {
        $id = $this->servico()->criar('Ana', 'ana@exemplo.com.br', 'abcd1234', 'gerente');

        $this->assertGreaterThan(0, $id);
        $this->expectException(ValidacaoException::class);
        $this->servico()->criar('Beto', 'beto@exemplo.com.br', 'abcd123', 'gerente');
    }
}
