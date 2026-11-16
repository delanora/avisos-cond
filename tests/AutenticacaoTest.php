<?php

declare(strict_types=1);

namespace Tests;

use App\AppFactory;
use App\Application;
use App\Support\Config;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use Tests\Support\FakeMailer;

/**
 * Testes de integração do login, logout, recuperação de senha e da autorização por papel
 * em /admin e /gerente. Usam a mesma montagem do public/index.php (AppFactory); o $_SESSION
 * faz o papel do navegador, mantendo a sessão entre as requisições de cada teste.
 */
final class AutenticacaoTest extends BancoMigradoTestCase
{
    private Application $app;
    private FakeMailer $mailer;
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->mailer = new FakeMailer();
        $this->log = tempnam(sys_get_temp_dir(), 'log');
        $this->app = AppFactory::criar(
            new Config(['APP_URL' => 'https://avisos.exemplo.com.br']),
            $this->pdo,
            Session::emMemoria(),
            $this->mailer,
            new Logger($this->log),
            dirname(__DIR__) . '/templates',
            $this->hasherRapido()
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @unlink($this->log);
    }

    private function get(string $caminho, string $ip = '203.0.113.10'): Response
    {
        // Separa a query string do caminho, como o servidor faz.
        [$rota, $consulta] = array_pad(explode('?', $caminho, 2), 2, '');
        parse_str($consulta, $query);

        return $this->app->handle(new Request('GET', $rota, query: $query, server: ['REMOTE_ADDR' => $ip]));
    }

    /** @param array<string, string> $corpo */
    private function post(string $caminho, array $corpo = [], bool $comCsrf = true, string $ip = '203.0.113.10'): Response
    {
        if ($comCsrf) {
            $corpo['_csrf'] = $this->tokenCsrf();
        }

        return $this->app->handle(new Request('POST', $caminho, body: $corpo, server: ['REMOTE_ADDR' => $ip]));
    }

    private function tokenCsrf(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $this->get('/login');
        }

        return (string) $_SESSION['csrf_token'];
    }

    private function logar(string $email = 'gerente@exemplo.com.br', string $senha = self::SENHA): Response
    {
        return $this->post('/login', ['email' => $email, 'senha' => $senha]);
    }

    // ---------- Autorização por papel ----------

    public function testVisitanteAnonimoEMandadoParaOLoginEmTodasAsAreasProtegidas(): void
    {
        foreach (['/', '/admin', '/gerente'] as $caminho) {
            $r = $this->get($caminho);

            $this->assertSame(302, $r->status, $caminho);
            $this->assertSame('/login', $r->headers['Location'], $caminho);
        }
    }

    public function testGerenteAcessaSoOPainelDoGerente(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br', 'gerente');
        $this->assertSame('/gerente', $this->logar()->headers['Location']);

        $painel = $this->get('/gerente');
        $this->assertSame(200, $painel->status);
        $this->assertStringContainsString('Painel do gerente', $painel->body);

        $proibido = $this->get('/admin');
        $this->assertSame(403, $proibido->status);
        $this->assertStringNotContainsString('Painel Embracore', $proibido->body);
    }

    public function testSuperadminAcessaSoOPainelAdmin(): void
    {
        $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $this->assertSame('/admin', $this->logar('admin@exemplo.com.br')->headers['Location']);

        $this->assertStringContainsString('Painel Embracore', $this->get('/admin')->body);
        $this->assertSame(403, $this->get('/gerente')->status);
    }

    public function testRaizRedirecionaParaOPainelDoPapel(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br', 'gerente');
        $this->logar();

        $this->assertSame('/gerente', $this->get('/')->headers['Location']);
    }

    // ---------- Login ----------

    public function testLoginComSucessoGravaASessaoETrocaOTokenCsrf(): void
    {
        $id = $this->criarUsuario();
        $csrfAntes = $this->tokenCsrf();

        $r = $this->logar();

        $this->assertSame(303, $r->status);
        $this->assertSame($id, $_SESSION['usuario_id']);
        $this->assertIsInt($_SESSION['autenticado_em']);
        $this->assertArrayNotHasKey('csrf_token', $_SESSION, 'O token CSRF é refeito após o login.');
        $this->assertNotSame($csrfAntes, $this->tokenCsrf());
    }

    public function testCredenciaisInvalidasDaoSempreAMesmaMensagemGenerica(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->criarUsuario('inativo@exemplo.com.br', ativo: false);

        $senhaErrada = $this->logar('maria@exemplo.com.br', 'senha-errada-123');
        $emailInexistente = $this->logar('ninguem@exemplo.com.br');
        $inativo = $this->logar('inativo@exemplo.com.br');

        foreach ([$senhaErrada, $emailInexistente, $inativo] as $r) {
            $this->assertSame(401, $r->status);
            $this->assertStringContainsString('Credenciais inválidas.', $r->body);
        }
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testLoginSemCsrfEBloqueado(): void
    {
        $this->criarUsuario();

        $r = $this->post('/login', ['email' => 'gerente@exemplo.com.br', 'senha' => self::SENHA], comCsrf: false);

        $this->assertSame(403, $r->status);
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testEmailComScriptNaoVirgaXssNoFormulario(): void
    {
        $r = $this->logar('"><script>alert(1)</script>', 'qualquer');

        $this->assertSame(401, $r->status);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $r->body);
        $this->assertStringContainsString('&lt;script&gt;', $r->body);
    }

    public function testBloqueioPorEmailAposCincoFalhasRetorna429(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        for ($i = 0; $i < 6; $i++) {
            $this->logar('maria@exemplo.com.br', 'errada-' . $i);
        }
        $r = $this->logar('maria@exemplo.com.br');

        $this->assertSame(429, $r->status);
        $this->assertArrayHasKey('Retry-After', $r->headers);
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
    }

    public function testBloqueioPorIpEmTentativasEmEmailsDiferentes(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->assertSame(401, $this->logar("alvo{$i}@exemplo.com.br", 'x')->status, "tentativa {$i}");
        }

        $r = $this->logar('alvo31@exemplo.com.br', 'x');
        $this->assertSame(429, $r->status);
        $this->assertArrayHasKey('Retry-After', $r->headers);

        // Outro IP não é afetado.
        $outro = $this->post('/login', ['email' => 'alvo@exemplo.com.br', 'senha' => 'x'], ip: '198.51.100.7');
        $this->assertSame(401, $outro->status);
    }

    // ---------- Sessão ----------

    public function testLogoutEncerraASessao(): void
    {
        $this->criarUsuario();
        $this->logar();

        $r = $this->post('/logout');

        $this->assertSame('/login', $r->headers['Location']);
        $this->assertArrayNotHasKey('usuario_id', $_SESSION);
        $this->assertSame('/login', $this->get('/gerente')->headers['Location']);
    }

    public function testLogoutSemCsrfNaoEncerraASessao(): void
    {
        $this->criarUsuario();
        $this->logar();

        $r = $this->post('/logout', comCsrf: false);

        $this->assertSame(403, $r->status);
        $this->assertSame(200, $this->get('/gerente')->status);
    }

    public function testContaDesativadaPerdeOAcessoNaProximaRequisicao(): void
    {
        $id = $this->criarUsuario();
        $this->logar();
        $this->assertSame(200, $this->get('/gerente')->status);

        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$id}");

        $this->assertSame('/login', $this->get('/gerente')->headers['Location']);
    }

    public function testTrocaDeSenhaDerrubaSessoesAnteriores(): void
    {
        $id = $this->criarUsuario();
        $this->logar();
        // O login aconteceu há 1 minuto; a senha é trocada agora.
        $_SESSION['autenticado_em'] = time() - 60;
        $this->pdo->exec("UPDATE usuarios SET senha_alterada_em = now() WHERE id = {$id}");

        $this->assertSame('/login', $this->get('/gerente')->headers['Location']);
    }

    // ---------- Recuperação de senha ----------

    public function testFluxoCompletoDeRecuperacaoDeSenha(): void
    {
        $id = $this->criarUsuario('maria@exemplo.com.br');

        $pedido = $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $this->assertSame(200, $pedido->status);
        $this->assertCount(1, $this->mailer->enviados);
        $token = $this->mailer->ultimoToken();

        $form = $this->get("/redefinir-senha/{$token}");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="confirmacao"', $form->body);
        $this->assertSame('no-referrer', $form->headers['Referrer-Policy']);

        $troca = $this->post("/redefinir-senha/{$token}", [
            'senha' => 'nova-senha-muito-boa',
            'confirmacao' => 'nova-senha-muito-boa',
        ]);
        $this->assertSame(303, $troca->status);
        $this->assertSame('/login?msg=senha-alterada', $troca->headers['Location']);
        $this->assertStringContainsString('Senha alterada com sucesso', $this->get('/login?msg=senha-alterada')->body);

        // A senha antiga deixa de valer e a nova funciona.
        $this->assertSame(401, $this->logar('maria@exemplo.com.br', self::SENHA)->status);
        $this->assertSame(303, $this->logar('maria@exemplo.com.br', 'nova-senha-muito-boa')->status);
        $this->assertSame($id, $_SESSION['usuario_id']);

        // O link é de uso único.
        $reuso = $this->get("/redefinir-senha/{$token}");
        $this->assertSame(400, $reuso->status);
        $this->assertStringNotContainsString('name="confirmacao"', $reuso->body);
    }

    public function testPedidoDeRecuperacaoNaoRevelaSeOEmailExiste(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        $existe = $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $naoExiste = $this->post('/esqueci-senha', ['email' => 'ninguem@exemplo.com.br']);

        $this->assertSame($existe->status, $naoExiste->status);
        $this->assertSame($existe->body, $naoExiste->body);
        $this->assertCount(1, $this->mailer->enviados);
    }

    public function testRecuperacaoDerrubaSessaoAbertaDoUsuario(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->logar('maria@exemplo.com.br');
        $this->assertSame(200, $this->get('/gerente')->status);
        $_SESSION['autenticado_em'] = time() - 60;

        $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $token = $this->mailer->ultimoToken();
        $this->post("/redefinir-senha/{$token}", ['senha' => 'nova-senha-muito-boa', 'confirmacao' => 'nova-senha-muito-boa']);

        // O navegador que já estava logado (sessão de antes da troca) perde o acesso.
        $_SESSION = ['usuario_id' => $_SESSION['usuario_id'] ?? 0, 'autenticado_em' => time() - 60];
        $this->assertSame('/login', $this->get('/gerente')->headers['Location']);
    }

    public function testRedefinirComSenhaFracaMostraErroEMantemOLink(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $token = $this->mailer->ultimoToken();

        $r = $this->post("/redefinir-senha/{$token}", ['senha' => 'curta', 'confirmacao' => 'curta']);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('no mínimo 10', $r->body);
        $this->assertSame(200, $this->get("/redefinir-senha/{$token}")->status);
    }

    public function testRedefinirSemCsrfEBloqueado(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $token = $this->mailer->ultimoToken();

        $r = $this->post("/redefinir-senha/{$token}", ['senha' => 'nova-senha-muito-boa', 'confirmacao' => 'nova-senha-muito-boa'], comCsrf: false);

        $this->assertSame(403, $r->status);
        $this->assertSame(200, $this->get("/redefinir-senha/{$token}")->status);
    }

    public function testTokenMalFormadoNaUrlRetorna404(): void
    {
        $this->assertSame(404, $this->get('/redefinir-senha/abc')->status);
        $this->assertSame(404, $this->get('/redefinir-senha/' . str_repeat('G', 64))->status);
    }

    public function testPedidoDeRecuperacaoComEmailInvalidoRetorna422(): void
    {
        $r = $this->post('/esqueci-senha', ['email' => 'isso-nao-e-email']);

        $this->assertSame(422, $r->status);
        $this->assertSame([], $this->mailer->enviados);
    }

    public function testLimiteDePedidosDeRecuperacaoPorEmailRetorna429(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br'])->status);
        }
        $r = $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);

        $this->assertSame(429, $r->status);
        $this->assertCount(3, $this->mailer->enviados);
    }

    // ---------- Cabeçalhos e armazenamento ----------

    public function testPaginasAutenticadasEDeLoginNaoSaoGuardadasEmCache(): void
    {
        $this->criarUsuario();
        $this->logar();

        foreach (['/login', '/gerente'] as $caminho) {
            $r = $this->get($caminho);
            $this->assertSame('no-store', $r->headers['Cache-Control'], $caminho);
            $this->assertSame('nosniff', $r->headers['X-Content-Type-Options'], $caminho);
        }
    }

    public function testFormulariosTemTokenCsrfEMetodoPost(): void
    {
        foreach (['/login', '/esqueci-senha'] as $caminho) {
            $r = $this->get($caminho);

            $this->assertStringContainsString('method="post"', $r->body, $caminho);
            $this->assertMatchesRegularExpression('/name="_csrf" value="[a-f0-9]{64}"/', $r->body, $caminho);
        }
    }

    public function testSenhaNuncaApareceNoBancoNemNoLog(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->logar('maria@exemplo.com.br', 'senha-que-nao-deve-vazar');
        $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);
        $this->mailer->falhar = true;
        $this->post('/esqueci-senha', ['email' => 'maria@exemplo.com.br']);

        $log = (string) file_get_contents($this->log);
        $this->assertStringNotContainsString('senha-que-nao-deve-vazar', $log);
        $this->assertStringNotContainsString(self::SENHA, $log);
        $this->assertStringNotContainsString(self::SENHA, (string) $this->pdo->query('SELECT string_agg(row_to_json(u)::text, \'\') FROM usuarios u')->fetchColumn());
    }
}
