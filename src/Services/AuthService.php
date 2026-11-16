<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;

final class AuthService
{
    // 5 falhas por e-mail em 15 min bloqueiam esse e-mail por 15 min.
    private const MAX_FALHAS = 5;
    private const JANELA = 900;
    private const BLOQUEIO = 900;

    private ?string $hashFalso = null;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limitador,
    ) {
    }

    /**
     * Valida e-mail e senha. Retorna o usuário ou null (credenciais inválidas, sem dizer o motivo).
     *
     * @return array<string, mixed>|null
     * @throws RateLimitExcedidoException quando o e-mail está temporariamente bloqueado
     */
    public function autenticar(string $email, string $senha): ?array
    {
        $chave = 'login:email:' . RateLimiter::hashDe($email);

        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0) {
            throw new RateLimitExcedidoException($espera);
        }

        $usuario = $this->usuarios->porEmail($email);

        // Verifica a senha mesmo quando o e-mail não existe, para o tempo de resposta
        // não revelar quais e-mails estão cadastrados.
        $hash = $usuario['senha_hash'] ?? $this->hashFalso();
        $senhaCorreta = $this->hasher->verificar($senha, $hash);

        if ($usuario === null || !$usuario['ativo'] || !$senhaCorreta) {
            $this->limitador->registrar($chave, self::MAX_FALHAS, self::JANELA, self::BLOQUEIO);

            return null;
        }

        $this->limitador->limpar($chave);

        if ($this->hasher->precisaRehash($usuario['senha_hash'])) {
            $this->usuarios->atualizarHash($usuario['id'], $this->hasher->hash($senha));
        }

        return $usuario;
    }

    private function hashFalso(): string
    {
        return $this->hashFalso ??= $this->hasher->hash(bin2hex(random_bytes(8)));
    }
}
