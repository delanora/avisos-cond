<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Repositories\AuditoriaRepository;
use App\Services\GestaoCondominiosService;
use App\Support\AcoesDeAuditoria;
use App\Support\DataHora;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Consulta da auditoria (somente superadmin; a rota já passa pelo RoleMiddleware). Só leitura: nada aqui altera registros. */
final class AuditoriaController
{
    public const POR_PAGINA = 50;

    public function __construct(
        private readonly View $view,
        private readonly AuditoriaRepository $auditoria,
        private readonly GestaoCondominiosService $condominios,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $texto = static fn (string $chave): string => is_string($request->query[$chave] ?? null) ? trim($request->query[$chave]) : '';
        $grupo = isset(AcoesDeAuditoria::GRUPOS[$texto('grupo')]) ? $texto('grupo') : '';
        $pessoa = ctype_digit($texto('pessoa')) && $texto('pessoa') !== '' ? (int) $texto('pessoa') : null;
        $condominio = ctype_digit($texto('condominio')) && $texto('condominio') !== '' ? (int) $texto('condominio') : null;
        $de = $this->dia($texto('de'));
        $ate = $this->dia($texto('ate'));
        $pagina = max(1, ctype_digit($texto('pagina')) && $texto('pagina') !== '' ? (int) $texto('pagina') : 1);

        $filtros = array_filter([
            'grupo' => $grupo,
            'usuario_id' => $pessoa,
            'condominio_id' => $condominio,
            'de_epoch' => $de,
            // "até" é o dia inteiro: vale até a meia-noite do dia seguinte.
            'ate_epoch' => $ate === null ? null : $ate + 86400,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        $resultado = $this->auditoria->listar($filtros, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA);
        $totalPaginas = max(1, (int) ceil($resultado['total'] / self::POR_PAGINA));

        $linhas = array_map(static fn (array $l): array => $l + [
            'quando' => DataHora::exibir($l['criado_epoch']),
            'rotulo' => AcoesDeAuditoria::rotulo($l['acao']),
        ], $resultado['linhas']);

        return Response::html($this->view->render('admin/auditoria/lista.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'linhas' => $linhas,
            'total' => $resultado['total'],
            'pagina' => $pagina,
            'total_paginas' => $totalPaginas,
            'grupos' => AcoesDeAuditoria::GRUPOS,
            'pessoas' => $this->auditoria->pessoas(),
            'condominios' => $this->condominios->listar(),
            'filtros' => ['grupo' => $grupo, 'pessoa' => $pessoa, 'condominio' => $condominio, 'de' => $de === null ? '' : $texto('de'), 'ate' => $ate === null ? '' : $texto('ate')],
            'consulta' => http_build_query(array_filter(['grupo' => $grupo, 'pessoa' => $pessoa, 'condominio' => $condominio, 'de' => $de === null ? '' : $texto('de'), 'ate' => $ate === null ? '' : $texto('ate')], static fn (mixed $v): bool => $v !== null && $v !== '')),
            'menu' => 'auditoria',
        ]));
    }

    /** "2026-11-23" (dia em Brasília) → início do dia em epoch; inválido → null. */
    private function dia(string $valor): ?int
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}\z/', $valor) === 1 ? DataHora::doFormulario($valor . 'T00:00') : null;
    }
}
