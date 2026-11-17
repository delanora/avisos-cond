<?php

declare(strict_types=1);

namespace App\Controllers\Tv;

use App\Services\RateLimitExcedidoException;
use App\Services\TvAcesso;
use App\Services\TvService;
use App\Services\UploadService;
use App\Support\NaoEncontradoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\View;
use Closure;

/**
 * Tela pública da TV, identificada só pelo token da URL (sem login, sem sessão, sem cookies).
 * Token inválido, inexistente ou de dispositivo revogado: 404 sem detalhes. Todas as respostas
 * enviam Referrer-Policy: no-referrer, porque o token aparece na URL.
 */
final class TvController
{
    public function __construct(
        private readonly View $view,
        private readonly TvAcesso $acesso,
        private readonly TvService $tv,
        private readonly ?string $aceleracao,
    ) {
    }

    /** @param array<string, string> $vars */
    public function tela(Request $request, array $vars): Response
    {
        return $this->acessar($request, $vars, function (array $acesso): Response {
            $this->tv->registrarContato($acesso);
            $estado = $this->tv->estado($acesso, time());

            return Response::html($this->view->render('tv/tela.html.twig', [
                'token' => $acesso['dispositivo']['token'],
                'estado_json' => json_encode($estado, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'intervalo' => $estado['intervalo'],
            ]));
        });
    }

    /** @param array<string, string> $vars */
    public function estado(Request $request, array $vars): Response
    {
        return $this->acessar($request, $vars, function (array $acesso): Response {
            // Heartbeat: cada consulta atualiza o último contato do dispositivo.
            $this->tv->registrarContato($acesso);

            return Response::json($this->tv->estado($acesso, time()));
        });
    }

    /** @param array<string, string> $vars */
    public function midia(Request $request, array $vars): Response
    {
        return $this->acessar($request, $vars, function (array $acesso) use ($vars): Response {
            $m = $this->tv->midia($acesso, $vars['arquivo'], time());

            // O nome do arquivo é aleatório e nunca muda de conteúdo: pode ficar em cache por bastante tempo.
            return Response::arquivo($m['caminho'], UploadService::mimeDoNome($m['nome']), $this->aceleracao)
                ->withHeader('Cache-Control', 'private, max-age=86400');
        });
    }

    /**
     * Valida o token e roda a ação. Excesso de tokens inválidos (429) vira página simples com Retry-After.
     *
     * @param array<string, string> $vars
     * @param Closure(array{dispositivo: array<string, mixed>, condominio: array<string, mixed>}): Response $acao
     */
    private function acessar(Request $request, array $vars, Closure $acao): Response
    {
        try {
            $resposta = $acao($this->acesso->resolver($vars['token'], $request->ip()));
        } catch (RateLimitExcedidoException $e) {
            return (new Response(429, 'Muitas tentativas. Tente novamente mais tarde.', ['Content-Type' => 'text/plain; charset=utf-8']))
                ->withHeader('Retry-After', (string) $e->segundos)
                ->withHeader('Referrer-Policy', 'no-referrer');
        } catch (NaoEncontradoException) {
            // Token inválido, dispositivo revogado ou mídia fora do ar: sempre o mesmo 404 sem detalhes.
            // Tratado aqui (e não só na Application) para também levar o Referrer-Policy, já que o token está na URL.
            return Response::html($this->view->render('erro.html.twig', ['codigo' => 404, 'mensagem' => 'Página não encontrada.']), 404)
                ->withHeader('Referrer-Policy', 'no-referrer');
        }

        return $resposta->withHeader('Referrer-Policy', 'no-referrer');
    }
}
