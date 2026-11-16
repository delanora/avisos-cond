<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ValidacaoException extends RuntimeException
{
    /** @param array<string, string> $erros campo => mensagem */
    public function __construct(public readonly array $erros)
    {
        parent::__construct('Dados inválidos.');
    }
}
