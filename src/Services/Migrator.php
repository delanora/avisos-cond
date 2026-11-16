<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MigrationRepository;
use PDO;
use Throwable;

/**
 * Aplica as migrations pendentes de um diretório (arquivos NNN_descricao.sql),
 * em ordem, cada uma dentro de uma transação.
 *
 * Migrations já aplicadas são imutáveis: se o conteúdo mudou (checksum diferente),
 * a execução é interrompida.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MigrationRepository $repositorio,
        private readonly string $diretorio,
    ) {
    }

    /** @return list<string> arquivos aplicados nesta execução */
    public function migrar(): array
    {
        $arquivos = $this->listarArquivos();

        $this->repositorio->travar();
        try {
            $this->repositorio->garantirTabela();
            $aplicadas = $this->repositorio->aplicadas();

            $novas = [];
            foreach ($arquivos as $nome => $caminho) {
                $conteudo = (string) file_get_contents($caminho);
                $checksum = hash('sha256', $conteudo);

                if (isset($aplicadas[$nome])) {
                    if ($aplicadas[$nome] !== $checksum) {
                        throw new MigrationException(
                            "A migration {$nome} já foi aplicada e foi alterada. Crie uma nova migration em vez de editá-la."
                        );
                    }
                    continue;
                }

                $this->aplicar($nome, $conteudo, $checksum);
                $novas[] = $nome;
            }

            return $novas;
        } finally {
            $this->repositorio->destravar();
        }
    }

    /** @return array<string, string> nome => caminho, em ordem */
    private function listarArquivos(): array
    {
        $caminhos = glob(rtrim($this->diretorio, '/') . '/*.sql');
        if ($caminhos === false) {
            throw new MigrationException('Não foi possível ler o diretório de migrations.');
        }
        sort($caminhos);

        $arquivos = [];
        $numeros = [];
        foreach ($caminhos as $caminho) {
            $nome = basename($caminho);
            if (preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $nome, $m) !== 1) {
                throw new MigrationException("Nome de migration inválido: {$nome} (use NNN_descricao.sql).");
            }
            if (isset($numeros[$m[1]])) {
                throw new MigrationException("Número de migration duplicado: {$m[1]}.");
            }
            $numeros[$m[1]] = true;
            $arquivos[$nome] = $caminho;
        }

        return $arquivos;
    }

    private function aplicar(string $nome, string $conteudo, string $checksum): void
    {
        $this->pdo->beginTransaction();
        try {
            // No PostgreSQL o DDL é transacional: se algo falhar, nada da migration fica aplicado.
            $this->pdo->exec($conteudo);
            $this->repositorio->registrar($nome, $checksum);
            $this->pdo->commit();
        } catch (Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new MigrationException("Falha ao aplicar a migration {$nome}: " . $erro->getMessage(), 0, $erro);
        }
    }
}
