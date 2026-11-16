<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use App\Support\ConfigException;
use App\Support\Db;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class DbTest extends TestCase
{
    private function pdo(): PDO
    {
        // A senha vem do .env; o nome do banco é forçado para o de teste pelo phpunit.xml.
        return Db::connect(Config::fromFile(dirname(__DIR__, 2) . '/.env'));
    }

    public function testUsaOBancoDeTeste(): void
    {
        $this->assertSame('avisos_cond_test', $this->pdo()->query('select current_database()')->fetchColumn());
    }

    public function testOpcoesDeSegurancaDoPdo(): void
    {
        $pdo = $this->pdo();

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        $this->assertFalse((bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function testFusoHorarioDaConexaoEUtc(): void
    {
        $this->assertSame('UTC', $this->pdo()->query('show timezone')->fetchColumn());
    }

    public function testPreparedStatementComParametroNomeado(): void
    {
        $stmt = $this->pdo()->prepare('select :valor::text as v');
        $stmt->execute(['valor' => "x'; drop table y; --"]);

        $this->assertSame("x'; drop table y; --", $stmt->fetchColumn());
    }

    public function testSqlInvalidoLancaExcecao(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo()->query('select * from tabela_que_nao_existe');
    }

    public function testConfiguracaoObrigatoriaAusenteFalha(): void
    {
        $this->expectException(ConfigException::class);
        Db::connect(new Config(['DB_HOST' => '127.0.0.1']));
    }
}
