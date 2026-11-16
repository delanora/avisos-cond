<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class RateLimitExcedidoException extends RuntimeException
{
    public function __construct(public readonly int $segundos)
    {
        parent::__construct('Limite de tentativas excedido.');
    }
}
