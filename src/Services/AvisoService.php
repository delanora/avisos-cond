<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\AvisoRepository;
use App\Support\DataHora;
use App\Support\NaoEncontradoException;
use App\Support\Validator;
use Throwable;

/**
 * Regras dos avisos. Todos os métodos recebem a lista de condomínios permitidos ao usuário
 * (do banco) e o condomínio pedido na URL; condomínio ou aviso fora do escopo vira 404.
 */
final class AvisoService
{
    public const CORPO_MAX = 2000;

    public function __construct(
        private readonly AvisoRepository $avisos,
        private readonly UploadService $upload,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    /**
     * @param list<int> $permitidos
     * @return list<array<string, mixed>> avisos com o campo "situacao"
     */
    public function listar(array $permitidos, int $condominioId, ?int $agora = null): array
    {
        $this->exigirPermitido($permitidos, $condominioId);
        $agora ??= time();

        return array_map(
            fn (array $aviso): array => $aviso + ['situacao' => self::situacao($aviso, $agora)],
            $this->avisos->listar($permitidos, $condominioId)
        );
    }

    /**
     * @param list<int> $permitidos
     * @return array<string, mixed>
     * @throws NaoEncontradoException
     */
    public function buscar(array $permitidos, int $condominioId, int $id): array
    {
        $this->exigirPermitido($permitidos, $condominioId);

        return $this->avisos->porId($permitidos, $condominioId, $id) ?? throw new NaoEncontradoException();
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados titulo, corpo, tipo, inicio_em, fim_em, ativo
     * @param mixed                $arquivo entrada de $_FILES["midia"]
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function criar(array $permitidos, int $condominioId, array $dados, mixed $arquivo, int $usuarioId): int
    {
        $this->exigirPermitido($permitidos, $condominioId);

        $campos = $this->validar($permitidos, $condominioId, $dados);
        $midia = $this->guardarMidia($arquivo);
        $campos = $this->criarTipoNovoSeHouver($permitidos, $condominioId, $campos);

        try {
            $id = $this->avisos->criar($permitidos, $condominioId, $campos + [
                'midia' => $midia['nome'] ?? null,
                'midia_tipo' => $midia['tipo'] ?? null,
            ], $usuarioId);
        } catch (Throwable $erro) {
            $this->upload->remover($midia['nome'] ?? null);

            throw $erro;
        }

        if ($id === null) {
            $this->upload->remover($midia['nome'] ?? null);

            throw new NaoEncontradoException();
        }

        return $id;
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados titulo, corpo, tipo, inicio_em, fim_em, ativo, remover_midia
     * @param mixed                $arquivo nova mídia (opcional); substitui a atual
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(array $permitidos, int $condominioId, int $id, array $dados, mixed $arquivo): void
    {
        $atual = $this->buscar($permitidos, $condominioId, $id);

        $campos = $this->validar($permitidos, $condominioId, $dados);
        $nova = $this->guardarMidia($arquivo);
        $campos = $this->criarTipoNovoSeHouver($permitidos, $condominioId, $campos);

        $removerAtual = $nova !== null || ($dados['remover_midia'] ?? '') === '1';
        $midia = $nova !== null ? $nova['nome'] : ($removerAtual ? null : $atual['midia']);
        $tipo = $nova !== null ? $nova['tipo'] : ($removerAtual ? null : $atual['midia_tipo']);

        try {
            $gravou = $this->avisos->atualizar($permitidos, $condominioId, $id, $campos + ['midia' => $midia, 'midia_tipo' => $tipo]);
        } catch (Throwable $erro) {
            $this->upload->remover($nova['nome'] ?? null);

            throw $erro;
        }

        if (!$gravou) {
            $this->upload->remover($nova['nome'] ?? null);

            throw new NaoEncontradoException();
        }

        // Só depois de gravar apaga o arquivo antigo, para nunca ficar um aviso sem a mídia dele.
        if ($removerAtual) {
            $this->upload->remover($atual['midia']);
        }
    }

    /**
     * @param list<int> $permitidos
     * @throws NaoEncontradoException
     */
    public function excluir(array $permitidos, int $condominioId, int $id): void
    {
        $this->exigirPermitido($permitidos, $condominioId);

        $apagado = $this->avisos->excluir($permitidos, $condominioId, $id) ?? throw new NaoEncontradoException();
        $this->upload->remover($apagado['midia']);
    }

    /**
     * Situação do aviso para a lista.
     *
     * @param array<string, mixed> $aviso
     * @return array{codigo: string, rotulo: string}
     */
    public static function situacao(array $aviso, int $agora): array
    {
        return match (true) {
            !$aviso['ativo'] => ['codigo' => 'desativado', 'rotulo' => 'Desativado'],
            $agora < $aviso['inicio_epoch'] => ['codigo' => 'agendado', 'rotulo' => 'Agendado'],
            $agora >= $aviso['fim_epoch'] => ['codigo' => 'encerrado', 'rotulo' => 'Encerrado'],
            default => ['codigo' => 'no-ar', 'rotulo' => 'No ar'],
        };
    }

    /**
     * Valida o formulário. O tipo é uma chave de tipo que o condomínio pode usar; ou, se "novo_tipo_nome" vier
     * preenchido, um tipo novo (validado aqui e criado só depois de todo o resto estar certo).
     *
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados
     * @return array{titulo: string, corpo: string, tipo: ?string, tipo_novo: ?array<string, mixed>, inicio_epoch: int, fim_epoch: int, ativo: bool}
     * @throws ValidacaoException
     */
    private function validar(array $permitidos, int $condominioId, array $dados): array
    {
        $validador = new Validator($dados, [
            'titulo' => 'required|max:150',
            'corpo' => 'max:' . self::CORPO_MAX,
        ]);
        $erros = $validador->errors();

        $tipo = null;
        $tipoNovo = null;
        $nomeNovo = is_string($dados['novo_tipo_nome'] ?? null) ? trim($dados['novo_tipo_nome']) : '';
        if ($nomeNovo !== '') {
            $dadosNovo = ['nome' => $nomeNovo, 'cor' => $dados['novo_tipo_cor'] ?? '', 'fixo' => $dados['novo_tipo_fixo'] ?? ''];
            try {
                $this->tipos->validar($condominioId, $dadosNovo);
                $tipoNovo = $dadosNovo;
            } catch (ValidacaoException $e) {
                foreach ($e->erros as $campo => $mensagem) {
                    $erros['novo_tipo_' . $campo] = $mensagem;
                }
            }
        } else {
            $chave = is_string($dados['tipo'] ?? null) ? $dados['tipo'] : '';
            if ($chave === '' || $this->tipos->resolver($permitidos, $condominioId, $chave) === null) {
                $erros['tipo'] = 'Escolha um tipo válido.';
            } else {
                $tipo = $chave;
            }
        }

        $inicio = DataHora::doFormulario(is_string($dados['inicio_em'] ?? null) ? $dados['inicio_em'] : '');
        $fim = DataHora::doFormulario(is_string($dados['fim_em'] ?? null) ? $dados['fim_em'] : '');
        if ($inicio === null) {
            $erros['inicio_em'] = 'Informe uma data e hora de início válidas.';
        }
        if ($fim === null) {
            $erros['fim_em'] = 'Informe uma data e hora de fim válidas.';
        }
        if ($inicio !== null && $fim !== null && $fim <= $inicio) {
            $erros['fim_em'] = 'O fim precisa ser depois do início.';
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        $valores = $validador->valores();

        return [
            'titulo' => $valores['titulo'],
            'corpo' => $valores['corpo'],
            'tipo' => $tipo,
            'tipo_novo' => $tipoNovo,
            'inicio_epoch' => (int) $inicio,
            'fim_epoch' => (int) $fim,
            'ativo' => ($dados['ativo'] ?? '') === '1',
        ];
    }

    /**
     * Se o formulário pediu um tipo novo, cria agora (tudo o mais já foi validado) e usa a chave dele.
     *
     * @param list<int>            $permitidos
     * @param array<string, mixed> $campos
     * @return array<string, mixed> campos sem "tipo_novo" e com "tipo" definido
     */
    private function criarTipoNovoSeHouver(array $permitidos, int $condominioId, array $campos): array
    {
        if ($campos['tipo_novo'] !== null) {
            $campos['tipo'] = $this->tipos->criar($permitidos, $condominioId, $campos['tipo_novo'])['chave'];
        }
        unset($campos['tipo_novo']);

        return $campos;
    }

    /**
     * @return array{nome: string, tipo: string}|null
     * @throws ValidacaoException
     */
    private function guardarMidia(mixed $arquivo): ?array
    {
        if (UploadService::semArquivo($arquivo)) {
            return null;
        }

        try {
            return $this->upload->guardar($arquivo, [UploadService::IMAGEM, UploadService::VIDEO]);
        } catch (UploadInvalidoException $e) {
            throw new ValidacaoException(['midia' => $e->getMessage()]);
        }
    }

    /** @param list<int> $permitidos */
    private function exigirPermitido(array $permitidos, int $condominioId): void
    {
        if (!in_array($condominioId, $permitidos, true)) {
            throw new NaoEncontradoException();
        }
    }
}
