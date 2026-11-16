<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Support\Response;
use App\Support\View;

/** Página de erro usada pelos middlewares (403, 429). */
final class Negado
{
    public function __construct(private readonly View $view)
    {
    }

    public function resposta(int $codigo, string $mensagem): Response
    {
        return Response::html($this->view->render('erro.html.twig', [
            'codigo' => $codigo,
            'mensagem' => $mensagem,
        ]), $codigo);
    }
}
