<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/** Vínculo N:N entre gerentes e condomínios (tabela usuario_condominio). */
final class VinculoRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<int> */
    public function condominiosDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare('SELECT condominio_id FROM usuario_condominio WHERE usuario_id = :id ORDER BY condominio_id');
        $stmt->execute(['id' => $usuarioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public function usuariosDoCondominio(int $condominioId): array
    {
        $stmt = $this->pdo->prepare('SELECT usuario_id FROM usuario_condominio WHERE condominio_id = :id ORDER BY usuario_id');
        $stmt->execute(['id' => $condominioId]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Deixa o usuário vinculado exatamente aos condomínios informados (remove os demais).
     * Deve rodar dentro de uma transação, junto com a alteração que a originou.
     *
     * @param list<int> $condominioIds
     */
    public function sincronizarDoUsuario(int $usuarioId, array $condominioIds): void
    {
        $ids = Pg::arrayDeInteiros($condominioIds);

        $remover = $this->pdo->prepare(
            'DELETE FROM usuario_condominio WHERE usuario_id = :usuario AND NOT (condominio_id = ANY(:ids::bigint[]))'
        );
        $remover->execute(['usuario' => $usuarioId, 'ids' => $ids]);

        $inserir = $this->pdo->prepare(
            'INSERT INTO usuario_condominio (usuario_id, condominio_id)
             SELECT :usuario, unnest(:ids::bigint[]) ON CONFLICT DO NOTHING'
        );
        $inserir->execute(['usuario' => $usuarioId, 'ids' => $ids]);
    }

    /**
     * Deixa o condomínio vinculado exatamente aos usuários informados (remove os demais).
     *
     * @param list<int> $usuarioIds
     */
    public function sincronizarDoCondominio(int $condominioId, array $usuarioIds): void
    {
        $ids = Pg::arrayDeInteiros($usuarioIds);

        $remover = $this->pdo->prepare(
            'DELETE FROM usuario_condominio WHERE condominio_id = :condominio AND NOT (usuario_id = ANY(:ids::bigint[]))'
        );
        $remover->execute(['condominio' => $condominioId, 'ids' => $ids]);

        $inserir = $this->pdo->prepare(
            'INSERT INTO usuario_condominio (usuario_id, condominio_id)
             SELECT unnest(:ids::bigint[]), :condominio ON CONFLICT DO NOTHING'
        );
        $inserir->execute(['condominio' => $condominioId, 'ids' => $ids]);
    }
}
