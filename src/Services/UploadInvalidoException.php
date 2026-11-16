<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Arquivo enviado recusado. A mensagem está em português e pode ser mostrada ao usuário. */
final class UploadInvalidoException extends RuntimeException
{
}
