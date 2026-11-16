<?php

declare(strict_types=1);

namespace App;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\GerenteController;
use App\Controllers\HomeController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\Negado;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UsuarioRepository;
use App\Services\AuthService;
use App\Services\Mailer;
use App\Services\PasswordHasher;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\Pipeline;
use App\Support\SecurityHeaders;
use App\Support\Session;
use App\Support\View;
use FastRoute\RouteCollector;
use PDO;

use function FastRoute\simpleDispatcher;

/**
 * Monta a aplicação (container mínimo, escrito à mão): objetos, dependências e rotas.
 * O public/index.php e os testes de integração usam esta mesma montagem.
 */
final class AppFactory
{
    public static function criar(
        Config $config,
        PDO $pdo,
        Session $sessao,
        Mailer $mailer,
        Logger $logger,
        string $diretorioTemplates,
        ?PasswordHasher $hasher = null,
    ): Application {
        $view = new View($diretorioTemplates, 'Avisos-Cond', $config->string('APP_ENV') === 'local');
        $csrf = new Csrf($sessao);
        $view->definirCsrf(static fn (): string => $csrf->token());

        $usuarios = new UsuarioRepository($pdo);
        $hasher ??= new PasswordHasher();
        $limitador = new RateLimiter(new RateLimitRepository($pdo));

        $auth = new AuthService($usuarios, $hasher, $limitador);
        $recuperacao = new PasswordResetService(
            $usuarios,
            new PasswordResetRepository($pdo),
            $limitador,
            $hasher,
            $mailer,
            $logger,
            $config->obrigatorio('APP_URL'),
        );

        $negado = new Negado($view);
        $mwCsrf = new CsrfMiddleware($csrf, $negado);
        $mwAuth = new AuthMiddleware($sessao, $usuarios);
        $mwAdmin = new RoleMiddleware(['superadmin'], $negado);
        $mwGerente = new RoleMiddleware(['gerente'], $negado);
        // Limite por IP nas rotas sensíveis: máx. de requisições por janela e tempo de bloqueio (segundos).
        $mwLimiteLogin = new RateLimitMiddleware($limitador, $negado, 'login', 30, 900, 900);
        $mwLimiteRecuperacao = new RateLimitMiddleware($limitador, $negado, 'recuperacao', 20, 3600, 3600);

        $authController = new AuthController($view, $sessao, $auth, $recuperacao);
        $home = new HomeController();
        $admin = new AdminController($view);
        $gerente = new GerenteController($view);

        // Ordem dos middlewares: o primeiro executa primeiro (limite → CSRF → login → papel).
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use (
            $authController, $home, $admin, $gerente,
            $mwCsrf, $mwAuth, $mwAdmin, $mwGerente, $mwLimiteLogin, $mwLimiteRecuperacao
        ): void {
            $token = '{token:[a-f0-9]{64}}';

            $r->addRoute('GET', '/login', Pipeline::envolver([$authController, 'formLogin'], $mwCsrf));
            $r->addRoute('POST', '/login', Pipeline::envolver([$authController, 'login'], $mwLimiteLogin, $mwCsrf));
            $r->addRoute('POST', '/logout', Pipeline::envolver([$authController, 'logout'], $mwCsrf));

            $r->addRoute('GET', '/esqueci-senha', Pipeline::envolver([$authController, 'formEsqueci'], $mwCsrf));
            $r->addRoute('POST', '/esqueci-senha', Pipeline::envolver([$authController, 'esqueci'], $mwLimiteRecuperacao, $mwCsrf));
            $r->addRoute('GET', "/redefinir-senha/{$token}", Pipeline::envolver([$authController, 'formRedefinir'], $mwCsrf));
            $r->addRoute('POST', "/redefinir-senha/{$token}", Pipeline::envolver([$authController, 'redefinir'], $mwLimiteRecuperacao, $mwCsrf));

            $r->addRoute('GET', '/', Pipeline::envolver([$home, 'index'], $mwAuth));
            $r->addRoute('GET', '/admin', Pipeline::envolver([$admin, 'painel'], $mwAuth, $mwAdmin));
            $r->addRoute('GET', '/gerente', Pipeline::envolver([$gerente, 'painel'], $mwAuth, $mwGerente));
        });

        return new Application($dispatcher, $view, $logger, new SecurityHeaders());
    }
}
