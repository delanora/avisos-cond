<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\DispositivoService;
use App\Services\GestaoCondominiosService;
use App\Services\ValidacaoException;
use App\Support\DataHora;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\SituacaoDispositivo;
use App\Support\View;

/** Cadastro de dispositivos (TVs/mini PCs): token, revogação e exclusão (somente superadmin). */
final class DispositivosController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly DispositivoService $dispositivos,
        private readonly GestaoCondominiosService $condominios,
        private readonly string $urlBase,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $agora = time();
        $todos = $this->dispositivos->listarComSituacao($agora);
        // Filtro por situação (?situacao=offline): valor fora da lista é ignorado.
        $filtro = is_string($request->query['situacao'] ?? null) && isset(SituacaoDispositivo::ROTULOS[$request->query['situacao']]) ? $request->query['situacao'] : '';
        $lista = $filtro === '' ? $todos : array_values(array_filter($todos, static fn (array $d): bool => $d['situacao'] === $filtro));
        $contatos = [];
        foreach ($lista as $d) {
            $contatos[$d['id']] = DataHora::relativo($d['ultimo_ping_epoch'], $agora);
        }

        return Response::html($this->view->render('admin/dispositivos/lista.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'dispositivos' => $lista,
            'total' => count($todos),
            'contagem' => DispositivoService::contarPorSituacao($todos),
            'rotulos' => SituacaoDispositivo::ROTULOS,
            'filtro' => $filtro,
            'ultimo_contato' => $contatos,
        ]));
    }

    /** @param array<string, string> $vars */
    public function novo(Request $request, array $vars): Response
    {
        return $this->formulario($request, false, '/admin/dispositivos', ['nome' => '', 'condominio_id' => (int) ($request->query['condominio'] ?? 0), 'ativo' => true], []);
    }

    /** @param array<string, string> $vars */
    public function criar(Request $request, array $vars): Response
    {
        try {
            $id = $this->dispositivos->criar($request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, false, '/admin/dispositivos', $this->valoresEnviados($request), $e->erros, 422);
        }

        $this->sessao->flash('Dispositivo criado. Use o endereço abaixo para configurar a TV.');

        return Response::redirect("/admin/dispositivos/{$id}", 303);
    }

    /** @param array<string, string> $vars */
    public function detalhe(Request $request, array $vars): Response
    {
        $dispositivo = $this->dispositivos->buscar((int) $vars['id']);
        $agora = time();

        return Response::html($this->view->render('admin/dispositivos/detalhe.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'dispositivo' => $dispositivo,
            'url' => $this->urlDaTv($dispositivo['token']),
            'ultimo_contato' => DataHora::relativo($dispositivo['ultimo_ping_epoch'], $agora),
            'situacao' => SituacaoDispositivo::de($dispositivo['ultimo_ping_epoch'], $dispositivo['ativo'], $agora),
            'rotulos' => SituacaoDispositivo::ROTULOS,
            'eventos' => array_map(static fn (array $e): array => $e + [
                'quando' => DataHora::exibir($e['registrado_epoch']),
                'desde' => $e['ultimo_ping_epoch'] === null ? null : DataHora::exibir($e['ultimo_ping_epoch']),
            ], $this->dispositivos->eventos($dispositivo['id'])),
        ]));
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $d = $this->dispositivos->buscar((int) $vars['id']);

        return $this->formulario($request, true, "/admin/dispositivos/{$d['id']}", [
            'nome' => $d['nome'], 'condominio_id' => $d['condominio_id'], 'ativo' => $d['ativo'],
        ], [], 200, $d);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];

        try {
            $this->dispositivos->atualizar($id, $request->body);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, true, "/admin/dispositivos/{$id}", $this->valoresEnviados($request), $e->erros, 422, $this->dispositivos->buscar($id));
        }

        $this->sessao->flash('Dispositivo atualizado com sucesso.');

        return Response::redirect('/admin/dispositivos', 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarToken(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('admin/dispositivos/token.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'dispositivo' => $this->dispositivos->buscar((int) $vars['id']),
        ]));
    }

    /** @param array<string, string> $vars */
    public function trocarToken(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];
        $this->dispositivos->trocarToken($id);
        $this->sessao->flash('Token trocado. O endereço antigo deixou de funcionar: configure a TV com o novo.');

        return Response::redirect("/admin/dispositivos/{$id}", 303);
    }

    /** @param array<string, string> $vars */
    public function confirmarExclusao(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('admin/dispositivos/excluir.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'dispositivo' => $this->dispositivos->buscar((int) $vars['id']),
        ]));
    }

    /** @param array<string, string> $vars */
    public function excluir(Request $request, array $vars): Response
    {
        $this->dispositivos->excluir((int) $vars['id']);
        $this->sessao->flash('Dispositivo excluído.');

        return Response::redirect('/admin/dispositivos', 303);
    }

    private function urlDaTv(string $token): string
    {
        return rtrim($this->urlBase, '/') . '/tv/' . $token;
    }

    /** @return array<string, mixed> */
    private function valoresEnviados(Request $request): array
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? trim($request->body[$chave]) : '';

        return ['nome' => $texto('nome'), 'condominio_id' => (int) $texto('condominio_id'), 'ativo' => $texto('ativo') === '1'];
    }

    /**
     * @param array<string, mixed>      $valores
     * @param array<string, string>     $erros
     * @param array<string, mixed>|null $dispositivo dispositivo em edição
     */
    private function formulario(Request $request, bool $editando, string $acao, array $valores, array $erros, int $status = 200, ?array $dispositivo = null): Response
    {
        return Response::html($this->view->render('admin/dispositivos/form.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'editando' => $editando,
            'dispositivo' => $dispositivo,
            'acao' => $acao,
            'valores' => $valores,
            'erros' => $erros,
            'condominios' => $this->condominios->listar(),
        ]), $status);
    }
}
