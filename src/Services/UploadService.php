<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Recebe arquivos enviados (imagens JPEG/PNG/WebP e vídeo MP4) com validação rígida:
 * tamanho, extensão, tipo MIME REAL (finfo, pelo conteúdo) e, nas imagens, se o arquivo
 * realmente é uma imagem. O nome gravado é aleatório (o enviado nunca é usado) e o arquivo
 * fica fora do webroot (storage/uploads). PDF, SVG e qualquer outro tipo são recusados.
 */
final class UploadService
{
    public const IMAGEM = 'imagem';
    public const VIDEO = 'video';

    /** MIME real => [extensão gravada, categoria, extensões aceitas no nome enviado] */
    private const TIPOS = [
        'image/jpeg' => ['jpg', self::IMAGEM, ['jpg', 'jpeg']],
        'image/png' => ['png', self::IMAGEM, ['png']],
        'image/webp' => ['webp', self::IMAGEM, ['webp']],
        'video/mp4' => ['mp4', self::VIDEO, ['mp4']],
    ];

    private const LIMITE_DIMENSAO = 10000; // pixels, para barrar imagens-bomba

    /** @var callable(string, string): bool */
    private $mover;

    /**
     * @param ?callable $mover move o arquivo temporário para o destino. Em produção é move_uploaded_file,
     *                         que só aceita arquivos realmente enviados por HTTP; os testes passam outro.
     */
    public function __construct(
        private readonly string $diretorio,
        private readonly int $maximoBytes,
        ?callable $mover = null,
    ) {
        $this->mover = $mover ?? static fn (string $de, string $para): bool => move_uploaded_file($de, $para);
    }

    /** O usuário não escolheu nenhum arquivo? (campo de arquivo deixado em branco) */
    public static function semArquivo(mixed $arquivo): bool
    {
        return !is_array($arquivo) || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE;
    }

    /**
     * Valida e guarda o arquivo.
     *
     * @param mixed        $arquivo    entrada de $_FILES
     * @param list<string> $categorias categorias aceitas: self::IMAGEM e/ou self::VIDEO
     * @return array{nome: string, tipo: string} nome gravado (aleatório) e categoria
     * @throws UploadInvalidoException
     */
    public function guardar(mixed $arquivo, array $categorias): array
    {
        $mensagemTipos = in_array(self::VIDEO, $categorias, true)
            ? 'Envie uma imagem (JPG, PNG ou WebP) ou um vídeo MP4.'
            : 'Envie uma imagem nos formatos JPG, PNG ou WebP.';

        if (!is_array($arquivo) || !isset($arquivo['error'], $arquivo['tmp_name'], $arquivo['size'], $arquivo['name'])) {
            throw new UploadInvalidoException('Arquivo inválido.');
        }

        $this->validarErroDeEnvio((int) $arquivo['error']);

        $tmp = $arquivo['tmp_name'];
        if (!is_string($tmp) || $tmp === '' || !is_file($tmp)) {
            throw new UploadInvalidoException('Arquivo inválido.');
        }

        $tamanho = (int) filesize($tmp);
        if ($tamanho <= 0) {
            throw new UploadInvalidoException('O arquivo está vazio.');
        }
        if ($tamanho > $this->maximoBytes) {
            throw new UploadInvalidoException(sprintf('O arquivo é maior que o limite de %s MB.', $this->maximoEmMb()));
        }

        // 1) Extensão do nome enviado (só a última) precisa estar na lista de permitidas.
        $extensaoEnviada = strtolower(pathinfo((string) $arquivo['name'], PATHINFO_EXTENSION));

        // 2) Tipo real, lido do conteúdo do arquivo (não confiamos no tipo informado pelo navegador).
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if ($mime === false || !isset(self::TIPOS[$mime])) {
            throw new UploadInvalidoException($mensagemTipos);
        }
        [$extensao, $categoria, $extensoesAceitas] = self::TIPOS[$mime];

        if (!in_array($categoria, $categorias, true) || !in_array($extensaoEnviada, $extensoesAceitas, true)) {
            throw new UploadInvalidoException($mensagemTipos);
        }

        if ($categoria === self::IMAGEM) {
            $this->validarImagem($tmp, $mime);
        }

        $nome = bin2hex(random_bytes(16)) . '.' . $extensao;
        $this->garantirDiretorio();
        $destino = $this->diretorio . '/' . $nome;

        if (!($this->mover)($tmp, $destino)) {
            throw new UploadInvalidoException('Não foi possível guardar o arquivo. Tente novamente.');
        }
        @chmod($destino, 0640);

        return ['nome' => $nome, 'tipo' => $categoria];
    }

    /** Caminho completo de um arquivo guardado, ou null se o nome for inválido ou o arquivo não existir. */
    public function caminho(string $nome): ?string
    {
        if (!self::nomeValido($nome)) {
            return null;
        }

        $caminho = $this->diretorio . '/' . $nome;

        return is_file($caminho) ? $caminho : null;
    }

    /** MIME para responder ao entregar o arquivo (decidido pela extensão que nós mesmos gravamos). */
    public static function mimeDoNome(string $nome): string
    {
        return match (pathinfo($nome, PATHINFO_EXTENSION)) {
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }

    /** Apaga o arquivo. Aceita apenas nomes gerados por nós, o que impede apagar outros caminhos. */
    public function remover(?string $nome): void
    {
        if ($nome === null || !self::nomeValido($nome)) {
            return;
        }

        @unlink($this->diretorio . '/' . $nome);
    }

    public static function nomeValido(string $nome): bool
    {
        return preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|mp4)$/', $nome) === 1;
    }

    private function validarErroDeEnvio(int $erro): void
    {
        match ($erro) {
            UPLOAD_ERR_OK => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => throw new UploadInvalidoException(sprintf('O arquivo é maior que o limite de %s MB.', $this->maximoEmMb())),
            UPLOAD_ERR_PARTIAL => throw new UploadInvalidoException('O envio foi interrompido. Tente novamente.'),
            UPLOAD_ERR_NO_FILE => throw new UploadInvalidoException('Nenhum arquivo foi enviado.'),
            default => throw new UploadInvalidoException('Não foi possível receber o arquivo. Tente novamente.'),
        };
    }

    private function validarImagem(string $caminho, string $mime): void
    {
        $info = @getimagesize($caminho);
        if ($info === false || $info['mime'] !== $mime) {
            throw new UploadInvalidoException('O arquivo não é uma imagem válida.');
        }
        if ($info[0] > self::LIMITE_DIMENSAO || $info[1] > self::LIMITE_DIMENSAO) {
            throw new UploadInvalidoException('A imagem é grande demais (máximo de 10.000 pixels por lado).');
        }
    }

    private function garantirDiretorio(): void
    {
        if (!is_dir($this->diretorio) && !@mkdir($this->diretorio, 0750, true) && !is_dir($this->diretorio)) {
            throw new UploadInvalidoException('Não foi possível guardar o arquivo. Tente novamente.');
        }
    }

    private function maximoEmMb(): string
    {
        return rtrim(rtrim(number_format($this->maximoBytes / 1048576, 1, ',', ''), '0'), ',');
    }
}
