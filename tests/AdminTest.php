<?php

declare(strict_types=1);

namespace Tests;

/**
 * Testes de integração do painel /admin: autorização por papel em todas as rotas e os fluxos
 * de CRUD de usuários, condomínios e vínculos.
 */
final class AdminTest extends AppHttpTestCase
{
    /** @return array{get: list<string>, post: list<string>} rotas do /admin para um usuário e um condomínio existentes */
    private function rotas(int $usuarioId, int $condominioId): array
    {
        return [
            'get' => [
                '/admin',
                '/admin/usuarios', '/admin/usuarios/novo',
                "/admin/usuarios/{$usuarioId}/editar", "/admin/usuarios/{$usuarioId}/excluir",
                '/admin/condominios', '/admin/condominios/novo',
                "/admin/condominios/{$condominioId}/editar", "/admin/condominios/{$condominioId}/excluir",
            ],
            'post' => [
                '/admin/usuarios', "/admin/usuarios/{$usuarioId}", "/admin/usuarios/{$usuarioId}/excluir",
                '/admin/condominios', "/admin/condominios/{$condominioId}", "/admin/condominios/{$condominioId}/excluir",
            ],
        ];
    }

    /** @return array<string, int> contagem de tudo que as rotas de escrita poderiam alterar */
    private function fotografia(): array
    {
        return [
            'usuarios' => $this->contar('usuarios'),
            'condominios' => $this->contar('condominios'),
            'vinculos' => $this->contar('usuario_condominio'),
            'nomes' => crc32((string) $this->pdo->query("SELECT string_agg(nome || email || papel || ativo::text, ',' ORDER BY id) FROM usuarios")->fetchColumn()),
            'condos' => crc32((string) $this->pdo->query("SELECT string_agg(nome || ativo::text, ',' ORDER BY id) FROM condominios")->fetchColumn()),
        ];
    }

    // ---------- Autorização ----------

    public function testVisitanteAnonimoNaoAcessaNenhumaRotaDoAdmin(): void
    {
        $alvo = $this->criarUsuario('alvo@exemplo.com.br');
        $condominio = $this->criarCondominio();
        $antes = $this->fotografia();
        $rotas = $this->rotas($alvo, $condominio);

        foreach ($rotas['get'] as $caminho) {
            $r = $this->get($caminho);
            $this->assertSame(302, $r->status, "GET {$caminho}");
            $this->assertSame('/login', $r->headers['Location'], "GET {$caminho}");
        }
        foreach ($rotas['post'] as $caminho) {
            foreach ([true, false] as $comCsrf) {
                $r = $this->post($caminho, ['nome' => 'Invasor', 'email' => 'i@exemplo.com.br', 'papel' => 'superadmin', 'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1'], $comCsrf);
                $this->assertSame(302, $r->status, "POST {$caminho}");
                $this->assertSame('/login', $r->headers['Location'], "POST {$caminho}");
            }
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testGerenteRecebe403EmTodasAsRotasDoAdminESemAlterarNada(): void
    {
        $alvo = $this->criarUsuario('alvo@exemplo.com.br');
        $this->criarUsuario('gerente@exemplo.com.br');
        $condominio = $this->criarCondominio();
        $this->logar();
        $antes = $this->fotografia();
        $rotas = $this->rotas($alvo, $condominio);

        foreach ($rotas['get'] as $caminho) {
            $this->assertSame(403, $this->get($caminho)->status, "GET {$caminho}");
        }
        foreach ($rotas['post'] as $caminho) {
            // Mesmo com token CSRF válido, o papel de gerente não basta.
            $r = $this->post($caminho, [
                'nome' => 'Invasor', 'email' => 'i@exemplo.com.br', 'papel' => 'superadmin',
                'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1', 'ativo' => '1',
            ]);
            $this->assertSame(403, $r->status, "POST {$caminho}");
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testSuperadminAcessaTodasAsPaginasDeLeitura(): void
    {
        $admin = $this->logarComoAdmin();
        $condominio = $this->criarCondominio();

        foreach ($this->rotas($admin, $condominio)['get'] as $caminho) {
            $r = $this->get($caminho);
            $this->assertSame(200, $r->status, "GET {$caminho}");
            $this->assertSame('no-store', $r->headers['Cache-Control'], $caminho);
        }
    }

    public function testEscritaSemCsrfEBloqueadaESemAlterarNada(): void
    {
        $admin = $this->logarComoAdmin();
        $alvo = $this->criarUsuario('alvo@exemplo.com.br');
        $condominio = $this->criarCondominio();
        $antes = $this->fotografia();

        foreach ($this->rotas($alvo, $condominio)['post'] as $caminho) {
            $r = $this->post($caminho, ['nome' => 'X', 'email' => 'x@exemplo.com.br', 'papel' => 'gerente', 'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1'], comCsrf: false);
            $this->assertSame(403, $r->status, "POST {$caminho}");
        }

        $this->assertSame($antes, $this->fotografia());
        $this->assertGreaterThan(0, $admin);
    }

    public function testPaginasNaoUsamStyleInlineNemScript(): void
    {
        $admin = $this->logarComoAdmin();
        $condominio = $this->criarCondominio();

        foreach ($this->rotas($admin, $condominio)['get'] as $caminho) {
            $corpo = $this->get($caminho)->body;
            // O CSP bloqueia CSS e JS inline; garantimos que nenhuma página depende deles.
            $this->assertStringNotContainsString(' style=', $corpo, $caminho);
            $this->assertStringNotContainsString('<script', $corpo, $caminho);
        }
    }

    // ---------- Painel inicial ----------

    public function testPainelMostraSaudacaoComOPrimeiroNomeENumeros(): void
    {
        $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $this->pdo->exec("UPDATE usuarios SET nome = 'Felipe Delanora' WHERE papel = 'superadmin'");
        $this->logar('admin@exemplo.com.br');
        $this->criarUsuario('g1@exemplo.com.br');
        $this->criarUsuario('g2@exemplo.com.br');
        $this->criarCondominio('A');
        $ativo = $this->criarCondominio('B');
        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id <> {$ativo}");

        $corpo = $this->get('/admin')->body;

        $this->assertMatchesRegularExpression('#<h1>(Bom dia|Boa tarde|Boa noite), Felipe</h1>#', $corpo);
        $this->assertStringNotContainsString('Delanora</h1>', $corpo);
        $this->assertStringContainsString('Painel Embracore', $corpo);
        $this->assertMatchesRegularExpression('#numero-valor">2</span><span class="numero-rotulo">condomínios \(1 ativo\)#', $corpo);
        $this->assertMatchesRegularExpression('#numero-valor">2</span><span class="numero-rotulo">gerentes#', $corpo);
        $this->assertMatchesRegularExpression('#numero-valor">1</span><span class="numero-rotulo">administrador<#', $corpo);
        $this->assertStringContainsString('href="/admin/condominios/novo"', $corpo);
        $this->assertStringContainsString('href="/admin/usuarios/novo"', $corpo);
    }

    public function testPainelDoGerenteTambemSaudaPeloPrimeiroNome(): void
    {
        $this->criarUsuario('maria@exemplo.com.br');
        $this->pdo->exec("UPDATE usuarios SET nome = 'Maria Souza Lima'");
        $this->logar('maria@exemplo.com.br');

        $corpo = $this->get('/gerente')->body;

        $this->assertMatchesRegularExpression('#<h1>(Bom dia|Boa tarde|Boa noite), Maria</h1>#', $corpo);
        $this->assertStringContainsString('Painel do gerente', $corpo);
    }

    public function testBarraSuperiorMostraNomePapelEAvatarDoUsuario(): void
    {
        $this->criarUsuario('admin@exemplo.com.br', 'superadmin');
        $this->pdo->exec("UPDATE usuarios SET nome = 'ágata Costa'");
        $this->logar('admin@exemplo.com.br');

        $corpo = $this->get('/admin')->body;

        $this->assertStringContainsString('<span class="perfil-nome">ágata Costa</span>', $corpo);
        $this->assertStringContainsString('<span class="perfil-papel">Administrador</span>', $corpo);
        $this->assertMatchesRegularExpression('#<span class="avatar av-\d" aria-hidden="true">á</span>#u', $corpo);
        $this->assertStringContainsString('class="logo-marca"', $corpo);
    }

    public function testErrosUsamATelaAmigavelComTituloPorCodigo(): void
    {
        $corpo = $this->get('/pagina-que-nao-existe')->body;

        $this->assertStringContainsString('class="erro-codigo">404<', $corpo);
        $this->assertStringContainsString('<h1>Não encontramos essa página</h1>', $corpo);
        $this->assertStringContainsString('Voltar ao início', $corpo);
    }

    // ---------- Navegação ----------

    public function testMenuDoAdminMarcaAPaginaAtual(): void
    {
        $this->logarComoAdmin();

        $usuarios = $this->get('/admin/usuarios')->body;
        $this->assertMatchesRegularExpression('#<a href="/admin/usuarios" class="ativo" aria-current="page">.*?Usuários</a>#s', $usuarios);
        $this->assertMatchesRegularExpression('#<a href="/admin/condominios">.*?Condomínios</a>#s', $usuarios);
        $this->assertDoesNotMatchRegularExpression('#<a href="/admin/condominios" class="ativo"#', $usuarios);

        $this->assertMatchesRegularExpression('#<a href="/admin/condominios" class="ativo"#', $this->get('/admin/condominios')->body);
        $this->assertMatchesRegularExpression('#<a href="/admin" class="ativo"#', $this->get('/admin')->body);
    }

    public function testGerenteNaoVeOMenuDoAdmin(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br');
        $this->logar();

        $this->assertStringNotContainsString('/admin/usuarios', $this->get('/gerente')->body);
    }

    // ---------- Usuários ----------

    public function testCriarGerenteComCondominiosMostraMensagemUmaVezEListaOUsuario(): void
    {
        $this->logarComoAdmin();
        $a = $this->criarCondominio('Residencial Sol');
        $b = $this->criarCondominio('Edifício Lua');

        $r = $this->post('/admin/usuarios', [
            'nome' => 'Maria Gerente', 'email' => 'Maria@Exemplo.com.br', 'papel' => 'gerente',
            'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1',
            'condominios' => [(string) $a, (string) $b],
        ]);

        $this->assertSame(303, $r->status);
        $this->assertSame('/admin/usuarios', $r->headers['Location']);

        $lista = $this->get('/admin/usuarios');
        $this->assertStringContainsString('Usuário criado com sucesso.', $lista->body);
        $this->assertStringContainsString('Maria Gerente', $lista->body);
        $this->assertStringContainsString('maria@exemplo.com.br', $lista->body);
        $this->assertStringNotContainsString('Usuário criado com sucesso.', $this->get('/admin/usuarios')->body, 'A mensagem aparece só uma vez.');

        $this->assertSame(2, (int) $this->pdo->query("SELECT count(*) FROM usuario_condominio uc JOIN usuarios u ON u.id = uc.usuario_id WHERE u.email = 'maria@exemplo.com.br'")->fetchColumn());
    }

    public function testNovoGerenteConsegueEntrarEVeApenasOPainelDeGerente(): void
    {
        $this->logarComoAdmin();
        $this->post('/admin/usuarios', [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente',
            'senha' => 'senha-bem-longa-1', 'confirmacao' => 'senha-bem-longa-1',
        ]);

        $_SESSION = [];
        $login = $this->logar('maria@exemplo.com.br', 'senha-bem-longa-1');

        $this->assertSame('/gerente', $login->headers['Location']);
        $this->assertSame(403, $this->get('/admin/usuarios')->status);
    }

    public function testCriarComErrosMostraMensagensEMantemOsDadosSemReexibirASenha(): void
    {
        $this->logarComoAdmin();
        $existente = $this->criarUsuario('ja@exemplo.com.br');

        $r = $this->post('/admin/usuarios', [
            'nome' => 'Fulano', 'email' => 'ja@exemplo.com.br', 'papel' => 'gerente',
            'senha' => 'curta', 'confirmacao' => 'curta',
        ]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Este e-mail já está cadastrado.', $r->body);
        $this->assertStringContainsString('no mínimo 10', $r->body);
        $this->assertStringContainsString('value="Fulano"', $r->body);
        $this->assertStringNotContainsString('value="curta"', $r->body);
        $this->assertStringContainsString('aria-invalid="true"', $r->body);
        $this->assertSame(2, $this->contar('usuarios'));
        $this->assertGreaterThan(0, $existente);
    }

    public function testNomeComScriptEEscapadoNaListaENoFormulario(): void
    {
        $admin = $this->logarComoAdmin();
        $id = $this->criarUsuario('x@exemplo.com.br');
        $this->pdo->exec("UPDATE usuarios SET nome = '<script>alert(1)</script>' WHERE id = {$id}");

        foreach (['/admin/usuarios', "/admin/usuarios/{$id}/editar", "/admin/usuarios/{$id}/excluir"] as $caminho) {
            $corpo = $this->get($caminho)->body;
            $this->assertStringNotContainsString('<script>alert(1)</script>', $corpo, $caminho);
            $this->assertStringContainsString('&lt;script&gt;', $corpo, $caminho);
        }
        $this->assertGreaterThan(0, $admin);
    }

    public function testFormularioDeEdicaoVemPreenchidoComVinculosENuncaComASenha(): void
    {
        $this->logarComoAdmin();
        $a = $this->criarCondominio('Residencial Sol');
        $this->criarCondominio('Edifício Lua');
        $g = $this->criarUsuario('maria@exemplo.com.br');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g}, {$a})");

        $r = $this->get("/admin/usuarios/{$g}/editar");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('value="maria@exemplo.com.br"', $r->body);
        $this->assertMatchesRegularExpression('/name="condominios\[\]" value="' . $a . '" checked/', $r->body);
        $this->assertDoesNotMatchRegularExpression('/name="condominios\[\]" value="\d+" checked[^>]*>\s*Edifício Lua/', $r->body);
        $this->assertDoesNotMatchRegularExpression('/type="password"[^>]*value=/', $r->body);
        $this->assertStringNotContainsString('argon2', $r->body, 'O hash da senha nunca vai para a página.');
    }

    public function testAtualizarUsuarioAlteraDadosEVinculos(): void
    {
        $this->logarComoAdmin();
        $a = $this->criarCondominio('A');
        $b = $this->criarCondominio('B');
        $g = $this->criarUsuario('maria@exemplo.com.br');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g}, {$a})");

        $r = $this->post("/admin/usuarios/{$g}", [
            'nome' => 'Maria Silva', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1',
            'condominios' => [(string) $b],
        ]);

        $this->assertSame(303, $r->status);
        $this->assertSame('Maria Silva', $this->pdo->query("SELECT nome FROM usuarios WHERE id = {$g}")->fetchColumn());
        $this->assertSame([$b], array_map('intval', $this->pdo->query("SELECT condominio_id FROM usuario_condominio WHERE usuario_id = {$g}")->fetchAll(\PDO::FETCH_COLUMN)));
        $this->assertStringContainsString('Usuário atualizado com sucesso.', $this->get('/admin/usuarios')->body);
    }

    public function testDesativarUsuarioBloqueiaOLoginEReativarLibera(): void
    {
        $this->logarComoAdmin();
        $g = $this->criarUsuario('maria@exemplo.com.br');
        $dados = ['nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente'];

        $this->post("/admin/usuarios/{$g}", $dados); // sem "ativo" = desativa
        $admin = $_SESSION;
        $_SESSION = [];
        $this->assertSame(401, $this->logar('maria@exemplo.com.br')->status);

        $_SESSION = $admin;
        $this->post("/admin/usuarios/{$g}", $dados + ['ativo' => '1']);
        $_SESSION = [];
        $this->assertSame(303, $this->logar('maria@exemplo.com.br')->status);
    }

    public function testRedefinirSenhaPeloAdminValeNoProximoLogin(): void
    {
        $this->logarComoAdmin();
        $g = $this->criarUsuario('maria@exemplo.com.br');

        $r = $this->post("/admin/usuarios/{$g}", [
            'nome' => 'Maria', 'email' => 'maria@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1',
            'senha' => 'nova-senha-do-admin-1', 'confirmacao' => 'nova-senha-do-admin-1',
        ]);
        $this->assertSame(303, $r->status);

        $_SESSION = [];
        $this->assertSame(401, $this->logar('maria@exemplo.com.br', self::SENHA)->status);
        $this->assertSame(303, $this->logar('maria@exemplo.com.br', 'nova-senha-do-admin-1')->status);
    }

    public function testAdminNaoSeTrancaParaForaPelaInterface(): void
    {
        $admin = $this->logarComoAdmin();

        $papel = $this->post("/admin/usuarios/{$admin}", ['nome' => 'Admin', 'email' => 'admin@exemplo.com.br', 'papel' => 'gerente', 'ativo' => '1']);
        $this->assertSame(422, $papel->status);
        $this->assertStringContainsString('próprio papel', $papel->body);

        $desativar = $this->post("/admin/usuarios/{$admin}", ['nome' => 'Admin', 'email' => 'admin@exemplo.com.br', 'papel' => 'superadmin']);
        $this->assertSame(422, $desativar->status);

        $excluir = $this->post("/admin/usuarios/{$admin}/excluir");
        $this->assertSame(422, $excluir->status);
        $this->assertStringContainsString('própria conta', $excluir->body);

        $this->assertSame(200, $this->get('/admin')->status, 'Continua logado como superadmin.');
        $this->assertSame('superadmin', $this->pdo->query("SELECT papel FROM usuarios WHERE id = {$admin}")->fetchColumn());
    }

    public function testFormularioDoProprioAdminNaoOfereAlterarPapelNemDesativar(): void
    {
        $admin = $this->logarComoAdmin();

        $corpo = $this->get("/admin/usuarios/{$admin}/editar")->body;

        $this->assertStringContainsString('Você não pode alterar o seu próprio papel', $corpo);
        $this->assertStringContainsString('Você não pode desativar a sua própria conta', $corpo);
    }

    public function testExcluirUsuarioPedeConfirmacaoERemoveVinculos(): void
    {
        $this->logarComoAdmin();
        $a = $this->criarCondominio();
        $g = $this->criarUsuario('maria@exemplo.com.br');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g}, {$a})");

        $confirmacao = $this->get("/admin/usuarios/{$g}/excluir");
        $this->assertSame(200, $confirmacao->status);
        $this->assertStringContainsString('Tem certeza', $confirmacao->body);
        $this->assertSame(2, $this->contar('usuarios'), 'Abrir a confirmação não exclui nada.');

        $r = $this->post("/admin/usuarios/{$g}/excluir");

        $this->assertSame(303, $r->status);
        $this->assertSame(1, $this->contar('usuarios'));
        $this->assertSame(0, $this->contar('usuario_condominio'));
        $this->assertSame(1, $this->contar('condominios'));
        $this->assertStringContainsString('Usuário excluído.', $this->get('/admin/usuarios')->body);
    }

    public function testIdInexistenteRetorna404EmTodasAsRotasComId(): void
    {
        $this->logarComoAdmin();

        foreach (['usuarios', 'condominios'] as $recurso) {
            foreach (['editar', 'excluir'] as $acao) {
                $this->assertSame(404, $this->get("/admin/{$recurso}/987654/{$acao}")->status, "GET {$recurso}/{$acao}");
            }
            $this->assertSame(404, $this->post("/admin/{$recurso}/987654", ['nome' => 'X', 'email' => 'x@exemplo.com.br', 'papel' => 'gerente'])->status);
            $this->assertSame(404, $this->post("/admin/{$recurso}/987654/excluir")->status);
        }
        $this->assertSame('', (string) file_get_contents($this->log), '404 não é registrado como erro.');
    }

    public function testIdQueNaoENumericoNaoCasaComARota(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(404, $this->get('/admin/usuarios/abc/editar')->status);
        $this->assertSame(404, $this->get('/admin/usuarios/1;DROP/editar')->status);
    }

    public function testListaVaziaMostraEstadoVazio(): void
    {
        $this->logarComoAdmin();

        $this->assertStringContainsString('Nenhum condomínio cadastrado', $this->get('/admin/condominios')->body);
    }

    // ---------- Condomínios ----------

    public function testCriarCondominioComGerentesEListar(): void
    {
        $this->logarComoAdmin();
        $g1 = $this->criarUsuario('g1@exemplo.com.br');
        $g2 = $this->criarUsuario('g2@exemplo.com.br');

        $r = $this->post('/admin/condominios', [
            'nome' => 'Residencial Sol', 'endereco' => 'Rua A, 10', 'ativo' => '1',
            'gerentes' => [(string) $g1, (string) $g2],
        ]);

        $this->assertSame(303, $r->status);
        $lista = $this->get('/admin/condominios');
        $this->assertStringContainsString('Condomínio criado com sucesso.', $lista->body);
        $this->assertStringContainsString('Residencial Sol', $lista->body);
        $this->assertStringNotContainsString('Aparência', $lista->body, 'Condomínio não tem mais aparência.');
        $this->assertSame(2, $this->contar('usuario_condominio'));
    }

    public function testCriarCondominioInvalidoMostraErros(): void
    {
        $this->logarComoAdmin();

        $r = $this->post('/admin/condominios', ['nome' => '', 'gerentes' => ['987654']]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Este campo é obrigatório.', $r->body);
        $this->assertStringContainsString('gerentes inválidos', $r->body);
        $this->assertSame(0, $this->contar('condominios'));
    }

    public function testEdicaoDoCondominioMostraOsVinculosESemAparencia(): void
    {
        $this->logarComoAdmin();
        $g = $this->criarUsuario('g@exemplo.com.br');
        $c = $this->criarCondominio('Residencial Sol');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g}, {$c})");

        $r = $this->get("/admin/condominios/{$c}/editar");

        $this->assertSame(200, $r->status);
        $this->assertMatchesRegularExpression('/name="gerentes\[\]" value="' . $g . '" checked/', $r->body);
        $this->assertStringNotContainsString('Aparência', $r->body);
        $this->assertStringNotContainsString('logo-embracore.svg', $r->body);
    }

    public function testAtualizarCondominioTrocaGerentesEDesativa(): void
    {
        $this->logarComoAdmin();
        $g1 = $this->criarUsuario('g1@exemplo.com.br');
        $g2 = $this->criarUsuario('g2@exemplo.com.br');
        $c = $this->criarCondominio('Antigo');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g1}, {$c})");

        $r = $this->post("/admin/condominios/{$c}", ['nome' => 'Novo Nome', 'endereco' => 'Av. B', 'gerentes' => [(string) $g2]]);

        $this->assertSame(303, $r->status);
        $linha = $this->pdo->query("SELECT nome, endereco, ativo FROM condominios WHERE id = {$c}")->fetch();
        $this->assertSame('Novo Nome', $linha['nome']);
        $this->assertFalse((bool) $linha['ativo']);
        $this->assertSame([$g2], array_map('intval', $this->pdo->query("SELECT usuario_id FROM usuario_condominio WHERE condominio_id = {$c}")->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testConfirmacaoDeExclusaoMostraOQueSeraApagadoEExcluiEmCascata(): void
    {
        $this->logarComoAdmin();
        $g = $this->criarUsuario('g@exemplo.com.br');
        $c = $this->criarCondominio('Residencial Sol');
        $outro = $this->criarCondominio('Outro');
        $this->pdo->exec("INSERT INTO usuario_condominio VALUES ({$g}, {$c})");
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, inicio_em, fim_em) VALUES ({$c}, 'a', now(), now() + interval '1 day'), ({$c}, 'b', now(), now() + interval '1 day'), ({$outro}, 'c', now(), now() + interval '1 day')");
        $this->pdo->exec("INSERT INTO dispositivos (condominio_id, nome, token) VALUES ({$c}, 'TV', 'AAAAAAAAAAAA')");

        $confirmacao = $this->get("/admin/condominios/{$c}/excluir");
        $this->assertStringContainsString('2 avisos', $confirmacao->body);
        $this->assertStringContainsString('1 dispositivo', $confirmacao->body);
        $this->assertStringContainsString('1 vínculo com gerente', $confirmacao->body);
        $this->assertSame(2, $this->contar('condominios'), 'Abrir a confirmação não exclui nada.');

        $r = $this->post("/admin/condominios/{$c}/excluir");

        $this->assertSame(303, $r->status);
        $this->assertSame(1, $this->contar('condominios'));
        $this->assertSame(1, $this->contar('avisos'), 'Só os avisos do condomínio excluído somem.');
        $this->assertSame(0, $this->contar('dispositivos'));
        $this->assertSame(0, $this->contar('usuario_condominio'));
        $this->assertSame(2, $this->contar('usuarios'), 'As contas continuam.');
        $this->assertStringContainsString('Condomínio excluído.', $this->get('/admin/condominios')->body);
    }
}
