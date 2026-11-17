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

/** Modelos de aviso de UM condomínio (o da URL): lista, criar, editar e apagar os modelos próprios. */
final class ModelosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly ModeloAvisoService $modelos,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return Response::html($this->view->render('gerente/modelos/lista.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'modelos' => $this->modelos->listar(EscopoGerente::permitidos($request), $condominio['id']),
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        return $this->formulario($request, $condominio, null, ['nome' => '', 'titulo' => '', 'corpo' => '', 'tipo' => 'comum'], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        try {
            $this->modelos->criar(EscopoGerente::permitidos($request), $condominio['id'], $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, null, $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Modelo criado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/modelos", 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $m = $this->modelos->buscarProprio(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return $this->formulario($request, $condominio, $m, ['nome' => $m['nome'], 'titulo' => $m['titulo'], 'corpo' => $m['corpo'], 'tipo' => $m['tipo']], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $permitidos = EscopoGerente::permitidos($request);
        $id = (int) $vars['id'];

        try {
            $this->modelos->atualizar($permitidos, $condominio['id'], $id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $condominio, $this->modelos->buscarProprio($permitidos, $condominio['id'], $id), $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Modelo atualizado com sucesso.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/modelos", 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $m = $this->modelos->buscarProprio(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);

        return Response::html($this->view->render('gerente/modelos/excluir.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'modelo' => $m,
            'menu' => 'avisos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);

        $this->modelos->excluir(EscopoGerente::permitidos($request), $condominio['id'], (int) $vars['id']);
        $this->sessao->flash('Modelo apagado.');

        return Response::redirect("/gerente/condominios/{$condominio['id']}/modelos", 303);
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        return ['nome' => trim($texto('nome')), 'titulo' => trim($texto('titulo')), 'corpo' => trim($texto('corpo')), 'tipo' => $texto('tipo')];
    }

    /**
     * @param array<string, mixed>      $condominio
     * @param array<string, mixed>|null $modelo modelo em edição
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     */
    private function formulario(Request $request, array $condominio, ?array $modelo, array $valores, array $erros, int $status = 200): Response
    {
        $base = "/gerente/condominios/{$condominio['id']}/modelos";

        return Response::html($this->view->render('gerente/modelos/form.html.twig', EscopoGerente::contexto($request, $condominio) + [
            'editando' => $modelo !== null,
            'acao' => $modelo === null ? $base : "{$base}/{$modelo['id']}",
            'valores' => $valores,
            'erros' => $erros,
            'tipos' => $this->tipos->listar(EscopoGerente::permitidos($request), $condominio['id']),
            'menu' => 'avisos',
        ]), $status);
    }
}
