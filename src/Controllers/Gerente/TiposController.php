<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Tipos de aviso de UM condomínio (o da URL): lista, criar, editar e apagar os tipos próprios. */
final class TiposController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return Response::html($this->view->render('gerente/tipos/lista.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'tipos' => $this->tipos->listar(EscopoGerente::permitidos($request), $condominio['id']),
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return $this->formulario($request, $condominio, null, ['nome' => '', 'cor' => '#2F6FED', 'fixo' => false], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        try {
            $this->tipos->criar(EscopoGerente::permitidos($request), $condominio['id'], $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, null, $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Tipo criado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/tipos", 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $tipo = $this->tipos->buscarProprio(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return $this->formulario($request, $condominio, $tipo, ['nome' => $tipo['nome'], 'cor' => $tipo['cor'] ?? '#2F6FED', 'fixo' => $tipo['fixo']], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $permitidos = EscopoGerente::permitidos($request);
        $id = (int) $vars['id'];

        try {
            $this->tipos->atualizar($permitidos, $condominio['id'], $id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, $this->tipos->buscarProprio($permitidos, $condominio['id'], $id), $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Tipo atualizado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/tipos", 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $tipo = $this->tipos->buscarProprio(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return Response::html($this->view->render('gerente/tipos/excluir.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'tipo' => $tipo,
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        try {
            $this->tipos->excluir(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);
        } catch (ValidacaoException $e) {
            $this->sessao->flash($e->erros['tipo'] ?? 'Não foi possível apagar o tipo.');

            return Response::redirect("/gerente/condominios/{$condominio['id']}/tipos", 303);
        }

        $this->sessao->flash('Tipo apagado.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/tipos", 303);
    }

    /**
     * Folha de estilo com as cores dos tipos deste condomínio (etiquetas do painel e prévia da TV).
     *
     * @param array<string, string> $vars
     */
    public function css(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return new Response(200, TipoAvisoService::css($this->tipos->listar(EscopoGerente::permitidos($request), $condominio['id'])), [
            'Content-Type' => 'text/css; charset=utf-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-cache',
        ]);
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        return ['nome' => $texto('nome'), 'cor' => $texto('cor') !== '' ? $texto('cor') : '#2F6FED', 'fixo' => $texto('fixo') === '1'];
    }

    /**
     * @param array<string, mixed>      $condominio
     * @param array<string, mixed>|null $tipo tipo em edição
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     */
    private function formulario(Request $request, array $condominio, ?array $tipo, array $valores, array $erros, int $status = 200): Response
    {
        $base = "/gerente/condominios/{$condominio['id']}/tipos";

        return Response::html($this->view->render('gerente/tipos/form.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'editando' => $tipo !== null,
            'tipo' => $tipo,
            'acao' => $tipo === null ? $base : "{$base}/{$tipo['id']}",
            'valores' => $valores,
            'erros' => $erros,
            'menu' => 'avisos',
        ]), $status);
    }
}
