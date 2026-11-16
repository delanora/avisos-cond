<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class RateLimitRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Conta uma tentativa de forma atômica (um único comando) e devolve o total na janela.
     * A contagem recomeça quando a janela expirou ou quando um bloqueio anterior terminou.
     */
    public function registrarTentativa(string $chave, int $janelaSegundos): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rate_limits (chave, tentativas, janela_inicio)
             VALUES (:chave, 1, now())
             ON CONFLICT (chave) DO UPDATE SET
                 tentativas = CASE
                     WHEN rate_limits.janela_inicio < now() - make_interval(secs => :janela)
                          OR rate_limits.bloqueado_ate < now()
                     THEN 1 ELSE rate_limits.tentativas + 1 END,
                 janela_inicio = CASE
                     WHEN rate_limits.janela_inicio < now() - make_interval(secs => :janela)
                          OR rate_limits.bloqueado_ate < now()
                     THEN now() ELSE rate_limits.janela_inicio END,
                 bloqueado_ate = CASE
                     WHEN rate_limits.janela_inicio < now() - make_interval(secs => :janela)
                          OR rate_limits.bloqueado_ate < now()
                     THEN NULL ELSE rate_limits.bloqueado_ate END
             RETURNING tentativas'
        );
        $stmt->execute(['chave' => $chave, 'janela' => $janelaSegundos]);

        return (int) $stmt->fetchColumn();
    }

    public function bloquear(string $chave, int $segundos): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE rate_limits SET bloqueado_ate = now() + make_interval(secs => :segundos) WHERE chave = :chave'
        );
        $stmt->execute(['segundos' => $segundos, 'chave' => $chave]);
    }

    /** Segundos que faltam para o bloqueio terminar (0 = não bloqueado). */
    public function segundosBloqueado(string $chave): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(ceil(extract(epoch FROM bloqueado_ate - now())), 0)::int
             FROM rate_limits WHERE chave = :chave AND bloqueado_ate > now()'
        );
        $stmt->execute(['chave' => $chave]);
        $segundos = $stmt->fetchColumn();

        return $segundos === false ? 0 : max(0, (int) $segundos);
    }

    public function limpar(string $chave): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM rate_limits WHERE chave = :chave');
        $stmt->execute(['chave' => $chave]);
    }

    /** Remove contadores antigos (chamado pelo cron). */
    public function removerAntigos(int $idadeSegundos): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM rate_limits
             WHERE janela_inicio < now() - make_interval(secs => :idade)
               AND (bloqueado_ate IS NULL OR bloqueado_ate < now())'
        );
        $stmt->execute(['idade' => $idadeSegundos]);

        return $stmt->rowCount();
    }
}
