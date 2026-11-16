<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/** Painel da Embracore. Provisório: o CRUD vem no ROADMAP 1.4. */
final class AdminController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $vars */
    public function painel(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('admin/painel.html.twig', [
            'usuario' => $request->attribute('usuario'),
        ]));
    }
}
