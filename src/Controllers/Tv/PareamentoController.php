<?php

declare(strict_types=1);

namespace App\Controllers\Tv;

use App\Services\PareamentoService;
use App\Services\RateLimitExcedidoException;
use App\Support\NaoEncontradoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Pareamento da TV por código (rotas públicas, sem sessão e sem cookies; a TV se identifica pelo segredo
 * que recebeu junto do código). Respostas sem detalhes e sempre com Referrer-Policy: no-referrer.
 */
final class PareamentoController
{
    public function __construct(
        private readonly View $view,
        private readonly PareamentoService $pareamento,
    ) {
    }

    /** @param array<string, string> $vars */
    public function pagina(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('tv/parear.html.twig', ['intervalo' => PareamentoService::INTERVALO]))
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    /** @param array<string, string> $vars */
    public function iniciar(Request $request, array $vars): Response
    {
        return $this->responder(function () use ($request): Response {
            $p = $this->pareamento->iniciar($request->ip(), time());

            return Response::json(['codigo' => $p['codigo'], 'segredo' => $p['segredo'], 'expira_em' => $p['expira_em'], 'segundos' => PareamentoService::VALIDADE]);
        });
    }

    /** @param array<string, string> $vars */
    public function consultar(Request $request, array $vars): Response
    {
        $segredo = is_string($request->body['segredo'] ?? null) ? $request->body['segredo'] : '';

        return $this->responder(fn (): Response => Response::json($this->pareamento->consultar($segredo, $request->ip())));
    }

    /** @param \Closure(): Response $acao */
    private function responder(\Closure $acao): Response
    {
        try {
            $resposta = $acao();
        } catch (RateLimitExcedidoException $e) {
            return (new Response(429, 'Muitas tentativas. Tente novamente mais tarde.', ['Content-Type' => 'text/plain; charset=utf-8']))
                ->withHeader('Retry-After', (string) $e->segundos)
                ->withHeader('Referrer-Policy', 'no-referrer');
        } catch (NaoEncontradoException) {
            return (new Response(404, 'Não encontrado.', ['Content-Type' => 'text/plain; charset=utf-8']))
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        return $resposta->withHeader('Referrer-Policy', 'no-referrer');
    }
}
