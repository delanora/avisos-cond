<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Log em arquivo (storage/logs). Nunca registre senhas, tokens ou chaves de API.
 */
final class Logger
{
    public function __construct(private readonly string $arquivo)
    {
    }

    public function error(string $mensagem, ?Throwable $erro = null): void
    {
        $linha = sprintf('[%s] ERROR %s', gmdate('c'), $mensagem);
        if ($erro !== null) {
            $linha .= sprintf(
                " | %s: %s em %s:%d\n%s",
                $erro::class,
                $erro->getMessage(),
                $erro->getFile(),
                $erro->getLine(),
                $erro->getTraceAsString()
            );
        }

        // Falha ao escrever o log não pode derrubar a resposta ao usuário.
        @file_put_contents($this->arquivo, $linha . "\n", FILE_APPEND | LOCK_EX);
    }
}
