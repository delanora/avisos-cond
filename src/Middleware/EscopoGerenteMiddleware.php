<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\CondominioRepository;
use App\Repositories\PadroesDoGerenteRepository;
use App\Support\Request;
use App\Support\Response;

/**
 * Carrega do BANCO, a cada requisição, os condomínios ativos aos quais o gerente está vinculado
 * (nunca da sessão nem do cliente). Um vínculo removido ou um condomínio desativado pelo admin
 * vale imediatamente. Deve vir depois do AuthMiddleware.
 */
final class EscopoGerenteMiddleware implements Middleware
{
    public function __construct(
        private readonly CondominioRepository $condominios,
        private readonly ?PadroesDoGerenteRepository $padroes = null,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $usuario = $request->attribute('usuario');
        // Primeira vez do gerente no painel: entrega a cópia dele dos tipos e modelos de fábrica (uma única vez).
        if (is_array($usuario) && $this->padroes !== null && !($usuario['padroes_criados'] ?? true)) {
            $this->padroes->garantir((int) $usuario['id']);
        }
        $condominios = is_array($usuario) ? $this->condominios->listarDoGerente((int) $usuario['id']) : [];

        return $proximo($request->withAttribute('condominios', $condominios));
    }
}
