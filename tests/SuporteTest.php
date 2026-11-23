<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;

/** Modo suporte: o superadmin entra como um gerente, vê o que ele vê, não mexe em senha/aparência e tudo é auditado. */
final class SuporteTest extends AppHttpTestCase
{
    private int $admin;
    private int $gerente;
    private int $condominio;
    private int $outroCondominio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $this->gerente = $this->criarUsuario('gerente@exemplo.com.br');
        $this->condominio = $this->criarCondominio('Residencial Sol');
        $this->outroCondominio = $this->criarCondominio('Edifício Lua');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerente, [$this->condominio]);
        $this->pdo->exec("UPDATE usuarios SET nome = 'Maria Gerente' WHERE id = {$this->gerente}");
    }

    private function entrar(?int $id = null): \App\Support\Response
    {
        $this->logar('admin@exemplo.com.br');

        return $this->post('/admin/usuarios/' . ($id ?? $this->gerente) . '/suporte');
    }

    /** @return list<string> */
    private function acoes(): array
    {
        return $this->pdo->query("SELECT acao FROM auditoria WHERE acao <> 'login.entrou' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testAdminEntraComoGerenteEVeOPainelDele(): void
    {
        $r = $this->entrar();

        $this->assertSame(303, $r->status);
        $this->assertSame('/gerente', $r->headers['Location']);
        $this->assertSame(302, $this->get('/gerente')->status, 'Com um só condomínio o painel abre direto nos avisos dele.');
        $painel = $this->get("/gerente/condominios/{$this->condominio}/avisos");
        $this->assertSame(200, $painel->status);
        $this->assertStringContainsString('Residencial Sol', $painel->body);
        $this->assertStringNotContainsString('Edifício Lua', $painel->body, 'Só os condomínios do gerente.');
        $this->assertStringContainsString('Modo suporte', $painel->body);
        $this->assertStringContainsString('Maria Gerente', $painel->body);
        $this->assertStringContainsString('action="/suporte/sair"', $painel->body);
        $this->assertSame(['suporte.iniciado'], $this->acoes());
    }

    public function testNaoMostraSenhaNemAparenciaNoMenuEmModoSuporte(): void
    {
        $this->entrar();

        $corpo = $this->get("/gerente/condominios/{$this->condominio}/avisos")->body;

        $this->assertStringNotContainsString('href="/conta/seguranca"', $corpo);
        $this->assertStringNotContainsString('href="/gerente/aparencia"', $corpo);
    }

    public function testSenhaEAparenciaFicamBloqueadasEmModoSuporte(): void
    {
        $this->entrar();
        $hashAntes = $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$this->gerente}")->fetchColumn();

        $this->assertSame(403, $this->get('/conta/seguranca')->status);
        $this->assertSame(403, $this->post('/conta/seguranca', ['senha_atual' => self::SENHA, 'nova_senha' => 'outra-senha-longa-1', 'confirmacao' => 'outra-senha-longa-1'])->status);
        $this->assertSame(403, $this->get('/gerente/aparencia')->status);
        $this->assertSame(403, $this->post('/gerente/aparencia', ['cor' => '#112233'])->status);

        $this->assertSame($hashAntes, $this->pdo->query("SELECT senha_hash FROM usuarios WHERE id = {$this->gerente}")->fetchColumn());
        $this->assertNull($this->pdo->query("SELECT cor_primaria FROM usuarios WHERE id = {$this->gerente}")->fetchColumn());
    }

    public function testAreaAdminFica403EnquantoEmModoSuporte(): void
    {
        $this->entrar();

        foreach (['/admin', '/admin/usuarios', '/admin/auditoria'] as $rota) {
            $this->assertSame(403, $this->get($rota)->status, $rota);
        }
        $this->assertSame(403, $this->post("/admin/usuarios/{$this->gerente}/suporte")->status, 'Não dá para empilhar suporte.');
    }

    public function testIsolamentoContinuaValendoEmModoSuporte(): void
    {
        $this->entrar();

        $this->assertSame(404, $this->get("/gerente/condominios/{$this->outroCondominio}/avisos")->status);
        $this->assertSame(200, $this->get("/gerente/condominios/{$this->condominio}/avisos")->status);
    }

    public function testAcoesEmModoSuporteGravamOAdminEOGerente(): void
    {
        $this->entrar();
        $this->get('/gerente'); // entrega os tipos do gerente
        $base = "/gerente/condominios/{$this->condominio}";
        $this->post("{$base}/avisos", [
            'titulo' => 'Aviso do suporte', 'corpo' => 'Texto', 'tipo' => 'comum',
            'inicio_em' => '2026-11-20T08:00', 'fim_em' => '2026-11-20T12:00', 'ativo' => '1',
        ]);

        $linha = $this->pdo->query("SELECT usuario_id, usuario_nome, como_usuario_id, como_usuario_nome FROM auditoria WHERE acao = 'aviso.criado'")->fetch();
        $this->assertNotFalse($linha, 'O aviso criado deve ser auditado.');
        $this->assertSame($this->admin, (int) $linha['usuario_id']);
        $this->assertSame($this->gerente, (int) $linha['como_usuario_id']);
        $this->assertSame('Maria Gerente', $linha['como_usuario_nome']);
    }

    public function testSairEncerraOModoEAudita(): void
    {
        $this->entrar();

        $r = $this->post('/suporte/sair');

        $this->assertSame(303, $r->status);
        $this->assertSame('/admin/usuarios', $r->headers['Location']);
        $this->assertSame(200, $this->get('/admin')->status, 'Voltou a ser administrador.');
        $this->assertSame(403, $this->get('/gerente')->status);
        $this->assertSame(['suporte.iniciado', 'suporte.encerrado'], $this->acoes());
        $fim = $this->pdo->query("SELECT usuario_id, como_usuario_id FROM auditoria WHERE acao = 'suporte.encerrado'")->fetch();
        $this->assertSame($this->admin, (int) $fim['usuario_id']);
        $this->assertSame($this->gerente, (int) $fim['como_usuario_id']);
    }

    public function testSairSemCsrfNaoFunciona(): void
    {
        $this->entrar();

        $this->assertSame(403, $this->post('/suporte/sair', comCsrf: false)->status);
        $this->assertSame(200, $this->get("/gerente/condominios/{$this->condominio}/avisos")->status, 'Continua em modo suporte.');
    }

    public function testSairForaDoModoSuporteNaoFazNada(): void
    {
        $this->logar('admin@exemplo.com.br');

        $r = $this->post('/suporte/sair');

        $this->assertSame(303, $r->status);
        $this->assertSame([], $this->acoes());
    }

    public function testSoSuperadminEntra(): void
    {
        $outro = $this->criarUsuario('outro@exemplo.com.br');
        $this->logar('gerente@exemplo.com.br');

        $this->assertSame(403, $this->post("/admin/usuarios/{$outro}/suporte")->status);
        $this->assertSame([], $this->acoes());
        $avisos = $this->get("/gerente/condominios/{$this->condominio}/avisos");
        $this->assertSame(200, $avisos->status);
        $this->assertStringNotContainsString('Modo suporte', $avisos->body);
    }

    public function testVisitanteNaoEntra(): void
    {
        $this->assertSame(302, $this->post("/admin/usuarios/{$this->gerente}/suporte", [], false)->status);
        $this->assertSame([], $this->acoes());
    }

    public function testNaoEntraComoAdministradorGerenteInativoOuInexistente(): void
    {
        $this->logar('admin@exemplo.com.br');
        $inativo = $this->criarUsuario('inativo@exemplo.com.br');
        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$inativo}");

        $this->assertSame(404, $this->post("/admin/usuarios/{$this->admin}/suporte")->status);
        $this->assertSame(404, $this->post("/admin/usuarios/{$inativo}/suporte")->status);
        $this->assertSame(404, $this->post('/admin/usuarios/999999/suporte')->status);
        $this->assertSame([], $this->acoes());
        $this->assertSame(200, $this->get('/admin')->status);
    }

    public function testGerenteDesativadoNoMeioDoSuporteEncerraOModo(): void
    {
        $this->entrar();
        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$this->gerente}");

        $this->assertSame(200, $this->get('/admin')->status, 'Volta a ser administrador na hora.');
        $this->assertStringNotContainsString('Modo suporte', $this->get('/admin')->body);
    }

    public function testGerenteApagadoNoMeioDoSuporteEncerraOModo(): void
    {
        $this->entrar();
        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$this->gerente}");

        $this->assertSame(200, $this->get('/admin')->status);
    }

    public function testDesativarOAdminEncerraTudo(): void
    {
        $this->entrar();
        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$this->admin}");

        $this->assertSame(302, $this->get('/gerente')->status, 'Sem o admin válido, nem o modo suporte vale.');
    }

    public function testBotaoEntrarComoSoApareceParaGerenteAtivo(): void
    {
        $inativo = $this->criarUsuario('inativo@exemplo.com.br');
        $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$inativo}");
        $this->logar('admin@exemplo.com.br');

        $corpo = $this->get('/admin/usuarios')->body;

        $this->assertStringContainsString("action=\"/admin/usuarios/{$this->gerente}/suporte\"", $corpo);
        $this->assertStringNotContainsString("/admin/usuarios/{$inativo}/suporte", $corpo);
        $this->assertStringNotContainsString("/admin/usuarios/{$this->admin}/suporte", $corpo);
    }

    public function testSessaoDoAdminNaoVazaOHashDeSenha(): void
    {
        $this->entrar();

        $corpo = $this->get("/gerente/condominios/{$this->condominio}/avisos")->body;

        $this->assertStringNotContainsString('$argon2', $corpo);
    }
}
