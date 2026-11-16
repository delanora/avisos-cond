<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\Negado;
use App\Middleware\TamanhoMiddleware;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use PHPUnit\Framework\TestCase;

final class TamanhoMiddlewareTest extends TestCase
{
    private function executar(?string $contentLength, int $maximo = 2097152): Response
    {
        $mw = new TamanhoMiddleware(new Negado(new View(dirname(__DIR__, 2) . '/templates')), $maximo);
        $servidor = $contentLength === null ? [] : ['CONTENT_LENGTH' => $contentLength];

        return $mw->process(new Request('POST', '/x', server: $servidor), static fn (): Response => Response::html('PASSOU'));
    }

    public function testEnvioDentroDoLimitePassa(): void
    {
        $this->assertSame('PASSOU', $this->executar('2097152')->body);
        $this->assertSame('PASSOU', $this->executar('100')->body);
        $this->assertSame('PASSOU', $this->executar(null)->body);
    }

    public function testEnvioMaiorQueOLimiteRecebe413ComMensagemClara(): void
    {
        $r = $this->executar('2097153');

        $this->assertSame(413, $r->status);
        $this->assertStringContainsString('maior que o permitido (2 MB)', $r->body);
        $this->assertStringNotContainsString('PASSOU', $r->body);
        $this->assertStringContainsString('Arquivo grande demais', $r->body);
    }

    public function testConteudoInvalidoNaoQuebra(): void
    {
        $this->assertSame('PASSOU', $this->executar('abc')->body);
    }
}
