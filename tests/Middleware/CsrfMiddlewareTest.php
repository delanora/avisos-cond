<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\CsrfMiddleware;
use App\Middleware\Negado;
use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    private Csrf $csrf;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->csrf = new Csrf(Session::emMemoria());
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function executar(Request $request): Response
    {
        $mw = new CsrfMiddleware($this->csrf, new Negado(new View(dirname(__DIR__, 2) . '/templates')));

        return $mw->process($request, static fn (): Response => Response::html('ok'));
    }

    public function testRequisicoesDeLeituraNaoPrecisamDeToken(): void
    {
        $this->assertSame('ok', $this->executar(new Request('GET', '/'))->body);
    }

    /** @return array<string, array{string}> */
    public static function metodosDeEscrita(): array
    {
        return ['POST' => ['POST'], 'PUT' => ['PUT'], 'PATCH' => ['PATCH'], 'DELETE' => ['DELETE']];
    }

    #[DataProvider('metodosDeEscrita')]
    public function testEscritaSemTokenEBloqueada(string $metodo): void
    {
        $r = $this->executar(new Request($metodo, '/'));

        $this->assertSame(403, $r->status);
        $this->assertStringNotContainsString('ok', $r->body);
    }

    #[DataProvider('metodosDeEscrita')]
    public function testEscritaComTokenNoCorpoPassa(string $metodo): void
    {
        $r = $this->executar(new Request($metodo, '/', body: ['_csrf' => $this->csrf->token()]));

        $this->assertSame('ok', $r->body);
    }

    public function testTokenNoCabecalhoDoHtmxPassa(): void
    {
        $r = $this->executar(new Request('POST', '/', server: ['HTTP_X_CSRF_TOKEN' => $this->csrf->token()]));

        $this->assertSame('ok', $r->body);
    }

    public function testTokenErradoOuDeOutraSessaoEBloqueado(): void
    {
        $this->csrf->token();

        $this->assertSame(403, $this->executar(new Request('POST', '/', body: ['_csrf' => 'errado']))->status);
        $this->assertSame(403, $this->executar(new Request('POST', '/', body: ['_csrf' => ['array']]))->status);
        $this->assertSame(403, $this->executar(new Request('POST', '/', server: ['HTTP_X_CSRF_TOKEN' => 'errado']))->status);
    }
}
