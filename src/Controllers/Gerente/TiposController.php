<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\TipoAvisoService;
use App\Services\ValidacaoException;
use App\Repositories\TipoAvisoRepository;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/**
 * Tipos de aviso do GERENTE logado (não do condomínio): lista, criar, editar e apagar. Nada aqui recebe
 * condominio_id nem id de usuário do cliente; o dono é sempre o usuário da sessão.
 */
final class TiposController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly TipoAvisoService $tipos,
        private readonly TipoAvisoRepository $repositorio,
    ) {
    }

    private function usuarioId(Request $request): int
    {
        return (int) $request->attribute('usuario')['id'];
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('gerente/tipos/lista.html.twig', EscopoGerente::contexto($request) + [
            'tipos' => $this->tipos->listar($this->usuarioId($request)),
            'menu' => 'tipos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        return $this->formulario($request, null, ['nome' => '', 'cor' => '#2F6FED', 'fixo' => false], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        try {
            $this->tipos->criar($this->usuarioId($request), $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, null, $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Tipo criado com sucesso.');

        return Response::redirect('/gerente/tipos', 303);
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $tipo = $this->tipos->buscarProprio($this->usuarioId($request), (int) $vars['id']);

        return $this->formulario($request, $tipo, ['nome' => $tipo['nome'], 'cor' => $tipo['cor'] ?? '#2F6FED', 'fixo' => $tipo['fixo']], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $usuario = $this->usuarioId($request);
        $id = (int) $vars['id'];

        try {
            $this->tipos->atualizar($usuario, $id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $this->tipos->buscarProprio($usuario, $id), $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Tipo atualizado com sucesso.');

        return Response::redirect('/gerente/tipos', 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        $tipo = $this->tipos->buscarProprio($this->usuarioId($request), (int) $vars['id']);

        return Response::html($this->view->render('gerente/tipos/excluir.html.twig', EscopoGerente::contexto($request) + [
            'tipo' => $tipo,
            'ultimo' => count($this->tipos->listar($this->usuarioId($request))) <= 1,
            'menu' => 'tipos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        try {
            $this->tipos->excluir($this->usuarioId($request), (int) $vars['id']);
        } catch (ValidacaoException $e) {
            $this->sessao->flash($e->erros['tipo'] ?? 'Não foi possível apagar o tipo.');

            return Response::redirect('/gerente/tipos', 303);
        }

        $this->sessao->flash('Tipo apagado.');

        return Response::redirect('/gerente/tipos', 303);
    }

    /**
     * Folha de estilo com as cores dos tipos usados numa tela do condomínio: os do gerente logado e os dos avisos
     * do condomínio (que podem ser de outro gerente dele).
     *
     * @param array<string, string> $vars
     */
    public function css(Request $request, array $vars): Response
    {
        $condominio = EscopoGerente::exigir($request, (int) $vars['cid']);
        $tipos = $this->repositorio->paraCores($this->usuarioId($request), EscopoGerente::permitidos($request), $condominio['id']);

        return new Response(200, TipoAvisoService::css($tipos), [
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
     * @param array<string, mixed>|null $tipo tipo em edição
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     */
    private function formulario(Request $request, ?array $tipo, array $valores, array $erros, int $status = 200): Response
    {
        return Response::html($this->view->render('gerente/tipos/form.html.twig', EscopoGerente::contexto($request) + [
            'editando' => $tipo !== null,
            'tipo' => $tipo,
            'acao' => $tipo === null ? '/gerente/tipos' : "/gerente/tipos/{$tipo['id']}",
            'valores' => $valores,
            'erros' => $erros,
            'menu' => 'tipos',
        ]), $status);
    }
}
