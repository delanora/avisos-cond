<?php

declare(strict_types=1);

namespace App\Support;

final class Session
{
    public function __construct(
        private readonly string $nome,
        private readonly bool $seguro,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        // Cookie Secure sempre que o site for HTTPS (produção); só em dev local por HTTP fica desligado.
        return new self(
            $config->string('SESSION_NAME', 'avisos_sess'),
            str_starts_with($config->string('APP_URL'), 'https://')
        );
    }

    /** @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string} */
    public function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/',
            'secure' => $this->seguro,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($this->nome);
        session_set_cookie_params($this->cookieParams());
        session_start();
    }

    public function get(string $chave, mixed $padrao = null): mixed
    {
        return $_SESSION[$chave] ?? $padrao;
    }

    public function set(string $chave, mixed $valor): void
    {
        $_SESSION[$chave] = $valor;
    }

    public function has(string $chave): bool
    {
        return isset($_SESSION[$chave]);
    }

    public function forget(string $chave): void
    {
        unset($_SESSION[$chave]);
    }

    /** Deve ser chamado no login, para impedir fixação de sessão. */
    public function regenerate(): void
    {
        session_regenerate_id(true);
    }

    /** Logout: limpa os dados, apaga o cookie e destrói a sessão. */
    public function destroy(): void
    {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            $params = $this->cookieParams();
            setcookie($this->nome, '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
            session_destroy();
        }
    }
}
