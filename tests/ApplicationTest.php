<?php

declare(strict_types=1);

namespace Tests;

use App\Application;
use App\Controllers\HomeController;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\SecurityHeaders;
use App\Support\View;
use FastRoute\RouteCollector;
use PHPUnit\Framework\TestCase;

use function FastRoute\simpleDispatcher;

final class ApplicationTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $this->log = tempnam(sys_get_temp_dir(), 'log');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    private function app(?string $templates = null): Application
    {
        $view = new View($templates ?? dirname(__DIR__) . '/templates');
        $home = new HomeController($view);

        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($home): void {
            $r->addRoute('GET', '/', [$home, 'index']);
            $r->addRoute('POST', '/enviar', static fn (Request $req, array $v): Response => Response::html('ok'));
            $r->addRoute('GET', '/item/{id:\d+}', static fn (Request $req, array $v): Response => Response::html('item ' . $v['id']));
            $r->addRoute('GET', '/quebra', static function (): Response {
                throw new \RuntimeException('segredo-interno-123');
            });
            $r->addRoute('GET', '/invalido', static fn (): string => 'nao e Response');
        });

        return new Application($dispatcher, $view, new Logger($this->log), new SecurityHeaders());
    }

    public function testRotaEncontradaRenderizaPaginaInicial(): void
    {
        $r = $this->app()->handle(new Request('GET', '/'));

        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('Avisos-Cond', $r->body);
    }

    public function testVariaveisDaRotaChegamAoHandler(): void
    {
        $r = $this->app()->handle(new Request('GET', '/item/42'));

        $this->assertSame('item 42', $r->body);
    }

    public function testRotaInexistenteRetorna404(): void
    {
        $r = $this->app()->handle(new Request('GET', '/nao-existe'));

        $this->assertSame(404, $r->status);
        $this->assertStringContainsString('Página não encontrada', $r->body);
    }

    public function testRotaComRestricaoNaoCasaRetorna404(): void
    {
        $this->assertSame(404, $this->app()->handle(new Request('GET', '/item/abc'))->status);
    }

    public function testMetodoNaoPermitidoRetorna405ComAllow(): void
    {
        $r = $this->app()->handle(new Request('GET', '/enviar'));

        $this->assertSame(405, $r->status);
        $this->assertSame('POST', $r->headers['Allow']);
    }

    public function testErroInternoMostraMensagemGenericaELogaOsDetalhes(): void
    {
        $r = $this->app()->handle(new Request('GET', '/quebra'));

        $this->assertSame(500, $r->status);
        $this->assertStringContainsString('erro inesperado', $r->body);
        $this->assertStringNotContainsString('segredo-interno-123', $r->body);
        $this->assertStringNotContainsString('RuntimeException', $r->body);
        $this->assertStringNotContainsString('Stack trace', $r->body);

        $log = (string) file_get_contents($this->log);
        $this->assertStringContainsString('segredo-interno-123', $log);
        $this->assertStringContainsString('GET /quebra', $log);
    }

    public function testHandlerQueNaoRetornaResponseViraErro500(): void
    {
        $r = $this->app()->handle(new Request('GET', '/invalido'));

        $this->assertSame(500, $r->status);
        $this->assertStringContainsString('Response', (string) file_get_contents($this->log));
    }

    public function testFalhaNaPaginaDeErroCaiParaTextoSimples(): void
    {
        // Diretório existente, mas sem erro.html.twig: a renderização da página de erro falha.
        $r = $this->app(__DIR__ . '/fixtures/templates')->handle(new Request('GET', '/nao-existe'));

        $this->assertSame(404, $r->status);
        $this->assertSame('Página não encontrada.', $r->body);
        $this->assertStringContainsString('text/plain', $r->headers['Content-Type']);
    }

    public function testTodasAsRespostasTemCabecalhosDeSeguranca(): void
    {
        foreach (['/', '/nao-existe', '/quebra'] as $caminho) {
            $r = $this->app()->handle(new Request('GET', $caminho));

            $this->assertTrue($r->hasHeader('Content-Security-Policy'), $caminho);
            $this->assertSame('nosniff', $r->headers['X-Content-Type-Options'], $caminho);
        }
    }
}
