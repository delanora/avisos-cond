<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Config;
use App\Support\Db;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base dos testes que usam o PostgreSQL. Antes de cada teste o schema "public"
 * do banco de TESTE é recriado do zero, então nenhum teste depende de outro.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        $config = Config::fromFile(dirname(__DIR__) . '/.env');
        $this->pdo = Db::connect($config);

        // Trava de segurança: nunca apagar um banco que não seja de teste.
        $banco = (string) $this->pdo->query('SELECT current_database()')->fetchColumn();
        if (!str_ends_with($banco, '_test')) {
            throw new RuntimeException("Os testes só podem rodar em banco terminado em _test (atual: {$banco}).");
        }

        $this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }

    /** Aplica todas as migrations reais do projeto no banco de teste. */
    protected function migrarTudo(): void
    {
        $migrator = new \App\Services\Migrator(
            $this->pdo,
            new \App\Repositories\MigrationRepository($this->pdo),
            dirname(__DIR__) . '/migrations'
        );
        $migrator->migrar();
    }
}
