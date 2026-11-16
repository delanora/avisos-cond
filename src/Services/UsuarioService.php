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
        $validador = new Validator(
            ['nome' => $nome, 'email' => $email, 'senha' => $senha, 'papel' => $papel],
            [
                'nome' => 'required|max:150',
                'email' => 'required|email|max:190',
                // A senha não passa por trim: espaços fazem parte dela.
                'papel' => 'required|in:superadmin,gerente',
            ]
        );

        $erros = $validador->errors();
        $erroSenha = self::validarSenha($senha, $senha);
        if ($erroSenha !== null) {
            $erros['senha'] = $erroSenha;
        }

        $emailNormalizado = mb_strtolower(trim($email));
        if (!isset($erros['email']) && $this->usuarios->porEmail($emailNormalizado) !== null) {
            $erros['email'] = 'Este e-mail já está cadastrado.';
        }

        if ($erros !== []) {
            throw new ValidacaoException($erros);
        }

        return $this->usuarios->criar(trim($nome), $emailNormalizado, $this->hasher->hash($senha), $papel);
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
