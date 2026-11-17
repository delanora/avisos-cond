<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\DispositivoRepository;
use App\Support\Response;
use Tests\Support\Amostras;
use Tests\Support\AvisosDeTeste;

/** Tela pública da TV: acesso só por token, estado (JSON), mídia, heartbeat, rate limit e isolamento entre condomínios. */
final class TvTest extends AppHttpTestCase
{
    use AvisosDeTeste;

    private const TOKEN = 'ABCDEFGH2345';
    private const TOKEN_B = 'JKMNPQRS6789';

    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->criarUsuario('g@exemplo.com.br');
        $this->a = $this->criarCondominio('Alfa');
        $this->b = $this->criarCondominio('Beta');
        $this->dispositivoTv($this->pdo, $this->a, self::TOKEN, 'TV do Alfa');
        $this->dispositivoTv($this->pdo, $this->b, self::TOKEN_B, 'TV do Beta');
    }

    /** @return array<string, mixed> */
    private function estado(string $token = self::TOKEN): array
    {
        $r = $this->get("/tv/{$token}/state");
        $this->assertSame(200, $r->status);

        return json_decode($r->body, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Guarda um arquivo no storage de testes e devolve o nome (como o UploadService faria). */
    private function arquivo(string $bytes, string $extensao = 'png'): string
    {
        @mkdir($this->uploads, 0750, true);
        $nome = bin2hex(random_bytes(16)) . '.' . $extensao;
        file_put_contents("{$this->uploads}/{$nome}", $bytes);

        return $nome;
    }

    private function pingDe(string $token): ?string
    {
        $v = $this->pdo->query("SELECT ultimo_ping FROM dispositivos WHERE token = '{$token}'")->fetchColumn();

        return $v === false || $v === null ? null : (string) $v;
    }

    /** @return list<string> caminhos de todas as rotas da TV para um token */
    private function rotas(string $token, string $midia = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png'): array
    {
        return ["/tv/{$token}", "/tv/{$token}/state", "/tv/{$token}/media/{$midia}", "/tv/{$token}/logo", "/tv/{$token}/tema.css"];
    }

    // ---------- Página ----------

    public function testPaginaDaTvEPublicaSemSessaoNemCookies(): void
    {
        $r = $this->get('/tv/' . self::TOKEN);

        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('text/html', $r->headers['Content-Type']);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers, 'A TV não usa sessão nem cookies.');
        $this->assertSame([], $_SESSION, 'Nenhuma sessão foi iniciada.');
    }

    public function testPaginaTemReferrerPolicyECabecalhosDeSeguranca(): void
    {
        $r = $this->get('/tv/' . self::TOKEN);

        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertStringContainsString("default-src 'self'", $r->headers['Content-Security-Policy']);
    }

    public function testPaginaReferenciaScriptsEEstilosLocaisSemNadaInline(): void
    {
        $corpo = $this->get('/tv/' . self::TOKEN)->body;

        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/tv.css">', $corpo);
        $this->assertStringContainsString('href="/tv/' . self::TOKEN . '/tema.css"', $corpo);
        $this->assertStringContainsString('<script src="/assets/tv.js" defer></script>', $corpo);
        $this->assertSame(1, substr_count($corpo, '<script'), 'Só o script externo.');
        $this->assertStringNotContainsString(' style=', $corpo);
        $this->assertStringContainsString('data-estado-url="/tv/' . self::TOKEN . '/state"', $corpo);
        $this->assertStringContainsString('content="noindex, nofollow"', $corpo);
        $this->assertStringNotContainsString('http://', $corpo);
        $this->assertStringNotContainsString('https://', $corpo, 'Nada de recursos externos.');
    }

    public function testPaginaJaNasceComOEstadoInicialParaFuncionarOffline(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'Aviso inicial', -60, 3600);

        $corpo = $this->get('/tv/' . self::TOKEN)->body;

        preg_match('/data-estado="([^"]*)"/', $corpo, $m);
        $estado = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Aviso inicial', $estado['avisos'][0]['titulo']);
        $this->assertArrayHasKey('server_epoch', $estado);
    }

    public function testTextoDoAvisoNaPaginaSaiEscapadoSemQuebrarOAtributo(): void
    {
        $this->avisoTv($this->pdo, $this->a, '"><script>alert(1)</script>', -60, 3600, ['corpo' => "</script><img src=x onerror=alert(2)>' \" &"]);

        $corpo = $this->get('/tv/' . self::TOKEN)->body;

        $this->assertStringNotContainsString('<script>alert(1)</script>', $corpo);
        $this->assertStringNotContainsString('<img src=x', $corpo);
        $this->assertSame(1, substr_count($corpo, '<script'), 'O aviso não consegue criar outro <script>.');
        preg_match('/data-estado="([^"]*)"/', $corpo, $m);
        $estado = json_decode(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('"><script>alert(1)</script>', $estado['avisos'][0]['titulo'], 'O texto original chega intacto ao JS, que o mostra como texto.');
    }

    public function testTokenAceitoEmQualquerCaixa(): void
    {
        $this->assertSame(200, $this->get('/tv/' . strtolower(self::TOKEN))->status);
        $this->assertSame(200, $this->get('/tv/' . strtolower(self::TOKEN) . '/state')->status);
        $this->assertSame(200, $this->get('/tv/AbCdEfGh2345/state')->status);
    }

    // ---------- Estado e heartbeat ----------

    public function testEstadoTrazSoOsAvisosVisiveisDoCondominioDoDispositivo(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'No ar', -60, 3600);
        $this->avisoTv($this->pdo, $this->a, 'Em 2 horas', 7200, 9000);
        $this->avisoTv($this->pdo, $this->a, 'Em 3 dias', 259200, 300000);
        $this->avisoTv($this->pdo, $this->a, 'Vencido', -7200, -3600);
        $this->avisoTv($this->pdo, $this->a, 'Desativado', -60, 3600, ['ativo' => false]);
        $this->avisoTv($this->pdo, $this->b, 'SEGREDO-DO-BETA', -60, 3600);

        $estado = $this->estado();

        $this->assertSame(['No ar', 'Em 2 horas'], array_column($estado['avisos'], 'titulo'));
        $this->assertStringNotContainsString('SEGREDO-DO-BETA', json_encode($estado, JSON_THROW_ON_ERROR));
        $this->assertSame(['SEGREDO-DO-BETA'], array_column($this->estado(self::TOKEN_B)['avisos'], 'titulo'), 'E a TV do Beta só vê o do Beta.');
    }

    public function testEstadoEJsonSemCacheEComReferrerPolicy(): void
    {
        $r = $this->get('/tv/' . self::TOKEN . '/state');

        $this->assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertArrayNotHasKey('Set-Cookie', $r->headers);
    }

    public function testCadaConsultaDoEstadoERegistradaComoHeartbeat(): void
    {
        $this->assertNull($this->pingDe(self::TOKEN));

        $this->get('/tv/' . self::TOKEN . '/state');

        $this->assertNotNull($this->pingDe(self::TOKEN));
        $this->assertNull($this->pingDe(self::TOKEN_B), 'Só o dispositivo que consultou.');

        $primeiro = (string) $this->pingDe(self::TOKEN);
        $this->pdo->exec("UPDATE dispositivos SET ultimo_ping = now() - interval '1 hour' WHERE token = '" . self::TOKEN . "'");
        $this->get('/tv/' . self::TOKEN . '/state');
        $this->assertGreaterThanOrEqual($primeiro, (string) $this->pingDe(self::TOKEN), 'O contato volta a ser atualizado.');
    }

    public function testCarregarAPaginaTambemContaComoContato(): void
    {
        $this->get('/tv/' . self::TOKEN);

        $this->assertNotNull($this->pingDe(self::TOKEN));
    }

    public function testAvisoUrgenteChegaNoEstadoParaOJsDarPrioridade(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'Normal', -60, 3600);
        $this->avisoTv($this->pdo, $this->a, 'Urgente', -30, 3600, ['tipo' => 'urgente']);

        $tipos = array_column($this->estado()['avisos'], 'tipo', 'titulo');

        $this->assertSame('urgente', $tipos['Urgente']);
        $this->assertSame('comum', $tipos['Normal']);
    }

    // ---------- Token inválido / revogado: sempre o mesmo 404 ----------

    public function testTokenInvalidoOuRevogadoRetorna404IgualEmTodasAsRotas(): void
    {
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE token = '" . self::TOKEN_B . "'");
        $desconhecido = null;

        foreach (['ZZZZZZZZZZZZ', self::TOKEN_B] as $token) {
            foreach ($this->rotas($token) as $rota) {
                $r = $this->get($rota);

                $this->assertSame(404, $r->status, $rota);
                $desconhecido ??= $r->body;
                // Revogado e inexistente são indistinguíveis (sem enumeração) e sem detalhes.
                $this->assertSame($desconhecido === $r->body || str_contains($r->body, 'erro-codigo'), true, $rota);
                foreach (['Beta', 'Alfa', 'TV do Alfa', 'TV do Beta', 'revogado', 'inativo', $token] as $vaza) {
                    $this->assertStringNotContainsString($vaza, strip_tags($r->body), "{$rota} vazou '{$vaza}'");
                }
                $this->assertSame('no-referrer', $r->headers['Referrer-Policy'] ?? 'ausente', $rota);
            }
        }
    }

    public function testTokenMalFormadoNemChegaAoBanco(): void
    {
        foreach (['/tv/abc', '/tv/ABCDEFGH234', '/tv/ABCDEFGH23456', '/tv/ABCDEFGH234!', '/tv/abc/state', '/tv/..%2f..%2fetc/state'] as $rota) {
            $this->assertSame(404, $this->get($rota)->status, $rota);
        }
    }

    public function testRevogarOuTrocarOTokenCortaOAcessoNaHora(): void
    {
        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state')->status);

        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE token = '" . self::TOKEN . "'");
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state')->status);

        $this->pdo->exec("UPDATE dispositivos SET ativo = true WHERE token = '" . self::TOKEN . "'");
        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state')->status);

        (new DispositivoRepository($this->pdo))->trocarToken((int) $this->pdo->query("SELECT id FROM dispositivos WHERE token = '" . self::TOKEN . "'")->fetchColumn(), 'MNPQRSTV2345');
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/state')->status);
        $this->assertSame(200, $this->get('/tv/MNPQRSTV2345/state')->status);
    }

    public function testFalhasNaoAtualizamOHeartbeat(): void
    {
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE token = '" . self::TOKEN . "'");

        $this->get('/tv/' . self::TOKEN . '/state');

        $this->assertNull($this->pingDe(self::TOKEN));
    }

    // ---------- Rate limit de tokens inválidos ----------

    public function testTrintaTokensInvalidosBloqueiamOIpComRetryAfter(): void
    {
        // As 30 primeiras falhas só dão 404; a 31ª ainda dá 404, mas já liga o bloqueio.
        for ($i = 0; $i < 31; $i++) {
            $token = 'ZZZZZZZZZZZ' . ['2', '3', '4', '5', '6', '7', '8', '9'][$i % 8];
            $this->assertSame(404, $this->get("/tv/{$token}/state", '198.51.100.9')->status, "tentativa {$i}");
        }

        $r = $this->get('/tv/QQQQQQQQQQQQ/state', '198.51.100.9');

        $this->assertSame(429, $r->status);
        $this->assertArrayHasKey('Retry-After', $r->headers);
        $this->assertGreaterThan(800, (int) $r->headers['Retry-After']);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
    }

    public function testIpBloqueadoNaoAcessaNemComTokenValido(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->get('/tv/ZZZZZZZZZZZZ/state', '198.51.100.9');
        }

        $this->assertSame(429, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.9')->status, 'Sem isso, o atacante continuaria adivinhando.');
        $this->assertSame(429, $this->get('/tv/' . self::TOKEN, '198.51.100.9')->status);
    }

    public function testBloqueioEPorIpEOutroIpNaoEAfetado(): void
    {
        for ($i = 0; $i < 31; $i++) {
            $this->get('/tv/ZZZZZZZZZZZZ/state', '198.51.100.9');
        }

        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state', '203.0.113.50')->status);
        $this->assertSame(200, $this->get('/login', '198.51.100.9')->status, 'O bloqueio da TV não afeta o login do mesmo IP.');
    }

    public function testRequisicoesValidasNaoContamNoRateLimit(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.9')->status, "consulta {$i}");
        }
    }

    public function testTokensMalFormadosNaoGastamORateLimit(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->get('/tv/abc/state', '198.51.100.9');
        }

        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . '/state', '198.51.100.9')->status);
    }

    // ---------- Mídia ----------

    public function testMidiaDeAvisoVisivelEEntregueComTipoCorretoECacheDeUmDia(): void
    {
        $nome = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->a, 'Com foto', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'imagem']);

        $r = $this->get("/tv/" . self::TOKEN . "/media/{$nome}");

        $this->assertSame(200, $r->status);
        $this->assertSame('image/png', $r->headers['Content-Type']);
        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('private, max-age=86400', $r->headers['Cache-Control']);
        $this->assertSame('no-referrer', $r->headers['Referrer-Policy']);
        $this->assertSame(Amostras::png(), (string) file_get_contents((string) $r->arquivo));
    }

    public function testVideoEEntregueComoMp4(): void
    {
        $nome = $this->arquivo(Amostras::mp4(), 'mp4');
        $this->avisoTv($this->pdo, $this->a, 'Com vídeo', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'video']);

        $this->assertSame('video/mp4', $this->get('/tv/' . self::TOKEN . "/media/{$nome}")->headers['Content-Type']);
    }

    public function testMidiaPeloNginxVaiPorXAccelRedirect(): void
    {
        $nome = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->a, 'Com foto', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'imagem']);
        $this->app = $this->novaApp(['MEDIA_ACCEL' => '/media/']);

        $r = $this->get('/tv/' . self::TOKEN . "/media/{$nome}");

        $this->assertSame("/media/{$nome}", $r->headers['X-Accel-Redirect']);
        $this->assertSame('', $r->body);
        $this->assertNull($r->arquivo);
    }

    public function testMidiaDeAvisoQueAindaNaoEstaNoPeriodoPodeSerPreCarregada(): void
    {
        $nome = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->a, 'Em 2 horas', 7200, 9000, ['midia' => $nome, 'midia_tipo' => 'imagem']);

        $this->assertSame(200, $this->get('/tv/' . self::TOKEN . "/media/{$nome}")->status);
    }

    /** @return array<string, array{string, int, int, array<string, mixed>}> */
    public static function midiasNegadas(): array
    {
        return [
            'aviso vencido' => ['vencido', -7200, -3600, []],
            'aviso muito no futuro' => ['longe', 259200, 300000, []],
            'aviso desativado' => ['desativado', -60, 3600, ['ativo' => false]],
        ];
    }

    /** @param array<string, mixed> $extra */
    #[\PHPUnit\Framework\Attributes\DataProvider('midiasNegadas')]
    public function testMidiaDeAvisoForaDeExibicaoRetorna404(string $rotulo, int $inicio, int $fim, array $extra): void
    {
        $nome = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->a, $rotulo, $inicio, $fim, ['midia' => $nome, 'midia_tipo' => 'imagem'] + $extra);

        $r = $this->get('/tv/' . self::TOKEN . "/media/{$nome}");

        $this->assertSame(404, $r->status);
        $this->assertNull($r->arquivo);
    }

    public function testIsolamentoMidiaDoOutroCondominioNaoSaiNaTvDeOutro(): void
    {
        $doBeta = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->b, 'Beta', -60, 3600, ['midia' => $doBeta, 'midia_tipo' => 'imagem']);

        // A TV do Alfa conhece o nome exato do arquivo do Beta e mesmo assim não o recebe.
        $r = $this->get('/tv/' . self::TOKEN . "/media/{$doBeta}");
        $this->assertSame(404, $r->status);
        $this->assertNull($r->arquivo);
        $this->assertStringNotContainsString('Beta', $r->body);

        $this->assertSame(200, $this->get('/tv/' . self::TOKEN_B . "/media/{$doBeta}")->status, 'A do Beta recebe normalmente.');
    }

    public function testMidiaDeDispositivoRevogadoOuCondominioDesativadoRetorna404(): void
    {
        $nome = $this->arquivo(Amostras::png());
        $this->avisoTv($this->pdo, $this->a, 'Com foto', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'imagem']);
        $rota = '/tv/' . self::TOKEN . "/media/{$nome}";
        $this->assertSame(200, $this->get($rota)->status);

        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->a}");
        $this->assertSame(404, $this->get($rota)->status);

        $this->pdo->exec("UPDATE condominios SET ativo = true WHERE id = {$this->a}");
        $this->pdo->exec("UPDATE dispositivos SET ativo = false WHERE token = '" . self::TOKEN . "'");
        $this->assertSame(404, $this->get($rota)->status);
    }

    public function testMidiaComNomeForaDoPadraoNaoCasaComARota(): void
    {
        foreach (['../../../etc/passwd', 'abc.png', str_repeat('a', 32) . '.php', str_repeat('A', 32) . '.png', str_repeat('a', 32) . '.png.php', str_repeat('a', 31) . '.png'] as $nome) {
            $this->assertSame(404, $this->get('/tv/' . self::TOKEN . "/media/{$nome}")->status, $nome);
        }
    }

    public function testArquivoSumidoDoDiscoRetorna404(): void
    {
        $nome = str_repeat('c', 32) . '.png';
        $this->avisoTv($this->pdo, $this->a, 'Sem arquivo', -60, 3600, ['midia' => $nome, 'midia_tipo' => 'imagem']);

        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . "/media/{$nome}")->status);
    }

    // ---------- Logo e tema ----------

    public function testLogoPersonalizadaDoPropriocondominio(): void
    {
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN . '/logo')->status, 'Sem logo própria.');

        $logo = $this->arquivo(Amostras::jpg(), 'jpg');
        $this->pdo->exec("UPDATE condominios SET logo = '{$logo}' WHERE id = {$this->a}");

        $r = $this->get('/tv/' . self::TOKEN . '/logo');
        $this->assertSame(200, $r->status);
        $this->assertSame('image/jpeg', $r->headers['Content-Type']);
        $this->assertSame(404, $this->get('/tv/' . self::TOKEN_B . '/logo')->status, 'A TV do Beta não recebe a logo do Alfa.');
        $this->assertStringContainsString('/tv/' . self::TOKEN . '/logo?v=' . substr($logo, 0, 12), $this->estado()['logo']);
    }

    public function testTemaCssComAsCoresDoCondominio(): void
    {
        $this->pdo->exec("UPDATE condominios SET cor_primaria = '#1E3A8A', cor_secundaria = '#FACC15' WHERE id = {$this->a}");

        $r = $this->get('/tv/' . self::TOKEN . '/tema.css');

        $this->assertSame(200, $r->status);
        $this->assertStringStartsWith('text/css', $r->headers['Content-Type']);
        $this->assertStringContainsString('--tv-primaria: #1E3A8A;', $r->body);
        $this->assertStringContainsString('--tv-secundaria: #FACC15;', $r->body);
        $this->assertStringContainsString('--tv-secundaria-texto: #1D2125;', $r->body);
        $this->assertStringContainsString('#00A67E', $this->get('/tv/' . self::TOKEN_B . '/tema.css')->body, 'O do Beta continua no padrão.');
    }

    // ---------- Condomínio desativado / bloqueado ----------

    public function testCondominioDesativadoMostraSoORelogioSemAvisos(): void
    {
        $this->avisoTv($this->pdo, $this->a, 'Não pode aparecer', -60, 3600);
        $this->pdo->exec("UPDATE condominios SET ativo = false WHERE id = {$this->a}");

        $estado = $this->estado();

        $this->assertTrue($estado['bloqueado']);
        $this->assertSame([], $estado['avisos']);
        $this->assertStringNotContainsString('Não pode aparecer', $this->get('/tv/' . self::TOKEN)->body);
    }

    // ---------- Quem está logado não muda nada ----------

    public function testLogadoOuNaoORetornoEOMesmoESemCookies(): void
    {
        $anonimo = $this->get('/tv/' . self::TOKEN . '/state');
        $this->logar('g@exemplo.com.br');
        $logado = $this->get('/tv/' . self::TOKEN . '/state');

        $this->assertSame(200, $logado->status);
        $this->assertSame(json_decode($anonimo->body, true)['version'], json_decode($logado->body, true)['version']);
    }

    public function testOsLogsDaAplicacaoNaoRecebemOTokenNemNada(): void
    {
        $this->get('/tv/ZZZZZZZZZZZZ/state');
        $this->get('/tv/' . self::TOKEN . '/state');

        $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($this->log));
        $this->assertStringNotContainsString('ZZZZZZZZZZZZ', (string) file_get_contents($this->log));
    }
}
