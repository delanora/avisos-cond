<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;
use Tests\Support\AvisosDeTeste;

/** Dispositivos (TVs) vistos pelo gerente: só os dos condomínios dele e só consulta. A lista não mostra o token; o detalhe mostra (para configurar a TV). */
final class GerenteDispositivosTest extends AppHttpTestCase
{
    use AvisosDeTeste;

    private const TOKEN_A = 'ABCDEFGH2345';
    private const TOKEN_A2 = 'JKMNPQRS6789';
    private const TOKEN_B = 'TUVWXYZ23456';

    private int $gerenteA;
    private int $condA;
    private int $condA2;
    private int $condB;
    private int $tvA;
    private int $tvA2;
    private int $tvB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gerenteA = $this->criarUsuario('gerente.a@exemplo.com.br');
        $this->condA = $this->criarCondominio('Residencial Alfa');
        $this->condA2 = $this->criarCondominio('Edifício Alfa Dois');
        $this->condB = $this->criarCondominio('Condomínio Beta');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, [$this->condA, $this->condA2]);
        $this->tvA = $this->dispositivoTv($this->pdo, $this->condA, self::TOKEN_A, 'Elevador social');
        $this->tvA2 = $this->dispositivoTv($this->pdo, $this->condA2, self::TOKEN_A2, 'Portaria');
        $this->tvB = $this->dispositivoTv($this->pdo, $this->condB, self::TOKEN_B, 'TV Secreta do Beta');
        $this->logar('gerente.a@exemplo.com.br');
    }

    private function pingHa(int $id, int $segundos): void
    {
        $this->pdo->exec("UPDATE dispositivos SET ultimo_ping = now() - interval '{$segundos} seconds' WHERE id = {$id}");
    }

    public function testVisitanteVaiParaOLoginEAdminRecebe403(): void
    {
        $_SESSION = [];
        $this->assertSame('/login', $this->get('/gerente/dispositivos')->headers['Location']);
        $this->assertSame('/login', $this->get("/gerente/dispositivos/{$this->tvA}")->headers['Location']);

        $this->logarComoAdmin();
        $this->assertSame(403, $this->get('/gerente/dispositivos')->status);
        $this->assertSame(403, $this->get("/gerente/dispositivos/{$this->tvA}")->status);
    }

    public function testListaMostraSoOsDispositivosDosCondominiosDoGerenteComASituacao(): void
    {
        $this->pingHa($this->tvA, 20);
        $this->pingHa($this->tvA2, 900);

        $r = $this->get('/gerente/dispositivos');

        $this->assertSame(200, $r->status);
        foreach (['Elevador social', 'Residencial Alfa', 'Portaria', 'Edifício Alfa Dois'] as $texto) {
            $this->assertStringContainsString($texto, $r->body);
        }
        $this->assertStringContainsString('etiqueta-disp-online">Online<', $r->body);
        $this->assertStringContainsString('etiqueta-disp-offline">Offline<', $r->body);
        $this->assertStringContainsString('Todos <strong>2</strong>', $r->body);
        $this->assertStringNotContainsString('TV Secreta do Beta', $r->body);
        $this->assertStringNotContainsString('Condomínio Beta', $r->body);
    }

    public function testListaNuncaTemTokenEnderecoNemAcoesDeAdministracao(): void
    {
        $corpo = $this->get('/gerente/dispositivos')->body;

        foreach ([self::TOKEN_A, self::TOKEN_A2, self::TOKEN_B, strtolower(self::TOKEN_A), '/tv/', '/editar', '/excluir', '/token', 'Novo dispositivo', 'Trocar token'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $corpo, "A lista do gerente não tem '{$proibido}'.");
        }
    }

    public function testDetalheMostraEnderecoETokenSoDoProprioDispositivoESemAcoesDeAdministracao(): void
    {
        $corpo = $this->get("/gerente/dispositivos/{$this->tvA}")->body;

        $this->assertStringContainsString('https://avisos.exemplo.com.br/tv/' . self::TOKEN_A, $corpo);
        $this->assertStringContainsString('<strong class="token-destaque">' . self::TOKEN_A . '</strong>', $corpo);
        $this->assertStringContainsString('Como configurar a TV', $corpo);
        $this->assertStringContainsString('https://avisos.exemplo.com.br/tv</code>', $corpo);
        foreach ([self::TOKEN_A2, self::TOKEN_B, '/editar', '/excluir', "/dispositivos/{$this->tvA}/token", 'Trocar token', 'Novo dispositivo'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $corpo, "O gerente não vê '{$proibido}'.");
        }
        $this->assertStringContainsString("href=\"/gerente/dispositivos/{$this->tvA}/parear\"", $corpo, 'O gerente pareia as TVs dele.');
    }

    public function testBotaoAbrirATelaFicaNaMesmaLinhaDoToken(): void
    {
        $corpo = $this->get("/gerente/dispositivos/{$this->tvA}")->body;

        $this->assertMatchesRegularExpression('#<div class="token-linha">\s*<span class="campo-ajuda">Token: <strong[^>]*>' . self::TOKEN_A . '</strong></span>\s*<a [^>]*>[^<]*<svg.*?Abrir a tela da TV</a>#s', $corpo);
    }

    public function testDetalheDeOutroCondominioContinua404SemVazarOToken(): void
    {
        $r = $this->get("/gerente/dispositivos/{$this->tvB}");

        $this->assertSame(404, $r->status);
        $this->assertStringNotContainsString(self::TOKEN_B, $r->body);
    }

    public function testSecoesNaOrdemEnderecoSituacaoHistoricoConfiguracao(): void
    {
        $corpo = $this->get("/gerente/dispositivos/{$this->tvA}")->body;

        $posicoes = array_map(static fn (string $t): int => (int) strpos($corpo, $t), ['Endereço da TV', '<h2>Orientação da tela</h2>', '<h2>Situação</h2>', 'Histórico de conexão', 'Como configurar a TV']);
        $ordenadas = $posicoes;
        sort($ordenadas);
        $this->assertSame($ordenadas, $posicoes);
        $this->assertNotContains(0, $posicoes);
    }

    private function eventos(int $dispositivo, int $quantidade): void
    {
        for ($i = 1; $i <= $quantidade; $i++) {
            $tipo = $i % 2 === 1 ? 'offline' : 'online';
            $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo, registrado_em) VALUES ({$dispositivo}, '{$tipo}', now() - interval '{$i} hours')");
        }
    }

    public function testHistoricoMostraCincoPorPaginaEPaginaOResto(): void
    {
        $this->eventos($this->tvA, 7);

        $um = $this->get("/gerente/dispositivos/{$this->tvA}")->body;
        $dois = $this->get("/gerente/dispositivos/{$this->tvA}?pagina=2")->body;

        $this->assertSame(5, substr_count($um, 'class="etiqueta etiqueta-disp-offline">Ficou') + substr_count($um, 'class="etiqueta etiqueta-disp-online">Voltou'));
        $this->assertSame(2, substr_count($dois, 'class="etiqueta etiqueta-disp-offline">Ficou') + substr_count($dois, 'class="etiqueta etiqueta-disp-online">Voltou'));
        $this->assertStringContainsString('Página 1 de 2', $um);
        $this->assertStringContainsString('?pagina=2', $um);
        $this->assertStringNotContainsString('Mais recentes', $um);
        $this->assertStringContainsString('Página 2 de 2', $dois);
        $this->assertStringContainsString('Mais recentes', $dois);
        $this->assertStringNotContainsString('Mais antigos', $dois);
    }

    public function testHistoricoComCincoOuMenosNaoTemPaginacao(): void
    {
        $this->eventos($this->tvA, 5);

        $this->assertStringNotContainsString('class="paginacao"', $this->get("/gerente/dispositivos/{$this->tvA}")->body);
    }

    public function testPaginaInvalidaOuForaDoLimiteNaoQuebra(): void
    {
        $this->eventos($this->tvA, 7);

        $alta = $this->get("/gerente/dispositivos/{$this->tvA}?pagina=999");
        $this->assertSame(200, $alta->status);
        $this->assertStringContainsString('Página 2 de 2', $alta->body);
        foreach (['0', '-3', 'abc', "1'; DROP TABLE dispositivos;--"] as $lixo) {
            $r = $this->get("/gerente/dispositivos/{$this->tvA}?pagina=" . urlencode($lixo));
            $this->assertSame(200, $r->status, $lixo);
            $this->assertStringContainsString('Página 1 de 2', $r->body, $lixo);
        }
    }

    public function testHistoricoNaoMisturaEventosDeOutroDispositivo(): void
    {
        $this->eventos($this->tvA2, 3);

        $this->assertStringContainsString('Nenhuma queda registrada.', $this->get("/gerente/dispositivos/{$this->tvA}")->body);
    }

    public function testFiltroPorSituacaoEIgnoraValorDesconhecido(): void
    {
        $this->pingHa($this->tvA, 20);
        $this->pingHa($this->tvA2, 900);

        $offline = $this->get('/gerente/dispositivos?situacao=offline')->body;
        $this->assertStringContainsString('Portaria', $offline);
        $this->assertStringNotContainsString('Elevador social', $offline);
        $this->assertStringContainsString('Nenhum dispositivo nesta situação', $this->get('/gerente/dispositivos?situacao=revogado')->body);
        $lixo = $this->get('/gerente/dispositivos?situacao=' . urlencode("x' OR 1=1 --"));
        $this->assertSame(200, $lixo->status);
        $this->assertStringContainsString('Elevador social', $lixo->body);
        $this->assertStringNotContainsString('TV Secreta do Beta', $lixo->body);
    }

    public function testGerenteSemDispositivosVeEstadoVazioAmigavel(): void
    {
        $this->pdo->exec('DELETE FROM dispositivos');

        $corpo = $this->get('/gerente/dispositivos')->body;

        $this->assertStringContainsString('Nenhum dispositivo por aqui ainda', $corpo);
        $this->assertStringContainsString('equipe da Embracore', $corpo);
    }

    public function testDetalheMostraSituacaoEHistoricoDeQuedas(): void
    {
        $this->pingHa($this->tvA, 20);
        $this->pdo->exec("INSERT INTO dispositivo_eventos (dispositivo_id, tipo, ultimo_ping_em, registrado_em) VALUES ({$this->tvA}, 'offline', now() - interval '2 hours', now() - interval '110 minutes'), ({$this->tvA}, 'online', now(), now())");

        $r = $this->get("/gerente/dispositivos/{$this->tvA}");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Elevador social', $r->body);
        $this->assertStringContainsString('Residencial Alfa', $r->body);
        $this->assertStringContainsString('etiqueta-disp-online">Online<', $r->body);
        $this->assertStringContainsString('Ficou offline', $r->body);
        $this->assertStringContainsString('Voltou', $r->body);
        $this->assertStringContainsString('último contato às', $r->body);
    }

    public function testDetalheSemQuedasEDispositivoRevogado(): void
    {
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$this->tvA}");

        $corpo = $this->get("/gerente/dispositivos/{$this->tvA}")->body;

        $this->assertStringContainsString('Nenhuma queda registrada.', $corpo);
        $this->assertStringContainsString('etiqueta-disp-revogado">Revogado<', $corpo);
        $this->assertStringContainsString('Este dispositivo está revogado', $corpo);
    }

    // ---------- Isolamento: gerente A × condomínio B ----------

    public function testDispositivoDeOutroCondominioEh404ESemVazarNada(): void
    {
        foreach ([$this->tvB, 999999] as $id) {
            $r = $this->get("/gerente/dispositivos/{$id}");
            $this->assertSame(404, $r->status, "id {$id}");
            $this->assertStringNotContainsString('TV Secreta do Beta', $r->body);
            $this->assertStringNotContainsString(self::TOKEN_B, $r->body);
        }
    }

    public function testVinculoRemovidoOuCondominioDesativadoFazOsDispositivosSumirNaHora(): void
    {
        $this->assertSame(200, $this->get("/gerente/dispositivos/{$this->tvA2}")->status);

        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, [$this->condA]);
        $this->assertSame(404, $this->get("/gerente/dispositivos/{$this->tvA2}")->status, 'Vínculo removido: vale na próxima requisição.');
        $this->assertStringNotContainsString('Portaria', $this->get('/gerente/dispositivos')->body);

        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->condA}");
        $this->assertSame(404, $this->get("/gerente/dispositivos/{$this->tvA}")->status, 'Condomínio desativado também.');
        $this->assertStringContainsString('Nenhum dispositivo por aqui ainda', $this->get('/gerente/dispositivos')->body);
    }

    public function testGerenteDoOutroCondominioNaoVeOsDispositivosDoA(): void
    {
        $gerenteB = $this->criarUsuario('gerente.b@exemplo.com.br');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($gerenteB, [$this->condB]);
        $_SESSION = [];
        $this->logar('gerente.b@exemplo.com.br');

        $lista = $this->get('/gerente/dispositivos')->body;

        $this->assertStringContainsString('TV Secreta do Beta', $lista);
        $this->assertStringNotContainsString('Elevador social', $lista);
        $this->assertStringNotContainsString('Portaria', $lista);
        $this->assertSame(404, $this->get("/gerente/dispositivos/{$this->tvA}")->status);
        $this->assertSame(404, $this->get("/gerente/dispositivos/{$this->tvA2}")->status);
    }

    public function testNaoHaRotasDeEscritaParaDispositivosNoPainelDoGerente(): void
    {
        foreach (['/gerente/dispositivos', "/gerente/dispositivos/{$this->tvA}", "/gerente/dispositivos/{$this->tvA}/editar", "/gerente/dispositivos/{$this->tvA}/token", "/gerente/dispositivos/{$this->tvA}/excluir"] as $caminho) {
            $this->assertContains($this->post($caminho, ['nome' => 'HACK'])->status, [404, 405], "POST {$caminho}");
        }
        $this->assertSame('Elevador social', $this->pdo->query("SELECT nome FROM dispositivos WHERE id = {$this->tvA}")->fetchColumn());
        $this->assertSame(self::TOKEN_A, $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$this->tvA}")->fetchColumn());
    }

    public function testMenuDoGerenteTemDispositivosEMarcaAAbaAtiva(): void
    {
        $corpo = $this->get('/gerente/dispositivos')->body;

        $this->assertStringContainsString('<a href="/gerente/dispositivos" class="ativo" aria-current="page">', $corpo);
        $this->assertStringNotContainsString('href="/gerente/dispositivos" class="ativo"', $this->get('/gerente/tipos')->body);
    }

    // ---------- Orientação da tela pelo gerente ----------

    private function orientacaoDe(int $id): string
    {
        return (string) $this->pdo->query("SELECT orientacao FROM dispositivos WHERE id = {$id}")->fetchColumn();
    }

    public function testGerenteAlternaAOrientacaoDeCadaTvIndependentemente(): void
    {
        $form = $this->get("/gerente/dispositivos/{$this->tvA}")->body;
        $this->assertStringContainsString('action="/gerente/dispositivos/' . $this->tvA . '/orientacao"', $form);
        $this->assertStringContainsString('<input type="radio" name="orientacao" value="horizontal" checked>', $form);

        $r = $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical']);

        $this->assertSame(303, $r->status);
        $this->assertSame("/gerente/dispositivos/{$this->tvA}", $r->headers['Location']);
        $this->assertSame('vertical', $this->orientacaoDe($this->tvA));
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA2), 'A outra TV do mesmo gerente não muda.');
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvB));
        $this->assertStringContainsString('<input type="radio" name="orientacao" value="vertical" checked>', $this->get("/gerente/dispositivos/{$this->tvA}")->body);
        $this->assertSame('vertical', json_decode($this->get('/tv/' . self::TOKEN_A . '/state', '198.51.100.30')->body, true)['orientacao']);
        $this->assertSame('horizontal', json_decode($this->get('/tv/' . self::TOKEN_A2 . '/state', '198.51.100.30')->body, true)['orientacao']);

        $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'horizontal']);
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA));
    }

    public function testGerenteNaoMudaOrientacaoDeTvDeOutroCondominio(): void
    {
        $r = $this->post("/gerente/dispositivos/{$this->tvB}/orientacao", ['orientacao' => 'vertical']);

        $this->assertSame(404, $r->status);
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvB));
        $this->assertSame(404, $this->post('/gerente/dispositivos/999999/orientacao', ['orientacao' => 'vertical'])->status);
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'dispositivo.orientacao_alterada'")->fetchColumn());
    }

    public function testVinculoRemovidoCortaAOrientacaoNaHora(): void
    {
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario($this->gerenteA, [$this->condA2]);

        $this->assertSame(404, $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical'])->status);
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA));
    }

    public function testOrientacaoDoGerenteExigeCsrfLoginEPapel(): void
    {
        $this->assertSame(403, $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical'], comCsrf: false)->status);
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA));

        $_SESSION = [];
        $this->assertSame(302, $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical'], false)->status);
        $this->logarComoAdmin();
        $this->assertSame(403, $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical'])->status);
        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA));
    }

    public function testOrientacaoInvalidaDoGerenteNaoAlteraNada(): void
    {
        foreach (['diagonal', '', 'VERTICAL'] as $valor) {
            $this->assertSame(303, $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => $valor])->status, $valor);
        }

        $this->assertSame('horizontal', $this->orientacaoDe($this->tvA));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'dispositivo.orientacao_alterada'")->fetchColumn());
    }

    public function testOrientacaoDoGerenteVaiParaAAuditoriaComOCondominio(): void
    {
        $this->post("/gerente/dispositivos/{$this->tvA}/orientacao", ['orientacao' => 'vertical']);

        $l = $this->pdo->query("SELECT usuario_id, condominio_id, entidade_id, detalhes::text AS d FROM auditoria WHERE acao = 'dispositivo.orientacao_alterada'")->fetch();

        $this->assertSame($this->gerenteA, (int) $l['usuario_id']);
        $this->assertSame($this->condA, (int) $l['condominio_id']);
        $this->assertSame($this->tvA, (int) $l['entidade_id']);
        $this->assertStringNotContainsString(self::TOKEN_A, $l['d']);
    }

    public function testListaNaoTemFormularioDeOrientacao(): void
    {
        $this->assertStringNotContainsString('name="orientacao"', $this->get('/gerente/dispositivos')->body);
    }

    // ---------- Pareamento pelo gerente ----------

    /** @return array{codigo: string, segredo: string} */
    private function codigoDaTv(string $ip = '198.51.100.20'): array
    {
        $r = $this->post('/tv/parear', comCsrf: false, ip: $ip);
        $this->assertSame(200, $r->status);

        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    private function tokenDe(int $id): string
    {
        return (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$id}")->fetchColumn();
    }

    public function testGerentePareiaTvDoProprioCondominioETvRecebeOTokenNovo(): void
    {
        $tv = $this->codigoDaTv();

        $form = $this->get("/gerente/dispositivos/{$this->tvA}/parear");
        $this->assertSame(200, $form->status);
        $this->assertStringContainsString('name="codigo"', $form->body);
        $this->assertStringNotContainsString(self::TOKEN_A, $form->body);

        $r = $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']]);

        $this->assertSame(303, $r->status);
        $this->assertSame("/gerente/dispositivos/{$this->tvA}", $r->headers['Location']);
        $novo = $this->tokenDe($this->tvA);
        $this->assertNotSame(self::TOKEN_A, $novo);
        $entrega = json_decode($this->post('/tv/parear/consulta', ['segredo' => $tv['segredo']], false, '198.51.100.20')->body, true);
        $this->assertSame(['estado' => 'pareado', 'token' => $novo], $entrega);
        $this->assertSame(200, $this->get("/tv/{$novo}/state", '198.51.100.20')->status);
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN_A . '/state', '198.51.100.21')->status);
    }

    public function testGerenteNaoPareiaDispositivoDeOutroCondominio(): void
    {
        $tv = $this->codigoDaTv();

        $this->assertSame(404, $this->get("/gerente/dispositivos/{$this->tvB}/parear")->status);
        $r = $this->post("/gerente/dispositivos/{$this->tvB}/parear", ['codigo' => $tv['codigo']]);

        $this->assertSame(404, $r->status);
        $this->assertStringNotContainsString(self::TOKEN_B, $r->body);
        $this->assertSame(self::TOKEN_B, $this->tokenDe($this->tvB));
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM pareamentos WHERE pareado_em IS NOT NULL')->fetchColumn(), 'O código continua livre.');
        $this->assertSame(404, $this->get('/gerente/dispositivos/999999/parear')->status);
        $this->assertSame(404, $this->post('/gerente/dispositivos/999999/parear', ['codigo' => $tv['codigo']])->status);
    }

    public function testGerenteDeOutroCondominioTambemNaoPareiaOsDoA(): void
    {
        $tv = $this->codigoDaTv();
        $_SESSION = [];
        $this->criarUsuario('gerente.b@exemplo.com.br');
        (new VinculoRepository($this->pdo))->sincronizarDoUsuario((int) $this->pdo->query("SELECT id FROM usuarios WHERE email = 'gerente.b@exemplo.com.br'")->fetchColumn(), [$this->condB]);
        $this->logar('gerente.b@exemplo.com.br');

        $this->assertSame(404, $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']])->status);
        $this->assertSame(self::TOKEN_A, $this->tokenDe($this->tvA));
    }

    public function testPareamentoDoGerenteExigeCsrfELogin(): void
    {
        $tv = $this->codigoDaTv();

        $this->assertSame(403, $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']], comCsrf: false)->status);
        $this->assertSame(self::TOKEN_A, $this->tokenDe($this->tvA));

        $_SESSION = [];
        $this->assertSame(302, $this->get("/gerente/dispositivos/{$this->tvA}/parear")->status);
        $this->assertSame(302, $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']], false)->status);
        $this->logarComoAdmin();
        $this->assertSame(403, $this->get("/gerente/dispositivos/{$this->tvA}/parear")->status, 'Administrador usa o painel dele.');
    }

    public function testCodigoInvalidoOuVencidoENaoAlteraNada(): void
    {
        $tv = $this->codigoDaTv();
        $this->pdo->exec("UPDATE pareamentos SET expira_em = now() - interval '1 second'");

        foreach ([$tv['codigo'], 'AAAAAA', 'abc', ''] as $codigo) {
            $r = $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $codigo]);
            $this->assertSame(422, $r->status, $codigo);
        }
        $this->assertSame(self::TOKEN_A, $this->tokenDe($this->tvA));
    }

    public function testDispositivoRevogadoNaoPareiaEBotaoSome(): void
    {
        $tv = $this->codigoDaTv();
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$this->tvA}");

        $this->assertStringNotContainsString("/gerente/dispositivos/{$this->tvA}/parear\"", $this->get("/gerente/dispositivos/{$this->tvA}")->body . '');
        $r = $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']]);

        $this->assertSame(422, $r->status);
        $this->assertSame(self::TOKEN_A, $this->tokenDe($this->tvA));
    }

    public function testGerenteNaoAdivinhaCodigos(): void
    {
        $tv = $this->codigoDaTv();
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(422, $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => 'AAAAA' . ['2', '3', '4', '5', '6', '7', '8', '9', 'B', 'C'][$i]])->status);
        }

        // Bloqueado: nem o código certo vale mais.
        $bloqueado = $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']]);

        $this->assertSame(422, $bloqueado->status);
        $this->assertStringContainsString('Muitas tentativas', $bloqueado->body);
        $this->assertSame(self::TOKEN_A, $this->tokenDe($this->tvA));
    }

    public function testPareamentoDoGerenteVaiParaAAuditoriaComOCondominioESemSegredos(): void
    {
        $tv = $this->codigoDaTv('198.51.100.77');
        $this->post("/gerente/dispositivos/{$this->tvA}/parear", ['codigo' => $tv['codigo']]);
        $novo = $this->tokenDe($this->tvA);

        $l = $this->pdo->query("SELECT usuario_id, condominio_id, entidade_id, detalhes::text AS d FROM auditoria WHERE acao = 'dispositivo.pareado'")->fetch();

        $this->assertSame($this->gerenteA, (int) $l['usuario_id']);
        $this->assertSame($this->condA, (int) $l['condominio_id']);
        $this->assertSame($this->tvA, (int) $l['entidade_id']);
        $this->assertStringContainsString('198.51.100.77', $l['d']);
        foreach ([$tv['codigo'], $tv['segredo'], $novo, self::TOKEN_A] as $segredo) {
            $this->assertStringNotContainsString($segredo, $l['d']);
        }
    }
}
