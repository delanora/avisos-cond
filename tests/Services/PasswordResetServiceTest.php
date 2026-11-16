<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UsuarioRepository;
use App\Services\PasswordResetService;
use App\Services\RateLimitExcedidoException;
use App\Services\RateLimiter;
use App\Services\ValidacaoException;
use App\Support\Logger;
use Tests\BancoMigradoTestCase;
use Tests\Support\FakeMailer;

final class PasswordResetServiceTest extends BancoMigradoTestCase
{
    private FakeMailer $mailer;
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailer = new FakeMailer();
        $this->log = tempnam(sys_get_temp_dir(), 'log');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    private function servico(): PasswordResetService
    {
        return new PasswordResetService(
            new UsuarioRepository($this->pdo),
            new PasswordResetRepository($this->pdo),
            new RateLimiter(new RateLimitRepository($this->pdo)),
            $this->hasherRapido(),
            $this->mailer,
            new Logger($this->log),
            'https://avisos.exemplo.com.br'
        );
    }

    private function contarResets(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM password_resets')->fetchColumn();
    }

    public function testEnviaEmailComLinkEGuardaSomenteOHashDoToken(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        $this->servico()->solicitar('maria@exemplo.com.br', '203.0.113.1');

        $this->assertCount(1, $this->mailer->enviados);
        $this->assertSame('maria@exemplo.com.br', $this->mailer->enviados[0]['para']);
        $token = $this->mailer->ultimoToken();
        $this->assertStringContainsString("https://avisos.exemplo.com.br/redefinir-senha/{$token}", $this->mailer->enviados[0]['texto']);

        // No banco só existe o SHA-256 do token; o token em si não aparece em nenhuma coluna.
        $guardado = (string) $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
        $this->assertSame(hash('sha256', $token), $guardado);
        $linha = (string) $this->pdo->query('SELECT row_to_json(p)::text FROM password_resets p')->fetchColumn();
        $this->assertStringNotContainsString($token, $linha);
    }

    public function testTokenTemEntropiaDe256Bits(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->servico()->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $primeiro = $this->mailer->ultimoToken();

        $this->servico()->solicitar('maria@exemplo.com.br', '203.0.113.2');

        $this->assertSame(64, strlen($primeiro));
        $this->assertNotSame($primeiro, $this->mailer->ultimoToken());
    }

    public function testEmailInexistenteOuInativoNaoEnviaNadaNemFalha(): void
    {
        $this->criarUsuario('inativo@exemplo.com.br', ativo: false);

        $this->servico()->solicitar('ninguem@exemplo.com.br', '203.0.113.1');
        $this->servico()->solicitar('inativo@exemplo.com.br', '203.0.113.1');

        $this->assertSame([], $this->mailer->enviados);
        $this->assertSame(0, $this->contarResets());
    }

    public function testNovoPedidoInvalidaOTokenAnterior(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $servico = $this->servico();

        $servico->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $antigo = $this->mailer->ultimoToken();
        $servico->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $novo = $this->mailer->ultimoToken();

        $this->assertFalse($servico->tokenValido($antigo));
        $this->assertTrue($servico->tokenValido($novo));
    }

    public function testRedefinirTrocaASenhaEOTokenValeUmaVez(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $servico = $this->servico();
        $servico->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $token = $this->mailer->ultimoToken();

        $this->assertTrue($servico->redefinir($token, 'nova-senha-muito-boa', 'nova-senha-muito-boa', '203.0.113.1'));

        $hash = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertTrue(password_verify('nova-senha-muito-boa', $hash));
        $this->assertFalse(password_verify(self::SENHA, $hash));
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertNotNull($this->pdo->query("SELECT senha_alterada_em FROM usuarios WHERE id = {$id}")->fetchColumn());

        // Uso único: o mesmo token não funciona de novo, nem para checagem.
        $this->assertFalse($servico->tokenValido($token));
        $this->assertFalse($servico->redefinir($token, 'outra-senha-muito-boa', 'outra-senha-muito-boa', '203.0.113.1'));
        $hashFinal = (string) $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$id}")->fetchColumn();
        $this->assertTrue(password_verify('nova-senha-muito-boa', $hashFinal));
    }

    public function testTokenExpiradoNaoFunciona(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $servico = $this->servico();
        $servico->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $token = $this->mailer->ultimoToken();
        $this->pdo->exec("UPDATE password_resets SET expira_em = now() - interval '1 second'");

        $this->assertFalse($servico->tokenValido($token));
        $this->assertFalse($servico->redefinir($token, 'nova-senha-muito-boa', 'nova-senha-muito-boa', '203.0.113.1'));
    }

    public function testTokenInventadoNaoFunciona(): void
    {
        $this->assertFalse($this->servico()->tokenValido(str_repeat('a', 64)));
        $this->assertFalse($this->servico()->redefinir(str_repeat('a', 64), 'nova-senha-muito-boa', 'nova-senha-muito-boa', '203.0.113.1'));
    }

    public function testValidadeDoTokenEDe30Minutos(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->servico()->solicitar('maria@exemplo.com.br', '203.0.113.1');

        $segundos = (int) $this->pdo->query('SELECT extract(epoch FROM expira_em - criado_em)::int FROM password_resets')->fetchColumn();

        $this->assertEqualsWithDelta(1800, $segundos, 2);
    }

    public function testSenhaFracaOuConfirmacaoDiferenteNaoConsomeOToken(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $servico = $this->servico();
        $servico->solicitar('maria@exemplo.com.br', '203.0.113.1');
        $token = $this->mailer->ultimoToken();

        foreach ([['curta', 'curta'], ['nova-senha-muito-boa', 'outra-diferente-123']] as [$senha, $confirmacao]) {
            try {
                $servico->redefinir($token, $senha, $confirmacao, '203.0.113.1');
                $this->fail('Era esperada uma ValidacaoException.');
            } catch (ValidacaoException $e) {
                $this->assertArrayHasKey('senha', $e->erros);
            }
        }

        $this->assertTrue($servico->tokenValido($token));
    }

    public function testFalhaNoEnvioNaoQuebraNemVazaOTokenNoLog(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->mailer->falhar = true;

        $this->servico()->solicitar('maria@exemplo.com.br', '203.0.113.1');

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('Falha ao enviar e-mail de recuperação', $log);
        $token = (string) $this->pdo->query('SELECT token_hash FROM password_resets')->fetchColumn();
        $this->assertStringNotContainsString($token, $log);
        $this->assertStringNotContainsString('redefinir-senha', $log);
    }

    public function testLimiteDeTresPedidosPorEmailPorHora(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $servico = $this->servico();

        // IPs diferentes: o limite que vale aqui é o do e-mail.
        for ($i = 1; $i <= 3; $i++) {
            $servico->solicitar('maria@exemplo.com.br', "203.0.113.{$i}");
        }

        $this->expectException(RateLimitExcedidoException::class);
        $servico->solicitar('maria@exemplo.com.br', '203.0.113.9');
    }

    public function testLimiteDeCincoPedidosPorIpPorHora(): void
    {
        $servico = $this->servico();

        for ($i = 1; $i <= 5; $i++) {
            $servico->solicitar("alvo{$i}@exemplo.com.br", '203.0.113.1');
        }

        $this->expectException(RateLimitExcedidoException::class);
        $servico->solicitar('alvo6@exemplo.com.br', '203.0.113.1');
    }

    public function testLimiteNaTrocaDeSenhaPorIp(): void
    {
        $servico = $this->servico();

        for ($i = 0; $i < 10; $i++) {
            $servico->redefinir(str_repeat('b', 64), 'nova-senha-muito-boa', 'nova-senha-muito-boa', '203.0.113.1');
        }

        $this->expectException(RateLimitExcedidoException::class);
        $servico->redefinir(str_repeat('b', 64), 'nova-senha-muito-boa', 'nova-senha-muito-boa', '203.0.113.1');
    }
}
