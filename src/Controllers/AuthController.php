<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditoriaService;
use App\Services\AuthService;
use App\Services\PasswordResetService;
use App\Services\RateLimitExcedidoException;
use App\Services\ValidacaoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use App\Support\Validator;
use App\Support\View;

final class AuthController
{
    // Mensagens fixas: nada vindo da URL é exibido na tela.
    private const AVISOS = [
        'senha-alterada' => 'Senha alterada com sucesso. Entre com a nova senha.',
    ];

    public function __construct(
        private readonly View $view,
        private readonly Session $sessao,
        private readonly AuthService $auth,
        private readonly PasswordResetService $recuperacao,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    /** @param array<string, string> $vars */
    public function formLogin(Request $request, array $vars): Response
    {
        $chave = $request->query['msg'] ?? '';
        $aviso = is_string($chave) ? (self::AVISOS[$chave] ?? '') : '';

        return $this->paginaLogin('', '', $aviso);
    }

    /** @param array<string, string> $vars */
    public function login(Request $request, array $vars): Response
    {
        $validador = new Validator($request->body, ['email' => 'required|email|max:190']);
        $email = $validador->valores()['email'];
        // A senha não passa por trim: espaços fazem parte dela.
        $senha = is_string($request->body['senha'] ?? null) ? $request->body['senha'] : '';

        if (!$validador->passes() || $senha === '' || mb_strlen($senha) > 1024) {
            return $this->paginaLogin($email, 'Credenciais inválidas.', '', 401);
        }

        try {
            $usuario = $this->auth->autenticar($email, $senha);
        } catch (RateLimitExcedidoException $e) {
            return $this->paginaLogin($email, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.', '', 429)
                ->withHeader('Retry-After', (string) $e->segundos);
        }

        if ($usuario === null) {
            return $this->paginaLogin($email, 'Credenciais inválidas.', '', 401);
        }

        $this->sessao->start();
        // Novo ID de sessão após o login impede a fixação de sessão.
        $this->sessao->regenerate();
        $this->sessao->forget('csrf_token');
        $this->sessao->set('usuario_id', $usuario['id']);
        $this->sessao->set('autenticado_em', time());
        $this->auditoria->registrar('login.entrou', $usuario, $request->ip(), 'usuario', (int) $usuario['id'], null, ['papel' => $usuario['papel']]);

        return Response::redirect($usuario['papel'] === 'superadmin' ? '/admin' : '/gerente', 303);
    }

    /** @param array<string, string> $vars */
    public function logout(Request $request, array $vars): Response
    {
        $this->sessao->destroy();

        return Response::redirect('/login', 303);
    }

    /** @param array<string, string> $vars */
    public function formEsqueci(Request $request, array $vars): Response
    {
        return $this->paginaEsqueci('', '', false);
    }

    /** @param array<string, string> $vars */
    public function esqueci(Request $request, array $vars): Response
    {
        $validador = new Validator($request->body, ['email' => 'required|email|max:190']);
        $email = $validador->valores()['email'];

        if (!$validador->passes()) {
            return $this->paginaEsqueci($email, $validador->errors()['email'], false, 422);
        }

        try {
            $this->recuperacao->solicitar($email, $request->ip());
        } catch (RateLimitExcedidoException $e) {
            return $this->paginaEsqueci($email, 'Muitos pedidos. Aguarde um pouco e tente novamente.', false, 429)
                ->withHeader('Retry-After', (string) $e->segundos);
        }

        // Mesma resposta exista ou não a conta.
        return $this->paginaEsqueci('', '', true);
    }

    /** @param array<string, string> $vars */
    public function formRedefinir(Request $request, array $vars): Response
    {
        if (!$this->recuperacao->tokenValido($vars['token'])) {
            return $this->paginaRedefinir($vars['token'], '', false, 400);
        }

        return $this->paginaRedefinir($vars['token'], '', true);
    }

    /** @param array<string, string> $vars */
    public function redefinir(Request $request, array $vars): Response
    {
        $senha = is_string($request->body['senha'] ?? null) ? $request->body['senha'] : '';
        $confirmacao = is_string($request->body['confirmacao'] ?? null) ? $request->body['confirmacao'] : '';

        try {
            $ok = $this->recuperacao->redefinir($vars['token'], $senha, $confirmacao, $request->ip());
        } catch (ValidacaoException $e) {
            return $this->paginaRedefinir($vars['token'], $e->erros['senha'] ?? 'Senha inválida.', true, 422);
        } catch (RateLimitExcedidoException $e) {
            return $this->paginaRedefinir($vars['token'], 'Muitas tentativas. Aguarde e tente novamente.', false, 429)
                ->withHeader('Retry-After', (string) $e->segundos);
        }

        if (!$ok) {
            return $this->paginaRedefinir($vars['token'], '', false, 400);
        }

        return Response::redirect('/login?msg=senha-alterada', 303);
    }

    private function paginaLogin(string $email, string $erro, string $aviso, int $status = 200): Response
    {
        return Response::html($this->view->render('auth/login.html.twig', [
            'email' => $email,
            'erro' => $erro,
            'aviso' => $aviso,
        ]), $status);
    }

    private function paginaEsqueci(string $email, string $erro, bool $enviado, int $status = 200): Response
    {
        return Response::html($this->view->render('auth/esqueci_senha.html.twig', [
            'email' => $email,
            'erro' => $erro,
            'enviado' => $enviado,
        ]), $status);
    }

    private function paginaRedefinir(string $token, string $erro, bool $tokenValido, int $status = 200): Response
    {
        $resposta = Response::html($this->view->render('auth/redefinir_senha.html.twig', [
            'token' => $token,
            'erro' => $erro,
            'token_valido' => $tokenValido,
        ]), $status);

        // O token está na URL: não deve vazar por Referer para outros sites.
        return $resposta->withHeader('Referrer-Policy', 'no-referrer');
    }
}
