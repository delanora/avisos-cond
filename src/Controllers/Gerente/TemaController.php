<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AparenciaService;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;

/**
 * CSS com as cores do condomínio (variáveis CSS). O CSP não permite estilos inline, então as cores
 * dinâmicas chegam por uma folha de estilo própria. As cores são validadas como #RRGGBB.
 */
final class TemaController
{
    public function __construct(private readonly AparenciaService $aparencia)
    {
    }

    /** @param array<string, string> $vars */
    public function css(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return new Response(200, self::gerar($this->aparencia->resolver($condominio)), [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param array{cor_primaria: string, cor_secundaria: string} $aparencia valores já validados
     */
    public static function gerar(array $aparencia): string
    {
        return sprintf(
            ":root {\n    --tv-primaria: %s;\n    --tv-primaria-texto: %s;\n    --tv-secundaria: %s;\n    --tv-secundaria-texto: %s;\n}\n",
            $aparencia['cor_primaria'],
            AparenciaService::corDoTexto($aparencia['cor_primaria']),
            $aparencia['cor_secundaria'],
            AparenciaService::corDoTexto($aparencia['cor_secundaria'])
        );
    }
}
