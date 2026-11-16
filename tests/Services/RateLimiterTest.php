<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\RateLimitRepository;
use App\Services\RateLimiter;
use Tests\BancoMigradoTestCase;

final class RateLimiterTest extends BancoMigradoTestCase
{
    private RateLimiter $limitador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limitador = new RateLimiter(new RateLimitRepository($this->pdo));
    }

    public function testNaoBloqueiaAteOMaximoEBloqueiaDepois(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($this->limitador->registrar('k', 3, 60, 600), "tentativa {$i}");
        }
        $this->assertSame(0, $this->limitador->segundosBloqueado('k'));

        $this->assertTrue($this->limitador->registrar('k', 3, 60, 600), 'a 4ª tentativa bloqueia');

        $espera = $this->limitador->segundosBloqueado('k');
        $this->assertGreaterThan(590, $espera);
        $this->assertLessThanOrEqual(600, $espera);
    }

    public function testChavesSaoIndependentes(): void
    {
        $this->limitador->registrar('a', 1, 60, 600);
        $this->limitador->registrar('a', 1, 60, 600);

        $this->assertGreaterThan(0, $this->limitador->segundosBloqueado('a'));
        $this->assertSame(0, $this->limitador->segundosBloqueado('b'));
    }

    public function testJanelaExpiradaRecomecaAContagem(): void
    {
        $this->limitador->registrar('k', 2, 60, 600);
        $this->limitador->registrar('k', 2, 60, 600);
        // Simula 2 minutos depois: a janela de 60 s já passou.
        $this->pdo->exec("UPDATE rate_limits SET janela_inicio = now() - interval '2 minutes' WHERE chave = 'k'");

        $this->assertFalse($this->limitador->registrar('k', 2, 60, 600));
        $this->assertSame(1, (int) $this->pdo->query("SELECT tentativas FROM rate_limits WHERE chave = 'k'")->fetchColumn());
    }

    public function testBloqueioTerminadoLiberaEZeraAContagem(): void
    {
        $this->limitador->registrar('k', 1, 3600, 600);
        $this->limitador->registrar('k', 1, 3600, 600);
        $this->assertGreaterThan(0, $this->limitador->segundosBloqueado('k'));

        // Bloqueio expirou, mas a janela de contagem ainda estaria aberta.
        $this->pdo->exec("UPDATE rate_limits SET bloqueado_ate = now() - interval '1 second' WHERE chave = 'k'");

        $this->assertSame(0, $this->limitador->segundosBloqueado('k'));
        $this->assertFalse($this->limitador->registrar('k', 1, 3600, 600));
    }

    public function testLimparRemoveOContador(): void
    {
        $this->limitador->registrar('k', 1, 60, 600);
        $this->limitador->registrar('k', 1, 60, 600);

        $this->limitador->limpar('k');

        $this->assertSame(0, $this->limitador->segundosBloqueado('k'));
        $this->assertSame(0, (int) $this->pdo->query("SELECT count(*) FROM rate_limits WHERE chave = 'k'")->fetchColumn());
    }

    public function testHashDeNormalizaECaixaEEspacosENaoExpoeOOriginal(): void
    {
        $this->assertSame(RateLimiter::hashDe('Maria@Exemplo.com'), RateLimiter::hashDe('  maria@exemplo.com '));
        $this->assertNotSame(RateLimiter::hashDe('a@x.com'), RateLimiter::hashDe('b@x.com'));
        $this->assertStringNotContainsString('maria', RateLimiter::hashDe('maria@exemplo.com'));
        $this->assertSame(64, strlen(RateLimiter::hashDe('x')));
    }

    public function testRemoverAntigosApagaSoContadoresVelhosSemBloqueio(): void
    {
        $repo = new RateLimitRepository($this->pdo);
        $repo->registrarTentativa('velho', 60);
        $repo->registrarTentativa('novo', 60);
        $repo->registrarTentativa('velho-bloqueado', 60);
        $repo->bloquear('velho-bloqueado', 600);
        $this->pdo->exec("UPDATE rate_limits SET janela_inicio = now() - interval '2 days' WHERE chave IN ('velho', 'velho-bloqueado')");

        $this->assertSame(1, $repo->removerAntigos(86400));

        $chaves = $this->pdo->query('SELECT chave FROM rate_limits ORDER BY chave')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['novo', 'velho-bloqueado'], $chaves);
    }
}
