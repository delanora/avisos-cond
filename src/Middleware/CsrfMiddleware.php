<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Csrf;
use App\Support\Request;
use App\Support\Response;

/** Valida o token CSRF em todo POST/PUT/PATCH/DELETE. */
final class CsrfMiddleware implements Middleware
{
    private const METODOS_COM_ESCRITA = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(
        private readonly Csrf $csrf,
        private readonly Negado $negado,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        if (in_array($request->method, self::METODOS_COM_ESCRITA, true)) {
            $informado = $request->body['_csrf'] ?? $request->header('X-CSRF-Token');

            if (!is_string($informado) || !$this->csrf->valido($informado)) {
                return $this->negado->resposta(403, 'Sessão expirada ou requisição inválida. Recarregue a página e tente novamente.');
            }
        }

        return $proximo($request);
    }
}
