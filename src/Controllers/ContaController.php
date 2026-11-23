<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\RateLimitExcedidoException;
use App\Services\SenhaService;
use App\Services\ValidacaoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\View;

/** Configurações da própria conta (qualquer usuário logado): Segurança (troca de senha). A aba Aparência é do gerente. */
final class ContaController
{
    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly SenhaService $senhas,
    ) {
    }

    /** @param array<string, string> $vars */
    public function seguranca(Request $request, array $vars): Response
    {
        return $this->pagina($request, []);
    }

    /** @param array<string, string> $vars */
    public function alterarSenha(Request $request, array $vars): Response
    {
        $texto = static fn (string $chave): string => is_string($request->body[$chave] ?? null) ? $request->body[$chave] : '';

        try {
            $autenticadoEm = $this->senhas->trocar($request->attribute('usuario'), $texto('senha_atual'), $texto('nova_senha'), $texto('confirmacao'));
        } catch (ValidacaoException $e) {
            return $this->pagina($request, $e->erros, 422);
        } catch (RateLimitExcedidoException $e) {
            return $this->pagina($request, ['senha_atual' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.'], 429)
                ->withHeader('Retry-After', (string) $e->segundos);
        }

        // A troca invalida as sessões abertas antes dela; esta continua valendo (novo momento de login e novo ID de sessão).
        $this->sessao->regenerate();
        $this->sessao->forget('csrf_token');
        $this->sessao->set('autenticado_em', $autenticadoEm);
        $this->sessao->flash('Senha alterada com sucesso. Os outros aparelhos conectados com esta conta foram desconectados.');

        return Response::redirect('/conta/seguranca', 303);
    }

    /**
     * @param array<string, string> $erros
     */
    private function pagina(Request $request, array $erros, int $status = 200): Response
    {
        return Response::html($this->view->render('conta/seguranca.html.twig', [
            'usuario' => $request->attribute('usuario'),
            'erros' => $erros,
            'menu' => 'seguranca',
        ]), $status);
    }
}
