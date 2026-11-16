<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AparenciaService;
use App\Services\GestaoCondominiosService;
use App\Services\ListaDeIds;
use App\Services\ValidacaoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** CRUD de condomínios e dos gerentes vinculados (somente superadmin). */
final class CondominiosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly GestaoCondominiosService $condominios,
        private readonly AparenciaService $aparencia,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $lista = array_map(function (array $condominio): array {
            $condominio['personalizada'] = $this->aparencia->resolver($condominio)['personalizada'];

            return $condominio;
        }, $this->condominios->listar());

        return Response::html($this->view->render('admin/condominios/lista.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'condominios' => $lista,
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        return $this->formulario($request, false, '/admin/condominios', [
            'nome' => '', 'endereco' => '', 'ativo' => true, 'gerentes' => [],
        ], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        try {
            $this->condominios->criar($request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, false, '/admin/condominios', $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Condomínio criado com sucesso.');

        return Response::redirect('/admin/condominios', 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $alvo = $this->condominios->buscar((int) $vars['id']);

        return $this->formulario($request, true, "/admin/condominios/{$alvo['id']}", [
            'nome' => $alvo['nome'],
            'endereco' => $alvo['endereco'] ?? '',
            'ativo' => $alvo['ativo'],
            'gerentes' => $alvo['gerentes'],
        ], [], 200, $alvo);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];

        try {
            $this->condominios->atualizar($id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, true, "/admin/condominios/{$id}", $this->valoresEnviados($request), $e->erros, 422, $this->condominios->buscar($id));
        }

        $this->sessao->flash('Condomínio atualizado com sucesso.');

        return Response::redirect('/admin/condominios', 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];

        return Response::html($this->view->render('admin/condominios/excluir.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'alvo' => $this->condominios->buscar($id),
            'dependencias' => $this->condominios->dependencias($id),
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $this->condominios->excluir((int) $vars['id']);
        $this->sessao->flash('Condomínio excluído.');

        return Response::redirect('/admin/condominios', 303);
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? trim($request->body[$chave]) : '';

        return [
            'nome' => $texto('nome'),
            'endereco' => $texto('endereco'),
            'ativo' => ($request->body['ativo'] ?? '') === '1',
            'gerentes' => ListaDeIds::de($request->body['gerentes'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed>       $valores
     * @param array<string, string>      $erros
     * @param array<string, mixed>|null  $alvo condomínio em edição (para mostrar a aparência)
     */
    private function formulario(
        Request $request,
        bool $editando,
        string $acao,
        array $valores,
        array $erros,
        int $status = 200,
        ?array $alvo = null,
    ): Response {
        return Response::html($this->view->render('admin/condominios/form.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'editando' => $editando,
            'acao' => $acao,
            'valores' => $valores,
            'erros' => $erros,
            'gerentes' => $this->condominios->gerentesDisponiveis(),
            'aparencia' => $this->aparencia->resolver($alvo ?? []),
        ]), $status);
    }
}
