<?php

declare(strict_types=1);

namespace App\Controllers\Gerente;

use App\Services\DispositivoService;
use App\Support\DataHora;
use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\SituacaoDispositivo;
use App\Support\View;

/**
 * Dispositivos (TVs) dos condomínios do gerente, só para consulta: situação (online/offline) e histórico de quedas.
 * Só aparecem os dos condomínios dele (lidos do banco a cada requisição). O token e o endereço da TV NUNCA aparecem
 * aqui: quem cadastra, troca e revoga dispositivos é a Embracore.
 */
final class DispositivosController
{
    public function __construct(
        private readonly View $view,
        private readonly DispositivoService $dispositivos,
    ) {
    }

    /** @param array<string, string> $vars */
    public function lista(Request $request, array $vars): Response
    {
        $agora = time();
        $todos = $this->dispositivos->listarDoGerente(EscopoGerente::permitidos($request), $agora);
        $filtro = is_string($request->query['situacao'] ?? null) && isset(SituacaoDispositivo::ROTULOS[$request->query['situacao']]) ? $request->query['situacao'] : '';
        $lista = $filtro === '' ? $todos : array_values(array_filter($todos, static fn (array $d): bool => $d['situacao'] === $filtro));
        $contatos = [];
        foreach ($lista as $d) {
            $contatos[$d['id']] = DataHora::relativo($d['ultimo_ping_epoch'], $agora);
        }

        return Response::html($this->view->render('gerente/dispositivos/lista.html.twig', EscopoGerente::contexto($request) + [
            'dispositivos' => $lista,
            'total' => count($todos),
            'contagem' => DispositivoService::contarPorSituacao($todos),
            'rotulos' => SituacaoDispositivo::ROTULOS,
            'filtro' => $filtro,
            'ultimo_contato' => $contatos,
            'menu' => 'dispositivos',
        ]));
    }

    /** @param array<string, string> $vars */
    public function detalhe(Request $request, array $vars): Response
    {
        $agora = time();
        $dados = $this->dispositivos->buscarDoGerente(EscopoGerente::permitidos($request), (int) $vars['id'], $agora);

        return Response::html($this->view->render('gerente/dispositivos/detalhe.html.twig', EscopoGerente::contexto($request) + [
            'dispositivo' => $dados['dispositivo'],
            'ultimo_contato' => DataHora::relativo($dados['dispositivo']['ultimo_ping_epoch'], $agora),
            'rotulos' => SituacaoDispositivo::ROTULOS,
            'eventos' => array_map(static fn (array $e): array => $e + [
                'quando' => DataHora::exibir($e['registrado_epoch']),
                'desde' => $e['ultimo_ping_epoch'] === null ? null : DataHora::exibir($e['ultimo_ping_epoch']),
            ], $dados['eventos']),
            'menu' => 'dispositivos',
        ]));
    }
}
