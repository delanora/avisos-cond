<?php

declare(strict_types=1);

namespace App\Support;

use App\Middleware\Middleware;

/**
 * Envolve o handler de uma rota com middlewares. O primeiro da lista é o mais externo
 * (executa primeiro). O resultado tem o mesmo formato de um handler: fn(Request, array): Response.
 */
final class Pipeline
{
    /** @return callable(Request, array<string, string>): Response */
    public static function envolver(callable $handler, Middleware ...$middlewares): callable
    {
        return static function (Request $request, array $vars) use ($handler, $middlewares): Response {
            $proximo = static fn (Request $req): Response => $handler($req, $vars);

            foreach (array_reverse($middlewares) as $middleware) {
                $seguinte = $proximo;
                $proximo = static fn (Request $req): Response => $middleware->process($req, $seguinte);
            }

            return $proximo($request);
        };
    }
}
