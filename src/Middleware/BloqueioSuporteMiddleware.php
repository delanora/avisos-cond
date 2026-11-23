<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;

/**
 * Nega a rota em modo suporte (administrador vendo o painel como um gerente). Serve para o que é pessoal do
 * gerente e não pode ser mexido por quem está dando suporte: troca de senha e aparência. Vem depois do Auth.
 */
final class BloqueioSuporteMiddleware implements Middleware
{
    public function __construct(private readonly Negado $negado)
    {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $usuario = $request->attribute('usuario');
        if (is_array($usuario) && isset($usuario['suporte_admin'])) {
            return $this->negado->resposta(403, 'Indisponível no modo suporte. Saia do modo suporte para usar esta opção na sua própria conta.');
        }

        return $proximo($request);
    }
}
