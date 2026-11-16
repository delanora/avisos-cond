<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\View;

/**
 * Página inicial provisória: será substituída pelo redirecionamento para o
 * login quando a autenticação (ROADMAP 1.3) for implementada.
 */
final class HomeController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $vars */
    public function index(Request $request, array $vars): Response
    {
        return Response::html($this->view->render('home.html.twig'));
    }
}
