<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class CondominioRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        $linhas = $this->pdo->query(
            'SELECT c.id, c.nome, c.endereco, c.logo, c.cor_primaria, c.cor_secundaria, c.ativo,
                    (SELECT count(*) FROM usuario_condominio uc WHERE uc.condominio_id = c.id) AS total_gerentes
             FROM condominios c
             ORDER BY lower(c.nome), c.id'
        )->fetchAll();

        return array_map($this->normalizar(...), $linhas);
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, nome, endereco, logo, cor_primaria, cor_secundaria, ativo, 0 AS total_gerentes
             FROM condominios WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Condomínios ATIVOS aos quais o usuário está vinculado. É daqui que vem, a cada requisição,
     * a lista de condomínios permitidos do gerente (isolamento entre condomínios).
     *
     * @return list<array<string, mixed>>
     */
    public function listarDoGerente(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.nome, c.endereco, c.logo, c.cor_primaria, c.cor_secundaria, c.ativo, 0 AS total_gerentes
             FROM condominios c
             JOIN usuario_condominio uc ON uc.condominio_id = c.id
             WHERE uc.usuario_id = :usuario AND c.ativo
             ORDER BY lower(c.nome), c.id'
        );
        $stmt->execute(['usuario' => $usuarioId]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * Logo e cores do condomínio, só se ele estiver entre os permitidos.
     *
     * @param list<int> $permitidos
     */
    public function atualizarAparencia(array $permitidos, int $id, ?string $logo, ?string $corPrimaria, ?string $corSecundaria): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE condominios SET logo = :logo, cor_primaria = :primaria, cor_secundaria = :secundaria
             WHERE id = :id AND id = ANY(:permitidos::bigint[])'
        );
        $stmt->bindValue('logo', $logo, $logo === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('primaria', $corPrimaria, $corPrimaria === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('secundaria', $corSecundaria, $corSecundaria === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function criar(string $nome, ?string $endereco, bool $ativo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO condominios (nome, endereco, ativo) VALUES (:nome, :endereco, :ativo) RETURNING id'
        );
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('endereco', $endereco);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function atualizar(int $id, string $nome, ?string $endereco, bool $ativo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE condominios SET nome = :nome, endereco = :endereco, ativo = :ativo WHERE id = :id'
        );
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('endereco', $endereco);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Exclui o condomínio. As FKs com ON DELETE CASCADE removem avisos, dispositivos e vínculos. */
    public function excluir(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM condominios WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /**
     * O que será apagado junto com o condomínio (mostrado na confirmação de exclusão).
     *
     * @return array{avisos: int, dispositivos: int, gerentes: int}
     */
    public function dependencias(int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT count(*) FROM avisos WHERE condominio_id = :id) AS avisos,
                    (SELECT count(*) FROM dispositivos WHERE condominio_id = :id) AS dispositivos,
                    (SELECT count(*) FROM usuario_condominio WHERE condominio_id = :id) AS gerentes'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return [
            'avisos' => (int) $linha['avisos'],
            'dispositivos' => (int) $linha['dispositivos'],
            'gerentes' => (int) $linha['gerentes'],
        ];
    }

    /**
     * Dos IDs informados, devolve só os que existem.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    public function idsExistentes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare('SELECT id FROM condominios WHERE id = ANY(:ids::bigint[]) ORDER BY id');
        $stmt->execute(['ids' => Pg::arrayDeInteiros($ids)]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param array<string, mixed> $linha
     * @return array<string, mixed>
     */
    private function normalizar(array $linha): array
    {
        return [
            'id' => (int) $linha['id'],
            'nome' => (string) $linha['nome'],
            'endereco' => $linha['endereco'] === null ? null : (string) $linha['endereco'],
            'logo' => $linha['logo'] === null ? null : (string) $linha['logo'],
            'cor_primaria' => $linha['cor_primaria'] === null ? null : (string) $linha['cor_primaria'],
            'cor_secundaria' => $linha['cor_secundaria'] === null ? null : (string) $linha['cor_secundaria'],
            'ativo' => (bool) $linha['ativo'],
            'total_gerentes' => (int) $linha['total_gerentes'],
        ];
    }
}
