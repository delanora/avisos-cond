<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Transaction;
use App\Support\Validator;

/** Cadastro de condomínios e dos gerentes vinculados, feito pelo superadmin (painel /admin). */
final class GestaoCondominiosService
{
    public function __construct(
        private readonly CondominioRepository $condominios,
        private readonly UsuarioRepository $usuarios,
        private readonly VinculoRepository $vinculos,
        private readonly Transaction $transacao,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->condominios->listar();
    }

    /**
     * Condomínio para o formulário de edição, com os IDs dos gerentes vinculados.
     *
     * @return array<string, mixed>
     * @throws NaoEncontradoException
     */
    public function buscar(int $id): array
    {
        $condominio = $this->condominios->porId($id) ?? throw new NaoEncontradoException();
        $condominio['gerentes'] = $this->vinculos->usuariosDoCondominio($id);

        return $condominio;
    }

    /** @return list<array{id: int, nome: string, email: string, ativo: bool}> */
    public function gerentesDisponiveis(): array
    {
        return $this->usuarios->listarGerentes();
    }

    /**
     * @param array<string, mixed> $dados nome, endereco, ativo, gerentes[]
     * @throws ValidacaoException
     */
    public function criar(array $dados): int
    {
        [$nome, $endereco, $ativo, $gerentes] = $this->validar($dados);

        return $this->transacao->executar(function () use ($nome, $endereco, $ativo, $gerentes): int {
            $id = $this->condominios->criar($nome, $endereco, $ativo);
            $this->vinculos->sincronizarDoCondominio($id, $gerentes);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $dados nome, endereco, ativo, gerentes[]
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(int $id, array $dados): void
    {
        $this->condominios->porId($id) ?? throw new NaoEncontradoException();
        [$nome, $endereco, $ativo, $gerentes] = $this->validar($dados);

        $this->transacao->executar(function () use ($id, $nome, $endereco, $ativo, $gerentes): void {
            $this->condominios->atualizar($id, $nome, $endereco, $ativo);
            $this->vinculos->sincronizarDoCondominio($id, $gerentes);
        });
    }

    /** O que será apagado junto, para a tela de confirmação. @return array{avisos: int, dispositivos: int, gerentes: int} */
    public function dependencias(int $id): array
    {
        $this->condominios->porId($id) ?? throw new NaoEncontradoException();

        return $this->condominios->dependencias($id);
    }

    /** @throws NaoEncontradoException */
    public function excluir(int $id): void
    {
        if (!$this->condominios->excluir($id)) {
            throw new NaoEncontradoException();
        }
    }

    /**
     * @param array<string, mixed> $dados
     * @return array{0: string, 1: ?string, 2: bool, 3: list<int>}
     * @throws ValidacaoException
     */
    private function validar(array $dados): array
    {
        $validador = new Validator($dados, ['nome' => 'required|max:150', 'endereco' => 'max:255']);
        $erros = $validador->errors();

        $gerentes = ListaDeIds::de($dados['gerentes'] ?? []);
        // Só gerentes existentes podem ser vinculados (superadmin não tem vínculo).
        if ($this->usuarios->idsDeGerentes($gerentes) !== $gerentes) {
            $erros['gerentes'] = 'Há gerentes inválidos na seleção.';
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        $valores = $validador->valores();

        return [
            $valores['nome'],
            $valores['endereco'] === '' ? null : $valores['endereco'],
            ($dados['ativo'] ?? '') === '1',
            $gerentes,
        ];
    }
}
