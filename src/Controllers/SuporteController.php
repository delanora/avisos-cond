<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuditoriaService;
use App\Services\GestaoUsuariosService;
use App\Support\NaoEncontradoException;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Modo suporte: o superadmin entra no painel de um gerente e vê o que ele vê. A sessão continua sendo do
 * administrador (login, troca de senha e desativação dele valem); só guarda QUEM está sendo visto. O AuthMiddleware
 * monta o gerente como usuário atual e a auditoria grava os dois nomes.
 */
final class SuporteController
{
    public function __construct(
        private readonly Session $sessao,
        private readonly GestaoUsuariosService $usuarios,
        private readonly AuditoriaService $auditoria,
    ) {
    }

    /** Rota só de superadmin (pipeline do /admin). @param array<string, string> $vars */
    public function entrar(Request $request, array $vars): Response
    {
        $alvo = $this->usuarios->buscar((int) $vars['id']);
        // Só gerente ativo: entrar como outro administrador ou como conta desativada não faz sentido.
        if ($alvo['papel'] !== 'gerente' || !$alvo['ativo']) {
            throw new NaoEncontradoException();
        }

        $this->sessao->set('suporte_como_id', (int) $alvo['id']);
        $this->auditoria->registrarDaRequisicao($request, 'suporte.iniciado', 'usuario', (int) $alvo['id'], null, ['gerente' => $alvo['nome'], 'email' => $alvo['email']]);
        $this->sessao->flash('Modo suporte: você está vendo o painel como ' . $alvo['nome'] . '.');

        return Response::redirect('/gerente', 303);
    }

    /** Qualquer usuário logado; fora do modo suporte não faz nada. @param array<string, string> $vars */
    public function sair(Request $request, array $vars): Response
    {
        $usuario = $request->attribute('usuario');
        if (!is_array($usuario) || !is_array($usuario['suporte_admin'] ?? null)) {
            return Response::redirect('/', 303);
        }

        // Com o gerente ainda no "usuario" da requisição, a auditoria grava o admin real e o gerente.
        $this->auditoria->registrarDaRequisicao($request, 'suporte.encerrado', 'usuario', (int) $usuario['id']);
        $this->sessao->forget('suporte_como_id');
        $this->sessao->flash('Você saiu do modo suporte.');

        return Response::redirect('/admin/usuarios', 303);
    }
}
