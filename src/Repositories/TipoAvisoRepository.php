<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Tipos de aviso de cada GERENTE. ISOLAMENTO: todo método recebe o id do gerente (do usuário logado, nunca do
 * cliente) e só enxerga ou altera os tipos dele; o tipo de um gerente nunca aparece para outro. As matrizes
 * de fábrica (sistema = true, sem dono) não são acessíveis por aqui.
 */
final class TipoAvisoRepository
{
    private const COLUNAS = 't.id, t.usuario_id, t.chave, t.nome, t.cor, t.fixo, t.sistema';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Os tipos do gerente, na ordem em que foram criados (os de fábrica primeiro), com quantos avisos usam cada um.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', (SELECT count(*) FROM avisos a WHERE a.tipo_id = t.id) AS em_uso
             FROM tipos_aviso t WHERE t.usuario_id = :u ORDER BY t.id'
        );
        $stmt->execute(['u' => $usuarioId]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function porChave(int $usuarioId, string $chave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', 0 AS em_uso FROM tipos_aviso t WHERE t.usuario_id = :u AND t.chave = :chave'
        );
        $stmt->execute(['u' => $usuarioId, 'chave' => $chave]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** @return array<string, mixed>|null */
    public function porId(int $usuarioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', (SELECT count(*) FROM avisos a WHERE a.tipo_id = t.id) AS em_uso
             FROM tipos_aviso t WHERE t.id = :id AND t.usuario_id = :u'
        );
        $stmt->execute(['id' => $id, 'u' => $usuarioId]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    public function contar(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM tipos_aviso WHERE usuario_id = :u');
        $stmt->execute(['u' => $usuarioId]);

        return (int) $stmt->fetchColumn();
    }

    /** Este gerente já tem um tipo com este nome (sem distinguir maiúsculas) ou esta chave? */
    public function nomeOuChaveEmUso(int $usuarioId, string $nome, string $chave, ?int $exceto = null): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM tipos_aviso
             WHERE usuario_id = :u AND (lower(nome) = lower(:nome) OR chave = :chave)
               AND (:exceto::bigint IS NULL OR id <> :exceto::bigint) LIMIT 1'
        );
        $stmt->bindValue('u', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('chave', $chave);
        $stmt->bindValue('exceto', $exceto, $exceto === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function criar(int $usuarioId, string $chave, string $nome, ?string $cor, bool $fixo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tipos_aviso (usuario_id, chave, nome, cor, fixo) VALUES (:u, :chave, :nome, :cor, :fixo) RETURNING id'
        );
        $stmt->bindValue('u', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('chave', $chave);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cor', $cor, $cor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('fixo', $fixo, PDO::PARAM_BOOL);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /** A chave nunca muda (os avisos continuam ligados ao tipo pelo id). */
    public function atualizar(int $usuarioId, int $id, string $nome, ?string $cor, bool $fixo): bool
    {
        $stmt = $this->pdo->prepare('UPDATE tipos_aviso SET nome = :nome, cor = :cor, fixo = :fixo WHERE id = :id AND usuario_id = :u');
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('cor', $cor, $cor === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue('fixo', $fixo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->bindValue('u', $usuarioId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Apaga um tipo do gerente que nenhum aviso use. False se não for dele, não existir ou estiver em uso. */
    public function excluir(int $usuarioId, int $id): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tipos_aviso t WHERE t.id = :id AND t.usuario_id = :u
               AND NOT EXISTS (SELECT 1 FROM avisos a WHERE a.tipo_id = t.id)'
        );
        $stmt->execute(['id' => $id, 'u' => $usuarioId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Tipos que precisam de cor numa tela do condomínio: os do gerente logado e os usados pelos avisos do
     * condomínio (que podem ser de outro gerente do mesmo condomínio: o aviso é compartilhado, então a etiqueta dele também).
     *
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function paraCores(int $usuarioId, array $permitidos, int $condominioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ', 0 AS em_uso FROM tipos_aviso t
             WHERE t.usuario_id = :u
                OR (:cid = ANY(:permitidos::bigint[]) AND t.id IN (SELECT a.tipo_id FROM avisos a WHERE a.condominio_id = :cid))
             ORDER BY t.id'
        );
        $stmt->execute(['u' => $usuarioId, 'cid' => $condominioId, 'permitidos' => Pg::arrayDeInteiros($permitidos)]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private function normalizar(array $l): array
    {
        return [
            'id' => (int) $l['id'],
            'usuario_id' => $l['usuario_id'] === null ? null : (int) $l['usuario_id'],
            'chave' => (string) $l['chave'],
            'nome' => (string) $l['nome'],
            'cor' => $l['cor'] === null ? null : (string) $l['cor'],
            'fixo' => (bool) $l['fixo'],
            'sistema' => (bool) $l['sistema'],
            'em_uso' => (int) $l['em_uso'],
        ];
    }
}
