<?php

declare(strict_types=1);

use App\AppFactory;
use App\Services\MailerNaoConfigurado;
use App\Services\SmtpMailer;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

$raiz = dirname(__DIR__);

// Servidor embutido do PHP (desenvolvimento): arquivos estáticos de /public são servidos direto.
if (PHP_SAPI === 'cli-server') {
    $caminho = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (is_string($caminho) && $caminho !== '/' && is_file(__DIR__ . $caminho)) {
        return false;
    }
}

require $raiz . '/vendor/autoload.php';

// Erros nunca aparecem para o usuário: vão para o log (AGENTS.md seção 5).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $raiz . '/storage/logs/php.log');
error_reporting(E_ALL);
date_default_timezone_set('UTC');
header_remove('X-Powered-By'); // não expõe a versão do PHP

// Avisos e notices viram exceções, para cair no tratamento de erro da Application.
set_error_handler(static function (int $nivel, string $mensagem, string $arquivo, int $linha): bool {
    if ((error_reporting() & $nivel) === 0) {
        return false;
    }
    throw new ErrorException($mensagem, 0, $nivel, $arquivo, $linha);
});

$logger = new Logger($raiz . '/storage/logs/app.log');

try {
    $config = Config::fromFile($raiz . '/.env');

    $mailer = $config->string('MAIL_HOST') === ''
        ? new MailerNaoConfigurado()
        : new SmtpMailer(
            $config->string('MAIL_HOST'),
            $config->int('MAIL_PORT', 587),
            $config->string('MAIL_USER'),
            $config->string('MAIL_PASS'),
            // Remetente: MAIL_FROM, ou o próprio usuário SMTP (o Google só aceita o endereço da conta ou um apelido dela).
            $config->string('MAIL_FROM') !== '' ? $config->string('MAIL_FROM') : $config->string('MAIL_USER'),
            $config->string('MAIL_FROM_NAME', 'Avisos-Cond'),
        );

    $app = AppFactory::criar(
        $config,
        Db::connect($config),
        Session::fromConfig($config),
        $mailer,
        $logger,
        $raiz . '/templates',
    );
    $resposta = $app->handle(Request::fromGlobals());
} catch (Throwable $erro) {
    // Falha antes de a Application existir (ex.: banco fora do ar): mensagem genérica, detalhes no log.
    $logger->error('Falha ao iniciar a aplicação', $erro);
    $resposta = new Response(500, 'Serviço indisponível. Tente novamente mais tarde.', [
        'Content-Type' => 'text/plain; charset=utf-8',
    ]);
}

$resposta->send();
