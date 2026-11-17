<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\ArquivoRepository;
use App\Services\UploadService;
use Closure;
use RuntimeException;

/**
 * Apaga de storage/uploads os arquivos que nenhum aviso nem condomínio usa mais (sobras de uploads
 * interrompidos ou de falhas ao gravar). É a única tarefa destrutiva, então tem várias travas:
 *  - só mexe em arquivos com o nome no padrão que nós geramos (o resto da pasta é ignorado);
 *  - só apaga arquivos com mais de 24 h (nunca atrapalha um upload em andamento);
 *  - DISJUNTOR: se encontrar órfãos demais (mais de 200, ou mais da metade dos arquivos), não apaga nada e
 *    falha com uma mensagem. Isso protege contra apontar o cron para o banco ou a pasta errados.
 */
final class LimparArquivosOrfaos implements Tarefa
{
    public const IDADE_MINIMA_SEGUNDOS = 86400;
    public const LIMITE_ABSOLUTO = 200;
    private const LIMITE_PARA_PROPORCAO = 20;

    /** @var Closure(): int */
    private readonly Closure $relogio;

    public function __construct(
        private readonly UploadService $upload,
        private readonly ArquivoRepository $arquivos,
        ?Closure $relogio = null,
    ) {
        $this->relogio = $relogio ?? static fn (): int => time();
    }

    public function nome(): string
    {
        return 'limpar_arquivos_orfaos';
    }

    public function intervaloSegundos(): int
    {
        return 86400;
    }

    public function executar(): string
    {
        $todos = $this->upload->arquivos();
        $emUso = array_flip($this->arquivos->emUso(array_column($todos, 'nome')));
        $limite = ($this->relogio)() - self::IDADE_MINIMA_SEGUNDOS;

        $orfaos = array_values(array_filter(
            $todos,
            static fn (array $a): bool => !isset($emUso[$a['nome']]) && $a['modificado_em'] < $limite
        ));

        $quantidade = count($orfaos);
        if ($quantidade > self::LIMITE_ABSOLUTO
            || ($quantidade > self::LIMITE_PARA_PROPORCAO && $quantidade * 2 > count($todos))) {
            throw new RuntimeException(sprintf(
                'Disjuntor: %d de %d arquivos pareceram órfãos; nada foi apagado. Confira se o cron aponta para o banco e a pasta de uploads certos.',
                $quantidade,
                count($todos)
            ));
        }

        $bytes = 0;
        foreach ($orfaos as $arquivo) {
            $this->upload->remover($arquivo['nome']);
            $bytes += $arquivo['tamanho'];
        }

        return sprintf('%d arquivo(s) órfão(s) removido(s) (%s), %d em uso', $quantidade, self::formatar($bytes), count($todos) - $quantidade);
    }

    private static function formatar(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '') . ' MB' : number_format($bytes / 1024, 1, ',', '') . ' KB';
    }
}
