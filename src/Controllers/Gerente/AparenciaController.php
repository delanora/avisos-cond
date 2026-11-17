<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AparenciaService;
use App\Services\ValidacaoException;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Cor de destaque do painel do gerente. É do próprio gerente (não de condomínio): só ele vê, e só o painel dele muda.
 * Nada aqui recebe condominio_id.
 */
final class AparenciaController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly AparenciaService $aparencia,
    ) {
    }

    /** @param array<string, string> $vars */
    public function formulario(Request $request, array $vars): Response
    {
        $cor = $request->attribute('usuario')['cor_primaria'] ?? null;

        return $this->pagina($request, [
            'usar_padrao' => $cor === null,
            'cor_primaria' => $cor ?? AparenciaService::COR_PADRAO,
        ], []);
    }

    /** @param array<string, string> $vars */
    public function salvar(Request $request, array $vars): Response
    {
        $usuario = $request->attribute('usuario');
        $usarPadrao = ($request->body['usar_padrao'] ?? '') === '1';

        try {
            $this->aparencia->salvar($usuario['id'], $request->body['cor_primaria'] ?? null, $usarPadrao);
        } catch (ValidacaoException $e) {
            $cor = $request->body['cor_primaria'] ?? '';

            return $this->pagina($request, [
                'usar_padrao' => false,
                'cor_primaria' => is_string($cor) ? $cor : '',
            ], $e->erros, 422);
        }

        $this->sessao->flash('Aparência atualizada com sucesso.');

        return Response::redirect('/gerente/aparencia', 303);
    }

    /** CSS com as variáveis da cor do gerente logado (nada de condomínio entra aqui). @param array<string, string> $vars */
    public function css(Request $request, array $vars): Response
    {
        $cor = $request->attribute('usuario')['cor_primaria'] ?? null;
        $css = is_string($cor) && AparenciaService::corValida($cor) ? AparenciaService::css($cor) : '';

        return new Response(200, $css, [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /**
     * @param array<string, mixed>  $valores
     * @param array<string, string> $erros
     */
    private function pagina(Request $request, array $valores, array $erros, int $status = 200): Response
    {
        $corValida = is_string($valores['cor_primaria']) && AparenciaService::corValida($valores['cor_primaria']);

        return Response::html($this->view->render('gerente/aparencia.html.twig', EscopoGerente::contexto($request) + [
            'valores' => $valores,
            'erros' => $erros,
            'paleta' => AparenciaService::paleta($corValida ? $valores['cor_primaria'] : AparenciaService::COR_PADRAO),
            'menu' => 'aparencia',
        ]), $status);
    }
}
