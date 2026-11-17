<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Situação de uma TV pelo último contato (heartbeat). A TV consulta o servidor a cada 30-60 s, então 3 minutos
 * sem contato (3 consultas perdidas, com a variação de ±10%) já indicam que ela caiu ou ficou sem internet.
 */
final class SituacaoDispositivo
{
    public const LIMITE_ONLINE_SEGUNDOS = 180;

    public const ONLINE = 'online';
    public const OFFLINE = 'offline';
    public const NUNCA = 'nunca';
    public const REVOGADO = 'revogado';

    public const ROTULOS = [
        self::ONLINE => 'Online',
        self::OFFLINE => 'Offline',
        self::NUNCA => 'Nunca conectou',
        self::REVOGADO => 'Revogado',
    ];

    public static function de(?int $ultimoPingEpoch, bool $ativo, int $agora): string
    {
        return match (true) {
            !$ativo => self::REVOGADO,
            $ultimoPingEpoch === null => self::NUNCA,
            $agora - $ultimoPingEpoch <= self::LIMITE_ONLINE_SEGUNDOS => self::ONLINE,
            default => self::OFFLINE,
        };
    }
}
