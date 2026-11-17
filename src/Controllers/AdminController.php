<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\DispositivoService;
use App\Services\GestaoCondominiosService;
use App\Services\GestaoUsuariosService;
use App\Support\Request;
use App\Support\Response;
use App\Support\Saudacao;
use App\Support\SituacaoDispositivo;
use App\Support\View;

/** Página inicial do painel da Embracore: saudação, números e atalhos. */
final class AdminController
{
    public function __construct(
        private readonly View $view,
        private readonly GestaoUsuariosService $usuarios,
        private readonly GestaoCondominiosService $condominios,
        private readonly DispositivoService $dispositivos,
    ) {
    }

    /** @param array<string, string> $vars */
    public function painel(Request $request, array $vars): Response
    {
        $usuario = $request->attribute('usuario');
        $pessoas = $this->usuarios->listar();
        $lista = $this->condominios->listar();
        $contagem = DispositivoService::contarPorSituacao($this->dispositivos->listarComSituacao(time()));

        return Response::html($this->view->render('admin/painel.html.twig', [
            'usuario' => $usuario,
            'saudacao' => Saudacao::para($usuario['nome']),
            'total_condominios' => count($lista),
            'condominios_ativos' => count(array_filter($lista, static fn (array $c): bool => $c['ativo'])),
            'total_gerentes' => count(array_filter($pessoas, static fn (array $u): bool => $u['papel'] === 'gerente')),
            'dispositivos_total' => array_sum($contagem) - $contagem[SituacaoDispositivo::REVOGADO],
            'dispositivos_online' => $contagem[SituacaoDispositivo::ONLINE],
            'dispositivos_offline' => $contagem[SituacaoDispositivo::OFFLINE],
            'total_admins' => count(array_filter($pessoas, static fn (array $u): bool => $u['papel'] === 'superadmin')),
        ]));
    }
}
