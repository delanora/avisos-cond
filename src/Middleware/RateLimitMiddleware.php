<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\RateLimiter;
use App\Support\Request;
use App\Support\Response;

/**
 * Limita requisições por IP em uma rota (ex.: tentativas de login). Cada requisição conta;
 * passando do máximo na janela, o IP fica bloqueado por um tempo e recebe 429.
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly RateLimiter $limitador,
        private readonly Negado $negado,
        private readonly string $escopo,
        private readonly int $maximo,
        private readonly int $janelaSegundos,
        private readonly int $bloqueioSegundos,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $chave = $this->escopo . ':ip:' . $request->ip();

        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera === 0 && $this->limitador->registrar($chave, $this->maximo, $this->janelaSegundos, $this->bloqueioSegundos)) {
            $espera = $this->bloqueioSegundos;
        }

        if ($espera > 0) {
            return $this->negado
                ->resposta(429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.')
                ->withHeader('Retry-After', (string) $espera);
        }

        return $proximo($request);
    }
}
