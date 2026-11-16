<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\MigrationRepository;
use App\Services\MigrationException;
use App\Services\Migrator;
use Tests\DatabaseTestCase;

final class MigratorTest extends DatabaseTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/migrations_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->pdo, new MigrationRepository($this->pdo), $this->dir);
    }

    private function escrever(string $nome, string $sql): void
    {
        file_put_contents($this->dir . '/' . $nome, $sql);
    }

    private function tabelaExiste(string $nome): bool
    {
        $stmt = $this->pdo->prepare('SELECT to_regclass(:nome) IS NOT NULL');
        $stmt->execute(['nome' => 'public.' . $nome]);

        return (bool) $stmt->fetchColumn();
    }

    public function testAplicaEmOrdemERegistraAsVersoes(): void
    {
        // Escritas fora de ordem de propósito: a ordem vem do número do arquivo.
        $this->escrever('002_segunda.sql', 'ALTER TABLE a ADD COLUMN b int;');
        $this->escrever('001_primeira.sql', 'CREATE TABLE a (id int);');

        $aplicadas = $this->migrator()->migrar();

        $this->assertSame(['001_primeira.sql', '002_segunda.sql'], $aplicadas);
        $versoes = $this->pdo->query('SELECT versao FROM schema_migrations ORDER BY versao')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['001_primeira.sql', '002_segunda.sql'], $versoes);
    }

    public function testSegundaExecucaoNaoAplicaNada(): void
    {
        $this->escrever('001_primeira.sql', 'CREATE TABLE a (id int);');
        $this->migrator()->migrar();

        $this->assertSame([], $this->migrator()->migrar());
    }

    public function testAplicaApenasAsNovasSobreOEstadoAnterior(): void
    {
        $this->escrever('001_primeira.sql', 'CREATE TABLE a (id int);');
        $this->migrator()->migrar();

        $this->escrever('002_segunda.sql', 'CREATE TABLE b (id int);');

        $this->assertSame(['002_segunda.sql'], $this->migrator()->migrar());
        $this->assertTrue($this->tabelaExiste('b'));
    }

    public function testFalhaFazRollbackDaMigrationEInterrompeAsSeguintes(): void
    {
        $this->escrever('001_ok.sql', 'CREATE TABLE a (id int);');
        // A primeira instrução é válida, a segunda falha: nada desta migration pode ficar aplicado.
        $this->escrever('002_quebrada.sql', 'CREATE TABLE b (id int); CREATE TABLE b (id int);');
        $this->escrever('003_nao_roda.sql', 'CREATE TABLE c (id int);');

        try {
            $this->migrator()->migrar();
            $this->fail('Era esperada uma MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('002_quebrada.sql', $e->getMessage());
        }

        $this->assertTrue($this->tabelaExiste('a'));
        $this->assertFalse($this->tabelaExiste('b'));
        $this->assertFalse($this->tabelaExiste('c'));
        $versoes = $this->pdo->query('SELECT versao FROM schema_migrations')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['001_ok.sql'], $versoes);
    }

    public function testMigrationAplicadaEAlteradaEBloqueada(): void
    {
        $this->escrever('001_primeira.sql', 'CREATE TABLE a (id int);');
        $this->migrator()->migrar();

        $this->escrever('001_primeira.sql', 'CREATE TABLE a (id int, extra int);');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('já foi aplicada e foi alterada');
        $this->migrator()->migrar();
    }

    public function testNomeInvalidoEhRejeitado(): void
    {
        $this->escrever('1_sem_tres_digitos.sql', 'SELECT 1;');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('Nome de migration inválido');
        $this->migrator()->migrar();
    }

    public function testNumeroDuplicadoEhRejeitado(): void
    {
        $this->escrever('001_a.sql', 'SELECT 1;');
        $this->escrever('001_b.sql', 'SELECT 1;');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessage('duplicado');
        $this->migrator()->migrar();
    }

    public function testDiretorioSemMigrationsNaoFazNada(): void
    {
        $this->assertSame([], $this->migrator()->migrar());
    }
}
