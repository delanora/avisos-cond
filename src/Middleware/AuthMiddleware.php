<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Repositories\UsuarioRepository;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/**
 * Exige login. O usuário é recarregado do banco a cada requisição, então desativar uma
 * conta ou trocar a senha tem efeito imediato (sessões antigas deixam de valer).
 * Anexa o usuário à requisição no atributo "usuario".
 */
final class AuthMiddleware implements Middleware
{
    public function __construct(
        private readonly Session $sessao,
        private readonly UsuarioRepository $usuarios,
    ) {
    }

    public function process(Request $request, callable $proximo): Response
    {
        $this->sessao->start();

        $usuario = $this->usuarioDaSessao();
        if ($usuario === null) {
            $this->sessao->destroy();

            return Response::redirect('/login');
        }

        return $proximo($request->withAttribute('usuario', $usuario));
    }

    /** @return array<string, mixed>|null */
    private function usuarioDaSessao(): ?array
    {
        $id = $this->sessao->get('usuario_id');
        $autenticadoEm = $this->sessao->get('autenticado_em');
        if (!is_int($id) || !is_int($autenticadoEm)) {
            return null;
        }

        $usuario = $this->usuarios->porId($id);
        if ($usuario === null || !$usuario['ativo']) {
            return null;
        }

        // Senha trocada depois deste login: a sessão é de antes da troca e não vale mais.
        $senhaAlteradaEm = $usuario['senha_alterada_epoch'];
        if ($senhaAlteradaEm !== null && $senhaAlteradaEm > $autenticadoEm) {
            return null;
        }

        return $usuario;
    }
}
