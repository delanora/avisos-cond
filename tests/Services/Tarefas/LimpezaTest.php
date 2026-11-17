<?php

declare(strict_types=1);

namespace Tests\Services\Tarefas;

use App\Repositories\ArquivoRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\RateLimitRepository;
use App\Services\Tarefas\LimparArquivosOrfaos;
use App\Services\Tarefas\LimparRateLimits;
use App\Services\Tarefas\LimparResetsDeSenha;
use App\Services\UploadService;
use RuntimeException;
use Tests\BancoMigradoTestCase;

final class LimpezaTest extends BancoMigradoTestCase
{
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uploads = sys_get_temp_dir() . '/orfaos_' . bin2hex(random_bytes(5));
        mkdir($this->uploads, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->uploads . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($this->uploads);
    }

    private function total(string $tabela): int
    {
        return (int) $this->pdo->query("SELECT count(*) FROM {$tabela}")->fetchColumn();
    }

    // ---------- rate limits ----------

    public function testLimparRateLimitsApagaSoOsAntigosSemBloqueioAtivo(): void
    {
        $repo = new RateLimitRepository($this->pdo);
        foreach (['velho', 'recente', 'velho-bloqueado'] as $chave) {
            $repo->registrarTentativa($chave, 60);
        }
        $repo->bloquear('velho-bloqueado', 900);
        $this->pdo->exec("UPDATE rate_limits SET janela_inicio = now() - interval '3 days' WHERE chave IN ('velho', 'velho-bloqueado')");

        $tarefa = new LimparRateLimits($repo);
        $resumo = $tarefa->executar();

        $this->assertSame('1 contador(es) removido(s)', $resumo);
        $chaves = $this->pdo->query('SELECT chave FROM rate_limits ORDER BY chave')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['recente', 'velho-bloqueado'], $chaves, 'Bloqueio ativo e contador recente ficam.');
        $this->assertSame('0 contador(es) removido(s)', $tarefa->executar(), 'Idempotente.');
    }

    public function testLimparRateLimitsTemNomeEIntervalo(): void
    {
        $t = new LimparRateLimits(new RateLimitRepository($this->pdo));

        $this->assertSame('limpar_rate_limits', $t->nome());
        $this->assertSame(3600, $t->intervaloSegundos());
    }

    // ---------- recuperação de senha ----------

    private function reset(int $usuario, string $hash, string $criadoHa, string $expiraEm, ?string $usadoHa = null): void
    {
        $usado = $usadoHa === null ? 'NULL' : "now() - interval '{$usadoHa}'";
        $this->pdo->exec(
            "INSERT INTO password_resets (usuario_id, token_hash, expira_em, usado_em, criado_em)
             VALUES ({$usuario}, '{$hash}', {$expiraEm}, {$usado}, now() - interval '{$criadoHa}')"
        );
    }

    public function testLimparResetsApagaSoOsInuteisEAntigos(): void
    {
        $u = $this->criarUsuario('a@exemplo.com.br');
        $this->reset($u, str_repeat('1', 64), '10 days', "now() - interval '9 days'", '9 days');   // usado, antigo -> some
        $this->reset($u, str_repeat('2', 64), '10 days', "now() - interval '9 days'");              // expirado, antigo -> some
        $this->reset($u, str_repeat('3', 64), '1 day', "now() - interval '1 day'");                 // expirado, recente -> fica
        $this->reset($u, str_repeat('4', 64), '1 day', "now() + interval '10 minutes'", '1 hour'); // usado, recente -> fica
        $this->reset($u, str_repeat('5', 64), '10 days', "now() + interval '10 minutes'");          // AINDA VÁLIDO -> nunca some

        $tarefa = new LimparResetsDeSenha(new PasswordResetRepository($this->pdo));
        $resumo = $tarefa->executar();

        $this->assertSame('2 token(s) de recuperação removido(s)', $resumo);
        $restantes = $this->pdo->query('SELECT left(token_hash, 1) FROM password_resets ORDER BY token_hash')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['3', '4', '5'], $restantes);
        $this->assertSame('0 token(s) de recuperação removido(s)', $tarefa->executar(), 'Idempotente.');
    }

    public function testLimparResetsTemNomeEIntervalo(): void
    {
        $t = new LimparResetsDeSenha(new PasswordResetRepository($this->pdo));

        $this->assertSame('limpar_resets_de_senha', $t->nome());
        $this->assertSame(86400, $t->intervaloSegundos());
    }

    // ---------- arquivos órfãos ----------

    private function arquivo(string $nome, int $idadeSegundos = 3 * 86400, string $conteudo = 'x'): string
    {
        file_put_contents("{$this->uploads}/{$nome}", $conteudo);
        touch("{$this->uploads}/{$nome}", time() - $idadeSegundos);

        return $nome;
    }

    private function nomeValido(string $ext = 'png'): string
    {
        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    private function tarefaDeOrfaos(): LimparArquivosOrfaos
    {
        return new LimparArquivosOrfaos(new UploadService($this->uploads, 1048576), new ArquivoRepository($this->pdo));
    }

    /** @return list<string> */
    private function guardados(): array
    {
        return array_values(array_filter(array_map('basename', glob($this->uploads . '/*') ?: [])));
    }

    public function testApagaSoArquivosOrfaosAntigos(): void
    {
        $condominio = (int) $this->pdo->query("INSERT INTO condominios (nome) VALUES ('A') RETURNING id")->fetchColumn();
        $usuario = $this->criarUsuario('g@exemplo.com.br');
        $emAviso = $this->arquivo($this->nomeValido());
        $orfaoVelho = $this->arquivo($this->nomeValido(), 3 * 86400, str_repeat('z', 2048));
        $orfaoNovo = $this->arquivo($this->nomeValido(), 3600);
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, midia, midia_tipo, inicio_em, fim_em, criado_por) VALUES ({$condominio}, 'a', '{$emAviso}', 'imagem', now(), now() + interval '1 day', {$usuario})");

        $resumo = $this->tarefaDeOrfaos()->executar();

        $this->assertSame('1 arquivo(s) órfão(s) removido(s) (2,0 KB), 2 em uso', $resumo);
        $this->assertEqualsCanonicalizing([$emAviso, $orfaoNovo], $this->guardados(), 'Em uso e recém-criado ficam; só o órfão antigo some.');
    }

    public function testIgnoraArquivosForaDoPadraoDeNome(): void
    {
        $this->arquivo('.gitkeep');
        $this->arquivo('notas.txt');
        $this->arquivo('FOTO.PNG');
        $this->arquivo(str_repeat('a', 31) . '.png');
        $this->arquivo(str_repeat('a', 32) . '.exe');

        $resumo = $this->tarefaDeOrfaos()->executar();

        $this->assertStringStartsWith('0 arquivo(s)', $resumo);
        $this->assertCount(4, $this->guardados(), 'Nenhum arquivo estranho foi tocado.');
        $this->assertFileExists("{$this->uploads}/.gitkeep");
        $this->assertFileExists("{$this->uploads}/notas.txt");
    }

    public function testEIdempotente(): void
    {
        $this->arquivo($this->nomeValido());
        $tarefa = $this->tarefaDeOrfaos();

        $this->assertStringStartsWith('1 arquivo(s)', $tarefa->executar());
        $this->assertStringStartsWith('0 arquivo(s)', $tarefa->executar());
        $this->assertSame([], $this->guardados());
    }

    public function testPastaInexistenteOuVaziaNaoQuebra(): void
    {
        $semPasta = new LimparArquivosOrfaos(new UploadService($this->uploads . '/nao-existe', 1048576), new ArquivoRepository($this->pdo));

        $this->assertStringStartsWith('0 arquivo(s)', $semPasta->executar());
        $this->assertStringStartsWith('0 arquivo(s)', $this->tarefaDeOrfaos()->executar());
    }

    public function testRespeitaOIntervaloEAIdadeMinima(): void
    {
        $t = $this->tarefaDeOrfaos();

        $this->assertSame('limpar_arquivos_orfaos', $t->nome());
        $this->assertSame(86400, $t->intervaloSegundos());
        $this->assertSame(86400, LimparArquivosOrfaos::IDADE_MINIMA_SEGUNDOS);
    }

    public function testArquivoComMenosDeUmDiaNuncaESolto(): void
    {
        $quaseUmDia = $this->arquivo($this->nomeValido(), 86400 - 60);

        $this->tarefaDeOrfaos()->executar();

        $this->assertSame([$quaseUmDia], $this->guardados());
    }

    public function testDisjuntorPorQuantidadeNaoApagaNada(): void
    {
        $this->arquivo($this->nomeValido()); // um em uso falso para o total passar de 200 com folga
        for ($i = 0; $i < LimparArquivosOrfaos::LIMITE_ABSOLUTO + 1; $i++) {
            $this->arquivo($this->nomeValido());
        }
        $antes = $this->guardados();

        try {
            $this->tarefaDeOrfaos()->executar();
            $this->fail('O disjuntor deveria ter disparado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Disjuntor', $e->getMessage());
            $this->assertStringContainsString('banco e a pasta de uploads certos', $e->getMessage());
        }

        $this->assertEqualsCanonicalizing($antes, $this->guardados(), 'Nada foi apagado.');
    }

    public function testDisjuntorPorProporcaoQuandoQuaseTudoParecerOrfao(): void
    {
        // 30 arquivos e nenhum em uso (banco errado ou vazio): mais da metade órfã e mais de 20 -> aborta.
        for ($i = 0; $i < 30; $i++) {
            $this->arquivo($this->nomeValido());
        }

        try {
            $this->tarefaDeOrfaos()->executar();
            $this->fail('O disjuntor deveria ter disparado.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('30 de 30', $e->getMessage());
        }

        $this->assertCount(30, $this->guardados());
    }

    public function testPoucosOrfaosEmPastaPequenaNaoDisparamODisjuntor(): void
    {
        // Até 20 órfãos podem ser todos apagados mesmo que sejam a maioria (pasta pequena, uso normal).
        for ($i = 0; $i < 15; $i++) {
            $this->arquivo($this->nomeValido());
        }

        $this->assertStringStartsWith('15 arquivo(s)', $this->tarefaDeOrfaos()->executar());
        $this->assertSame([], $this->guardados());
    }

    public function testRelogioInjetadoDecideAIdade(): void
    {
        $nome = $this->arquivo($this->nomeValido(), 100);
        $daquiA2Dias = new LimparArquivosOrfaos(new UploadService($this->uploads, 1048576), new ArquivoRepository($this->pdo), static fn (): int => time() + 2 * 86400);

        $this->assertStringStartsWith('1 arquivo(s)', $daquiA2Dias->executar());
        $this->assertFileDoesNotExist("{$this->uploads}/{$nome}");
    }

    // ---------- ArquivoRepository ----------

    public function testArquivoRepositoryDevolveSoOsEmUsoEAguentaListasGrandes(): void
    {
        $condominio = (int) $this->pdo->query("INSERT INTO condominios (nome) VALUES ('A') RETURNING id")->fetchColumn();
        $usuario = $this->criarUsuario('g@exemplo.com.br');
        $usado = $this->nomeValido();
        $this->pdo->exec("INSERT INTO avisos (condominio_id, titulo, midia, midia_tipo, inicio_em, fim_em, criado_por) VALUES ({$condominio}, 'a', '{$usado}', 'imagem', now(), now() + interval '1 day', {$usuario})");
        $repo = new ArquivoRepository($this->pdo);

        $nomes = [$usado];
        for ($i = 0; $i < 1200; $i++) {
            $nomes[] = $this->nomeValido();
        }

        $this->assertEqualsCanonicalizing([$usado], $repo->emUso($nomes), 'Mais de 500 nomes: processa em lotes.');
        $this->assertSame([], $repo->emUso([]));
        $this->assertSame([], $repo->emUso(["a\"b'; DROP TABLE avisos; --"]), 'Texto malicioso é só um nome que não existe.');
        $this->assertSame(1, $this->total('avisos'));
    }
}
