<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\AuditoriaRepository;

/** Apaga registros de auditoria além do prazo de retenção (AUDITORIA_RETENCAO_DIAS, padrão 365). É a única forma de remover registros. */
final class LimparAuditoria implements Tarefa
{
    public function __construct(
        private readonly AuditoriaRepository $repositorio,
        private readonly int $retencaoDias,
    ) {
    }

    public function nome(): string
    {
        return 'limpar_auditoria';
    }

    public function intervaloSegundos(): int
    {
        return 86400;
    }

    public function executar(): string
    {
        $removidos = $this->repositorio->removerAntigos(max(30, $this->retencaoDias));

        return "{$removidos} registro(s) de auditoria removido(s)";
    }
}
