<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\DispositivoRepository;
use Tests\Support\AvisosDeTeste;

/** Painel /admin/dispositivos: autorização por papel em todas as rotas, CRUD, token e revogação. */
final class AdminDispositivosTest extends AppHttpTestCase
{
    use AvisosDeTeste;

    private const TOKEN = 'ABCDEFGH2345';

    private int $condominio;
    private int $dispositivo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->condominio = $this->criarCondominio('Residencial Sol');
        $this->dispositivo = $this->dispositivoTv($this->pdo, $this->condominio, self::TOKEN, 'Elevador social');
    }

    /** @return array{get: list<string>, post: list<string>} */
    private function rotas(): array
    {
        $d = $this->dispositivo;

        return [
            'get' => ['/admin/dispositivos', '/admin/dispositivos/novo', "/admin/dispositivos/{$d}", "/admin/dispositivos/{$d}/editar", "/admin/dispositivos/{$d}/token", "/admin/dispositivos/{$d}/parear", "/admin/dispositivos/{$d}/excluir"],
            'post' => ['/admin/dispositivos', "/admin/dispositivos/{$d}", "/admin/dispositivos/{$d}/token", "/admin/dispositivos/{$d}/parear", "/admin/dispositivos/{$d}/orientacao", "/admin/dispositivos/{$d}/excluir"],
        ];
    }

    /** @return array<string, mixed> */
    private function fotografia(): array
    {
        return [
            'dispositivos' => $this->pdo->query("SELECT string_agg(id || '|' || condominio_id || '|' || nome || '|' || token || '|' || ativo::text, ';' ORDER BY id) FROM dispositivos")->fetchColumn(),
            'total' => $this->contar('dispositivos'),
        ];
    }

    private function tokenAtual(): string
    {
        return (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$this->dispositivo}")->fetchColumn();
    }

    // ---------- Autorização ----------

    public function testVisitanteAnonimoNaoAcessaNenhumaRota(): void
    {
        $antes = $this->fotografia();

        foreach ($this->rotas()['get'] as $rota) {
            $r = $this->get($rota);
            $this->assertSame(302, $r->status, "GET {$rota}");
            $this->assertSame('/login', $r->headers['Location'], "GET {$rota}");
        }
        foreach ($this->rotas()['post'] as $rota) {
            $r = $this->post($rota, ['nome' => 'Invasor', 'condominio_id' => (string) $this->condominio]);
            $this->assertSame(302, $r->status, "POST {$rota}");
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testGerenteRecebe403EmTodasAsRotasESemAlterarNada(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br');
        $this->logar();
        $antes = $this->fotografia();

        foreach ($this->rotas()['get'] as $rota) {
            $this->assertSame(403, $this->get($rota)->status, "GET {$rota}");
        }
        foreach ($this->rotas()['post'] as $rota) {
            $r = $this->post($rota, ['nome' => 'Invasor', 'condominio_id' => (string) $this->condominio, 'ativo' => '1']);
            $this->assertSame(403, $r->status, "POST {$rota}");
            $this->assertStringNotContainsString(self::TOKEN, $r->body, "{$rota} não pode mostrar o token");
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testGerenteNuncaVeOTokenDeNenhumaTv(): void
    {
        $this->criarUsuario('gerente@exemplo.com.br');
        $this->logar();

        foreach (['/gerente', '/admin/dispositivos', "/admin/dispositivos/{$this->dispositivo}"] as $rota) {
            $this->assertStringNotContainsString(self::TOKEN, $this->get($rota)->body, $rota);
        }
    }

    public function testEscritasSemCsrfSaoBloqueadas(): void
    {
        $this->logarComoAdmin();
        $antes = $this->fotografia();

        foreach ($this->rotas()['post'] as $rota) {
            $this->assertSame(403, $this->post($rota, ['nome' => 'x', 'condominio_id' => (string) $this->condominio], comCsrf: false)->status, $rota);
        }

        $this->assertSame($antes, $this->fotografia());
    }

    public function testSuperadminAcessaAsPaginasDeLeituraSemStyleOuScriptInline(): void
    {
        $this->logarComoAdmin();

        foreach ($this->rotas()['get'] as $rota) {
            $r = $this->get($rota);
            $this->assertSame(200, $r->status, $rota);
            $this->assertStringNotContainsString(' style=', $r->body, $rota);
            $this->assertStringNotContainsString('<script', $r->body, $rota);
        }
    }

    // ---------- Fluxos ----------

    public function testMenuTemOLinkDeDispositivos(): void
    {
        $this->logarComoAdmin();

        $this->assertMatchesRegularExpression('#<a href="/admin/dispositivos" class="ativo" aria-current="page">.*?Dispositivos</a>#s', $this->get('/admin/dispositivos')->body);
        $this->assertMatchesRegularExpression('#<a href="/admin/dispositivos">.*?Dispositivos</a>#s', $this->get('/admin')->body);
    }

    public function testListaMostraDispositivoCondominioESituacao(): void
    {
        $this->logarComoAdmin();

        $corpo = $this->get('/admin/dispositivos')->body;

        $this->assertStringContainsString('Elevador social', $corpo);
        $this->assertStringContainsString('Residencial Sol', $corpo);
        $this->assertStringContainsString('nunca', $corpo, 'Ainda não conectou.');
        $this->assertStringContainsString('etiqueta-disp-nunca">Nunca conectou<', $corpo);
        $this->assertStringNotContainsString(self::TOKEN, $corpo, 'O token só aparece na página do dispositivo.');
    }

    public function testListaVaziaMostraEstadoVazio(): void
    {
        $this->pdo->exec('DELETE FROM dispositivos');
        $this->logarComoAdmin();

        $this->assertStringContainsString('Nenhum dispositivo cadastrado', $this->get('/admin/dispositivos')->body);
    }

    public function testUltimoContatoAparecePassadoOHeartbeat(): void
    {
        $this->logarComoAdmin();
        $admin = $_SESSION;
        $_SESSION = [];
        $this->get('/tv/' . self::TOKEN . '/state');
        $_SESSION = $admin;

        $this->assertStringContainsString('agora há pouco', $this->get('/admin/dispositivos')->body);
        $this->assertStringContainsString('Último contato: agora há pouco', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
    }

    public function testCriarDispositivoGeraTokenEMostraOEnderecoDaTv(): void
    {
        $this->logarComoAdmin();

        $r = $this->post('/admin/dispositivos', ['nome' => 'Portaria', 'condominio_id' => (string) $this->condominio]);

        $this->assertSame(303, $r->status);
        $novo = (int) $this->pdo->query('SELECT max(id) FROM dispositivos')->fetchColumn();
        $this->assertSame("/admin/dispositivos/{$novo}", $r->headers['Location']);
        $token = (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$novo}")->fetchColumn();
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{12}$/', $token);

        $detalhe = $this->get($r->headers['Location']);
        $this->assertStringContainsString('Dispositivo criado.', $detalhe->body);
        $this->assertStringContainsString("https://avisos.exemplo.com.br/tv/{$token}", $detalhe->body);
        $this->assertStringContainsString($token, $detalhe->body);
        $this->assertStringContainsString('Como configurar a TV', $detalhe->body);
        $this->assertSame(200, $this->get("/tv/{$token}/state", '198.51.100.7')->status, 'O endereço gerado já funciona.');
    }

    public function testCriarComErrosMostraMensagensENaoCria(): void
    {
        $this->logarComoAdmin();

        $semNome = $this->post('/admin/dispositivos', ['nome' => '', 'condominio_id' => (string) $this->condominio]);
        $semCondominio = $this->post('/admin/dispositivos', ['nome' => 'TV', 'condominio_id' => '']);
        $inexistente = $this->post('/admin/dispositivos', ['nome' => 'TV', 'condominio_id' => '987654']);

        $this->assertSame(422, $semNome->status);
        $this->assertStringContainsString('Este campo é obrigatório.', $semNome->body);
        $this->assertSame(422, $semCondominio->status);
        $this->assertStringContainsString('Escolha o condomínio onde a TV será instalada.', $semCondominio->body);
        $this->assertSame(422, $inexistente->status);
        $this->assertStringContainsString('Escolha um condomínio válido.', $inexistente->body);
        $this->assertSame(1, $this->contar('dispositivos'));
    }

    public function testFormularioNovoPodeVirPreSelecionadoPeloCondominio(): void
    {
        $this->logarComoAdmin();

        $corpo = $this->get("/admin/dispositivos/novo?condominio={$this->condominio}")->body;

        $this->assertMatchesRegularExpression('/<option value="' . $this->condominio . '" selected>/', $corpo);
    }

    public function testEditarMoveDeCondominioERenomeia(): void
    {
        $this->logarComoAdmin();
        $outro = $this->criarCondominio('Outro');

        $form = $this->get("/admin/dispositivos/{$this->dispositivo}/editar");
        $this->assertStringContainsString('value="Elevador social"', $form->body);
        $this->assertMatchesRegularExpression('/name="ativo" value="1" checked/', $form->body);

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}", ['nome' => 'Portaria', 'condominio_id' => (string) $outro, 'ativo' => '1']);

        $this->assertSame(303, $r->status);
        $d = (new DispositivoRepository($this->pdo))->porId($this->dispositivo);
        $this->assertSame('Portaria', $d['nome']);
        $this->assertSame($outro, $d['condominio_id']);
        $this->assertSame(self::TOKEN, $d['token'], 'Editar não troca o token.');
        $this->assertStringContainsString('Dispositivo atualizado com sucesso.', $this->get('/admin/dispositivos')->body);
    }

    public function testRevogarCortaATvEReativarVolta(): void
    {
        $this->logarComoAdmin();
        $admin = $_SESSION;
        $dados = ['nome' => 'Elevador social', 'condominio_id' => (string) $this->condominio];

        $this->post("/admin/dispositivos/{$this->dispositivo}", $dados); // sem "ativo" = revoga
        $_SESSION = [];
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.7')->status);

        $_SESSION = $admin;
        $this->assertStringContainsString('Revogado', $this->get('/admin/dispositivos')->body);
        $this->assertStringContainsString('Este dispositivo está revogado', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
        $this->post("/admin/dispositivos/{$this->dispositivo}", $dados + ['ativo' => '1']);
        $_SESSION = [];
        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.7')->status);
    }

    public function testTrocaEExclusaoDeDispositivoVaoParaAAuditoriaSemOToken(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/token");
        $novoToken = $this->tokenAtual();
        $this->post("/admin/dispositivos/{$this->dispositivo}/excluir");

        $linhas = $this->pdo->query("SELECT acao, detalhes::text AS d, condominio_nome FROM auditoria WHERE acao LIKE 'dispositivo.%' ORDER BY id")->fetchAll();
        $this->assertSame(['dispositivo.token_trocado', 'dispositivo.excluido'], array_column($linhas, 'acao'));
        $this->assertSame('Residencial Sol', $linhas[0]['condominio_nome']);
        $tudo = json_encode($linhas, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::TOKEN, $tudo);
        $this->assertStringNotContainsString($novoToken, $tudo);
    }

    public function testTrocarOTokenPedeConfirmacaoEInvalidaOEnderecoAntigo(): void
    {
        $this->logarComoAdmin();
        $admin = $_SESSION;

        $confirmacao = $this->get("/admin/dispositivos/{$this->dispositivo}/token");
        $this->assertStringContainsString('deixa de funcionar na hora', $confirmacao->body);
        $this->assertSame(self::TOKEN, $this->tokenAtual(), 'Abrir a confirmação não troca nada.');

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/token");

        $this->assertSame(303, $r->status);
        $novo = $this->tokenAtual();
        $this->assertNotSame(self::TOKEN, $novo);
        $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{12}$/', $novo);
        $detalhe = $this->get("/admin/dispositivos/{$this->dispositivo}");
        $this->assertStringContainsString('Token trocado.', $detalhe->body);
        $this->assertStringContainsString("/tv/{$novo}", $detalhe->body);
        $this->assertStringNotContainsString(self::TOKEN, $detalhe->body);

        $_SESSION = [];
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.7')->status, 'O token antigo morreu.');
        $this->assertSame(200, $this->get("/tv/{$novo}/state", '198.51.100.7')->status);
        $_SESSION = $admin;
    }

    public function testExcluirPedeConfirmacaoERemoveOAcessoDaTv(): void
    {
        $this->logarComoAdmin();
        $admin = $_SESSION;

        $this->assertStringContainsString('Tem certeza', $this->get("/admin/dispositivos/{$this->dispositivo}/excluir")->body);
        $this->assertSame(1, $this->contar('dispositivos'));

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/excluir");

        $this->assertSame(303, $r->status);
        $this->assertSame(0, $this->contar('dispositivos'));
        $this->assertStringContainsString('Dispositivo excluído.', $this->get('/admin/dispositivos')->body);
        $_SESSION = [];
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.7')->status);
        $_SESSION = $admin;
    }

    public function testIdInexistenteRetorna404(): void
    {
        $this->logarComoAdmin();

        foreach (['', '/editar', '/token', '/excluir'] as $sufixo) {
            $this->assertSame(404, $this->get("/admin/dispositivos/987654{$sufixo}")->status, "GET {$sufixo}");
        }
        foreach (['', '/token', '/excluir'] as $sufixo) {
            $this->assertSame(404, $this->post("/admin/dispositivos/987654{$sufixo}", ['nome' => 'x', 'condominio_id' => (string) $this->condominio])->status, "POST {$sufixo}");
        }
    }

    public function testNomeComScriptSaiEscapado(): void
    {
        $this->logarComoAdmin();
        $this->pdo->exec("UPDATE dispositivos SET nome = '<script>alert(1)</script>' WHERE id = {$this->dispositivo}");

        foreach (['', '/editar', '/token', '/excluir'] as $sufixo) {
            $corpo = $this->get("/admin/dispositivos/{$this->dispositivo}{$sufixo}")->body;
            $this->assertStringNotContainsString('<script>alert(1)</script>', $corpo, $sufixo);
        }
        $this->assertStringNotContainsString('<script>alert(1)</script>', $this->get('/admin/dispositivos')->body);
    }

    public function testExcluirOCondominioRemoveSeusDispositivosEDerrubaATv(): void
    {
        $this->logarComoAdmin();
        $confirmacao = $this->get("/admin/condominios/{$this->condominio}/excluir")->body;
        $this->assertStringContainsString('1 dispositivo', $confirmacao);

        $this->post("/admin/condominios/{$this->condominio}/excluir");

        $this->assertSame(0, $this->contar('dispositivos'));
        $_SESSION = [];
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.7')->status);
    }

    // ---------- Situação online/offline (Fase 2, 2.1) ----------

    private function pingHa(int $segundos, ?int $id = null): void
    {
        $id ??= $this->dispositivo;
        $this->pdo->exec("UPDATE dispositivos SET ultimo_ping = now() - interval '{$segundos} seconds' WHERE id = {$id}");
    }

    public function testListaMostraOnlineOfflineENuncaConectouComContagem(): void
    {
        $segundo = $this->dispositivoTv($this->pdo, $this->condominio, 'JKMNPQRS6789', 'Portaria');
        $this->dispositivoTv($this->pdo, $this->condominio, 'TUVWXYZ23456', 'Salão');
        $this->pingHa(30);
        $this->pingHa(600, $segundo);
        $this->logarComoAdmin();

        $corpo = $this->get('/admin/dispositivos')->body;

        $this->assertStringContainsString('etiqueta-disp-online">Online<', $corpo);
        $this->assertStringContainsString('etiqueta-disp-offline">Offline<', $corpo);
        $this->assertStringContainsString('etiqueta-disp-nunca">Nunca conectou<', $corpo);
        $this->assertStringContainsString('Todos <strong>3</strong>', $corpo);
        $this->assertStringContainsString('Offline <strong>1</strong>', $corpo);
    }

    public function testFiltroPorSituacaoMostraSoAquelaSituacao(): void
    {
        $segundo = $this->dispositivoTv($this->pdo, $this->condominio, 'JKMNPQRS6789', 'Portaria');
        $this->pingHa(30);
        $this->pingHa(600, $segundo);
        $this->logarComoAdmin();

        $offline = $this->get('/admin/dispositivos?situacao=offline')->body;
        $this->assertStringContainsString('Portaria', $offline);
        $this->assertStringNotContainsString('Elevador social', $offline);

        $this->assertStringContainsString('Elevador social', $this->get('/admin/dispositivos?situacao=online')->body);
        $this->assertStringContainsString('Nenhum dispositivo nesta situação', $this->get('/admin/dispositivos?situacao=revogado')->body);
        $lixo = $this->get('/admin/dispositivos?situacao=' . urlencode("x' OR 1=1 --"));
        $this->assertSame(200, $lixo->status);
        $this->assertStringContainsString('Elevador social', $lixo->body, 'Filtro desconhecido é ignorado.');
        $this->assertStringContainsString('Portaria', $lixo->body);
    }

    public function testLimiteDeTresMinutosSeparaOnlineDeOffline(): void
    {
        $this->logarComoAdmin();

        $this->pingHa(170);
        $this->assertStringContainsString('etiqueta-disp-online', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);

        $this->pingHa(200);
        $this->assertStringContainsString('etiqueta-disp-offline', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
    }

    public function testDispositivoRevogadoNaoContaComoOffline(): void
    {
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$this->dispositivo}");
        $this->pingHa(9000);
        $this->logarComoAdmin();

        $this->assertStringContainsString('etiqueta-disp-revogado">Revogado<', $this->get('/admin/dispositivos')->body);
        $this->assertStringNotContainsString('offline)', $this->get('/admin')->body);
    }

    public function testPaginaInicialDoAdminMostraTvsOnlineEOffline(): void
    {
        $segundo = $this->dispositivoTv($this->pdo, $this->condominio, 'JKMNPQRS6789', 'Portaria');
        $this->pingHa(20);
        $this->pingHa(900, $segundo);
        $this->logarComoAdmin();

        $corpo = $this->get('/admin')->body;

        $this->assertStringContainsString('1/2', $corpo);
        $this->assertStringContainsString('TVs online (1 offline)', $corpo);
        $this->assertStringContainsString('href="/admin/dispositivos?situacao=offline"', $corpo);
    }

    public function testDetalheMostraHistoricoDeQuedasEMesmoAssimGerenteNaoVe(): void
    {
        $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo, ultimo_ping_em, registrado_em) VALUES ({$this->dispositivo}, 'offline', now() - interval '2 hours', now() - interval '110 minutes'), ({$this->dispositivo}, 'online', now(), now())");
        $this->logarComoAdmin();

        $corpo = $this->get("/admin/dispositivos/{$this->dispositivo}")->body;

        $this->assertStringContainsString('Histórico de conexão', $corpo);
        $this->assertStringContainsString('Ficou offline', $corpo);
        $this->assertStringContainsString('Voltou', $corpo);
        $this->assertStringContainsString('último contato às', $corpo);
    }

    public function testHistoricoDoAdminTemCincoPorPaginaEAsMesmasSecoesDoGerente(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo, registrado_em) VALUES ({$this->dispositivo}, 'offline', now() - interval '{$i} hours')");
        }
        $this->logarComoAdmin();

        $um = $this->get("/admin/dispositivos/{$this->dispositivo}")->body;
        $dois = $this->get("/admin/dispositivos/{$this->dispositivo}?pagina=2")->body;

        $this->assertSame(5, substr_count($um, 'Ficou offline'));
        $this->assertSame(2, substr_count($dois, 'Ficou offline'));
        $this->assertStringContainsString('Página 2 de 2', $dois);
        $this->assertStringContainsString("/admin/dispositivos/{$this->dispositivo}?pagina=2", $um);
        $posicoes = array_map(static fn (string $t): int => (int) strpos($um, $t), ['Endereço da TV', '<h2>Orientação da tela</h2>', '<h2>Situação</h2>', 'Histórico de conexão', 'Como configurar a TV']);
        $ordenadas = $posicoes;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posicoes);
        $this->assertStringContainsString('Parear TV', $um, 'O admin tem o botão de parear.');
        $this->assertSame(200, $this->get("/admin/dispositivos/{$this->dispositivo}?pagina=abc")->status);
    }

    private function orientacaoNoBanco(int $id): string
    {
        return (string) $this->pdo->query("SELECT orientacao FROM dispositivos WHERE id = {$id}")->fetchColumn();
    }

    public function testAdminAlternaAOrientacaoDeCadaDispositivoDeFormaIndependente(): void
    {
        $outro = $this->dispositivoTv($this->pdo, $this->condominio, 'ZZZZ2222XXXX', 'Garagem');
        $this->logarComoAdmin();

        $form = $this->get("/admin/dispositivos/{$this->dispositivo}")->body;
        $this->assertStringContainsString('Orientação da tela', $form);
        $this->assertStringContainsString('<input type="radio" name="orientacao" value="horizontal" checked>', $form);
        $this->assertStringContainsString('<input type="radio" name="orientacao" value="vertical">', $form);

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/orientacao", ['orientacao' => 'vertical']);

        $this->assertSame(303, $r->status);
        $this->assertSame("/admin/dispositivos/{$this->dispositivo}", $r->headers['Location']);
        $this->assertSame('vertical', $this->orientacaoNoBanco($this->dispositivo));
        $this->assertSame('horizontal', $this->orientacaoNoBanco($outro), 'O outro dispositivo não muda.');
        $this->assertStringContainsString('<input type="radio" name="orientacao" value="vertical" checked>', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state')->status);
        $this->assertSame('vertical', json_decode($this->get('/tv/' . self::TOKEN . '/state')->body, true)['orientacao']);

        $this->post("/admin/dispositivos/{$this->dispositivo}/orientacao", ['orientacao' => 'horizontal']);
        $this->assertSame('horizontal', $this->orientacaoNoBanco($this->dispositivo));
    }

    public function testOrientacaoInvalidaOuAusenteNaoAlteraNadaENaoAudita(): void
    {
        $this->logarComoAdmin();

        foreach (['diagonal', '', "x'; DROP TABLE dispositivos;--"] as $valor) {
            $r = $this->post("/admin/dispositivos/{$this->dispositivo}/orientacao", ['orientacao' => $valor]);
            $this->assertSame(303, $r->status, $valor);
        }
        $this->assertSame(303, $this->post("/admin/dispositivos/{$this->dispositivo}/orientacao")->status);

        $this->assertSame('horizontal', $this->orientacaoNoBanco($this->dispositivo));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'dispositivo.orientacao_alterada'")->fetchColumn());
    }

    public function testOrientacaoDeDispositivoInexistenteDa404(): void
    {
        $this->logarComoAdmin();

        $this->assertSame(404, $this->post('/admin/dispositivos/999999/orientacao', ['orientacao' => 'vertical'])->status);
    }

    public function testMudarAOrientacaoVaiParaAAuditoria(): void
    {
        $admin = $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/orientacao", ['orientacao' => 'vertical']);

        $l = $this->pdo->query("SELECT usuario_id, entidade_id, condominio_nome, detalhes::text AS d FROM auditoria WHERE acao = 'dispositivo.orientacao_alterada'")->fetch();

        $this->assertSame($admin, (int) $l['usuario_id']);
        $this->assertSame($this->dispositivo, (int) $l['entidade_id']);
        $this->assertSame('Residencial Sol', $l['condominio_nome']);
        $this->assertStringContainsString('"orientacao": "vertical"', $l['d']);
        $this->assertStringNotContainsString(self::TOKEN, $l['d']);
    }

    public function testDetalheSemQuedasDizNenhumaQueda(): void
    {
        $this->logarComoAdmin();

        $this->assertStringContainsString('Nenhuma queda registrada.', $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
    }
}
