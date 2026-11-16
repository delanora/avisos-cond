<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\AuthMiddleware;
use App\Repositories\UsuarioRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use Tests\BancoMigradoTestCase;

final class AuthMiddlewareTest extends BancoMigradoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function executar(): Response
    {
        $mw = new AuthMiddleware(Session::emMemoria(), new UsuarioRepository($this->pdo));

        return $mw->process(
            new Request('GET', '/gerente'),
            static fn (Request $req): Response => Response::html('usuario:' . $req->attribute('usuario')['email'])
        );
    }

    private function logarSessao(int $usuarioId, ?int $quando = null): void
    {
        $_SESSION = ['usuario_id' => $usuarioId, 'autenticado_em' => $quando ?? time()];
    }

    public function testSemSessaoRedirecionaParaOLogin(): void
    {
        $r = $this->executar();

        $this->assertSame(302, $r->status);
        $this->assertSame('/login', $r->headers['Location']);
    }

    public function testSessaoValidaPassaComOUsuarioNaRequisicao(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $this->logarSessao($id);

        $r = $this->executar();

        $this->assertSame(200, $r->status);
        $this->assertSame('usuario:maria@exemplo.com.br', $r->body);
    }

    public function testUsuarioDesativadoPerdeOAcessoNaHoraELimpaASessao(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $this->logarSessao($id);
        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$id}");

        $r = $this->executar();

        $this->assertSame('/login', $r->headers['Location']);
        $this->assertSame([], $_SESSION);
    }

    public function testUsuarioExcluidoPerdeOAcesso(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $this->logarSessao($id);
        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$id}");

        $this->assertSame('/login', $this->executar()->headers['Location']);
    }

    public function testSessaoAnteriorATrocaDeSenhaDeixaDeValer(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $this->logarSessao($id, time() - 60);
        $this->pdo->exec("UPDATE usuarios SET senha_alterada_em = now() WHERE id = {$id}");

        $r = $this->executar();

        $this->assertSame('/login', $r->headers['Location']);
        $this->assertSame([], $_SESSION);
    }

    public function testSessaoPosteriorATrocaDeSenhaContinuaValida(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $this->pdo->exec("UPDATE usuarios SET senha_alterada_em = now() - interval '1 hour' WHERE id = {$id}");
        $this->logarSessao($id);

        $this->assertSame(200, $this->executar()->status);
    }

    public function testDadosDeSessaoComTipoErradoSaoRejeitados(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');
        $_SESSION = ['usuario_id' => (string) $id, 'autenticado_em' => time()];
        $this->assertSame('/login', $this->executar()->headers['Location']);

        $_SESSION = ['usuario_id' => $id];
        $this->assertSame('/login', $this->executar()->headers['Location']);
    }
}
