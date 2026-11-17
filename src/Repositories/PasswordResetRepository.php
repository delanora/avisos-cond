<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class PasswordResetRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function criar(int $usuarioId, string $tokenHash, int $validadeSegundos): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (usuario_id, token_hash, expira_em)
             VALUES (:usuario, :hash, now() + make_interval(secs => :validade))'
        );
        $stmt->execute(['usuario' => $usuarioId, 'hash' => $tokenHash, 'validade' => $validadeSegundos]);
    }

    /**
     * Token ainda utilizável: existe, não expirou e não foi usado.
     *
     * @return array{id: int, usuario_id: int}|null
     */
    public function buscarValido(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, usuario_id FROM password_resets
             WHERE token_hash = :hash AND usado_em IS NULL AND expira_em > now()'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $linha = $stmt->fetch();

        return $linha === false ? null : ['id' => (int) $linha['id'], 'usuario_id' => (int) $linha['usuario_id']];
    }

    /**
     * Marca como usado (uso único). Retorna false se outro processo usou antes,
     * o que impede o mesmo token de ser aplicado duas vezes em requisições simultâneas.
     */
    public function marcarUsado(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET usado_em = now() WHERE id = :id AND usado_em IS NULL'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Remove tokens que já não servem para nada (usados ou expirados) e são mais antigos que o prazo informado.
     * Tokens ainda válidos nunca são removidos. Chamado pelo agendador.
     */
    public function removerAntigos(int $idadeSegundos): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM password_resets
             WHERE criado_em < now() - make_interval(secs => :idade)
               AND (usado_em IS NOT NULL OR expira_em < now())'
        );
        $stmt->execute(['idade' => $idadeSegundos]);

        return $stmt->rowCount();
    }

    /** Invalida todos os tokens pendentes do usuário (novo pedido ou senha já trocada). */
    public function invalidarDoUsuario(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE password_resets SET usado_em = now() WHERE usuario_id = :usuario AND usado_em IS NULL'
        );
        $stmt->execute(['usuario' => $usuarioId]);
    }
}
