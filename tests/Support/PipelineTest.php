<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Middleware\Middleware;
use App\Support\Pipeline;
use App\Support\Request;
use App\Support\Response;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    private function middleware(string $nome, array &$ordem): Middleware
    {
        return new class ($nome, $ordem) implements Middleware {
            /** @param list<string> $ordem */
            public function __construct(private string $nome, private array &$ordem)
            {
            }

            public function process(Request $request, callable $proximo): Response
            {
                $this->ordem[] = $this->nome;

                return $proximo($request->withAttribute($this->nome, true));
            }
        };
    }

    public function testExecutaNaOrdemEPassaAtributosEVariaveis(): void
    {
        $ordem = [];
        $handler = static function (Request $req, array $vars) use (&$ordem): Response {
            $ordem[] = 'handler';

            return Response::html($vars['id'] . ':' . (int) $req->attribute('a') . (int) $req->attribute('b'));
        };

        $resposta = Pipeline::envolver($handler, $this->middleware('a', $ordem), $this->middleware('b', $ordem))(
            new Request('GET', '/'),
            ['id' => '7']
        );

        $this->assertSame(['a', 'b', 'handler'], $ordem);
        $this->assertSame('7:11', $resposta->body);
    }

    public function testMiddlewarePodeInterromperAExecucao(): void
    {
        $chamado = false;
        $bloqueia = new class implements Middleware {
            public function process(Request $request, callable $proximo): Response
            {
                return new Response(403);
            }
        };

        $resposta = Pipeline::envolver(static function () use (&$chamado): Response {
            $chamado = true;

            return Response::html('x');
        }, $bloqueia)(new Request('GET', '/'), []);

        $this->assertSame(403, $resposta->status);
        $this->assertFalse($chamado);
    }
}
