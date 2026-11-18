<?php

declare(strict_types=1);

namespace App;

use App\Controllers\Admin\CondominiosController;
use App\Controllers\Admin\DispositivosController;
use App\Controllers\Admin\UsuariosController;
use App\Controllers\Gerente\AparenciaController;
use App\Controllers\Gerente\AvisosController;
use App\Controllers\Gerente\MidiaController;
use App\Controllers\Gerente\ModelosController;
use App\Controllers\Gerente\TiposController;
use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\GerenteController;
use App\Controllers\HomeController;
use App\Controllers\Tv\TvController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\EscopoGerenteMiddleware;
use App\Middleware\Negado;
use App\Middleware\RateLimitMiddleware;
use App\Middleware\RoleMiddleware;
use App\Middleware\TamanhoMiddleware;
use App\Repositories\AvisoRepository;
use App\Repositories\CondominioRepository;
use App\Repositories\DispositivoRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\ModeloAvisoRepository;
use App\Repositories\PadroesDoGerenteRepository;
use App\Repositories\TipoAvisoRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Services\AparenciaService;
use App\Services\AuthService;
use App\Services\AvisoService;
use App\Services\DispositivoService;
use App\Services\GestaoCondominiosService;
use App\Services\GestaoUsuariosService;
use App\Services\Mailer;
use App\Services\PasswordHasher;
use App\Services\PasswordResetService;
use App\Services\RateLimiter;
use App\Services\ModeloAvisoService;
use App\Services\TipoAvisoService;
use App\Services\TvAcesso;
use App\Services\TvService;
use App\Services\UploadService;
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
        ?callable $moverUpload = null,
        ?string $versaoApp = null,
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

        // Uploads: fora do webroot. Com MEDIA_ACCEL (ex.: /media/) o Nginx entrega os arquivos (X-Accel-Redirect).
        $maximoUpload = max(1, $config->int('UPLOAD_MAX_MB', 10)) * 1048576;
        $upload = new UploadService($config->obrigatorio('UPLOAD_DIR'), $maximoUpload, $moverUpload);
        $aceleracao = $config->string('MEDIA_ACCEL') === '' ? null : $config->string('MEDIA_ACCEL');
        $aparenciaService = new AparenciaService($usuarios);
        $tipoAvisoService = new TipoAvisoService(new TipoAvisoRepository($pdo));
        $modeloAvisoService = new ModeloAvisoService(new ModeloAvisoRepository($pdo), $tipoAvisoService);
        $avisoService = new AvisoService(new AvisoRepository($pdo), $upload, $tipoAvisoService);

        // TV: token na URL, sem sessão. Intervalo de consulta configurável (30 a 60 s).
        $dispositivos = new DispositivoRepository($pdo);
        $intervaloTv = min(60, max(30, $config->int('TV_POLL_SECONDS', 45)));
        $tvService = new TvService(new AvisoRepository($pdo), $dispositivos, $upload, $intervaloTv, $versaoApp ?? self::versaoDoCodigoDaTv($diretorioTemplates));
        $tvAcesso = new TvAcesso($dispositivos, $limitador);
        $gestaoDispositivos = new DispositivoService($dispositivos, $condominios);

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
        $mwEscopo = new EscopoGerenteMiddleware($condominios, new PadroesDoGerenteRepository($pdo));
        // Margem de 1 MB para os demais campos do formulário além do arquivo.
        $mwTamanho = new TamanhoMiddleware($negado, $maximoUpload + 1048576);
        // Limite por IP nas rotas sensíveis: máx. de requisições por janela e tempo de bloqueio (segundos).
        $mwLimiteLogin = new RateLimitMiddleware($limitador, $negado, 'login', 30, 900, 900);
        $mwLimiteRecuperacao = new RateLimitMiddleware($limitador, $negado, 'recuperacao', 20, 3600, 3600);

        $authController = new AuthController($view, $sessao, $auth, $recuperacao);
        $home = new HomeController();
        $gerente = new GerenteController($view);
        $admin = new AdminController($view, $gestaoUsuarios, $gestaoCondominios, $gestaoDispositivos);
        $adminUsuarios = new UsuariosController($view, $sessao, $gestaoUsuarios, $gestaoCondominios);
        $adminCondominios = new CondominiosController($view, $sessao, $gestaoCondominios);
        $gerenteAvisos = new AvisosController($view, $sessao, $avisoService, $tipoAvisoService, $modeloAvisoService);
        $gerenteAparencia = new AparenciaController($view, $sessao, $aparenciaService);
        $gerenteTipos = new TiposController($view, $sessao, $tipoAvisoService, new TipoAvisoRepository($pdo));
        $gerenteModelos = new ModelosController($view, $sessao, $modeloAvisoService, $tipoAvisoService);
        $gerenteMidia = new MidiaController($avisoService, $upload, $aceleracao);
        $adminDispositivos = new DispositivosController($view, $sessao, $gestaoDispositivos, $gestaoCondominios, $config->obrigatorio('APP_URL'));
        $tv = new TvController($view, $tvAcesso, $tvService, $aceleracao);

        // Ordem dos middlewares: o primeiro executa primeiro (limite → CSRF → login → papel).
        $dispatcher = simpleDispatcher(static function (RouteCollector $r) use (
            $authController, $home, $admin, $gerente, $adminUsuarios, $adminCondominios,
            $gerenteAvisos, $gerenteAparencia, $gerenteMidia, $gerenteTipos, $gerenteModelos, $mwEscopo, $mwTamanho, $adminDispositivos, $tv,
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

            $r->addRoute('GET', '/admin/dispositivos', $leitura([$adminDispositivos, 'lista']));
            $r->addRoute('GET', '/admin/dispositivos/novo', $leitura([$adminDispositivos, 'novo']));
            $r->addRoute('POST', '/admin/dispositivos', $escrita([$adminDispositivos, 'criar']));
            $r->addRoute('GET', '/admin/dispositivos/{id:\d+}', $leitura([$adminDispositivos, 'detalhe']));
            $r->addRoute('GET', '/admin/dispositivos/{id:\d+}/editar', $leitura([$adminDispositivos, 'editar']));
            $r->addRoute('POST', '/admin/dispositivos/{id:\d+}', $escrita([$adminDispositivos, 'atualizar']));
            $r->addRoute('GET', '/admin/dispositivos/{id:\d+}/token', $leitura([$adminDispositivos, 'confirmarToken']));
            $r->addRoute('POST', '/admin/dispositivos/{id:\d+}/token', $escrita([$adminDispositivos, 'trocarToken']));
            $r->addRoute('GET', '/admin/dispositivos/{id:\d+}/excluir', $leitura([$adminDispositivos, 'confirmarExclusao']));
            $r->addRoute('POST', '/admin/dispositivos/{id:\d+}/excluir', $escrita([$adminDispositivos, 'excluir']));

            // Tela da TV: pública, identificada só pelo token da URL (sem login, sem sessão, sem CSRF: só leitura).
            // O token aceita qualquer caixa; a validação e o rate limit de tokens inválidos ficam no TvAcesso.
            $t = '/tv/{token:[A-Za-z0-9]{12}}';
            $r->addRoute('GET', $t, [$tv, 'tela']);
            $r->addRoute('GET', "{$t}/state", [$tv, 'estado']);
            $r->addRoute('GET', "{$t}/media/{arquivo:[a-f0-9]{32}\.(?:jpg|png|webp|mp4)}", [$tv, 'midia']);

            // Área do gerente: login → papel → condomínios permitidos (do banco) → (upload) tamanho → (escrita) CSRF.
            $gLeitura = static fn (callable $h): callable => Pipeline::envolver($h, $mwAuth, $mwGerente, $mwEscopo);
            $gEscrita = static fn (callable $h): callable => Pipeline::envolver($h, $mwAuth, $mwGerente, $mwEscopo, $mwCsrf);
            $gUpload = static fn (callable $h): callable => Pipeline::envolver($h, $mwAuth, $mwGerente, $mwEscopo, $mwTamanho, $mwCsrf);

            $r->addRoute('GET', '/gerente', $gLeitura([$gerente, 'painel']));

            // Aparência é do próprio gerente (sem condomínio na URL): só login, papel e, na escrita, CSRF.
            $r->addRoute('GET', '/gerente/aparencia', $gLeitura([$gerenteAparencia, 'formulario']));
            $r->addRoute('POST', '/gerente/aparencia', $gEscrita([$gerenteAparencia, 'salvar']));
            $r->addRoute('GET', '/gerente/tema.css', $gLeitura([$gerenteAparencia, 'css']));

            // Tipos e modelos de aviso são DO GERENTE (sem condomínio na URL): só login, papel e, na escrita, CSRF.
            foreach (['tipos' => $gerenteTipos, 'modelos' => $gerenteModelos] as $recurso => $controller) {
                $r->addRoute('GET', "/gerente/{$recurso}", $gLeitura([$controller, 'lista']));
                $r->addRoute('GET', "/gerente/{$recurso}/novo", $gLeitura([$controller, 'novo']));
                $r->addRoute('POST', "/gerente/{$recurso}", $gEscrita([$controller, 'criar']));
                $r->addRoute('GET', "/gerente/{$recurso}/{id:\d+}/editar", $gLeitura([$controller, 'editar']));
                $r->addRoute('POST', "/gerente/{$recurso}/{id:\d+}", $gEscrita([$controller, 'atualizar']));
                $r->addRoute('GET', "/gerente/{$recurso}/{id:\d+}/excluir", $gLeitura([$controller, 'confirmarExclusao']));
                $r->addRoute('POST', "/gerente/{$recurso}/{id:\d+}/excluir", $gEscrita([$controller, 'excluir']));
            }

            $r->addRoute('GET', '/gerente/tipos.css', $gLeitura([$gerenteTipos, 'cssProprio']));

            $c = '/gerente/condominios/{cid:\d+}';
            $r->addRoute('GET', "{$c}/avisos", $gLeitura([$gerenteAvisos, 'lista']));
            $r->addRoute('GET', "{$c}/avisos/novo", $gLeitura([$gerenteAvisos, 'novo']));
            $r->addRoute('POST', "{$c}/avisos", $gUpload([$gerenteAvisos, 'criar']));
            $r->addRoute('GET', "{$c}/avisos/{id:\d+}/editar", $gLeitura([$gerenteAvisos, 'editar']));
            $r->addRoute('POST', "{$c}/avisos/{id:\d+}", $gUpload([$gerenteAvisos, 'atualizar']));
            $r->addRoute('GET', "{$c}/avisos/{id:\d+}/excluir", $gLeitura([$gerenteAvisos, 'confirmarExclusao']));
            $r->addRoute('POST', "{$c}/avisos/{id:\d+}/excluir", $gEscrita([$gerenteAvisos, 'excluir']));
            $r->addRoute('GET', "{$c}/avisos/{id:\d+}/previa", $gLeitura([$gerenteAvisos, 'previa']));
            $r->addRoute('GET', "{$c}/avisos/{id:\d+}/midia", $gLeitura([$gerenteMidia, 'aviso']));

            $r->addRoute('GET', "{$c}/tipos.css", $gLeitura([$gerenteTipos, 'css']));
        });

        return new Application($dispatcher, $view, $logger, new SecurityHeaders());
    }

    /**
     * Versão do JS/CSS da TV (muda a cada deploy): a TV se recarrega sozinha quando ela muda.
     * A pasta public fica ao lado de templates.
     */
    private static function versaoDoCodigoDaTv(string $diretorioTemplates): string
    {
        $assets = dirname($diretorioTemplates) . '/public/assets';
        $mtimes = '';
        foreach (['tv.js', 'tv.css'] as $arquivo) {
            $mtimes .= (string) @filemtime($assets . '/' . $arquivo);
        }

        return substr(md5($mtimes), 0, 8);
    }
}
