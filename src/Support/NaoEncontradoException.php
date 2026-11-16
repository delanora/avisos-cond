<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** Recurso inexistente (ou fora do escopo do usuário): a Application responde 404. */
final class NaoEncontradoException extends RuntimeException
{
}
