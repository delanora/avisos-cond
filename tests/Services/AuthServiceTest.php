<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\RateLimitRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AuthService;
use App\Services\PasswordHasher;
use App\Services\RateLimitExcedidoException;
use App\Services\RateLimiter;
use Tests\BancoMigradoTestCase;

final class AuthServiceTest extends BancoMigradoTestCase
{
    private function auth(?PasswordHasher $hasher = null): AuthService
    {
        return new AuthService(
            new UsuarioRepository($this->pdo),
            $hasher ?? $this->hasherRapido(),
            new RateLimiter(new RateLimitRepository($this->pdo))
        );
    }

    public function testAutenticaComCredenciaisCorretas(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');

        $usuario = $this->auth()->autenticar('maria@exemplo.com.br', self::SENHA);

        $this->assertSame($id, $usuario['id']);
        $this->assertSame('gerente', $usuario['papel']);
    }

    public function testEmailNaoDiferenciaMaiusculas(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        $this->assertNotNull($this->auth()->autenticar('MARIA@Exemplo.com.br', self::SENHA));
    }

    public function testSenhaErradaEmailInexistenteEUsuarioInativoRetornamNull(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->criarUsuario('inativo@exemplo.com.br', ativo: false);

        $this->assertNull($this->auth()->autenticar('maria@exemplo.com.br', 'senha-errada-123'));
        $this->assertNull($this->auth()->autenticar('ninguem@exemplo.com.br', self::SENHA));
        $this->assertNull($this->auth()->autenticar('inativo@exemplo.com.br', self::SENHA));
    }

    public function testBloqueiaOEmailAposCincoFalhasMesmoComSenhaCorreta(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        for ($i = 0; $i < 6; $i++) {
            $this->assertNull($this->auth()->autenticar('maria@exemplo.com.br', 'errada-' . $i));
        }

        try {
            $this->auth()->autenticar('maria@exemplo.com.br', self::SENHA);
            $this->fail('Era esperado o bloqueio por excesso de tentativas.');
        } catch (RateLimitExcedidoException $e) {
            $this->assertGreaterThan(0, $e->segundos);
        }
    }

    public function testBloqueioTambemValeParaEmailInexistenteSemRevelarSeExiste(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->auth()->autenticar('fantasma@exemplo.com.br', 'x' . $i);
        }

        $this->expectException(RateLimitExcedidoException::class);
        $this->auth()->autenticar('fantasma@exemplo.com.br', 'qualquer');
    }

    public function testLoginComSucessoZeraOContadorDeFalhas(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        for ($i = 0; $i < 4; $i++) {
            $this->auth()->autenticar('maria@exemplo.com.br', 'errada-' . $i);
        }
        $this->assertNotNull($this->auth()->autenticar('maria@exemplo.com.br', self::SENHA));

        // Contador zerado: mais quatro falhas ainda não bloqueiam.
        for ($i = 0; $i < 4; $i++) {
            $this->assertNull($this->auth()->autenticar('maria@exemplo.com.br', 'errada-' . $i));
        }
        $this->assertNotNull($this->auth()->autenticar('maria@exemplo.com.br', self::SENHA));
    }

    public function testRefazOHashQuandoOsParametrosFicaramFracos(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $antes = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $forteParaTeste = new PasswordHasher(['memory_cost' => 2048, 'time_cost' => 2, 'threads' => 1]);

        $this->assertNotNull($this->auth($forteParaTeste)->autenticar('maria@exemplo.com.br', self::SENHA));

        $depois = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertNotSame($antes, $depois);
        $this->assertFalse($forteParaTeste->precisaRehash($depois));
        $this->assertTrue(password_verify(self::SENHA, $depois));
        // O rehash não deve invalidar sessões abertas.
        $this->assertNull($this->pdo->query("SELECT senha_alterada_em FROM usuarios WHERE id = {$id}")->fetchColumn() ?: null);
    }
}
