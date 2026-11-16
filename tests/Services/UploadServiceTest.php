<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\UploadInvalidoException;
use App\Services\UploadService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Amostras;

final class UploadServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/upload_teste_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dir);
        Amostras::limpar();
    }

    private function servico(int $maximo = 5 * 1048576): UploadService
    {
        // Em testes não há envio HTTP real, então copiar substitui o move_uploaded_file.
        return new UploadService($this->dir, $maximo, static fn (string $de, string $para): bool => copy($de, $para));
    }

    /** @return list<string> */
    private function guardados(): array
    {
        return array_map('basename', glob($this->dir . '/*') ?: []);
    }

    /** @return array<string, array{string, string, string, string}> nome enviado, bytes (método), extensão gravada, categoria */
    public static function aceitos(): array
    {
        return [
            'jpg' => ['foto.jpg', 'jpg', 'jpg', 'imagem'],
            'jpeg vira jpg' => ['foto.jpeg', 'jpg', 'jpg', 'imagem'],
            'png' => ['foto.png', 'png', 'png', 'imagem'],
            'webp' => ['foto.webp', 'webp', 'webp', 'imagem'],
            'mp4' => ['video.mp4', 'mp4', 'mp4', 'video'],
            'extensão em maiúsculas' => ['FOTO.PNG', 'png', 'png', 'imagem'],
        ];
    }

    #[DataProvider('aceitos')]
    public function testAceitaImagensEVideoValidos(string $nomeEnviado, string $metodo, string $extensao, string $categoria): void
    {
        $r = $this->servico()->guardar(Amostras::arquivo($nomeEnviado, Amostras::$metodo()), ['imagem', 'video']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}\.' . $extensao . '$/', $r['nome']);
        $this->assertSame($categoria, $r['tipo']);
        $this->assertFileExists($this->dir . '/' . $r['nome']);
        $this->assertTrue(UploadService::nomeValido($r['nome']));
    }

    public function testNomeEnviadoNuncaEUsadoENomesSaoAleatorios(): void
    {
        $a = $this->servico()->guardar(Amostras::arquivo('minha-foto-secreta.png', Amostras::png()), ['imagem']);
        $b = $this->servico()->guardar(Amostras::arquivo('minha-foto-secreta.png', Amostras::png()), ['imagem']);

        $this->assertStringNotContainsString('secreta', $a['nome']);
        $this->assertNotSame($a['nome'], $b['nome']);
        $this->assertCount(2, $this->guardados());
    }

    public function testNomeComTraversalNaoEscapaDaPasta(): void
    {
        $fora = sys_get_temp_dir() . '/escapou_' . bin2hex(random_bytes(4)) . '.png';

        $r = $this->servico()->guardar(Amostras::arquivo('../../' . basename($fora), Amostras::png()), ['imagem']);

        $this->assertFileDoesNotExist($fora);
        $this->assertFileExists($this->dir . '/' . $r['nome']);
    }

    /** @return array<string, array{string, string}> nome enviado, método com os bytes */
    public static function recusados(): array
    {
        return [
            'PDF' => ['documento.pdf', 'pdf'],
            'PDF disfarçado de JPG' => ['documento.jpg', 'pdf'],
            'SVG (risco de XSS)' => ['desenho.svg', 'svg'],
            'SVG disfarçado de PNG' => ['desenho.png', 'svg'],
            'script PHP com dupla extensão' => ['shell.php.jpg', 'php'],
            'script PHP puro' => ['shell.php', 'php'],
            'HTML disfarçado de imagem' => ['pagina.jpg', 'html'],
            'GIF (não permitido)' => ['animacao.gif', 'gif'],
            'PNG com extensão de JPG' => ['foto.jpg', 'png'],
            'JPG com extensão de PNG' => ['foto.png', 'jpg'],
            'MP4 com extensão de imagem' => ['video.png', 'mp4'],
            'sem extensão' => ['foto', 'png'],
            'extensão desconhecida' => ['foto.exe', 'png'],
        ];
    }

    #[DataProvider('recusados')]
    public function testRecusaTiposNaoPermitidosOuDisfarcados(string $nomeEnviado, string $metodo): void
    {
        try {
            $this->servico()->guardar(Amostras::arquivo($nomeEnviado, Amostras::$metodo()), ['imagem', 'video']);
            $this->fail('O arquivo deveria ter sido recusado.');
        } catch (UploadInvalidoException $e) {
            $this->assertStringContainsString('Envie uma imagem', $e->getMessage());
        }

        $this->assertSame([], $this->guardados(), 'Nada pode ser gravado quando o arquivo é recusado.');
    }

    public function testLogoSoAceitaImagemEntaoRecusaVideo(): void
    {
        $this->expectException(UploadInvalidoException::class);
        $this->expectExceptionMessage('JPG, PNG ou WebP');

        $this->servico()->guardar(Amostras::arquivo('video.mp4', Amostras::mp4()), ['imagem']);
    }

    public function testRecusaImagemQueDeclaraDimensoesEnormes(): void
    {
        try {
            $this->servico()->guardar(Amostras::arquivo('bomba.png', Amostras::pngEnorme()), ['imagem']);
            $this->fail('Era esperada uma UploadInvalidoException.');
        } catch (UploadInvalidoException $e) {
            $this->assertStringContainsString('grande demais', $e->getMessage());
        }
        $this->assertSame([], $this->guardados());
    }

    public function testRecusaArquivoVazio(): void
    {
        $this->expectException(UploadInvalidoException::class);
        $this->expectExceptionMessage('vazio');

        $this->servico()->guardar(Amostras::arquivo('foto.png', ''), ['imagem']);
    }

    public function testRecusaArquivoMaiorQueOLimiteComAMensagemEmMb(): void
    {
        try {
            $this->servico(1048576)->guardar(Amostras::arquivo('foto.png', Amostras::png() . str_repeat('x', 1048576)), ['imagem']);
            $this->fail('Era esperada uma UploadInvalidoException.');
        } catch (UploadInvalidoException $e) {
            $this->assertSame('O arquivo é maior que o limite de 1 MB.', $e->getMessage());
        }
        $this->assertSame([], $this->guardados());
    }

    public function testMensagemDoLimiteComVariosMegas(): void
    {
        $this->expectExceptionMessage('limite de 10 MB');

        $this->servico(10 * 1048576)->guardar(Amostras::arquivo('f.png', '', UPLOAD_ERR_INI_SIZE), ['imagem']);
    }

    /** @return array<string, array{int, string}> */
    public static function errosDeEnvio(): array
    {
        return [
            'maior que o limite do PHP' => [UPLOAD_ERR_INI_SIZE, 'maior que o limite'],
            'maior que o limite do formulário' => [UPLOAD_ERR_FORM_SIZE, 'maior que o limite'],
            'envio interrompido' => [UPLOAD_ERR_PARTIAL, 'interrompido'],
            'nenhum arquivo' => [UPLOAD_ERR_NO_FILE, 'Nenhum arquivo'],
            'sem pasta temporária' => [UPLOAD_ERR_NO_TMP_DIR, 'Não foi possível receber'],
            'falha ao gravar' => [UPLOAD_ERR_CANT_WRITE, 'Não foi possível receber'],
        ];
    }

    #[DataProvider('errosDeEnvio')]
    public function testErrosDeEnvioDoPhpViramMensagensAmigaveis(int $codigo, string $trecho): void
    {
        try {
            $this->servico()->guardar(Amostras::arquivo('foto.png', Amostras::png(), $codigo), ['imagem']);
            $this->fail('Era esperada uma UploadInvalidoException.');
        } catch (UploadInvalidoException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());
        }
        $this->assertSame([], $this->guardados());
    }

    public function testEntradasMalFormadasSaoRecusadas(): void
    {
        foreach ([null, 'texto', [], ['name' => 'a.png'], ['name' => 'a.png', 'error' => 0, 'tmp_name' => '/nao/existe', 'size' => 5]] as $entrada) {
            try {
                $this->servico()->guardar($entrada, ['imagem']);
                $this->fail('Era esperada uma UploadInvalidoException.');
            } catch (UploadInvalidoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testFalhaAoMoverNaoDeixaNada(): void
    {
        $servico = new UploadService($this->dir, 1048576, static fn (): bool => false);

        $this->expectException(UploadInvalidoException::class);
        try {
            $servico->guardar(Amostras::arquivo('foto.png', Amostras::png()), ['imagem']);
        } finally {
            $this->assertSame([], $this->guardados());
        }
    }

    public function testEmProducaoSoArquivosEnviadosPorHttpSaoAceitos(): void
    {
        // O mover padrão é move_uploaded_file: um caminho qualquer (ex.: forjado pelo cliente) é recusado.
        $servico = new UploadService($this->dir, 1048576);

        $this->expectException(UploadInvalidoException::class);
        try {
            $servico->guardar(Amostras::arquivo('foto.png', Amostras::png()), ['imagem']);
        } finally {
            $this->assertSame([], $this->guardados());
        }
    }

    public function testCriaAPastaDeUploadsSeNaoExistir(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);

        $this->servico()->guardar(Amostras::arquivo('foto.png', Amostras::png()), ['imagem']);

        $this->assertDirectoryExists($this->dir);
    }

    public function testCaminhoSoParaNomesValidosEExistentes(): void
    {
        $r = $this->servico()->guardar(Amostras::arquivo('foto.png', Amostras::png()), ['imagem']);

        $this->assertSame($this->dir . '/' . $r['nome'], $this->servico()->caminho($r['nome']));
        $this->assertNull($this->servico()->caminho(str_repeat('a', 32) . '.png'), 'Nome válido, mas arquivo inexistente.');
        $this->assertNull($this->servico()->caminho('../' . $r['nome']));
        $this->assertNull($this->servico()->caminho('foto.png'));
        $this->assertNull($this->servico()->caminho(''));
    }

    public function testRemoverApagaSoArquivosComNomeGeradoPorNos(): void
    {
        $r = $this->servico()->guardar(Amostras::arquivo('foto.png', Amostras::png()), ['imagem']);
        $fora = sys_get_temp_dir() . '/intocavel_' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($fora, 'importante');

        try {
            $this->servico()->remover('../' . basename($fora));
            $this->servico()->remover($fora);
            $this->servico()->remover('qualquer.jpg');
            $this->servico()->remover(null);
            $this->assertFileExists($fora, 'Nomes fora do padrão nunca apagam nada.');
            $this->assertFileExists($this->dir . '/' . $r['nome']);

            $this->servico()->remover($r['nome']);
            $this->assertFileDoesNotExist($this->dir . '/' . $r['nome']);
        } finally {
            @unlink($fora);
        }
    }

    public function testMimeDoNomePelaExtensaoGravada(): void
    {
        $this->assertSame('image/jpeg', UploadService::mimeDoNome('a.jpg'));
        $this->assertSame('image/png', UploadService::mimeDoNome('a.png'));
        $this->assertSame('image/webp', UploadService::mimeDoNome('a.webp'));
        $this->assertSame('video/mp4', UploadService::mimeDoNome('a.mp4'));
        $this->assertSame('application/octet-stream', UploadService::mimeDoNome('a.svg'));
    }

    public function testSemArquivo(): void
    {
        $this->assertTrue(UploadService::semArquivo(null));
        $this->assertTrue(UploadService::semArquivo([]));
        $this->assertTrue(UploadService::semArquivo(Amostras::nenhum()));
        $this->assertFalse(UploadService::semArquivo(Amostras::arquivo('foto.png', Amostras::png())));
    }
}
