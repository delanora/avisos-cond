<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Controle das migrations aplicadas (tabela schema_migrations).
 * É o único SQL do migrador; o conteúdo das migrations vive em /migrations.
 */
final class MigrationRepository
{
    // Número fixo, só para identificar o lock consultivo das migrations.
    private const LOCK_ID = 7_351_001;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function garantirTabela(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                versao       varchar(255) PRIMARY KEY,
                checksum     char(64)     NOT NULL,
                aplicada_em  timestamptz  NOT NULL DEFAULT now()
            )'
        );
    }

    /** @return array<string, string> versao => checksum */
    public function aplicadas(): array
    {
        $linhas = $this->pdo->query('SELECT versao, checksum FROM schema_migrations ORDER BY versao')->fetchAll();

        $resultado = [];
        foreach ($linhas as $linha) {
            $resultado[(string) $linha['versao']] = (string) $linha['checksum'];
        }

        return $resultado;
    }

    public function registrar(string $versao, string $checksum): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (versao, checksum) VALUES (:versao, :checksum)');
        $stmt->execute(['versao' => $versao, 'checksum' => $checksum]);
    }

    /** Impede duas execuções simultâneas de migrate. Bloqueia até obter o lock. */
    public function travar(): void
    {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_lock(:id)');
        $stmt->execute(['id' => self::LOCK_ID]);
    }

    public function destravar(): void
    {
        $stmt = $this->pdo->prepare('SELECT pg_advisory_unlock(:id)');
        $stmt->execute(['id' => self::LOCK_ID]);
    }
}
