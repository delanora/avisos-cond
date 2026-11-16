<?php

declare(strict_types=1);

namespace Tests;

use App\Repositories\MigrationRepository;
use App\Services\Migrator;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Valida as migrations reais: aplicam do zero e sobre o estado anterior,
 * e as constraints do banco barram dados inválidos.
 */
final class SchemaTest extends DatabaseTestCase
{
    private const TABELAS = [
        'usuarios', 'condominios', 'usuario_condominio', 'avisos', 'dispositivos',
        'password_resets', 'rate_limits', 'schema_migrations',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarTudo();
    }

    private function usuario(string $email = 'gerente@exemplo.com.br', string $papel = 'gerente'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (:n, :e, :s, :p) RETURNING id'
        );
        $stmt->execute(['n' => 'Teste', 'e' => $email, 's' => 'hash', 'p' => $papel]);

        return (int) $stmt->fetchColumn();
    }

    private function condominio(string $nome = 'Condomínio A'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO condominios (nome) VALUES (:n) RETURNING id');
        $stmt->execute(['n' => $nome]);

        return (int) $stmt->fetchColumn();
    }

    /** @param array<string, mixed> $extra */
    private function aviso(int $condominioId, array $extra = []): int
    {
        $dados = array_merge([
            'c' => $condominioId, 't' => 'Aviso', 'tipo' => 'comum',
            'midia' => null, 'midia_tipo' => null,
            'ini' => '2026-01-01 00:00:00+00', 'fim' => '2026-01-02 00:00:00+00',
        ], $extra);
        $stmt = $this->pdo->prepare(
            'INSERT INTO avisos (condominio_id, titulo, tipo, midia, midia_tipo, inicio_em, fim_em)
             VALUES (:c, :t, :tipo, :midia, :midia_tipo, :ini, :fim) RETURNING id'
        );
        $stmt->execute($dados);

        return (int) $stmt->fetchColumn();
    }

    private function dispositivo(int $condominioId, string $token = 'ABCDEFGH2345'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO dispositivos (condominio_id, nome, token) VALUES (:c, :n, :t) RETURNING id'
        );
        $stmt->execute(['c' => $condominioId, 'n' => 'Elevador', 't' => $token]);

        return (int) $stmt->fetchColumn();
    }

    private function contar(string $tabela): int
    {
        // $tabela vem sempre de constantes deste teste, nunca de entrada externa.
        return (int) $this->pdo->query("SELECT count(*) FROM {$tabela}")->fetchColumn();
    }

    public function testTodasAsTabelasForamCriadas(): void
    {
        $existentes = $this->pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (self::TABELAS as $tabela) {
            $this->assertContains($tabela, $existentes, "Tabela ausente: {$tabela}");
        }
    }

    public function testMigrationsAplicamSobreOEstadoAnterior(): void
    {
        // Volta ao zero e aplica só a 001; depois aplica o restante.
        $this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
        $dir = sys_get_temp_dir() . '/mig_parcial_' . bin2hex(random_bytes(4));
        mkdir($dir);
        copy(dirname(__DIR__) . '/migrations/001_init.sql', $dir . '/001_init.sql');

        try {
            $parcial = new Migrator($this->pdo, new MigrationRepository($this->pdo), $dir);
            $this->assertSame(['001_init.sql'], $parcial->migrar());

            $this->migrarTudo();

            $versoes = $this->pdo->query('SELECT versao FROM schema_migrations ORDER BY versao')->fetchAll(PDO::FETCH_COLUMN);
            $esperadas = array_map('basename', glob(dirname(__DIR__) . '/migrations/*.sql'));
            sort($esperadas);
            $this->assertSame($esperadas, $versoes);
        } finally {
            unlink($dir . '/001_init.sql');
            rmdir($dir);
        }
    }

    public function testChavesPrimariasSaoIdentityGeradaSempre(): void
    {
        $colunas = $this->pdo->query(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'id' AND identity_generation = 'ALWAYS'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach (['usuarios', 'condominios', 'avisos', 'dispositivos', 'password_resets', 'rate_limits'] as $tabela) {
            $this->assertContains($tabela, $colunas, "{$tabela}.id deve ser GENERATED ALWAYS");
        }

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO condominios (id, nome) VALUES (999, 'x')");
    }

    public function testPapelInvalidoEhRejeitado(): void
    {
        $this->expectException(PDOException::class);
        $this->usuario('x@exemplo.com.br', 'zelador');
    }

    public function testEmailUnicoSemDistinguirMaiusculas(): void
    {
        $this->usuario('Maria@Exemplo.com.br');

        $this->expectException(PDOException::class);
        $this->usuario('maria@exemplo.com.br');
    }

    public function testCorInvalidaEhRejeitada(): void
    {
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO condominios (nome, cor_primaria) VALUES ('x', 'vermelho')");
    }

    public function testCorValidaEPadraoNuloSaoAceitos(): void
    {
        $this->pdo->exec("INSERT INTO condominios (nome, cor_primaria, cor_secundaria) VALUES ('x', '#1A2b3C', NULL)");

        $this->assertSame(1, $this->contar('condominios'));
    }

    public function testAvisoComFimAntesDoInicioEhRejeitado(): void
    {
        $this->expectException(PDOException::class);
        $this->aviso($this->condominio(), ['ini' => '2026-01-02 00:00:00+00', 'fim' => '2026-01-01 00:00:00+00']);
    }

    public function testAvisoTipoInvalidoEhRejeitado(): void
    {
        $this->expectException(PDOException::class);
        $this->aviso($this->condominio(), ['tipo' => 'outro']);
    }

    /** @param array<string, mixed> $extra */
    #[DataProvider('midiasInvalidas')]
    public function testMidiaIncoerenteEhRejeitada(array $extra): void
    {
        $this->expectException(PDOException::class);
        $this->aviso($this->condominio(), $extra);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function midiasInvalidas(): array
    {
        return [
            'arquivo sem tipo' => [['midia' => 'abc.jpg', 'midia_tipo' => null]],
            'tipo sem arquivo' => [['midia' => null, 'midia_tipo' => 'imagem']],
            'tipo desconhecido' => [['midia' => 'abc.pdf', 'midia_tipo' => 'pdf']],
        ];
    }

    public function testAvisoComMidiaValidaEhAceito(): void
    {
        $this->aviso($this->condominio(), ['midia' => 'a1b2.mp4', 'midia_tipo' => 'video']);

        $this->assertSame(1, $this->contar('avisos'));
    }

    public function testAvisoDeCondominioInexistenteEhRejeitado(): void
    {
        $this->expectException(PDOException::class);
        $this->aviso(987654);
    }

    public function testTokenDeDispositivoEUnicoETemDozeCaracteres(): void
    {
        $c = $this->condominio();
        $this->dispositivo($c, 'ABCDEFGH2345');

        try {
            $this->dispositivo($c, 'ABCDEFGH2345');
            $this->fail('Token duplicado deveria ser rejeitado.');
        } catch (PDOException) {
            $this->assertSame(1, $this->contar('dispositivos'));
        }

        $this->expectException(PDOException::class);
        $this->dispositivo($c, 'CURTO');
    }

    public function testVinculoNaoPodeSerDuplicado(): void
    {
        $u = $this->usuario();
        $c = $this->condominio();
        $this->pdo->exec("INSERT INTO usuario_condominio (usuario_id, condominio_id) VALUES ({$u}, {$c})");

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO usuario_condominio (usuario_id, condominio_id) VALUES ({$u}, {$c})");
    }

    public function testExcluirCondominioRemoveAvisosDispositivosEVinculos(): void
    {
        $u = $this->usuario();
        $a = $this->condominio('A');
        $b = $this->condominio('B');
        $this->pdo->exec("INSERT INTO usuario_condominio (usuario_id, condominio_id) VALUES ({$u}, {$a}), ({$u}, {$b})");
        $this->aviso($a);
        $this->aviso($b);
        $this->dispositivo($a, 'AAAAAAAAAAAA');
        $this->dispositivo($b, 'BBBBBBBBBBBB');

        $this->pdo->exec("DELETE FROM condominios WHERE id = {$a}");

        // Só o condomínio B e seus dados continuam.
        $this->assertSame(1, $this->contar('avisos'));
        $this->assertSame(1, $this->contar('dispositivos'));
        $this->assertSame(1, $this->contar('usuario_condominio'));
        $this->assertSame(1, $this->contar('usuarios'));
    }

    public function testExcluirUsuarioMantemOAvisoEZeraCriadoPor(): void
    {
        $u = $this->usuario();
        $c = $this->condominio();
        $avisoId = $this->aviso($c);
        $this->pdo->exec("UPDATE avisos SET criado_por = {$u} WHERE id = {$avisoId}");

        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$u}");

        $this->assertSame(1, $this->contar('avisos'));
        $this->assertNull($this->pdo->query('SELECT criado_por FROM avisos')->fetchColumn());
    }

    public function testPasswordResetsTokenUnicoERemovidoComOUsuario(): void
    {
        $u = $this->usuario();
        $hash = str_repeat('a', 64);
        $sql = "INSERT INTO password_resets (usuario_id, token_hash, expira_em) VALUES ({$u}, '{$hash}', now() + interval '30 minutes')";
        $this->pdo->exec($sql);

        try {
            $this->pdo->exec($sql);
            $this->fail('token_hash duplicado deveria ser rejeitado.');
        } catch (PDOException) {
            $this->assertSame(1, $this->contar('password_resets'));
        }

        $this->pdo->exec("DELETE FROM usuarios WHERE id = {$u}");
        $this->assertSame(0, $this->contar('password_resets'));
    }

    public function testRateLimitsChaveUnicaETentativasNaoNegativas(): void
    {
        $this->pdo->exec("INSERT INTO rate_limits (chave, tentativas) VALUES ('login:ip:203.0.113.7', 1)");

        try {
            $this->pdo->exec("INSERT INTO rate_limits (chave) VALUES ('login:ip:203.0.113.7')");
            $this->fail('chave duplicada deveria ser rejeitada.');
        } catch (PDOException) {
            $this->assertSame(1, $this->contar('rate_limits'));
        }

        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO rate_limits (chave, tentativas) VALUES ('outra', -1)");
    }

    public function testIndicesDeFiltroExistem(): void
    {
        $indices = $this->pdo->query("SELECT indexname FROM pg_indexes WHERE schemaname = 'public'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach ([
            'avisos_condominio_periodo_idx', 'dispositivos_token_unico', 'dispositivos_condominio_idx',
            'usuario_condominio_condominio_idx', 'usuarios_email_unico',
        ] as $indice) {
            $this->assertContains($indice, $indices, "Índice ausente: {$indice}");
        }
    }
}
