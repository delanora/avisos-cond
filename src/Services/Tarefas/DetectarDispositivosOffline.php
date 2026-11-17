<?php

declare(strict_types=1);

namespace App\Services\Tarefas;

use App\Repositories\DispositivoRepository;
use App\Support\SituacaoDispositivo;

/**
 * Guarda no histórico quando uma TV fica offline (sem contato há mais de 3 minutos) e quando volta.
 * A tela do painel não depende desta tarefa para mostrar a situação atual; ela existe para o histórico
 * e para as notificações futuras.
 */
final class DetectarDispositivosOffline implements Tarefa
{
    public function __construct(private readonly DispositivoRepository $dispositivos)
    {
    }

    public function nome(): string
    {
        return 'detectar_dispositivos_offline';
    }

    public function intervaloSegundos(): int
    {
        return 60;
    }

    public function executar(): string
    {
        $mudancas = $this->dispositivos->registrarMudancasDeSituacao(SituacaoDispositivo::LIMITE_ONLINE_SEGUNDOS);

        return "{$mudancas['offline']} ficou(aram) offline, {$mudancas['online']} voltou(aram)";
    }
}
