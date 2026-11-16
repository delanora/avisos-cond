<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\EscopoGerente;
use App\Support\Request;
use App\Support\Response;
use App\Support\Saudacao;
use App\Support\View;

/** Página inicial do gerente: os condomínios dele. Com um só condomínio, vai direto para os avisos. */
final class GerenteController
{
    public function __construct(private readonly View $view)
    {
    }

    /** @param array<string, string> $vars */
    public function painel(Request $request, array $vars): Response
    {
        $condominios = EscopoGerente::condominios($request);

        if (count($condominios) === 1) {
            return Response::redirect("/gerente/condominios/{$condominios[0]['id']}/avisos");
        }

        $usuario = $request->attribute('usuario');

        return Response::html($this->view->render('gerente/painel.html.twig', EscopoGerente::contexto($request) + [
            'saudacao' => Saudacao::para($usuario['nome']),
        ]));
    }
}
