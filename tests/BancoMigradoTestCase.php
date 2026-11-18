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

    /** Id da matriz de fábrica (tipo "do sistema", sem dono) com esta chave: serve para avisos montados direto no banco. */
    protected function tipoDeFabrica(string $chave = 'comum'): int
    {
        return (int) $this->pdo->query('SELECT id FROM tipos_aviso WHERE sistema AND chave = ' . $this->pdo->quote($chave))->fetchColumn();
    }

    /** Entrega ao gerente a cópia dele dos tipos e modelos de fábrica (como o painel faz no primeiro acesso). */
    protected function padroesDoGerente(int $usuarioId): void
    {
        (new \App\Repositories\PadroesDoGerenteRepository($this->pdo))->garantir($usuarioId);
    }

    /** Id do tipo de fábrica (cópia do gerente) com esta chave; cria as cópias se ainda não existirem. */
    protected function tipoDoGerente(int $usuarioId, string $chave = 'comum'): int
    {
        $this->padroesDoGerente($usuarioId);

        return (int) $this->pdo->query("SELECT id FROM tipos_aviso WHERE usuario_id = {$usuarioId} AND chave = " . $this->pdo->quote($chave))->fetchColumn();
    }

    /** Vincula o gerente ao condomínio (necessário para ele poder usar tipos próprios nos avisos de lá). */
    protected function vincular(int $usuarioId, int $condominioId): void
    {
        $this->pdo->exec("INSERT INTO usuario_condominio (usuario_id, condominio_id) VALUES ({$usuarioId}, {$condominioId}) ON CONFLICT DO NOTHING");
    }
}
