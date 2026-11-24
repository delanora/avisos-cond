<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuditoriaService;
use App\Services\FinanceiroService;
use App\Services\ValidacaoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Controle financeiro manual por condomínio e bloqueio da exibição (somente superadmin). */
final class FinanceiroController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly FinanceiroService $financeiro,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $filtro = is_string($request->query['situacao'] ?? null) ? $request->query['situacao'] : '';
        $filtro = in_array($filtro, FinanceiroService::FILTROS, true) ? $filtro : '';

        return Response::html($this->view->render('admin/financeiro/lista.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'condominios' => $this->financeiro->listar($filtro),
            'resumo' => $this->financeiro->resumo(),
            'filtro' => $filtro,
        ]));
    }

    /** @param array<string, string> $vars */
    public function editar(Request $request, array $vars): Response
    {
        $alvo = $this->financeiro->buscar((int) $vars['id']);

        return $this->formulario($request, $alvo, [
            'situacao' => $alvo['situacao'] ?? 'em_dia',
            'valor_mensal' => $alvo['valor_mensal'] === null ? '' : number_format($alvo['valor_mensal'] / 100, 2, ',', '.'),
            'dia_vencimento' => $alvo['dia_vencimento'] === null ? '' : (string) $alvo['dia_vencimento'],
            'observacoes' => $alvo['observacoes'] ?? '',
        ], []);
    }

    /** @param array<string, string> $vars */
    public function atualizar(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];
        $antes = $this->financeiro->buscar($id);

        try {
            $this->financeiro->salvar($id, $request->body);
        } catch (ValidacaoException $e) {
            $texto = static fn (string $k): string => is_string($request->body[$k] ?? null) ? trim($request->body[$k]) : '';

            return $this->formulario($request, $antes, [
                'situacao' => $texto('situacao'), 'valor_mensal' => $texto('valor_mensal'),
                'dia_vencimento' => $texto('dia_vencimento'), 'observacoes' => $texto('observacoes'),
            ], $e->erros, 422);
        }

        $depois = $this->financeiro->buscar($id);
        $this->auditoria->registrarDaRequisicao($request, 'financeiro.atualizado', 'condominio', $id, ['id' => $id, 'nome' => $depois['nome']], [
            'situacao' => $depois['situacao'], 'situacao_anterior' => $antes['situacao'] ?? 'sem cadastro',
            'valor_mensal' => FinanceiroService::formatar($depois['valor_mensal']), 'dia_vencimento' => $depois['dia_vencimento'] ?? '',
        ]);
        $this->sessao->flash('Situação financeira salva.');

        return Response::redirect('/admin/financeiro', 303);
    }

    /** @param array<string, string> $vars */
    public function bloquear(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];
        $alvo = $this->financeiro->buscar($id);
        $motivo = is_string($request->body['motivo'] ?? null) ? trim($request->body['motivo']) : '';

        try {
            $this->financeiro->bloquear($id, $motivo);
        } catch (ValidacaoException $e) {
            return $this->formulario($request, $alvo, [
                'situacao' => $alvo['situacao'] ?? 'em_dia',
                'valor_mensal' => $alvo['valor_mensal'] === null ? '' : number_format($alvo['valor_mensal'] / 100, 2, ',', '.'),
                'dia_vencimento' => $alvo['dia_vencimento'] === null ? '' : (string) $alvo['dia_vencimento'],
                'observacoes' => $alvo['observacoes'] ?? '',
            ], $e->erros, 422, $motivo);
        }

        $this->auditoria->registrarDaRequisicao($request, 'condominio.bloqueado', 'condominio', $id, ['id' => $id, 'nome' => $alvo['nome']], ['motivo' => $motivo]);
        $this->sessao->flash('Exibição bloqueada: as TVs deste condomínio passam a mostrar só o relógio.');

        return Response::redirect("/admin/financeiro/{$id}/editar", 303);
    }

    /** @param array<string, string> $vars */
    public function desbloquear(Request $request, array $vars): Response
    {
        $id = (int) $vars['id'];
        $alvo = $this->financeiro->buscar($id);
        $this->financeiro->desbloquear($id);
        $this->auditoria->registrarDaRequisicao($request, 'condominio.desbloqueado', 'condominio', $id, ['id' => $id, 'nome' => $alvo['nome']], ['motivo_anterior' => $alvo['bloqueio_motivo'] ?? '']);
        $this->sessao->flash('Exibição liberada: as TVs voltam a mostrar os avisos.');

        return Response::redirect("/admin/financeiro/{$id}/editar", 303);
    }

    /**
     * @param array<string, mixed>  $alvo
     * @param array<string, string> $valores
     * @param array<string, string> $erros
     */
    private function formulario(Request $request, array $alvo, array $valores, array $erros, int $status = 200, string $motivo = ''): Response
    {
        return Response::html($this->view->render('admin/financeiro/form.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'alvo' => $alvo,
            'valores' => $valores,
            'erros' => $erros,
            'motivo' => $motivo,
            'situacoes' => FinanceiroService::SITUACOES,
        ]), $status);
    }
}
