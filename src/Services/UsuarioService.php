<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;
use App\Support\Validator;

final class UsuarioService
{
    public const SENHA_MIN = 10;
    public const SENHA_MAX = 128;

    public function __construct(
        private readonly UsuarioRepository $usuarios,
        private readonly PasswordHasher $hasher,
    ) {
    }

    /**
     * Cria um usuário com a senha protegida por Argon2id.
     *
     * @throws ValidacaoException dados inválidos ou e-mail já cadastrado
     */
    public function criar(string $nome, string $email, string $senha, string $papel): int
    {
        $erros = $this->validar($nome, $email, $papel);

        $erroSenha = self::validarSenha($senha, $senha);
        if ($erroSenha !== null) {
            $erros['senha'] = $erroSenha;
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return $this->usuarios->criar(trim($nome), self::normalizarEmail($email), $this->hasher->hash($senha), $papel);
    }

    /**
     * Valida nome, e-mail (inclusive se já existe) e papel.
     *
     * @param ?int $ignorarId usuário que está sendo editado (o próprio e-mail dele não conta como duplicado)
     * @return array<string, string> campo => mensagem
     */
    public function validar(string $nome, string $email, string $papel, ?int $ignorarId = null): array
    {
        $validador = new Validator(
            ['nome' => $nome, 'email' => $email, 'papel' => $papel],
            [
                'nome' => 'required|max:150',
                'email' => 'required|email|max:190',
                'papel' => 'required|in:superadmin,gerente',
            ]
        );
        $erros = $validador->errors();

        if (!isset($erros['email'])) {
            $existente = $this->usuarios->porEmail(self::normalizarEmail($email));
            if ($existente !== null && $existente['id'] !== $ignorarId) {
                $erros['email'] = 'Este e-mail já está cadastrado.';
            }
        }

        return $erros;
    }

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Regras de senha novas. Retorna a mensagem de erro ou null se estiver ok. */
    public static function validarSenha(string $senha, string $confirmacao): ?string
    {
        if ($senha === '') {
            return 'Informe a senha.';
        }
        if (mb_strlen($senha) < self::SENHA_MIN) {
            return 'A senha deve ter no mínimo ' . self::SENHA_MIN . ' caracteres.';
        }
        if (mb_strlen($senha) > self::SENHA_MAX) {
            return 'A senha deve ter no máximo ' . self::SENHA_MAX . ' caracteres.';
        }
        if (!hash_equals($senha, $confirmacao)) {
            return 'A confirmação não coincide com a senha.';
        }

        return null;
    }
}
