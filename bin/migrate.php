#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Repositories\MigrationRepository;
use App\Services\Migrator;
use App\Support\Config;
use App\Support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

$raiz = dirname(__DIR__);
$config = Config::fromFile($raiz . '/.env');

try {
    // Em produção o usuário da aplicação não tem permissão de DDL: use MIGRATE_DB_USER/MIGRATE_DB_PASS.
    $pdo = Db::connect($config, $config->get('MIGRATE_DB_USER') ?: null, $config->get('MIGRATE_DB_PASS'));
    $migrator = new Migrator($pdo, new MigrationRepository($pdo), $raiz . '/migrations');

    $aplicadas = $migrator->migrar();
} catch (Throwable $erro) {
    fwrite(STDERR, 'Erro nas migrations: ' . $erro->getMessage() . PHP_EOL);
    exit(1);
}

if ($aplicadas === []) {
    echo 'Nenhuma migration pendente.' . PHP_EOL;
    exit(0);
}

foreach ($aplicadas as $nome) {
    echo 'Aplicada: ' . $nome . PHP_EOL;
}
