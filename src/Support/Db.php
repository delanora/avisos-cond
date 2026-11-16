<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

final class Db
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $config->obrigatorio('DB_HOST'),
            $config->int('DB_PORT', 5432),
            $config->obrigatorio('DB_NAME')
        );

        $pdo = new PDO($dsn, $config->obrigatorio('DB_USER'), $config->string('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // Prepared statements reais no servidor: a entrada nunca é concatenada em SQL.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // timestamptz é guardado/lido em UTC; a conversão para America/Sao_Paulo é só na exibição.
        $pdo->exec("SET TIME ZONE 'UTC'");

        return $pdo;
    }
}
