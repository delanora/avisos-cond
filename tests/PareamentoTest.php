<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\PareamentoRepository;
use App\Services\PareamentoService;
use App\Services\Tarefas\LimparPareamentos;
use Tests\Support\AvisosDeTeste;

/** Pareamento da TV por código: fluxo completo, segurança do segredo, limites, expiração e acesso só do superadmin. */
final class PareamentoTest extends AppHttpTestCase
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

    /** @return array{codigo: string, segredo: string} */
    private function pedirCodigo(string $ip = '203.0.113.10'): array
    {
        $r = $this->post('/tv/parear', comCsrf: false, ip: $ip);
        $this->assertSame(200, $r->status, $r->body);

        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function consultar(string $segredo, string $ip = '203.0.113.10'): array
    {
        $r = $this->post('/tv/parear/consulta', ['segredo' => $segredo], false, $ip);

        return ['status' => $r->status, 'json' => json_decode($r->body, true), 'resposta' => $r];
    }

    private function tokenAtual(): string
    {
        return (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$this->dispositivo}")->fetchColumn();
    }

    // ---------- Página e criação do código ----------

    public function testPaginaPublicaSemSessaoNemCookiesENemReferrer(): void
    {
        $r = $this->get('/tv');

        $this->assertSame(200, $r->status);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertStringContainsString('/assets/parear.js', $r->body);
        $this->assertStringNotContainsString('<script>', $r->body, 'O CSP não permite script inline.');
    }

    public function testCriaCodigoNoFormatoCertoEGuardaSoOHashDoSegredo(): void
    {
        $p = $this->pedirCodigo();

        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{6}$/', $p['codigo']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $p['segredo']);
        $linha = $this->pdo->query('SELECT codigo, segredo_hash, ip FROM pareamentos')->fetch();
        $this->assertSame($p['codigo'], $linha['codigo']);
        $this->assertSame(hash('sha256', $p['segredo']), $linha['segredo_hash']);
        $this->assertSame('203.0.113.10', $linha['ip']);
        $this->assertStringNotContainsString($p['segredo'], json_encode($this->pdo->query('SELECT * FROM pareamentos')->fetchAll(), JSON_THROW_ON_ERROR));
    }

    public function testRespostasDaTvNaoCriamCookiesEEnviamNoReferrer(): void
    {
        $r = $this->post('/tv/parear', comCsrf: false);

        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testCodigosPendentesNuncaSeRepetem(): void
    {
        $codigos = [];
        for ($i = 0; $i < 15; $i++) {
            $codigos[] = $this->pedirCodigo()['codigo'];
        }

        $this->assertCount(15, array_unique($codigos));
    }

    public function testLimiteDeCodigosPorIpBloqueiaComRetryAfterEOutroIpNaoEAfetado(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertSame(200, $this->post('/tv/parear', comCsrf: false)->status);
        }

        $r = $this->post('/tv/parear', comCsrf: false);
        $this->assertSame(429, $r->status);
        $this->assertGreaterThan(0, (int) $r->headers['Retry-After']);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertSame(429, $this->post('/tv/parear', comCsrf: false)->status, 'Continua bloqueado.');
        $this->assertSame(200, $this->post('/tv/parear', comCsrf: false, ip: '198.51.100.7')->status);
    }

    // ---------- Consulta da TV ----------

    public function testConsultaAguardandoEnquantoNinguemPareia(): void
    {
        $p = $this->pedirCodigo();

        $r = $this->consultar($p['segredo']);

        $this->assertSame(200, $r['status']);
        $this->assertSame(['estado' => 'aguardando'], $r['json']);
        $this->assertArrayNotHasKey('token', $r['json']);
    }

    public function testSegredoMalformadoOuDesconhecidoDa404SemDetalhes(): void
    {
        $this->pedirCodigo();

        foreach (['', 'abc', str_repeat('g', 32), str_repeat('a', 32), str_repeat('A', 32), str_repeat('a', 33)] as $segredo) {
            $r = $this->consultar($segredo);
            $this->assertSame(404, $r['status'], "segredo: {$segredo}");
            $this->assertSame('no-referrer', $r['resposta']->headers['Referrer-Policy']);
        }
    }

    public function testCodigoNaoServeComoSegredo(): void
    {
        $p = $this->pedirCodigo();

        $this->assertSame(404, $this->consultar($p['codigo'])['status']);
        $this->assertSame(404, $this->consultar(hash('sha256', $p['segredo']))['status'], 'O hash guardado também não serve.');
    }

    public function testSegredosDesconhecidosBloqueiamOIpMasMalformadosNaoContam(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->assertSame(404, $this->consultar('lixo')['status']);
        }
        $this->assertSame(404, $this->consultar(str_repeat('a', 32))['status'], 'Malformados não contam: ainda 404, não 429.');

        // 30 falhas permitidas; a 31ª liga o bloqueio e dali em diante tudo é 429.
        for ($i = 0; $i < 30; $i++) {
            $this->consultar(bin2hex(random_bytes(16)));
        }
        $this->consultar(bin2hex(random_bytes(16)));
        $bloqueado = $this->consultar(bin2hex(random_bytes(16)));

        $this->assertSame(429, $bloqueado['status']);
        $this->assertGreaterThan(0, (int) $bloqueado['resposta']->headers['Retry-After']);
        $this->assertSame(200, $this->consultar($this->pedirCodigo(ip: '198.51.100.8')['segredo'], '198.51.100.8')['status'], 'Outro IP não é afetado.');
    }

    // ---------- Fluxo completo ----------

    public function testFluxoCompletoTvRecebeOTokenNovoUmaUnicaVezEOAntigoMorre(): void
    {
        $p = $this->pedirCodigo();
        $admin = $this->logarComoAdmin();

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);

        $this->assertSame(303, $r->status);
        $this->assertSame("/admin/dispositivos/{$this->dispositivo}", $r->headers['Location']);
        $novo = $this->tokenAtual();
        $this->assertNotSame(self::TOKEN, $novo);

        $primeira = $this->consultar($p['segredo']);
        $this->assertSame(['estado' => 'pareado', 'token' => $novo], $primeira['json']);
        $this->assertArrayNotHasKey('Set-Cookie', $primeira['resposta']->headers);
        $this->assertSame('no-store', $primeira['resposta']->headers['Cache-Control']);

        $this->assertSame(['estado' => 'expirado'], $this->consultar($p['segredo'])['json'], 'O token só é entregue uma vez.');
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state')->status, 'O endereço antigo morreu.');
        $this->assertSame(200, $this->get("/tv/{$novo}/state")->status);
        $this->assertSame($admin, (int) $this->pdo->query('SELECT pareado_por FROM pareamentos')->fetchColumn());
    }

    public function testCodigoAceitaMinusculasEspacosEHifen(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();
        $digitado = strtolower(substr($p['codigo'], 0, 3)) . ' - ' . substr($p['codigo'], 3);

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $digitado]);

        $this->assertSame(303, $r->status);
        $this->assertSame('pareado', $this->consultar($p['segredo'])['json']['estado']);
    }

    public function testCodigoSoPareiaUmaVez(): void
    {
        $p = $this->pedirCodigo();
        $outro = $this->dispositivoTv($this->pdo, $this->condominio, 'ZZZZ2222XXXX', 'Garagem');
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);
        $tokenOutroAntes = (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$outro}")->fetchColumn();

        $r = $this->post("/admin/dispositivos/{$outro}/parear", ['codigo' => $p['codigo']]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('Código não encontrado ou expirado', $r->body);
        $this->assertSame($tokenOutroAntes, (string) $this->pdo->query("SELECT token FROM dispositivos WHERE id = {$outro}")->fetchColumn(), 'O outro dispositivo não foi tocado.');
    }

    public function testCodigosInvalidosNaoAlteramNada(): void
    {
        $this->pedirCodigo();
        $this->logarComoAdmin();

        foreach (['', 'ABC', 'ABCDEF1', 'OOOOOO', '000000', 'AAAAAA'] as $codigo) {
            $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $codigo]);
            $this->assertSame(422, $r->status, "código: {$codigo}");
        }

        $this->assertSame(self::TOKEN, $this->tokenAtual());
        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM pareamentos WHERE pareado_em IS NOT NULL')->fetchColumn());
    }

    public function testCodigoVencidoNaoPareia(): void
    {
        $p = $this->pedirCodigo();
        $this->pdo->exec("UPDATE pareamentos SET expira_em = now() - interval '1 second'");
        $this->logarComoAdmin();

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);

        $this->assertSame(422, $r->status);
        $this->assertSame(self::TOKEN, $this->tokenAtual());
        $this->assertSame(['estado' => 'expirado'], $this->consultar($p['segredo'])['json']);
    }

    public function testDepoisDeVencidoOCodigoPodeSerSorteadoDeNovo(): void
    {
        $this->pedirCodigo();
        $this->pdo->exec("UPDATE pareamentos SET expira_em = now() - interval '1 second'");

        $this->pedirCodigo();

        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM pareamentos')->fetchColumn(), 'O pendente vencido foi apagado ao pedir um novo.');
    }

    public function testEntregaForaDaJanelaNaoDaOToken(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);
        $this->pdo->exec("UPDATE pareamentos SET pareado_em = now() - interval '5 minutes'");

        $this->assertSame(['estado' => 'expirado'], $this->consultar($p['segredo'])['json']);
    }

    public function testDispositivoRevogadoNaoPareia(): void
    {
        $p = $this->pedirCodigo();
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$this->dispositivo}");
        $this->logarComoAdmin();

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);

        $this->assertSame(422, $r->status);
        $this->assertStringContainsString('revogado', $r->body);
        $this->assertSame(self::TOKEN, $this->tokenAtual());
        $this->assertSame(['estado' => 'aguardando'], $this->consultar($p['segredo'])['json']);
    }

    public function testDispositivoRevogadoDepoisDoPareamentoNaoEntregaToken(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE id = {$this->dispositivo}");

        $this->assertSame(['estado' => 'expirado'], $this->consultar($p['segredo'])['json']);
    }

    public function testDispositivoInexistenteDa404(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();

        $this->assertSame(404, $this->post('/admin/dispositivos/999999/parear', ['codigo' => $p['codigo']])->status);
        $this->assertSame(404, $this->get('/admin/dispositivos/999999/parear')->status);
    }

    public function testExcluirODispositivoApagaOsPareamentosLigadosAEle(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);

        $this->post("/admin/dispositivos/{$this->dispositivo}/excluir");

        $this->assertSame(0, (int) $this->pdo->query('SELECT count(*) FROM pareamentos')->fetchColumn());
    }

    // ---------- Auditoria ----------

    public function testPareamentoVaiParaAAuditoriaSemCodigoSegredoNemToken(): void
    {
        $p = $this->pedirCodigo(ip: '203.0.113.77');
        $admin = $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']]);
        $novo = $this->tokenAtual();

        $linha = $this->pdo->query("SELECT usuario_id, entidade_id, condominio_nome, detalhes::text AS d FROM auditoria WHERE acao = 'dispositivo.pareado'")->fetch();

        $this->assertSame($admin, (int) $linha['usuario_id']);
        $this->assertSame($this->dispositivo, (int) $linha['entidade_id']);
        $this->assertSame('Residencial Sol', $linha['condominio_nome']);
        $this->assertStringContainsString('203.0.113.77', $linha['d']);
        foreach ([$p['codigo'], $p['segredo'], $novo, self::TOKEN] as $segredo) {
            $this->assertStringNotContainsString($segredo, $linha['d']);
        }
    }

    public function testPareamentoComFalhaNaoAudita(): void
    {
        $this->logarComoAdmin();
        $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => 'AAAAAA']);

        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM auditoria WHERE acao = 'dispositivo.pareado'")->fetchColumn());
    }

    // ---------- Painel ----------

    public function testPaginaDoAdminMostraOFormularioEOEnderecoCurto(): void
    {
        $this->logarComoAdmin();

        $r = $this->get("/admin/dispositivos/{$this->dispositivo}/parear");

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('name="codigo"', $r->body);
        $this->assertStringContainsString('name="_csrf"', $r->body);
        $this->assertStringContainsString('Elevador social', $r->body);
        $this->assertStringNotContainsString(self::TOKEN, $r->body, 'A página de pareamento nunca mostra o token.');
        $this->assertStringContainsString("/admin/dispositivos/{$this->dispositivo}/parear", $this->get("/admin/dispositivos/{$this->dispositivo}")->body);
    }

    public function testPareamentoSemCsrfEBloqueado(): void
    {
        $p = $this->pedirCodigo();
        $this->logarComoAdmin();

        $r = $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']], comCsrf: false);

        $this->assertSame(403, $r->status);
        $this->assertSame(self::TOKEN, $this->tokenAtual());
    }

    public function testGerenteNaoPareia(): void
    {
        $p = $this->pedirCodigo();
        $this->criarUsuario('g@exemplo.com.br');
        $this->logar('g@exemplo.com.br');

        $this->assertSame(403, $this->get("/admin/dispositivos/{$this->dispositivo}/parear")->status);
        $this->assertSame(403, $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']])->status);
        $this->assertSame(self::TOKEN, $this->tokenAtual());
    }

    public function testVisitanteNaoPareia(): void
    {
        $p = $this->pedirCodigo();

        $this->assertSame(302, $this->get("/admin/dispositivos/{$this->dispositivo}/parear")->status);
        $this->assertSame(302, $this->post("/admin/dispositivos/{$this->dispositivo}/parear", ['codigo' => $p['codigo']], false)->status);
        $this->assertSame(self::TOKEN, $this->tokenAtual());
    }

    // ---------- Banco, limpeza e normalização ----------

    public function testBancoRecusaCodigoForaDoFormatoEPendenteDuplicado(): void
    {
        $ins = $this->pdo->prepare("INSERT INTO pareamentos (codigo, segredo_hash, ip, expira_em) VALUES (:c, :s, '1.1.1.1', now())");
        $ins->execute(['c' => 'ABC234', 's' => str_repeat('a', 64)]);

        foreach ([['abc234', 'b'], ['ABC23', 'c'], ['OOO000', 'd'], ['ABC234', 'e']] as [$codigo, $letra]) {
            try {
                $ins->execute(['c' => $codigo, 's' => str_repeat($letra, 64)]);
                $this->fail("O banco aceitou o código {$codigo}.");
            } catch (\PDOException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testNormalizarCodigo(): void
    {
        $this->assertSame('ABC234', PareamentoService::normalizarCodigo(' abc-234 '));
        $this->assertNull(PareamentoService::normalizarCodigo('ABC23'));
        $this->assertNull(PareamentoService::normalizarCodigo('ABC2345'));
        $this->assertNull(PareamentoService::normalizarCodigo('ABC0O1'));
    }

    public function testMarcarEntregueSoFuncionaUmaVez(): void
    {
        $this->pedirCodigo();
        $id = (int) $this->pdo->query('SELECT id FROM pareamentos')->fetchColumn();
        $repo = new PareamentoRepository($this->pdo);

        $this->assertTrue($repo->marcarEntregue($id));
        $this->assertFalse($repo->marcarEntregue($id));
    }

    public function testLimpezaApagaSoOsComMaisDeUmDia(): void
    {
        $this->pedirCodigo();
        $this->pedirCodigo();
        $this->pdo->exec("UPDATE pareamentos SET criado_em = now() - interval '2 days' WHERE id = (SELECT min(id) FROM pareamentos)");

        $msg = (new LimparPareamentos(new PareamentoRepository($this->pdo)))->executar();

        $this->assertStringContainsString('1 pareamento', $msg);
        $this->assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM pareamentos')->fetchColumn());
    }

    public function testRotasDeTvNaoColidemComOTokenEOSegredoNaoVaiNaUrl(): void
    {
        $this->assertSame(405, $this->get('/tv/parear')->status, 'Só POST: GET de /tv/parear não vira token nem página.');
        $this->assertSame(200, $this->get('/tv')->status);
        $this->assertSame(404, $this->get('/tv/PARE23')->status);
    }
}
