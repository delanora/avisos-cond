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
     * Todos os usuários, com a quantidade de condomínios vinculados (sem o hash da senha).
     *
     * @return list<array<string, mixed>>
     */
    public function listar(): array
    {
        $linhas = $this->pdo->query(
            'SELECT u.id, u.nome, u.email, u.senha_hash, u.papel, u.ativo,
                    extract(epoch FROM u.senha_alterada_em)::bigint AS senha_alterada_epoch,
                    (SELECT count(*) FROM usuario_condominio uc WHERE uc.usuario_id = u.id) AS total_condominios
             FROM usuarios u
             ORDER BY u.papel, lower(u.nome), u.id'
        )->fetchAll();

        return array_map(function (array $linha): array {
            $usuario = $this->normalizar($linha) ?? [];
            $usuario['total_condominios'] = (int) $linha['total_condominios'];
            unset($usuario['senha_hash']);

            return $usuario;
        }, $linhas);
    }

    /** Dos IDs informados, devolve só os que existem e são gerentes. @param list<int> $ids @return list<int> */
    public function idsDeGerentes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT id FROM usuarios WHERE papel = 'gerente' AND id = ANY(:ids::bigint[]) ORDER BY id");
        $stmt->execute(['ids' => Pg::arrayDeInteiros($ids)]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<array{id: int, nome: string, email: string, ativo: bool}> */
    public function listarGerentes(): array
    {
        $linhas = $this->pdo->query(
            "SELECT id, nome, email, ativo FROM usuarios WHERE papel = 'gerente' ORDER BY lower(nome), id"
        )->fetchAll();

        return array_map(static fn (array $l): array => [
            'id' => (int) $l['id'],
            'nome' => (string) $l['nome'],
            'email' => (string) $l['email'],
            'ativo' => (bool) $l['ativo'],
        ], $linhas);
    }

    public function atualizar(int $id, string $nome, string $email, string $papel, bool $ativo): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE usuarios SET nome = :nome, email = :email, papel = :papel, ativo = :ativo WHERE id = :id'
        );
        $stmt->bindValue('nome', $nome);
        $stmt->bindValue('email', $email);
        $stmt->bindValue('papel', $papel);
        $stmt->bindValue('ativo', $ativo, PDO::PARAM_BOOL);
        $stmt->bindValue('id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    public function excluir(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() === 1;
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
