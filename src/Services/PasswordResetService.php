<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PasswordResetRepository;
use App\Repositories\UsuarioRepository;
use App\Support\Logger;
use App\Support\View;

final class PasswordResetService
{
    public const VALIDADE_SEGUNDOS = 1800; // 30 minutos

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly PasswordResetRepository $resets,
        private readonly RateLimiter $limitador,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly Logger $logger,
        private readonly string $urlBase,
        private readonly View $view,
        private readonly ?AuditoriaService $auditoria = null,
    ) {
    }

    /**
     * Pedido de recuperação. Não informa se o e-mail existe: o resultado é sempre o mesmo
     * para quem chama; só o e-mail enviado (ou não) difere.
     *
     * @throws RateLimitExcedidoException
     */
    public function solicitar(string $email, string $ip): void
    {
        // 5 pedidos por IP e 3 por e-mail por hora.
        $this->limitar('reset:ip:' . $ip, 5);
        $this->limitar('reset:email:' . RateLimiter::hashDe($email), 3);

        $usuario = $this->usuarios->porEmail($email);
        if ($usuario === null || !$usuario['ativo']) {
            return;
        }

        // Um novo pedido invalida os anteriores.
        $this->resets->invalidarDoUsuario($usuario['id']);

        // O token só existe no e-mail; no banco fica apenas o hash (SHA-256).
        $token = bin2hex(random_bytes(32));
        $this->resets->criar($usuario['id'], self::hashDoToken($token), self::VALIDADE_SEGUNDOS);
        $this->auditoria?->registrar('senha.recuperacao_pedida', $usuario, $ip, 'usuario', (int) $usuario['id']);

        $link = rtrim($this->urlBase, '/') . '/redefinir-senha/' . $token;
        $texto = "Olá, {$usuario['nome']}.\n\n"
            . "Recebemos um pedido para redefinir a senha da sua conta no Avisos-Cond.\n"
            . "Use o link abaixo (válido por 30 minutos e de uso único):\n\n{$link}\n\n"
            . "Se você não fez este pedido, ignore este e-mail: sua senha continua a mesma.\n";

        try {
            $html = $this->view->render('emails/redefinir-senha.html.twig', [
                'nome' => $usuario['nome'],
                'link' => $link,
                'validade_minutos' => intdiv(self::VALIDADE_SEGUNDOS, 60),
            ]);
            $this->mailer->enviar($usuario['email'], 'Redefinição de senha - Avisos-Cond', $texto, $html);
        } catch (MailerException $erro) {
            // O usuário recebe a mesma resposta genérica; o problema fica no log (sem o token).
            $this->logger->error('Falha ao enviar e-mail de recuperação de senha', $erro);
        }
    }

    public function tokenValido(string $token): bool
    {
        return $this->resets->buscarValido(self::hashDoToken($token)) !== null;
    }

    /**
     * Define a nova senha com o token. O token vale uma única vez.
     *
     * @return bool false se o token é inválido, expirou ou já foi usado
     * @throws ValidacaoException senha fora das regras
     * @throws RateLimitExcedidoException
     */
    public function redefinir(string $token, string $senha, string $confirmacao, string $ip): bool
    {
        $this->limitar('resetconf:ip:' . $ip, 10);

        $erro = UsuarioService::validarSenha($senha, $confirmacao);
        if ($erro !== null) {
            throw new ValidacaoException(['senha' => $erro]);
        }

        $reset = $this->resets->buscarValido(self::hashDoToken($token));
        if ($reset === null) {
            return false;
        }

        // Marcar como usado é atômico: em requisições simultâneas só uma passa.
        if (!$this->resets->marcarUsado($reset['id'])) {
            return false;
        }

        $this->usuarios->atualizarSenha($reset['usuario_id'], $this->hasher->hash($senha));
        $this->resets->invalidarDoUsuario($reset['usuario_id']);
        $dono = $this->usuarios->porId($reset['usuario_id']);
        $this->auditoria?->registrar('senha.redefinida', $dono, $ip, 'usuario', (int) $reset['usuario_id']);

        return true;
    }

    private function limitar(string $chave, int $maximo): void
    {
        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0 || $this->limitador->registrar($chave, $maximo, 3600, 3600)) {
            throw new RateLimitExcedidoException($espera > 0 ? $espera : 3600);
        }
    }

    private static function hashDoToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
