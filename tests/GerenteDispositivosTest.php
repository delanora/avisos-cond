<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\VinculoRepository;
use Tests\Support\AvisosDeTeste;

/** Dispositivos (TVs) vistos pelo gerente: só os dos condomínios dele, só consulta e nunca com o token. */
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
        $corpo = $this->get('/gerente/dispositivos')->body . $this->get("/gerente/dispositivos/{$this->tvA}")->body;

        foreach ([self::TOKEN_A, self::TOKEN_A2, self::TOKEN_B, strtolower(self::TOKEN_A), '/tv/', '/editar', '/excluir', '/token', 'Novo dispositivo', 'Trocar token'] as $proibido) {
            $this->assertStringNotContainsString($proibido, $corpo, "O gerente não vê '{$proibido}'.");
        }
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
}
