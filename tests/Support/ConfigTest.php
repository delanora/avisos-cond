<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Config;
use App\Support\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    private string $arquivo;

    protected function setUp(): void
    {
        $this->arquivo = tempnam(sys_get_temp_dir(), 'env');
    }

    protected function tearDown(): void
    {
        @unlink($this->arquivo);
        putenv('TESTE_CHAVE');
    }

    public function testLeValoresComentariosEAspas(): void
    {
        file_put_contents($this->arquivo, <<<'ENV'
# comentário
APP_NOME=Avisos
APP_KEY=   # valor vazio com comentário
COM_ASPAS="valor # com cerquilha"
COM_COMENTARIO=abc   # fim de linha
LINHA_INVALIDA
ENV);
        $config = Config::fromFile($this->arquivo);

        $this->assertSame('Avisos', $config->get('APP_NOME'));
        $this->assertSame('', $config->get('APP_KEY'));
        $this->assertSame('valor # com cerquilha', $config->get('COM_ASPAS'));
        $this->assertSame('abc', $config->get('COM_COMENTARIO'));
        $this->assertNull($config->get('LINHA_INVALIDA'));
    }

    public function testArquivoInexistenteGeraConfiguracaoVazia(): void
    {
        $config = Config::fromFile('/caminho/que/nao/existe/.env');

        $this->assertNull($config->get('QUALQUER'));
        $this->assertSame('padrao', $config->string('QUALQUER', 'padrao'));
    }

    public function testVariavelDeAmbienteTemPrioridadeSobreOArquivo(): void
    {
        file_put_contents($this->arquivo, "TESTE_CHAVE=do_arquivo\n");
        putenv('TESTE_CHAVE=do_ambiente');

        $this->assertSame('do_ambiente', Config::fromFile($this->arquivo)->get('TESTE_CHAVE'));
    }

    public function testObrigatorioFalhaQuandoAusenteOuVazio(): void
    {
        $config = new Config(['VAZIA' => '']);

        $this->expectException(ConfigException::class);
        $config->obrigatorio('VAZIA');
    }

    public function testObrigatorioRetornaOValor(): void
    {
        $this->assertSame('x', (new Config(['K' => 'x']))->obrigatorio('K'));
    }

    public function testIntEBool(): void
    {
        $config = new Config(['N' => '42', 'RUIM' => 'abc', 'LIGADO' => 'true', 'DESLIGADO' => '0']);

        $this->assertSame(42, $config->int('N'));
        $this->assertSame(7, $config->int('RUIM', 7));
        $this->assertSame(9, $config->int('AUSENTE', 9));
        $this->assertTrue($config->bool('LIGADO'));
        $this->assertFalse($config->bool('DESLIGADO'));
        $this->assertTrue($config->bool('AUSENTE', true));
    }
}
