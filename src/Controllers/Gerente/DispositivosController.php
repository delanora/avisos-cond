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
 * Dispositivos (TVs) dos condomínios do gerente, só para consulta: endereço e token da TV, situação (online/offline)
 * e histórico de quedas. Só aparecem os dos condomínios dele (lidos do banco a cada requisição). A LISTA não mostra o
 * token; o detalhe mostra, para ele configurar a TV. Quem cadastra, troca o token, pareia e revoga é a Embracore.
 */
final class DispositivosController
{
    public function __construct(
        private readonly View $view,
        private readonly DispositivoService $dispositivos,
        private readonly string $urlBase,
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
        $dispositivo = $this->dispositivos->buscarDoGerente(EscopoGerente::permitidos($request), (int) $vars['id'], $agora);
        $pagina = ctype_digit((string) ($request->query['pagina'] ?? '')) ? (int) $request->query['pagina'] : 1;
        $historico = $this->dispositivos->historico($dispositivo['id'], $pagina);

        return Response::html($this->view->render('gerente/dispositivos/detalhe.html.twig', EscopoGerente::contexto($request) + [
            'dispositivo' => $dispositivo,
            'url' => rtrim($this->urlBase, '/') . '/tv/' . $dispositivo['token'],
            'url_pareamento' => rtrim($this->urlBase, '/') . '/tv',
            'ultimo_contato' => DataHora::relativo($dispositivo['ultimo_ping_epoch'], $agora),
            'situacao' => $dispositivo['situacao'],
            'rotulos' => SituacaoDispositivo::ROTULOS,
            'historico' => $historico,
            'eventos' => array_map(static fn (array $e): array => $e + [
                'quando' => DataHora::exibir($e['registrado_epoch']),
                'desde' => $e['ultimo_ping_epoch'] === null ? null : DataHora::exibir($e['ultimo_ping_epoch']),
            ], $historico['eventos']),
            'base_historico' => "/gerente/dispositivos/{$dispositivo['id']}",
            'pode_parear' => false,
            'menu' => 'dispositivos',
        ]));
    }
}
