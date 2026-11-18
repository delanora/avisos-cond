<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\ModeloAvisoService;
use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Modelos de aviso do GERENTE logado (não do condomínio): lista, criar, editar e apagar. O dono é sempre o usuário da sessão. */
final class ModelosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly ModeloAvisoService $modelos,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    private function usuarioId(Request $request): int
    {
        return (int) $request->attribute('usuario')['id'];
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {

        return Response::html($this->view->render('gerente/modelos/lista.html.twig', EscopoGerente::contexto($request) + [
            'modelos' => $this->modelos->listar($this->usuarioId($request)),
            'menu' => 'modelos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {

        return $this->formulario($request, null, ['nome' => '', 'titulo' => '', 'corpo' => '', 'tipo' => 'comum'], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {

        try {
            $this->modelos->criar($this->usuarioId($request), $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, null, $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Modelo criado com sucesso.');

        return Response::redirect('/gerente/modelos', 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $m = $this->modelos->buscarProprio($this->usuarioId($request), (int) $vars['id']);

        return $this->formulario($request, $m, ['nome' => $m['nome'], 'titulo' => $m['titulo'], 'corpo' => $m['corpo'], 'tipo' => $m['tipo']], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $usuario = $this->usuarioId($request);
        $id = (int) $vars['id'];

        try {
            $this->modelos->atualizar($usuario, $id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $this->modelos->buscarProprio($usuario, $id), $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Modelo atualizado com sucesso.');

        return Response::redirect('/gerente/modelos', 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $m = $this->modelos->buscarProprio($this->usuarioId($request), (int) $vars['id']);

        return Response::html($this->view->render('gerente/modelos/excluir.html.twig', EscopoGerente::contexto($request) + [
            'modelo' => $m,
            'menu' => 'modelos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {

        $this->modelos->excluir($this->usuarioId($request), (int) $vars['id']);
        $this->sessao->flash('Modelo apagado.');

        return Response::redirect('/gerente/modelos', 303);
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        return ['nome' => trim($texto('nome')), 'titulo' => trim($texto('titulo')), 'corpo' => trim($texto('corpo')), 'tipo' => $texto('tipo')];
    }

    /**
     * @param array<string, mixed>|null $modelo modelo em edição
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     */
    private function formulario(Request $request, ?array $modelo, array $valores, array $erros, int $status = 200): Response
    {
        $base = '/gerente/modelos';

        return Response::html($this->view->render('gerente/modelos/form.html.twig', EscopoGerente::contexto($request) + [
            'editando' => $modelo !== null,
            'acao' => $modelo === null ? $base : "{$base}/{$modelo['id']}",
            'valores' => $valores,
            'erros' => $erros,
            'tipos' => $this->tipos->listar($this->usuarioId($request)),
            'menu' => 'modelos',
        ]), $status);
    }
}
