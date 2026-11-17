<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ModeloAvisoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Validator;

/** Modelos de aviso do condomínio: os do sistema (falta de água, elevador, dedetização, assembleia) e os do gerente. */
final class ModeloAvisoService
{
    public const NOME_MAX = 60;

    public function __construct(
        private readonly ModeloAvisoRepository $modelos,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    /**
     * @param list<int> $permitidos
     * @return list<array<string, mixed>>
     */
    public function listar(array $permitidos, int $condominioId): array
    {
        $this->exigirPermitido($permitidos, $condominioId);

        return $this->modelos->listar($permitidos, $condominioId);
    }

    /**
     * Modelo pronto para preencher o formulário de um aviso novo. Se o tipo sugerido não existe mais neste
     * condomínio, o aviso começa como "comum".
     *
     * @param list<int> $permitidos
     * @return array<string, mixed>
     * @throws NaoEncontradoException modelo inexistente ou de outro condomínio
     */
    public function paraUsar(array $permitidos, int $condominioId, int $id): array
    {
        $this->exigirPermitido($permitidos, $condominioId);
        $modelo = $this->modelos->utilizavelPorId($permitidos, $condominioId, $id) ?? throw new NaoEncontradoException();
        if ($this->tipos->resolver($permitidos, $condominioId, $modelo['tipo']) === null) {
            $modelo['tipo'] = 'comum';
        }

        return $modelo;
    }

    /**
     * @param list<int> $permitidos
     * @return array<string, mixed>
     * @throws NaoEncontradoException modelo do sistema, inexistente ou de outro condomínio
     */
    public function buscarProprio(array $permitidos, int $condominioId, int $id): array
    {
        $this->exigirPermitido($permitidos, $condominioId);

        return $this->modelos->proprioPorId($permitidos, $condominioId, $id) ?? throw new NaoEncontradoException();
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados nome, titulo, corpo, tipo
     * @throws ValidacaoException
     */
    public function criar(array $permitidos, int $condominioId, array $dados): int
    {
        $this->exigirPermitido($permitidos, $condominioId);
        $v = $this->validar($permitidos, $condominioId, $dados);

        return $this->modelos->criar($permitidos, $condominioId, $v['nome'], $v['titulo'], $v['corpo'], $v['tipo']) ?? throw new NaoEncontradoException();
    }

    /**
     * Guarda um aviso já validado como modelo ("salvar como modelo" no formulário). O nome é o título; se já
     * existir, vira "Título (2)", "(3)"... Nunca falha o aviso por causa disso: se não der, simplesmente não grava.
     *
     * @param list<int> $permitidos
     */
    public function criarDoAviso(array $permitidos, int $condominioId, string $titulo, string $corpo, string $tipo): void
    {
        $this->exigirPermitido($permitidos, $condominioId);
        $base = mb_substr($titulo, 0, self::NOME_MAX);
        $nome = $base;
        for ($n = 2; $this->modelos->nomeEmUso($condominioId, $nome) && $n < 50; $n++) {
            $nome = mb_substr($base, 0, self::NOME_MAX - strlen(" ({$n})")) . " ({$n})";
        }
        if (!$this->modelos->nomeEmUso($condominioId, $nome)) {
            $this->modelos->criar($permitidos, $condominioId, $nome, $titulo, $corpo, $tipo);
        }
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(array $permitidos, int $condominioId, int $id, array $dados): void
    {
        $this->buscarProprio($permitidos, $condominioId, $id);
        $v = $this->validar($permitidos, $condominioId, $dados, $id);

        $this->modelos->atualizar($permitidos, $condominioId, $id, $v['nome'], $v['titulo'], $v['corpo'], $v['tipo']) || throw new NaoEncontradoException();
    }

    /**
     * @param list<int> $permitidos
     * @throws NaoEncontradoException
     */
    public function excluir(array $permitidos, int $condominioId, int $id): void
    {
        $this->buscarProprio($permitidos, $condominioId, $id);

        $this->modelos->excluir($permitidos, $condominioId, $id) || throw new NaoEncontradoException();
    }

    /**
     * @param list<int>            $permitidos
     * @param array<string, mixed> $dados
     * @return array{nome: string, titulo: string, corpo: string, tipo: string}
     * @throws ValidacaoException
     */
    private function validar(array $permitidos, int $condominioId, array $dados, ?int $exceto = null): array
    {
        $validador = new Validator($dados, [
            'nome' => 'required|max:' . self::NOME_MAX,
            'titulo' => 'required|max:150',
            'corpo' => 'max:' . AvisoService::CORPO_MAX,
        ]);
        $erros = $validador->errors();
        $v = $validador->valores();

        $chave = is_string($dados['tipo'] ?? null) ? $dados['tipo'] : '';
        if ($this->tipos->resolver($permitidos, $condominioId, $chave) === null) {
            $erros['tipo'] = 'Escolha um tipo válido.';
        }
        if (!isset($erros['nome']) && $this->modelos->nomeEmUso($condominioId, $v['nome'], $exceto)) {
            $erros['nome'] = 'Já existe um modelo com esse nome.';
        }
        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return ['nome' => $v['nome'], 'titulo' => $v['titulo'], 'corpo' => $v['corpo'], 'tipo' => $chave];
    }

    /** @param list<int> $permitidos */
    private function exigirPermitido(array $permitidos, int $condominioId): void
    {
        if (!in_array($condominioId, $permitidos, true)) {
            throw new NaoEncontradoException();
        }
    }
}
