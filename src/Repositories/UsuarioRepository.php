<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class UsuarioRepository
{
    private const COLUNAS = 'id, nome, email, senha_hash, papel, ativo,
        extract(epoch FROM senha_alterada_em)::bigint AS senha_alterada_epoch';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed>|null */
    public function porEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM usuarios WHERE lower(email) = lower(:email)');
        $stmt->execute(['email' => $email]);

        return $this->normalizar($stmt->fetch());
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUNAS . ' FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $this->normalizar($stmt->fetch());
    }

    public function criar(string $nome, string $email, string $senhaHash, string $papel): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (:nome, :email, :senha, :papel) RETURNING id'
        );
        $stmt->execute(['nome' => $nome, 'email' => $email, 'senha' => $senhaHash, 'papel' => $papel]);

        return (int) $stmt->fetchColumn();
    }

    /** Troca a senha e registra o momento, o que invalida as sessões abertas antes dele. */
    public function atualizarSenha(int $id, string $senhaHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET senha_hash = :senha, senha_alterada_em = now() WHERE id = :id'
        );
        $stmt->execute(['senha' => $senhaHash, 'id' => $id]);
    }

    /** Atualiza só o hash (rehash transparente no login), sem invalidar sessões. */
    public function atualizarHash(int $id, string $senhaHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE usuarios SET senha_hash = :senha WHERE id = :id');
        $stmt->execute(['senha' => $senhaHash, 'id' => $id]);
    }

    /**
     * @param array<string, mixed>|false $linha
     * @return array<string, mixed>|null
     */
    private function normalizar(array|false $linha): ?array
    {
        if ($linha === false) {
            return null;
        }

        return [
            'id' => (int) $linha['id'],
            'nome' => (string) $linha['nome'],
            'email' => (string) $linha['email'],
            'senha_hash' => (string) $linha['senha_hash'],
            'papel' => (string) $linha['papel'],
            'ativo' => (bool) $linha['ativo'],
            'senha_alterada_epoch' => $linha['senha_alterada_epoch'] === null ? null : (int) $linha['senha_alterada_epoch'],
        ];
    }
}
