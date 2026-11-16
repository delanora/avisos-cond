<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/** "Bom dia, Maria" conforme o horário de Brasília e o primeiro nome da pessoa. */
final class Saudacao
{
    public static function para(string $nomeCompleto, ?DateTimeImmutable $agora = null): string
    {
        $agora = ($agora ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        $hora = (int) $agora->format('G');

        $periodo = match (true) {
            $hora >= 5 && $hora < 12 => 'Bom dia',
            $hora >= 12 && $hora < 18 => 'Boa tarde',
            default => 'Boa noite',
        };

        $partes = preg_split('/\s+/u', trim($nomeCompleto), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $partes === [] ? $periodo : $periodo . ', ' . $partes[0];
    }
}
