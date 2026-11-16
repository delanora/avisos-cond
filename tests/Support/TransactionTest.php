<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Transaction;
use RuntimeException;
use Tests\DatabaseTestCase;

final class TransactionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('CREATE TABLE t (id int)');
    }

    private function total(): int
    {
        return (int) $this->pdo->query('SELECT count(*) FROM t')->fetchColumn();
    }

    public function testConfirmaEDevolveOResultado(): void
    {
        $resultado = (new Transaction($this->pdo))->executar(function (): string {
            $this->pdo->exec('INSERT INTO t VALUES (1)');

            return 'ok';
        });

        $this->assertSame('ok', $resultado);
        $this->assertSame(1, $this->total());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testDesfazTudoEPropagaAExcecao(): void
    {
        try {
            (new Transaction($this->pdo))->executar(function (): void {
                $this->pdo->exec('INSERT INTO t VALUES (1)');
                throw new RuntimeException('falhou no meio');
            });
            $this->fail('A exceção deveria ser propagada.');
        } catch (RuntimeException $e) {
            $this->assertSame('falhou no meio', $e->getMessage());
        }

        $this->assertSame(0, $this->total());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testErroDeSqlTambemDesfaz(): void
    {
        try {
            (new Transaction($this->pdo))->executar(function (): void {
                $this->pdo->exec('INSERT INTO t VALUES (1)');
                $this->pdo->exec('INSERT INTO tabela_inexistente VALUES (1)');
            });
            $this->fail('Era esperada uma exceção do PDO.');
        } catch (\PDOException) {
            $this->assertSame(0, $this->total());
        }
    }
}
