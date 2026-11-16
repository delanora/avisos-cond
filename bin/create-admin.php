#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Repositories\UsuarioRepository;
use App\Services\PasswordHasher;
use App\Services\UsuarioService;
use App\Services\ValidacaoException;
use App\Support\Config;
use App\Support\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Lê uma linha do terminal; com $oculto, não mostra o que é digitado (senha). */
function perguntar(string $texto, bool $oculto = false): string
{
    echo $texto;
    $interativo = $oculto && function_exists('posix_isatty') && posix_isatty(STDIN);

    if ($interativo) {
        shell_exec('stty -echo');
    }
    $linha = fgets(STDIN);
    if ($interativo) {
        shell_exec('stty echo');
        echo PHP_EOL;
    }

    return $linha === false ? '' : rtrim($linha, "\r\n");
}

try {
    $config = Config::fromFile(dirname(__DIR__) . '/.env');
    $pdo = Db::connect($config);
    $servico = new UsuarioService(new UsuarioRepository($pdo), new PasswordHasher());

    echo 'Criação de superadmin (Embracore)' . PHP_EOL;
    $nome = perguntar('Nome: ');
    $email = perguntar('E-mail: ');
    $senha = perguntar('Senha (mínimo ' . UsuarioService::SENHA_MIN . ' caracteres): ', true);
    $confirmacao = perguntar('Confirme a senha: ', true);

    if ($senha !== $confirmacao) {
        fwrite(STDERR, 'As senhas não coincidem.' . PHP_EOL);
        exit(1);
    }

    $id = $servico->criar($nome, $email, $senha, 'superadmin');
    echo "Superadmin criado (id {$id})." . PHP_EOL;
} catch (ValidacaoException $e) {
    foreach ($e->erros as $campo => $mensagem) {
        fwrite(STDERR, "{$campo}: {$mensagem}" . PHP_EOL);
    }
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
