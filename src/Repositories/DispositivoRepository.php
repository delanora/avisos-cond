<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class DispositivoRepository
{
    private const COLUNAS = 'd.id, d.condominio_id, d.nome, d.token, d.ativo,
        extract(epoch FROM d.ultimo_ping)::bigint AS ultimo_ping_epoch,
        extract(epoch FROM d.criado_em)::bigint AS criado_epoch,
        c.nome AS condominio_nome';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        $linhas = $this->pdo->query(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             ORDER BY lower(c.nome), lower(d.nome), d.id'
        )->fetchAll();

        return array_map($this->normalizar(...), $linhas);
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . ' FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id WHERE d.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $linha = $stmt->fetch();

        return $linha === false ? null : $this->normalizar($linha);
    }

    /**
     * Dispositivo e condomínio dele pelo token (já normalizado). Usado pela tela da TV.
     *
     * @return array{dispositivo: array<string, mixed>, condominio: array<string, mixed>}|null
     */
    public function porToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.id, d.condominio_id, d.nome, d.token, d.ativo,
                    c.nome AS condominio_nome, c.logo, c.cor_primaria, c.cor_secundaria, c.ativo AS condominio_ativo
             FROM dispositivos d JOIN condominios c ON c.id = d.condominio_id
             WHERE d.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $l = $stmt->fetch();
        if ($l === false) {
            return null;
        }

        return [
            'dispositivo' => ['id' => (int) $l['id'], 'condominio_id' => (int) $l['condominio_id'], 'nome' => (string) $l['nome'], 'token' => (string) $l['token'], 'ativo' => (bool) $l['ativo']],
            'condominio' => [
                'id' => (int) $l['condominio_id'],
                'nome' => (string) $l['condominio_nome'],
                'logo' => $l['logo'] === null ? null : (string) $l['logo'],
                'cor_primaria' => $l['cor_primaria'] === null ? null : (string) $l['cor_primaria'],
                'cor_secundaria' => $l['cor_secundaria'] === null ? null : (string) $l['cor_secundaria'],
                'ativo' => (bool) $l['condominio_ativo'],
            ],
        ];
    }

    /** Cria o dispositivo. Retorna null se o token já existir (o chamador sorteia outro). */
    public function criar(int $condominioId, string $nome, string $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dispositivos (condominio_id, nome, token) VALUES (:condominio, :nome, :token)
             ON CONFLICT (token) DO NOTHING RETURNING id'
        );
        $stmt->execute(['condominio' => $condominioId, 'nome' => $nome, 'token' => $token]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    public function atualizar(int $id, int $condominioId, string $nome, bool $ativo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispositivos SET condominio_id = :condominio, nome = :nome, ativo = :ativo WHERE id = :id'
        );
        $stmt->bindValue('condominio', $condominioId, PDO::PARAM_INT);
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Troca o token (o antigo deixa de funcionar). Retorna false se o novo já existir ou o dispositivo não existir. */
    public function trocarToken(int $id, string $novoToken): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE dispositivos SET token = :token, ultimo_ping = NULL
             WHERE id = :id AND NOT EXISTS (SELECT 1 FROM dispositivos WHERE token = :token)'
        );
        $stmt->execute(['token' => $novoToken, 'id' => $id]);

        return $stmt->rowCount() === 1;
    }

    /** Heartbeat: cada consulta da TV ao /state atualiza o último contato. */
    public function registrarPing(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE dispositivos SET ultimo_ping = now() WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function excluir(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM dispositivos WHERE id = :id');
        $stmt->execute(['id' => $id]);

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
            'condominio_id' => (int) $l['condominio_id'],
            'condominio_nome' => (string) $l['condominio_nome'],
            'nome' => (string) $l['nome'],
            'token' => (string) $l['token'],
            'ativo' => (bool) $l['ativo'],
            'ultimo_ping_epoch' => $l['ultimo_ping_epoch'] === null ? null : (int) $l['ultimo_ping_epoch'],
            'criado_epoch' => (int) $l['criado_epoch'],
        ];
    }
}
