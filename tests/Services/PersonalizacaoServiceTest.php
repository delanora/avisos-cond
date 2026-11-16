<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\CondominioRepository;
use App\Services\PersonalizacaoService;
use App\Services\UploadService;
use App\Services\ValidacaoException;
use App\Support\NaoEncontradoException;
use Tests\BancoMigradoTestCase;
use Tests\Support\Amostras;

final class PersonalizacaoServiceTest extends BancoMigradoTestCase
{
    private string $dir;
    private int $a;
    private int $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/pers_srv_' . bin2hex(random_bytes(6));
        $repo = new CondominioRepository($this->pdo);
        $this->a = $repo->criar('A', null, true);
        $this->b = $repo->criar('B', null, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
        Amostras::limpar();
    }

    private function servico(): PersonalizacaoService
    {
        return new PersonalizacaoService(
            new CondominioRepository($this->pdo),
            new UploadService($this->dir, 1048576, static fn (string $de, string $para): bool => copy($de, $para))
        );
    }

    /** @return array<string, mixed> */
    private function condominio(int $id): array
    {
        return (new CondominioRepository($this->pdo))->porId($id);
    }

    /** @return list<string> */
    private function arquivos(): array
    {
        return array_map('basename', glob($this->dir . '/*') ?: []);
    }

    public function testSalvaCoresNormalizandoParaMaiusculas(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['cor_primaria' => '#1a2b3c', 'cor_secundaria' => '#abcdef'], null);

        $c = $this->condominio($this->a);
        $this->assertSame('#1A2B3C', $c['cor_primaria']);
        $this->assertSame('#ABCDEF', $c['cor_secundaria']);
    }

    public function testUsarPadraoLimpaAsCoresEIgnoraOsValoresEnviados(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['cor_primaria' => '#112233', 'cor_secundaria' => '#445566'], null);

        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1', 'cor_primaria' => '#112233', 'cor_secundaria' => 'lixo'], null);

        $c = $this->condominio($this->a);
        $this->assertNull($c['cor_primaria']);
        $this->assertNull($c['cor_secundaria']);
    }

    /** @return array<string, array{string}> */
    public static function coresInvalidas(): array
    {
        return [
            'nome de cor' => ['red'],
            'sem cerquilha' => ['00A67E'],
            'curta' => ['#12345'],
            'longa' => ['#1234567'],
            'não hexadecimal' => ['#GGGGGG'],
            'injeção de CSS' => ['#000;}body{display:none'],
            'injeção de atributo' => ['#000" onload="x'],
            'vazia' => [''],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coresInvalidas')]
    public function testCorInvalidaEhRejeitadaESemAlterarNada(string $cor): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['cor_primaria' => '#112233', 'cor_secundaria' => '#445566'], null);

        try {
            $this->servico()->salvar([$this->a], $this->a, ['cor_primaria' => $cor, 'cor_secundaria' => '#778899'], null);
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('cor_primaria', $e->erros);
        }

        $this->assertSame('#112233', $this->condominio($this->a)['cor_primaria']);
        $this->assertSame('#445566', $this->condominio($this->a)['cor_secundaria']);
    }

    public function testEnviaEGuardaALogo(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('logo.png', Amostras::png()));

        $logo = $this->condominio($this->a)['logo'];
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/', $logo);
        $this->assertSame([$logo], $this->arquivos());
    }

    public function testTrocarALogoApagaAAntiga(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('logo.png', Amostras::png()));
        $antiga = $this->condominio($this->a)['logo'];

        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('logo.jpg', Amostras::jpg()));

        $nova = $this->condominio($this->a)['logo'];
        $this->assertNotSame($antiga, $nova);
        $this->assertSame([$nova], $this->arquivos());
    }

    public function testSalvarSemArquivoMantemALogoAtual(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('logo.png', Amostras::png()));
        $logo = $this->condominio($this->a)['logo'];

        $this->servico()->salvar([$this->a], $this->a, ['cor_primaria' => '#112233', 'cor_secundaria' => '#445566'], Amostras::nenhum());

        $this->assertSame($logo, $this->condominio($this->a)['logo']);
        $this->assertFileExists($this->dir . '/' . $logo);
    }

    public function testRemoverALogoVoltaAoPadraoEApagaOArquivo(): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('logo.png', Amostras::png()));

        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1', 'remover_logo' => '1'], null);

        $this->assertNull($this->condominio($this->a)['logo']);
        $this->assertSame([], $this->arquivos());
    }

    /** @return array<string, array{string, string}> */
    public static function logosInvalidas(): array
    {
        return [
            'vídeo' => ['logo.mp4', 'mp4'],
            'PDF' => ['logo.pdf', 'pdf'],
            'SVG' => ['logo.svg', 'svg'],
            'PHP disfarçado' => ['logo.php.png', 'php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('logosInvalidas')]
    public function testLogoSoAceitaImagemEMantemOEstadoAnteriorNoErro(string $nome, string $metodo): void
    {
        $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo('boa.png', Amostras::png()));
        $logoBoa = $this->condominio($this->a)['logo'];

        try {
            $this->servico()->salvar([$this->a], $this->a, ['usar_padrao' => '1'], Amostras::arquivo($nome, Amostras::$metodo()));
            $this->fail('Era esperada uma ValidacaoException.');
        } catch (ValidacaoException $e) {
            $this->assertArrayHasKey('logo', $e->erros);
        }

        $this->assertSame($logoBoa, $this->condominio($this->a)['logo']);
        $this->assertSame([$logoBoa], $this->arquivos());
    }

    public function testCondominioNaoPermitidoRetorna404SemAlterarNemGuardarArquivo(): void
    {
        try {
            $this->servico()->salvar([$this->a], $this->b, ['cor_primaria' => '#112233', 'cor_secundaria' => '#445566'], Amostras::arquivo('logo.png', Amostras::png()));
            $this->fail('Era esperada uma NaoEncontradoException.');
        } catch (NaoEncontradoException) {
            $this->addToAssertionCount(1);
        }

        $b = $this->condominio($this->b);
        $this->assertNull($b['cor_primaria']);
        $this->assertNull($b['logo']);
        $this->assertSame([], $this->arquivos());
    }
}
