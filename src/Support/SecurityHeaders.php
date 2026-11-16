<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cabeçalhos de segurança padrão. Uma rota pode definir o seu próprio valor
 * (ex.: Referrer-Policy: no-referrer na TV); aqui só preenchemos o que faltar.
 *
 * O CSP não permite scripts/estilos inline: todo JS e CSS vem de /assets.
 * (O Alpine.js exigirá a build "CSP" dele ao ser adicionado.)
 * HSTS fica no proxy reverso (AGENTS.md 6.9).
 */
final class SecurityHeaders
{
    private const PADRAO = [
        'Content-Security-Policy' => "default-src 'self'; img-src 'self' data:; media-src 'self'; "
            . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'",
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-Frame-Options' => 'DENY',
    ];

    public function apply(Response $resposta): Response
    {
        foreach (self::PADRAO as $nome => $valor) {
            if (!$resposta->hasHeader($nome)) {
                $resposta = $resposta->withHeader($nome, $valor);
            }
        }

        return $resposta;
    }
}
