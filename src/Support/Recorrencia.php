<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Repetição de um aviso dentro do período dele: dias da semana (bitmask, bit 0 = domingo) e janela diária de
 * horário, sempre em horário de Brasília. A TV aplica a mesma regra em JavaScript (tv.js, recorrenciaAtiva).
 */
final class Recorrencia
{
    public const DIAS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    private const FUSO = 'America/Sao_Paulo';

    /** O aviso aparece neste instante? Sem repetição (tudo nulo) = sempre que o período permitir. */
    public static function ativaEm(int $epoch, ?int $dias, ?string $horaInicio, ?string $horaFim): bool
    {
        $local = (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone(self::FUSO));

        if ($dias !== null && ($dias & (1 << (int) $local->format('w'))) === 0) {
            return false;
        }
        if ($horaInicio !== null && $horaFim !== null) {
            $minutos = (int) $local->format('G') * 60 + (int) $local->format('i');

            return $minutos >= self::minutos($horaInicio) && $minutos < self::minutos($horaFim);
        }

        return true;
    }

    public static function temRepeticao(?int $dias, ?string $horaInicio): bool
    {
        return $dias !== null || $horaInicio !== null;
    }

    /** @param list<int> $lista dias 0 a 6 */
    public static function mascaraDeDias(array $lista): ?int
    {
        $mascara = 0;
        foreach ($lista as $dia) {
            $mascara |= 1 << $dia;
        }

        // Todos os sete dias é o mesmo que não restringir.
        return $mascara === 0 || $mascara === 127 ? null : $mascara;
    }

    /** @return list<int> dias 0 a 6 (vazio = todos) */
    public static function listaDeDias(?int $mascara): array
    {
        $dias = [];
        for ($d = 0; $d < 7; $d++) {
            if ($mascara !== null && ($mascara & (1 << $d)) !== 0) {
                $dias[] = $d;
            }
        }

        return $dias;
    }

    /** "Seg, Qua e Sex · 08:00 às 12:00" (ou null sem repetição). */
    public static function resumo(?int $dias, ?string $horaInicio, ?string $horaFim): ?string
    {
        if (!self::temRepeticao($dias, $horaInicio)) {
            return null;
        }
        $partes = [];
        if ($dias !== null) {
            $nomes = array_map(static fn (int $d): string => self::DIAS[$d], self::listaDeDias($dias));
            $partes[] = count($nomes) > 1 ? implode(', ', array_slice($nomes, 0, -1)) . ' e ' . end($nomes) : $nomes[0];
        } else {
            $partes[] = 'Todos os dias';
        }
        if ($horaInicio !== null && $horaFim !== null) {
            $partes[] = "{$horaInicio} às {$horaFim}";
        }

        return implode(' · ', $partes);
    }

    /** "08:30" → minutos desde a meia-noite. */
    public static function minutos(string $hhmm): int
    {
        return (int) substr($hhmm, 0, 2) * 60 + (int) substr($hhmm, 3, 2);
    }

    /** "08:30" ou "8:30" válido (00:00 a 23:59)? */
    public static function horaValida(string $hhmm): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d\z/', $hhmm) === 1;
    }
}
