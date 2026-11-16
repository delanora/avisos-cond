<?php

declare(strict_types=1);

namespace App;

use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\SecurityHeaders;
use App\Support\View;
use FastRoute\Dispatcher;
use LogicException;
use Throwable;

/**
 * Recebe uma Request, despacha para o handler da rota e devolve uma Response.
 * Qualquer erro vira uma página genérica; os detalhes vão só para o log.
 *
 * Os handlers são callables no formato fn(Request $req, array $vars): Response.
 */
final class Application
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly View $view,
        private readonly Logger $logger,
        private readonly SecurityHeaders $securityHeaders,
    ) {
    }

    public function handle(Request $request): Response
    {
        try {
            $resposta = $this->despachar($request);
        } catch (Throwable $erro) {
            $this->logger->error('Erro ao processar ' . $request->method . ' ' . $request->path, $erro);
            $resposta = $this->paginaDeErro(500, 'Ocorreu um erro inesperado. Tente novamente mais tarde.');
        }

        return $this->securityHeaders->apply($resposta);
    }

    private function despachar(Request $request): Response
    {
        $rota = $this->dispatcher->dispatch($request->method, $request->path);

        switch ($rota[0]) {
            case Dispatcher::FOUND:
                $resposta = ($rota[1])($request, $rota[2]);
                if (!$resposta instanceof Response) {
                    throw new LogicException('O handler da rota não retornou uma Response.');
                }

                return $resposta;

            case Dispatcher::METHOD_NOT_ALLOWED:
                return $this->paginaDeErro(405, 'Método não permitido.')
                    ->withHeader('Allow', implode(', ', $rota[1]));

            default:
                return $this->paginaDeErro(404, 'Página não encontrada.');
        }
    }

    private function paginaDeErro(int $codigo, string $mensagem): Response
    {
        try {
            return Response::html($this->view->render('erro.html.twig', [
                'codigo' => $codigo,
                'mensagem' => $mensagem,
            ]), $codigo);
        } catch (Throwable $erro) {
            // Se até a página de erro falhar, devolve texto simples (sem detalhes técnicos).
            $this->logger->error('Falha ao renderizar a página de erro', $erro);

            return new Response($codigo, $mensagem, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }
}
