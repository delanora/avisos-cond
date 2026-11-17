<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Tipos de aviso: os do sistema (condominio_id nulo, valem para todos) e os criados por condomínio.
 * ISOLAMENTO: tudo que muda ou lista tipos de um condomínio recebe a lista de condomínios permitidos e filtra
 * por ela e pelo condomínio pedido; tipo de outro condomínio nunca é visto nem alterado.
 */
final class TipoAvisoRepository
{
    private const COLUNAS = 'id, condominio_id, chave, nome, cor, fixo, sistema';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Tipos que o condomínio pode usar: primeiro os do sistema, depois os dele (por nome).
     *
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function listar(array $permitidos, int $condominioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', (SELECT count(*) FROM avisos a WHERE a.tipo = t.chave AND a.condominio_id = :cid) AS em_uso
             FROM tipos_aviso t
             WHERE (t.condominio_id IS NULL OR t.condominio_id = :cid) AND :cid = ANY(:permitidos::bigint[])
             ORDER BY t.sistema DESC, CASE WHEN t.sistema THEN t.id END, lower(t.nome), t.id'
        );
        $stmt->execute(['cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * Um tipo pela chave, desde que o condomínio possa usá-lo (do sistema ou dele).
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function porChave(array $permitidos, int $condominioId, string $chave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', 0 AS em_uso FROM tipos_aviso
             WHERE chave = :chave AND (condominio_id IS NULL OR condominio_id = :cid) AND :cid = ANY(:permitidos::bigint[])
             LIMIT 1'
        );
        $stmt->execute(['chave' => $chave, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Um tipo PRÓPRIO do condomínio (os do sistema não são editáveis), pelo id.
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>|null
     */
    public function proprioPorId(array $permitidos, int $condominioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', (SELECT count(*) FROM avisos a WHERE a.tipo = t.chave AND a.condominio_id = t.condominio_id) AS em_uso
             FROM tipos_aviso t
             WHERE t.id = :id AND t.condominio_id = :cid AND t.condominio_id = ANY(:permitidos::bigint[])'
        );
        $stmt->execute(['id' => $id, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** Já existe, entre os do sistema e os do condomínio, um tipo com este nome (sem distinguir maiúsculas) ou esta chave? */
    public function nomeOuChaveEmUso(int $condominioId, string $nome, string $chave, ?int $exceto = null): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tipos_aviso
             WHERE (condominio_id IS NULL OR condominio_id = :cid) AND (lower(nome) = lower(:nome) OR chave = :chave)
               AND (:exceto::bigint IS NULL OR id <> :exceto::bigint)
             LIMIT 1'
        );
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('chave', $chave);
        $stmt->bindValue('exceto', $exceto, $exceto === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Cria um tipo no condomínio, se ele estiver entre os permitidos (senão null).
     *
     * @param list<int> $permitidos
     */
    public function criar(array $permitidos, int $condominioId, string $chave, string $nome, ?string $cor, bool $fixo): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tipos_aviso (condominio_id, chave, nome, cor, fixo)
             SELECT :cid::bigint, :chave::text, :nome::text, :cor::text, :fixo::boolean
             WHERE :cid::bigint = ANY(:permitidos::bigint[])
             RETURNING id'
        );
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('chave', $chave);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cor', $cor, $cor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('fixo', $fixo, PDO::PARAM_BOOL);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * A chave nunca muda (os avisos continuam ligados ao tipo).
     *
     * @param list<int> $permitidos
     */
    public function atualizar(array $permitidos, int $condominioId, int $id, string $nome, ?string $cor, bool $fixo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tipos_aviso SET nome = :nome, cor = :cor, fixo = :fixo
             WHERE id = :id AND condominio_id = :cid AND condominio_id = ANY(:permitidos::bigint[]) AND NOT sistema'
        );
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cor', $cor, $cor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('fixo', $fixo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('cid', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('permitidos', Pg::arrayDeInteiros($permitidos));
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Apaga um tipo próprio que nenhum aviso use. Retorna false se não existir, for de outro condomínio ou estiver em uso.
     *
     * @param list<int> $permitidos
     */
    public function excluir(array $permitidos, int $condominioId, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tipos_aviso t
             WHERE t.id = :id AND t.condominio_id = :cid AND t.condominio_id = ANY(:permitidos::bigint[]) AND NOT t.sistema
               AND NOT EXISTS (SELECT 1 FROM avisos a WHERE a.tipo = t.chave AND a.condominio_id = t.condominio_id)'
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
            'chave' => (string) $l['chave'],
            'nome' => (string) $l['nome'],
            'cor' => $l['cor'] === null ? null : (string) $l['cor'],
            'fixo' => (bool) $l['fixo'],
            'sistema' => (bool) $l['sistema'],
            'em_uso' => (int) $l['em_uso'],
        ];
    }
}
