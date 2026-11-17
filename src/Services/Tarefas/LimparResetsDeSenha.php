<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\PasswordResetRepository;

/** Apaga tokens de recuperação de senha já usados ou expirados há mais de 7 dias. Tokens ainda válidos ficam. */
final class LimparResetsDeSenha implements Tarefa
{
    private const IDADE_SEGUNDOS = 7 * 86400;

    public function __construct(private readonly PasswordResetRepository $repositorio)
    {
    }

    public function nome(): string
    {
        return 'limpar_resets_de_senha';
    }

    public function intervaloSegundos(): int
    {
        return 86400;
    }

    public function executar(): string
    {
        $removidos = $this->repositorio->removerAntigos(self::IDADE_SEGUNDOS);

        return "{$removidos} token(s) de recuperação removido(s)";
    }
}
