<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Modelos de aviso de cada GERENTE. ISOLAMENTO: todo método recebe o id do gerente (do usuário logado, nunca do
 * cliente) e só enxerga ou altera os modelos dele. As matrizes de fábrica (sem dono) não são acessíveis por aqui.
 */
final class ModeloAvisoRepository
{
    private const COLUNAS = 'id, usuario_id, nome, titulo, corpo, tipo';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Os modelos do gerente, na ordem de criação (os de fábrica primeiro).
     *
     * @return list<array<string, mixed>>
     */
    public function listar(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM modelos_aviso WHERE usuario_id = :u ORDER BY id');
        $stmt->execute(['u' => $usuarioId]);

        return array_map($this->normalizar(...), $stmt->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function porId(int $usuarioId, int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM modelos_aviso WHERE id = :id AND usuario_id = :u');
        $stmt->execute(['id' => $id, 'u' => $usuarioId]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /** Este gerente já tem um modelo com este nome (sem distinguir maiúsculas)? */
    public function nomeEmUso(int $usuarioId, string $nome, ?int $exceto = null): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM modelos_aviso WHERE usuario_id = :u AND lower(nome) = lower(:nome)
               AND (:exceto::bigint IS NULL OR id <> :exceto::bigint) LIMIT 1'
        );
        $stmt->bindValue('u', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('exceto', $exceto, $exceto === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    public function criar(int $usuarioId, string $nome, string $titulo, string $corpo, string $tipo): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modelos_aviso (usuario_id, nome, titulo, corpo, tipo) VALUES (:u, :nome, :titulo, :corpo, :tipo) RETURNING id'
        );
        $stmt->execute(['u' => $usuarioId, 'nome' => $nome, 'titulo' => $titulo, 'corpo' => $corpo, 'tipo' => $tipo]);

        return (int) $stmt->fetchColumn();
    }

    public function atualizar(int $usuarioId, int $id, string $nome, string $titulo, string $corpo, string $tipo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE modelos_aviso SET nome = :nome, titulo = :titulo, corpo = :corpo, tipo = :tipo WHERE id = :id AND usuario_id = :u'
        );
        $stmt->execute(['nome' => $nome, 'titulo' => $titulo, 'corpo' => $corpo, 'tipo' => $tipo, 'id' => $id, 'u' => $usuarioId]);

        return $stmt->rowCount() === 1;
    }

    public function excluir(int $usuarioId, int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM modelos_aviso WHERE id = :id AND usuario_id = :u');
        $stmt->execute(['id' => $id, 'u' => $usuarioId]);

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
            'usuario_id' => (int) $l['usuario_id'],
            'nome' => (string) $l['nome'],
            'titulo' => (string) $l['titulo'],
            'corpo' => (string) $l['corpo'],
            'tipo' => (string) $l['tipo'],
        ];
    }
}
