<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\ModeloAvisoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Validator;

/**
 * Modelos de aviso de cada gerente. Os quatro de fábrica (falta de água, elevador, dedetização, assembleia) são
 * copiados para o gerente na primeira vez e depois são dele: ele edita e apaga. O modelo de um gerente nunca
 * aparece para outro.
 */
final class ModeloAvisoService
{
    public const NOME_MAX = 60;

    public function __construct(
        private readonly ModeloAvisoRepository $modelos,
        private readonly TipoAvisoService $tipos,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listar(int $usuarioId): array
    {
        return $this->modelos->listar($usuarioId);
    }

    /**
     * Modelo pronto para preencher o formulário de um aviso novo. Se o tipo sugerido não existe mais para este
     * gerente (ele apagou), o aviso começa com o primeiro tipo que ele tiver.
     *
     * @return array<string, mixed>
     * @throws NaoEncontradoException modelo inexistente ou de outro gerente
     */
    public function paraUsar(int $usuarioId, int $id): array
    {
        $modelo = $this->buscarProprio($usuarioId, $id);
        if ($this->tipos->resolver($usuarioId, $modelo['tipo']) === null) {
            $modelo['tipo'] = $this->tipos->listar($usuarioId)[0]['chave'] ?? 'comum';
        }

        return $modelo;
    }

    /**
     * @return array<string, mixed>
     * @throws NaoEncontradoException modelo inexistente ou de outro gerente
     */
    public function buscarProprio(int $usuarioId, int $id): array
    {
        return $this->modelos->porId($usuarioId, $id) ?? throw new NaoEncontradoException();
    }

    /**
     * @param array<string, mixed> $dados nome, titulo, corpo, tipo
     * @throws ValidacaoException
     */
    public function criar(int $usuarioId, array $dados): int
    {
        $v = $this->validar($usuarioId, $dados);

        return $this->modelos->criar($usuarioId, $v['nome'], $v['titulo'], $v['corpo'], $v['tipo']);
    }

    /**
     * Guarda um aviso já validado como modelo ("salvar como modelo" no formulário). O nome é o título; se já
     * existir, vira "Título (2)", "(3)"...
     */
    public function criarDoAviso(int $usuarioId, string $titulo, string $corpo, string $tipo): void
    {
        $base = mb_substr($titulo, 0, self::NOME_MAX);
        $nome = $base;
        for ($n = 2; $this->modelos->nomeEmUso($usuarioId, $nome) && $n < 50; $n++) {
            $nome = mb_substr($base, 0, self::NOME_MAX - strlen(" ({$n})")) . " ({$n})";
        }
        if (!$this->modelos->nomeEmUso($usuarioId, $nome)) {
            $this->modelos->criar($usuarioId, $nome, $titulo, $corpo, $tipo);
        }
    }

    /**
     * @param array<string, mixed> $dados
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(int $usuarioId, int $id, array $dados): void
    {
        $this->buscarProprio($usuarioId, $id);
        $v = $this->validar($usuarioId, $dados, $id);

        $this->modelos->atualizar($usuarioId, $id, $v['nome'], $v['titulo'], $v['corpo'], $v['tipo']) || throw new NaoEncontradoException();
    }

    /** @throws NaoEncontradoException */
    public function excluir(int $usuarioId, int $id): void
    {
        $this->buscarProprio($usuarioId, $id);

        $this->modelos->excluir($usuarioId, $id) || throw new NaoEncontradoException();
    }

    /**
     * @param array<string, mixed> $dados
     * @return array{nome: string, titulo: string, corpo: string, tipo: string}
     * @throws ValidacaoException
     */
    private function validar(int $usuarioId, array $dados, ?int $exceto = null): array
    {
        $validador = new Validator($dados, [
            'nome' => 'required|max:' . self::NOME_MAX,
            'titulo' => 'required|max:150',
            'corpo' => 'max:' . AvisoService::CORPO_MAX,
        ]);
        $erros = $validador->errors();
        $v = $validador->valores();

        $chave = is_string($dados['tipo'] ?? null) ? $dados['tipo'] : '';
        if ($this->tipos->resolver($usuarioId, $chave) === null) {
            $erros['tipo'] = 'Escolha um tipo válido.';
        }
        if (!isset($erros['nome']) && $this->modelos->nomeEmUso($usuarioId, $v['nome'], $exceto)) {
            $erros['nome'] = 'Já existe um modelo com esse nome.';
        }
        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return ['nome' => $v['nome'], 'titulo' => $v['titulo'], 'corpo' => $v['corpo'], 'tipo' => $chave];
    }
}
