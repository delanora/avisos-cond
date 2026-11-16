<?php

declare(strict_types=1);

namespace Tests;

use App\AppFactory;
use App\Application;
use App\Support\Config;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;
use App\Support\Session;
use Tests\Support\FakeMailer;

/**
 * Base dos testes de integração HTTP: monta a aplicação com a mesma AppFactory do index.php,
 * e usa o $_SESSION como se fosse o navegador (a sessão persiste entre as requisições do teste).
 */
abstract class AppHttpTestCase extends BancoMigradoTestCase
{
    protected Application $app;
    protected FakeMailer $mailer;
    protected string $log;

    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
        $this->mailer = new FakeMailer();
        $this->log = tempnam(sys_get_temp_dir(), 'log');
        $this->app = AppFactory::criar(
            new Config(['APP_URL' => 'https://avisos.exemplo.com.br']),
            $this->pdo,
            Session::emMemoria(),
            $this->mailer,
            new Logger($this->log),
            dirname(__DIR__) . '/templates',
            $this->hasherRapido()
        );
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        @unlink($this->log);
    }

    protected function get(string $caminho, string $ip = '203.0.113.10'): Response
    {
        // Separa a query string do caminho, como o servidor faz.
        [$rota, $consulta] = array_pad(explode('?', $caminho, 2), 2, '');
        parse_str($consulta, $query);

        return $this->app->handle(new Request('GET', $rota, query: $query, server: ['REMOTE_ADDR' => $ip]));
    }

    /** @param array<string, mixed> $corpo */
    protected function post(string $caminho, array $corpo = [], bool $comCsrf = true, string $ip = '203.0.113.10'): Response
    {
        if ($comCsrf) {
            $corpo['_csrf'] = $this->tokenCsrf();
        }

        return $this->app->handle(new Request('POST', $caminho, body: $corpo, server: ['REMOTE_ADDR' => $ip]));
    }

    protected function tokenCsrf(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $this->get('/login');
        }

        return (string) $_SESSION['csrf_token'];
    }

    protected function logar(string $email = 'gerente@exemplo.com.br', string $senha = self::SENHA): Response
    {
        return $this->post('/login', ['email' => $email, 'senha' => $senha]);
    }

    /** Cria um superadmin e deixa o "navegador" logado como ele. */
    protected function logarComoAdmin(string $email = 'admin@exemplo.com.br'): int
    {
        $id = $this->criarUsuario($email, 'superadmin');
        $this->logar($email);

        return $id;
    }

    protected function contar(string $tabela): int
    {
        // $tabela vem sempre de constantes dos testes, nunca de entrada externa.
        return (int) $this->pdo->query("SELECT count(*) FROM {$tabela}")->fetchColumn();
    }

    protected function criarCondominio(string $nome = 'Condomínio A'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO condominios (nome) VALUES (:n) RETURNING id');
        $stmt->execute(['n' => $nome]);

        return (int) $stmt->fetchColumn();
    }
}
