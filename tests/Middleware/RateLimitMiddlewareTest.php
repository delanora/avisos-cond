<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\Negado;
use App\Middleware\RateLimitMiddleware;
use App\Repositories\RateLimitRepository;
use App\Services\RateLimiter;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use Tests\BancoMigradoTestCase;

final class RateLimitMiddlewareTest extends BancoMigradoTestCase
{
    private function middleware(int $maximo = 3): RateLimitMiddleware
    {
        return new RateLimitMiddleware(
            new RateLimiter(new RateLimitRepository($this->pdo)),
            new Negado(new View(dirname(__DIR__, 2) . '/templates')),
            'teste',
            $maximo,
            60,
            600
        );
    }

    private function chamar(RateLimitMiddleware $mw, string $ip): Response
    {
        return $mw->process(
            new Request('POST', '/login', server: ['REMOTE_ADDR' => $ip]),
            static fn (): Response => Response::html('PASSOU-PELO-MIDDLEWARE')
        );
    }

    public function testPermiteAteOMaximoEDepoisRetorna429ComRetryAfter(): void
    {
        $mw = $this->middleware();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->chamar($mw, '203.0.113.1')->status);
        }

        $r = $this->chamar($mw, '203.0.113.1');
        $this->assertSame(429, $r->status);
        $this->assertSame('600', $r->headers['Retry-After']);
        $this->assertStringNotContainsString('PASSOU-PELO-MIDDLEWARE', $r->body);

        // Continua bloqueado nas tentativas seguintes.
        $this->assertSame(429, $this->chamar($mw, '203.0.113.1')->status);
    }

    public function testOBloqueioEPorIp(): void
    {
        $mw = $this->middleware(1);
        $this->chamar($mw, '203.0.113.1');
        $this->chamar($mw, '203.0.113.1');

        $this->assertSame(429, $this->chamar($mw, '203.0.113.1')->status);
        $this->assertSame(200, $this->chamar($mw, '203.0.113.2')->status);
    }

    public function testEscoposDiferentesTemContadoresDiferentes(): void
    {
        $outro = new RateLimitMiddleware(
            new RateLimiter(new RateLimitRepository($this->pdo)),
            new Negado(new View(dirname(__DIR__, 2) . '/templates')),
            'outro-escopo',
            1,
            60,
            600
        );
        $mw = $this->middleware(1);
        $this->chamar($mw, '203.0.113.1');
        $this->chamar($mw, '203.0.113.1');

        $this->assertSame(429, $this->chamar($mw, '203.0.113.1')->status);
        $this->assertSame(200, $this->chamar($outro, '203.0.113.1')->status);
    }
}
