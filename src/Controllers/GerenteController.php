<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Painel do gerente. Provisório: condomínios e avisos vêm no ROADMAP 1.5. */
final class GerenteController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $vars */
    public function painel(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('gerente/painel.html.twig', [
            'usuario' => $request->attribute('usuario'),
        ]));
    }
}
