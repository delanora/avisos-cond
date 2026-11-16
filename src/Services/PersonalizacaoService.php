<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CondominioRepository;
use App\Support\NaoEncontradoException;

/** Logo e cores do condomínio, definidas pelo gerente. Sem personalização vale o padrão da Embracore. */
final class PersonalizacaoService
{
    public function __construct(
        private readonly CondominioRepository $condominios,
        private readonly UploadService $upload,
    ) {
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados usar_padrao, cor_primaria, cor_secundaria, remover_logo
     * @param mixed                $arquivoLogo entrada de $_FILES["logo"] (opcional)
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function salvar(array $permitidos, int $condominioId, array $dados, mixed $arquivoLogo): void
    {
        if (!in_array($condominioId, $permitidos, true)) {
            throw new NaoEncontradoException();
        }
        $atual = $this->condominios->porId($condominioId) ?? throw new NaoEncontradoException();

        $erros = [];
        $primaria = $secundaria = null;

        if (($dados['usar_padrao'] ?? '') !== '1') {
            $primaria = $this->cor($dados, 'cor_primaria', $erros);
            $secundaria = $this->cor($dados, 'cor_secundaria', $erros);
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        $novaLogo = null;
        if (!UploadService::semArquivo($arquivoLogo)) {
            try {
                $novaLogo = $this->upload->guardar($arquivoLogo, [UploadService::IMAGEM]);
            } catch (UploadInvalidoException $e) {
                throw new ValidacaoException(['logo' => $e->getMessage()]);
            }
        }

        $removerAtual = $novaLogo !== null || ($dados['remover_logo'] ?? '') === '1';
        $logo = $novaLogo !== null ? $novaLogo['nome'] : ($removerAtual ? null : $atual['logo']);

        try {
            $gravou = $this->condominios->atualizarAparencia($permitidos, $condominioId, $logo, $primaria, $secundaria);
        } catch (\Throwable $erro) {
            $this->upload->remover($novaLogo['nome'] ?? null);

            throw $erro;
        }

        if (!$gravou) {
            $this->upload->remover($novaLogo['nome'] ?? null);

            throw new NaoEncontradoException();
        }

        if ($removerAtual) {
            $this->upload->remover($atual['logo']);
        }
    }

    /**
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     */
    private function cor(array $dados, string $campo, array &$erros): ?string
    {
        $valor = is_string($dados[$campo] ?? null) ? trim($dados[$campo]) : '';
        if (!AparenciaService::corValida($valor)) {
            $erros[$campo] = 'Escolha uma cor válida.';

            return null;
        }

        return strtoupper($valor);
    }
}
