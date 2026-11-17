<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\DispositivoRepository;
use App\Support\NaoEncontradoException;

/**
 * Autenticação da TV pelo token da URL. Token malformado, inexistente ou de dispositivo revogado
 * resulta sempre no mesmo 404 sem detalhes, e cada falha conta no rate limit do IP (anti-adivinhação).
 */
final class TvAcesso
{
    // 30 falhas em 10 min bloqueiam o IP por 15 min. Várias TVs atrás do mesmo IP não falham sozinhas:
    // só tokens inválidos contam, e uma TV com token revogado falha ~1 vez por minuto.
    private const MAX_FALHAS = 30;
    private const JANELA = 600;
    private const BLOQUEIO = 900;

    public function __construct(
        private readonly DispositivoRepository $dispositivos,
        private readonly RateLimiter $limitador,
    ) {
    }

    /**
     * @return array{dispositivo: array<string, mixed>, condominio: array<string, mixed>}
     * @throws RateLimitExcedidoException IP bloqueado por excesso de tokens inválidos
     * @throws NaoEncontradoException token inválido ou dispositivo inativo
     */
    public function resolver(string $tokenDaUrl, string $ip): array
    {
        $chave = 'tv:ip:' . $ip;

        $espera = $this->limitador->segundosBloqueado($chave);
        if ($espera > 0) {
            throw new RateLimitExcedidoException($espera);
        }

        $token = TokenDispositivo::normalizar($tokenDaUrl);
        $acesso = $token === null ? null : $this->dispositivos->porToken($token);

        if ($acesso === null || !$acesso['dispositivo']['ativo']) {
            $this->limitador->registrar($chave, self::MAX_FALHAS, self::JANELA, self::BLOQUEIO);

            throw new NaoEncontradoException();
        }

        return $acesso;
    }
}
