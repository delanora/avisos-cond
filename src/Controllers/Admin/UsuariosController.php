<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuditoriaService;
use App\Services\GestaoCondominiosService;
use App\Services\GestaoUsuariosService;
use App\Services\ListaDeIds;
use App\Services\ValidacaoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** CRUD de gerentes e administradores (somente superadmin; a rota já passa pelo RoleMiddleware). */
final class UsuariosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly GestaoUsuariosService $usuarios,
        private readonly GestaoCondominiosService $condominios,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('admin/usuarios/lista.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'usuarios' => $this->usuarios->listar(),
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        return $this->formulario($request, false, '/admin/usuarios', [
            'nome' => '', 'email' => '', 'papel' => 'gerente', 'ativo' => true, 'condominios' => [],
        ], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        try {
            $novoId = $this->usuarios->criar($request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, false, '/admin/usuarios', $this->valoresEnviados($request), $e->erros, 422);
        }
        $this->auditoria->registrarDaRequisicao($request, 'usuario.criado', 'usuario', $novoId, null, $this->resumoDoUsuario($this->usuarios->buscar($novoId)));

        $this->sessao->flash('Usuário criado com sucesso.');

        return Response::redirect('/admin/usuarios', 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $alvo = $this->usuarios->buscar((int) $vars['id']);

        return $this->formulario($request, true, "/admin/usuarios/{$alvo['id']}", [
            'nome' => $alvo['nome'],
            'email' => $alvo['email'],
            'papel' => $alvo['papel'],
            'ativo' => $alvo['ativo'],
            'condominios' => $alvo['condominios'],
        ], [], 200, (int) $alvo['id']);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];

        try {
            $this->usuarios->atualizar($id, $request->body, $this->atorId($request));
        } catch (ValidacaoException $e) {
            return $this->formulario($request, true, "/admin/usuarios/{$id}", $this->valoresEnviados($request), $e->erros, 422, $id);
        }

        $this->auditoria->registrarDaRequisicao($request, 'usuario.atualizado', 'usuario', $id, null, $this->resumoDoUsuario($this->usuarios->buscar($id)));
        $this->sessao->flash('Usuário atualizado com sucesso.');

        return Response::redirect('/admin/usuarios', 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        return $this->paginaExclusao($request, $this->usuarios->buscar((int) $vars['id']), '');
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];
        $antes = $this->resumoDoUsuario($this->usuarios->buscar($id));

        try {
            $this->usuarios->excluir($id, $this->atorId($request));
        } catch (ValidacaoException $e) {
            return $this->paginaExclusao($request, $this->usuarios->buscar($id), $e->erros['geral'] ?? 'Não foi possível excluir.', 422);
        }

        $this->auditoria->registrarDaRequisicao($request, 'usuario.excluido', 'usuario', $id, null, $antes);
        $this->sessao->flash('Usuário excluído.');

        return Response::redirect('/admin/usuarios', 303);
    }

    /**
     * Resumo seguro de um usuário para a auditoria (nunca a senha).
     *
     * @param array<string, mixed> $u
     * @return array<string, mixed>
     */
    private function resumoDoUsuario(array $u): array
    {
        return ['nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel'], 'ativo' => $u['ativo'] ? 'sim' : 'não', 'condominios' => count($u['condominios'] ?? [])];
    }

    private function atorId(Request $request): int
    {
        return (int) $request->attribute('usuario')['id'];
    }

    /**
     * Valores do formulário reenviados após erro de validação (a senha nunca volta).
     *
     * @return array<string, mixed>
     */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? trim($request->body[$chave]) : '';

        return [
            'nome' => $texto('nome'),
            'email' => $texto('email'),
            'papel' => $texto('papel'),
            'ativo' => ($request->body['ativo'] ?? '') === '1',
            'condominios' => ListaDeIds::de($request->body['condominios'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed>  $valores
     * @param array<string, string> $erros
     */
    private function formulario(
        Request $request,
        bool $editando,
        string $acao,
        array $valores,
        array $erros,
        int $status = 200,
        ?int $alvoId = null,
    ): Response {
        $logado = $request->attribute('usuario');

        return Response::html($this->view->render('admin/usuarios/form.html.twig', [
            'usuario' => $logado,
            'editando' => $editando,
            'usuario_proprio' => $alvoId !== null && $alvoId === $logado['id'],
            'acao' => $acao,
            'valores' => $valores,
            'erros' => $erros,
            'condominios' => $this->condominios->listar(),
        ]), $status);
    }

    /** @param array<string, mixed> $alvo */
    private function paginaExclusao(Request $request, array $alvo, string $erro, int $status = 200): Response
    {
        return Response::html($this->view->render('admin/usuarios/excluir.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'alvo' => $alvo,
            'erro' => $erro,
        ]), $status);
    }
}
