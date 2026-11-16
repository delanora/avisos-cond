<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\CondominioRepository;
use App\Repositories\UsuarioRepository;
use App\Repositories\VinculoRepository;
use App\Support\NaoEncontradoException;
use App\Support\Transaction;

/** Cadastro de gerentes e administradores feito pelo superadmin (painel /admin). */
final class GestaoUsuariosService
{
    public function __construct(
        private readonly UsuarioService $usuarioService,
        private readonly UsuarioRepository $usuarios,
        private readonly CondominioRepository $condominios,
        private readonly VinculoRepository $vinculos,
        private readonly PasswordHasher $hasher,
        private readonly Transaction $transacao,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        return $this->usuarios->listar();
    }

    /**
     * Usuário para o formulário de edição, com os IDs dos condomínios vinculados.
     *
     * @return array<string, mixed>
     * @throws NaoEncontradoException
     */
    public function buscar(int $id): array
    {
        $usuario = $this->usuarios->porId($id) ?? throw new NaoEncontradoException();
        unset($usuario['senha_hash']);
        $usuario['condominios'] = $this->vinculos->condominiosDoUsuario($id);

        return $usuario;
    }

    /**
     * @param array<string, mixed> $dados nome, email, papel, senha, confirmacao, condominios[]
     * @throws ValidacaoException
     */
    public function criar(array $dados): int
    {
        $nome = self::texto($dados, 'nome');
        $email = self::texto($dados, 'email');
        $papel = self::texto($dados, 'papel');
        $senha = self::bruto($dados, 'senha');

        $erros = $this->usuarioService->validar($nome, $email, $papel);
        $erroSenha = UsuarioService::validarSenha($senha, self::bruto($dados, 'confirmacao'));
        if ($erroSenha !== null) {
            $erros['senha'] = $erroSenha;
        }

        $vinculos = $this->vinculosValidos($dados, $papel, $erros);
        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return $this->transacao->executar(function () use ($nome, $email, $papel, $senha, $vinculos): int {
            $id = $this->usuarios->criar($nome, UsuarioService::normalizarEmail($email), $this->hasher->hash($senha), $papel);
            $this->vinculos->sincronizarDoUsuario($id, $vinculos);

            return $id;
        });
    }

    /**
     * @param array<string, mixed> $dados nome, email, papel, ativo, senha (opcional), confirmacao, condominios[]
     * @param int $atorId quem está editando (não pode mexer no próprio papel nem desativar a si mesmo)
     * @throws ValidacaoException
     * @throws NaoEncontradoException
     */
    public function atualizar(int $id, array $dados, int $atorId): void
    {
        $atual = $this->usuarios->porId($id) ?? throw new NaoEncontradoException();

        $nome = self::texto($dados, 'nome');
        $email = self::texto($dados, 'email');
        $papel = self::texto($dados, 'papel');
        $ativo = ($dados['ativo'] ?? '') === '1';
        $senha = self::bruto($dados, 'senha');
        $confirmacao = self::bruto($dados, 'confirmacao');

        $erros = $this->usuarioService->validar($nome, $email, $papel, $id);

        // Evita que o administrador se tranque para fora do sistema.
        if ($id === $atorId && ($papel !== $atual['papel'] || !$ativo)) {
            $erros['geral'] = 'Você não pode alterar o seu próprio papel nem desativar a sua própria conta.';
        }

        // A senha só muda se for preenchida.
        $trocarSenha = $senha !== '' || $confirmacao !== '';
        if ($trocarSenha) {
            $erroSenha = UsuarioService::validarSenha($senha, $confirmacao);
            if ($erroSenha !== null) {
                $erros['senha'] = $erroSenha;
            }
        }

        $vinculos = $this->vinculosValidos($dados, $papel, $erros);
        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        $this->transacao->executar(function () use ($id, $nome, $email, $papel, $ativo, $trocarSenha, $senha, $vinculos): void {
            $this->usuarios->atualizar($id, $nome, UsuarioService::normalizarEmail($email), $papel, $ativo);
            if ($trocarSenha) {
                // Registra senha_alterada_em: os logins abertos desse usuário deixam de valer.
                $this->usuarios->atualizarSenha($id, $this->hasher->hash($senha));
            }
            $this->vinculos->sincronizarDoUsuario($id, $vinculos);
        });
    }

    /**
     * @throws ValidacaoException ao tentar excluir a própria conta
     * @throws NaoEncontradoException
     */
    public function excluir(int $id, int $atorId): void
    {
        if ($id === $atorId) {
            throw new ValidacaoException(['geral' => 'Você não pode excluir a sua própria conta.']);
        }

        if (!$this->usuarios->excluir($id)) {
            throw new NaoEncontradoException();
        }
    }

    /**
     * Condomínios escolhidos no formulário. Só vale para gerente; superadmin fica sem vínculos.
     *
     * @param array<string, mixed>  $dados
     * @param array<string, string> $erros
     * @return list<int>
     */
    private function vinculosValidos(array $dados, string $papel, array &$erros): array
    {
        if ($papel !== 'gerente') {
            return [];
        }

        $pedidos = ListaDeIds::de($dados['condominios'] ?? []);
        if ($this->condominios->idsExistentes($pedidos) !== $pedidos) {
            $erros['condominios'] = 'Há condomínios inválidos na seleção.';

            return [];
        }

        return $pedidos;
    }

    /** @param array<string, mixed> $dados */
    private static function texto(array $dados, string $chave): string
    {
        return trim(self::bruto($dados, $chave));
    }

    /** Valor sem trim (senhas). @param array<string, mixed> $dados */
    private static function bruto(array $dados, string $chave): string
    {
        $valor = $dados[$chave] ?? '';

        return is_string($valor) ? $valor : '';
    }
}
