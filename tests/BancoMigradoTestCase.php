<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\UsuarioRepository;
use App\Services\PasswordHasher;
use App\Services\UsuarioService;

/** Banco de teste já com todas as migrations aplicadas e atalhos para criar usuários. */
abstract class BancoMigradoTestCase extends DatabaseTestCase
{
    public const SENHA = 'senha-segura-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarTudo();
    }

    /** Parâmetros mínimos do Argon2id: deixa os testes rápidos (em produção usa o padrão forte). */
    protected function hasherRapido(): PasswordHasher
    {
        return new PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
    }

    protected function criarUsuario(
        string $email = 'gerente@exemplo.com.br',
        string $papel = 'gerente',
        string $senha = self::SENHA,
        bool $ativo = true,
    ): int {
        $servico = new UsuarioService(new UsuarioRepository($this->pdo), $this->hasherRapido());
        $id = $servico->criar('Usuário Teste', $email, $senha, $papel);

        if (!$ativo) {
            $this->pdo->exec("UPDATE usuarios SET ativo = false WHERE id = {$id}");
        }

        return $id;
    }
}
