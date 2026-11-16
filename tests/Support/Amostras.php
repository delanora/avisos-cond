<?php

declare(strict_types=1);

namespace Tests\Support;

/** Arquivos de exemplo (bytes reais, mínimos) e entradas de $_FILES para os testes de upload. */
final class Amostras
{
    /** @var list<string> */
    private static array $temporarios = [];

    public static function png(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    public static function jpg(): string
    {
        return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
    }

    public static function webp(): string
    {
        return (string) base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==');
    }

    public static function mp4(): string
    {
        return "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" . str_repeat("\x00", 64);
    }

    public static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    }

    public static function svg(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
    }

    public static function php(): string
    {
        return '<?php echo "invadido"; ?>';
    }

    public static function html(): string
    {
        return '<html><script>alert(1)</script></html>';
    }

    public static function gif(): string
    {
        return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    /** PNG válido cujo cabeçalho declara dimensões enormes (imagem-bomba). */
    public static function pngEnorme(): string
    {
        $bytes = self::png();

        return substr($bytes, 0, 16) . pack('N', 20000) . pack('N', 20000) . substr($bytes, 24);
    }

    /**
     * Cria um arquivo temporário e devolve a entrada no formato de $_FILES.
     *
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}
     */
    public static function arquivo(string $nomeEnviado, string $bytes, int $erro = UPLOAD_ERR_OK): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'amostra');
        file_put_contents($tmp, $bytes);
        self::$temporarios[] = $tmp;

        return ['name' => $nomeEnviado, 'type' => 'application/octet-stream', 'tmp_name' => $tmp, 'error' => $erro, 'size' => strlen($bytes)];
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int} */
    public static function nenhum(): array
    {
        return ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];
    }

    public static function limpar(): void
    {
        foreach (self::$temporarios as $arquivo) {
            @unlink($arquivo);
        }
        self::$temporarios = [];
    }
}
