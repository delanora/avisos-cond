<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;

/**
 * Restringe a rota a certos papéis. Deve vir depois do AuthMiddleware.
 * Nega por padrão: sem usuário autenticado ou com papel fora da lista, responde 403.
 */
final class RoleMiddleware implements Middleware
{
    /** @param list<string> $papeis */
    public function __construct(
        private readonly array $papeis,
        private readonly Negado $negado,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $usuario = $request->attribute('usuario');
        $papel = is_array($usuario) ? ($usuario['papel'] ?? null) : null;

        if (!is_string($papel) || !in_array($papel, $this->papeis, true)) {
            return $this->negado->resposta(403, 'Você não tem permissão para acessar esta página.');
        }

        return $proximo($request);
    }
}
