<?php

declare(strict_types=1);

namespace App;

use App\Controllers\Admin\CondominiosController;
use App\Controllers\Admin\UsuariosController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\GerenteController;
use App\Controllers\HomeController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\Negado;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use App\Repositories\CondominioRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Services\AparenciaService;
use App\Services\AuthService;
use App\Services\GestaoCondominiosService;
use App\Services\GestaoUsuariosService;
use App\Services\Mailer;
use App\Services\PasswordHasher;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use App\Services\UsuarioService;
use App\Support\Config;
use App\Support\Csrf;
use App\Support\Logger;
use App\Support\Pipeline;
use App\Support\SecurityHeaders;
use App\Support\Session;
use App\Support\Transaction;
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
        $view->definirFlash(static fn (): string => $sessao->pegarFlash());

        $usuarios = new UsuarioRepository($pdo);
        $hasher ??= new PasswordHasher();
        $limitador = new RateLimiter(new RateLimitRepository($pdo));
        $condominios = new CondominioRepository($pdo);
        $vinculos = new VinculoRepository($pdo);
        $transacao = new Transaction($pdo);
        $usuarioService = new UsuarioService($usuarios, $hasher);
        $gestaoUsuarios = new GestaoUsuariosService($usuarioService, $usuarios, $condominios, $vinculos, $hasher, $transacao);
        $gestaoCondominios = new GestaoCondominiosService($condominios, $usuarios, $vinculos, $transacao);

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
        $gerente = new GerenteController($view);
        $admin = new AdminController($view, $gestaoUsuarios, $gestaoCondominios);
        $adminUsuarios = new UsuariosController($view, $sessao, $gestaoUsuarios, $gestaoCondominios);
        $adminCondominios = new CondominiosController($view, $sessao, $gestaoCondominios, new AparenciaService());

        // Ordem dos middlewares: o primeiro executa primeiro (limite → CSRF → login → papel).
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use (
            $authController, $home, $admin, $gerente, $adminUsuarios, $adminCondominios,
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
            // Área do superadmin: login → papel → (nas escritas) CSRF.
            $leitura = static fn (callable $h): callable => Pipeline::envolver($h, $mwAuth, $mwAdmin);
            $escrita = static fn (callable $h): callable => Pipeline::envolver($h, $mwAuth, $mwAdmin, $mwCsrf);

            $r->addRoute('GET', '/admin', $leitura([$admin, 'painel']));

            $r->addRoute('GET', '/admin/usuarios', $leitura([$adminUsuarios, 'lista']));
            $r->addRoute('GET', '/admin/usuarios/novo', $leitura([$adminUsuarios, 'novo']));
            $r->addRoute('POST', '/admin/usuarios', $escrita([$adminUsuarios, 'criar']));
            $r->addRoute('GET', '/admin/usuarios/{id:\d+}/editar', $leitura([$adminUsuarios, 'editar']));
            $r->addRoute('POST', '/admin/usuarios/{id:\d+}', $escrita([$adminUsuarios, 'atualizar']));
            $r->addRoute('GET', '/admin/usuarios/{id:\d+}/excluir', $leitura([$adminUsuarios, 'confirmarExclusao']));
            $r->addRoute('POST', '/admin/usuarios/{id:\d+}/excluir', $escrita([$adminUsuarios, 'excluir']));

            $r->addRoute('GET', '/admin/condominios', $leitura([$adminCondominios, 'lista']));
            $r->addRoute('GET', '/admin/condominios/novo', $leitura([$adminCondominios, 'novo']));
            $r->addRoute('POST', '/admin/condominios', $escrita([$adminCondominios, 'criar']));
            $r->addRoute('GET', '/admin/condominios/{id:\d+}/editar', $leitura([$adminCondominios, 'editar']));
            $r->addRoute('POST', '/admin/condominios/{id:\d+}', $escrita([$adminCondominios, 'atualizar']));
            $r->addRoute('GET', '/admin/condominios/{id:\d+}/excluir', $leitura([$adminCondominios, 'confirmarExclusao']));
            $r->addRoute('POST', '/admin/condominios/{id:\d+}/excluir', $escrita([$adminCondominios, 'excluir']));

            $r->addRoute('GET', '/gerente', Pipeline::envolver([$gerente, 'painel'], $mwAuth, $mwGerente));
        });

        return new Application($dispatcher, $view, $logger, new SecurityHeaders());
    }
}
