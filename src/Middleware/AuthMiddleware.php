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
 *
 * Modo suporte: se a sessão de um superadmin guarda "suporte_como_id" de um gerente ativo, o "usuario" passa a
 * ser esse gerente (com a chave "suporte_admin" trazendo o administrador real, sem o hash da senha). Gerente
 * apagado, desativado ou que mudou de papel encerra o modo suporte na hora.
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

        return $proximo($request->withAttribute('usuario', $this->emModoSuporte($usuario)));
    }

    /**
     * @param array<string, mixed> $usuario
     * @return array<string, mixed>
     */
    private function emModoSuporte(array $usuario): array
    {
        $comoId = $this->sessao->get('suporte_como_id');
        if ($comoId === null) {
            return $usuario;
        }

        $gerente = is_int($comoId) && $usuario['papel'] === 'superadmin' ? $this->usuarios->porId($comoId) : null;
        if ($gerente === null || !$gerente['ativo'] || $gerente['papel'] !== 'gerente') {
            $this->sessao->forget('suporte_como_id');

            return $usuario;
        }

        unset($usuario['senha_hash'], $gerente['senha_hash']);

        return $gerente + ['suporte_admin' => $usuario];
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
