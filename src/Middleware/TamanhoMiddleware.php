<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Request;
use App\Support\Response;

/**
 * Recusa envios maiores que o permitido com uma mensagem clara. Sem isso, quando o corpo passa do
 * limite do PHP (post_max_size) o formulário chega vazio e o usuário veria só "sessão expirada".
 */
final class TamanhoMiddleware implements Middleware
{
    public function __construct(
        private readonly Negado $negado,
        private readonly int $maximoBytes,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $tamanho = (int) ($request->server['CONTENT_LENGTH'] ?? 0);

        if ($tamanho > $this->maximoBytes) {
            return $this->negado->resposta(413, sprintf(
                'O envio é maior que o permitido (%s MB). Escolha um arquivo menor.',
                rtrim(rtrim(number_format($this->maximoBytes / 1048576, 1, ',', ''), '0'), ',')
            ));
        }

        return $proximo($request);
    }
}
