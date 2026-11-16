<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use Throwable;

/** Executa um bloco dentro de uma transação: confirma se terminar bem, desfaz se lançar exceção. */
final class Transaction
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $bloco
     * @return T
     */
    public function executar(callable $bloco): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $resultado = $bloco();
            $this->pdo->commit();

            return $resultado;
        } catch (Throwable $erro) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $erro;
        }
    }
}
