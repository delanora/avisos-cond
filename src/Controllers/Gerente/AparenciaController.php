<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\AparenciaService;
use App\Services\PersonalizacaoService;
use App\Services\ValidacaoException;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Logo e cores do condomínio (aparência na TV). */
final class AparenciaController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly PersonalizacaoService $personalizacao,
        private readonly AparenciaService $aparencia,
    ) {
    }

    /** @param array<string, string> $vars */
    public function formulario(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $atual = $this->aparencia->resolver($condominio);

        return $this->pagina($request, $condominio, [
            'usar_padrao' => $condominio['cor_primaria'] === null && $condominio['cor_secundaria'] === null,
            'cor_primaria' => $atual['cor_primaria'],
            'cor_secundaria' => $atual['cor_secundaria'],
        ], []);
    }

    /** @param array<string, string> $vars */
    public function salvar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        try {
            $this->personalizacao->salvar(
                EscopoGerente::permitidos($request),
                $condominio['id'],
                $request->body,
                $request->files['logo'] ?? null
            );
        } catch (ValidacaoException $e) {
            $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

            return $this->pagina($request, $condominio, [
                'usar_padrao' => $texto('usar_padrao') === '1',
                'cor_primaria' => $texto('cor_primaria'),
                'cor_secundaria' => $texto('cor_secundaria'),
            ], $e->erros, 422);
        }

        $this->sessao->flash('Aparência atualizada com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/aparencia", 303);
    }

    /**
     * @param array<string, mixed>  $condominio
     * @param array<string, mixed>  $valores
     * @param array<string, string> $erros
     */
    private function pagina(Request $request, array $condominio, array $valores, array $erros, int $status = 200): Response
    {
        return Response::html($this->view->render('gerente/aparencia.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'valores' => $valores,
            'erros' => $erros,
            'aparencia' => $this->aparencia->resolver($condominio, "/gerente/condominios/{$condominio['id']}/logo"),
            'menu' => 'aparencia',
        ]), $status);
    }
}
