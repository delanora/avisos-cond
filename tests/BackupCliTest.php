<?php

declare(strict_types=1);

namespace Tests;

use App\Support\Config;

/**
 * Executa o bin/backup.sh de verdade (subprocesso) contra o banco de teste. Destino e uploads são SEMPRE
 * pastas temporárias: o teste nunca toca em /var/backups nem nos arquivos reais do sistema.
 */
final class BackupCliTest extends BancoMigradoTestCase
{
    private string $base;
    private string $destino;
    private string $uploads;

    protected function setUp(): void
    {
        parent::setUp();
        if (trim((string) shell_exec('command -v pg_dump')) === '') {
            $this->markTestSkipped('pg_dump não está instalado.');
        }
        $this->base = sys_get_temp_dir() . '/backup_cli_' . bin2hex(random_bytes(5));
        $this->destino = $this->base . '/destino';
        $this->uploads = $this->base . '/uploads';
        mkdir($this->uploads, 0750, true);
        file_put_contents($this->uploads . '/' . str_repeat('a', 32) . '.png', 'conteudo-da-midia');
    }

    protected function tearDown(): void
    {
        if (isset($this->base) && str_starts_with($this->base, sys_get_temp_dir() . '/backup_cli_')) {
            exec('rm -rf ' . escapeshellarg($this->base));
        }
    }

    /**
     * @param array<string, string> $ambiente
     * @return array{saida: string, erro: string, codigo: int}
     */
    private function rodar(array $ambiente = []): array
    {
        $env = $ambiente + [
            'BACKUP_DIR' => $this->destino,
            'UPLOAD_DIR' => $this->uploads,
            'DB_NAME' => (string) getenv('DB_NAME'),
        ] + getenv();
        $p = proc_open(['/bin/bash', dirname(__DIR__) . '/bin/backup.sh'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
        $saida = (string) stream_get_contents($pipes[1]);
        $erro = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['saida' => $saida, 'erro' => $erro, 'codigo' => proc_close($p)];
    }

    /** @return list<string> */
    private function arquivosDoDestino(): array
    {
        return array_values(array_map('basename', glob($this->destino . '/*') ?: []));
    }

    public function testGeraDumpValidoEArquivoDeUploadsComPermissaoRestrita(): void
    {
        $this->pdo->exec("INSERT INTO condominios (nome) VALUES ('Condomínio do backup')");

        $r = $this->rodar();

        $this->assertSame(0, $r['codigo'], $r['erro'] . $r['saida']);
        $dumps = glob($this->destino . '/avisos-*.dump') ?: [];
        $tars = glob($this->destino . '/uploads-*.tar.gz') ?: [];
        $this->assertCount(1, $dumps);
        $this->assertCount(1, $tars);
        $this->assertSame([], glob($this->destino . '/*.tmp') ?: [], 'Nada pela metade.');

        $indice = (string) shell_exec('pg_restore --list ' . escapeshellarg($dumps[0]));
        $this->assertStringContainsString('TABLE public condominios', $indice);
        $this->assertStringContainsString('TABLE DATA public condominios', $indice, 'O dump tem os dados, não só a estrutura.');
        $this->assertStringContainsString(str_repeat('a', 32) . '.png', (string) shell_exec('tar -tzf ' . escapeshellarg($tars[0])));

        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->destino)), -4), 'Pasta só do dono.');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($dumps[0])), -4), 'Dump só do dono.');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($tars[0])), -4));
    }

    public function testRetencaoApagaSoBackupsAntigosComONossoPadrao(): void
    {
        mkdir($this->destino, 0700, true);
        $antigoDump = $this->destino . '/avisos-20200101-000000.dump';
        $antigoTar = $this->destino . '/uploads-20200101-000000.tar.gz';
        $recente = $this->destino . '/avisos-20991231-000000.dump';
        $estranho = $this->destino . '/anotacoes-importantes.txt';
        foreach ([$antigoDump, $antigoTar, $recente, $estranho] as $f) {
            file_put_contents($f, 'x');
        }
        foreach ([$antigoDump, $antigoTar, $estranho] as $f) {
            touch($f, time() - 40 * 86400);
        }

        $r = $this->rodar(['BACKUP_KEEP_DAYS' => '14']);

        $this->assertSame(0, $r['codigo'], $r['erro']);
        $this->assertFileDoesNotExist($antigoDump);
        $this->assertFileDoesNotExist($antigoTar);
        $this->assertFileExists($recente);
        $this->assertFileExists($estranho, 'Arquivo fora do padrão nunca é apagado.');
        $this->assertStringContainsString('Removido (antigo): ', $r['saida']);
    }

    public function testFalhaNoBancoSaiComErroENaoApagaBackupsAntigos(): void
    {
        mkdir($this->destino, 0700, true);
        $antigo = $this->destino . '/avisos-20200101-000000.dump';
        file_put_contents($antigo, 'x');
        touch($antigo, time() - 40 * 86400);

        $r = $this->rodar(['DB_NAME' => 'banco_que_nao_existe_' . bin2hex(random_bytes(3))]);

        $this->assertSame(1, $r['codigo']);
        $this->assertStringContainsString('ERRO: pg_dump falhou', $r['erro']);
        $this->assertFileExists($antigo, 'Sem backup novo válido, nada antigo é removido.');
        $this->assertSame(['avisos-20200101-000000.dump'], $this->arquivosDoDestino(), 'Nem arquivo temporário sobra.');
    }

    public function testSenhaDoBancoNuncaApareceNaSaida(): void
    {
        $senha = Config::fromFile(dirname(__DIR__) . '/.env')->string('DB_PASS');

        $ok = $this->rodar();
        $falha = $this->rodar(['DB_NAME' => 'banco_que_nao_existe_' . bin2hex(random_bytes(3))]);

        foreach ([$ok, $falha] as $r) {
            $this->assertNotSame('', $senha);
            $this->assertStringNotContainsString($senha, $r['saida'] . $r['erro']);
        }
    }

    public function testConfiguracaoInvalidaFalhaCedo(): void
    {
        $this->assertSame(1, $this->rodar(['BACKUP_KEEP_DAYS' => 'abc'])['codigo']);
        $this->assertSame(1, $this->rodar(['BACKUP_KEEP_DAYS' => '0'])['codigo']);
        $this->assertSame(1, $this->rodar(['BACKUP_ENV_FILE' => '/nao/existe', 'DB_NAME' => '', 'DB_USER' => ''])['codigo']);
        $this->assertSame([], $this->arquivosDoDestino());
    }
}
