<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Modelos de aviso: os do sistema (condominio_id nulo, valem para todos) e os de cada condomínio.
 * ISOLAMENTO: tudo recebe a lista de condomínios permitidos e filtra por ela e pelo condomínio pedido;
 * modelo de outro condomínio nunca é visto, usado nem alterado.
 */
final class ModeloAvisoRepository
{
    private const COLUNAS = 'id, condominio_id, nome, titulo, corpo, tipo, sistema';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Modelos que o condomínio pode usar: os do sistema e depois os dele, por nome.
     *
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function listar(array $permitidos, int $condominioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM modelos_aviso
             WHERE (condominio_id IS NULL OR condominio_id = :cid) AND :cid = ANY(:permitidos::bigint[])
             ORDER BY sistema DESC, CASE WHEN sistema THEN id END, lower(nome), id'
        );
        $stmt->execute(['cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * Um modelo que o condomínio pode usar (do sistema ou dele).
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function utilizavelPorId(array $permitidos, int $condominioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM modelos_aviso
             WHERE id = :id AND (condominio_id IS NULL OR condominio_id = :cid) AND :cid = ANY(:permitidos::bigint[])'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Um modelo PRÓPRIO do condomínio (os do sistema não se editam).
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function proprioPorId(array $permitidos, int $condominioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM modelos_aviso
             WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[])'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** Já existe, entre os do sistema e os do condomínio, um modelo com este nome (sem distinguir maiúsculas)? */
    public function nomeEmUso(int $condominioId, string $nome, ?int $exceto = null): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM modelos_aviso
             WHERE (condominio_id IS NULL OR condominio_id = :cid) AND lower(nome) = lower(:nome)
               AND (:exceto::bigint IS NULL OR id <> :exceto::bigint) LIMIT 1'
        );
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('exceto', $exceto, $exceto === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Cria um modelo no condomínio, se ele estiver entre os permitidos (senão null).
     *
     * @param list<int> $permitidos
     */
    public function criar(array $permitidos, int $condominioId, string $nome, string $titulo, string $corpo, string $tipo): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modelos_aviso (condominio_id, nome, titulo, corpo, tipo)
             SELECT :cid::bigint, :nome::text, :titulo::text, :corpo::text, :tipo::text
             WHERE :cid::bigint = ANY(:permitidos::bigint[])
             RETURNING id'
        );
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('titulo', $titulo);
        $stmt->bindValue('corpo', $corpo);
        $stmt->bindValue('tipo', $tipo);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @param list<int> $permitidos */
    public function atualizar(array $permitidos, int $condominioId, int $id, string $nome, string $titulo, string $corpo, string $tipo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE modelos_aviso SET nome = :nome, titulo = :titulo, corpo = :corpo, tipo = :tipo
             WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[]) AND NOT sistema'
        );
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('titulo', $titulo);
        $stmt->bindValue('corpo', $corpo);
        $stmt->bindValue('tipo', $tipo);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** @param list<int> $permitidos */
    public function excluir(array $permitidos, int $condominioId, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM modelos_aviso WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[]) AND NOT sistema'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return $stmt->rowCount() === 1;
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private function normalizar(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'condominio_id' => $l['condominio_id'] === null ? null : (int) $l['condominio_id'],
            'nome' => (string) $l['nome'],
            'titulo' => (string) $l['titulo'],
            'corpo' => (string) $l['corpo'],
            'tipo' => (string) $l['tipo'],
            'sistema' => (bool) $l['sistema'],
        ];
    }
}
