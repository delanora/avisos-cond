<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;

/**
 * Troca de senha pelo próprio usuário logado (Configurações > Segurança). Exige a senha atual, aplica as regras
 * de senha nova e limita tentativas erradas da senha atual (alguém com uma sessão aberta num computador esquecido
 * não consegue descobrir a senha por tentativa e erro).
 */
final class SenhaService
{
    // 5 erros da senha atual em 15 min bloqueiam novas tentativas por 15 min.
    private const MAX_FALHAS = 5;
    private const JANELA = 900;
    private const BLOQUEIO = 900;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly PasswordHasher $hasher,
        private readonly RateLimiter $limitador,
    ) {
    }

    /**
     * @param array<string, mixed> $usuario o usuário logado (com senha_hash)
     * @return int o novo "autenticado_em" da sessão de quem trocou (para ela continuar valendo)
     * @throws ValidacaoException           senha atual errada ou senha nova inválida (erros por campo)
     * @throws RateLimitExcedidoException   muitas tentativas erradas da senha atual
     */
    public function trocar(array $usuario, string $atual, string $nova, string $confirmacao): int
    {
        $chave = 'senha:usuario:' . (int) $usuario['id'];
        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0) {
            throw new RateLimitExcedidoException($espera);
        }

        if ($atual === '' || mb_strlen($atual) > 1024 || !$this->hasher->verificar($atual, (string) $usuario['senha_hash'])) {
            $this->limitador->registrar($chave, self::MAX_FALHAS, self::JANELA, self::BLOQUEIO);

            throw new ValidacaoException(['senha_atual' => 'A senha atual não confere.']);
        }

        $erro = UsuarioService::validarSenha($nova, $confirmacao);
        if ($erro === null && hash_equals($atual, $nova)) {
            $erro = 'A nova senha precisa ser diferente da atual.';
        }
        if ($erro !== null) {
            throw new ValidacaoException(['nova_senha' => $erro]);
        }

        $this->limitador->limpar($chave);
        // Registra o momento da troca: as sessões abertas antes dele (outros aparelhos) deixam de valer.
        $this->usuarios->atualizarSenha((int) $usuario['id'], $this->hasher->hash($nova));

        // O banco guarda o instante da troca arredondado ao segundo (pode ser 1 s à frente do relógio daqui): a sessão de quem
        // trocou usa o maior dos dois, senão ela mesma se invalidaria.
        $atualizado = $this->usuarios->porId((int) $usuario['id']);

        return max(time(), (int) ($atualizado['senha_alterada_epoch'] ?? 0));
    }
}
