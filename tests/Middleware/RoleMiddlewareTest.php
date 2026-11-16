<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\Negado;
use App\Middleware\RoleMiddleware;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use PHPUnit\Framework\TestCase;

final class RoleMiddlewareTest extends TestCase
{
    private function executar(?string $papel, array $permitidos = ['superadmin']): Response
    {
        $mw = new RoleMiddleware($permitidos, new Negado(new View(dirname(__DIR__, 2) . '/templates')));
        $request = new Request('GET', '/admin');
        if ($papel !== null) {
            $request = $request->withAttribute('usuario', ['papel' => $papel]);
        }

        return $mw->process($request, static fn (): Response => Response::html('liberado'));
    }

    public function testPapelPermitidoPassa(): void
    {
        $this->assertSame('liberado', $this->executar('superadmin')->body);
    }

    public function testPapelNaoPermitidoRecebe403(): void
    {
        $r = $this->executar('gerente');

        $this->assertSame(403, $r->status);
        $this->assertStringNotContainsString('liberado', $r->body);
    }

    public function testSemUsuarioNegaPorPadrao(): void
    {
        $this->assertSame(403, $this->executar(null)->status);
    }

    public function testListaVaziaNegaTodos(): void
    {
        $this->assertSame(403, $this->executar('superadmin', [])->status);
    }

    public function testPapelDesconhecidoEhNegado(): void
    {
        $this->assertSame(403, $this->executar('zelador')->status);
    }
}
