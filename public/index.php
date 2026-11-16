<?php

declare(strict_types=1);

use App\Application;
use App\Controllers\HomeController;
use App\Support\Config;
use App\Support\Logger;
use App\Support\Request;
use App\Support\SecurityHeaders;
use App\Support\View;
use FastRoute\RouteCollector;

use function FastRoute\simpleDispatcher;

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

$config = Config::fromFile($raiz . '/.env');
$view = new View($raiz . '/templates', 'Avisos-Cond', $config->string('APP_ENV') === 'local');
$logger = new Logger($raiz . '/storage/logs/app.log');

$home = new HomeController($view);

$dispatcher = simpleDispatcher(static function (RouteCollector $r) use ($home): void {
    $r->addRoute('GET', '/', [$home, 'index']);
});

$app = new Application($dispatcher, $view, $logger, new SecurityHeaders());
$app->handle(Request::fromGlobals())->send();
