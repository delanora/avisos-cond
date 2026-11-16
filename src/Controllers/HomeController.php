<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

/** Envia o usuário autenticado para o painel do seu papel. */
final class HomeController
{
    /** @param array<string, string> $vars */
    public function index(Request $request, array $vars): Response
    {
        $usuario = $request->attribute('usuario');
        $papel = is_array($usuario) ? $usuario['papel'] : '';

        return Response::redirect($papel === 'superadmin' ? '/admin' : '/gerente');
    }
}
