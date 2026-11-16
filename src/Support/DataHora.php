<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Datas no sistema: guardadas em UTC (timestamptz) e mostradas em America/Sao_Paulo.
 * Aqui os instantes circulam como "epoch" (segundos desde 1970), que não depende de fuso.
 */
final class DataHora
{
    public const FUSO = 'America/Sao_Paulo';
    private const FORMATO_FORMULARIO = 'Y-m-d\TH:i';

    /** Valor de <input type="datetime-local"> (hora de Brasília) → epoch, ou null se inválido. */
    public static function doFormulario(string $valor): ?int
    {
        $data = DateTimeImmutable::createFromFormat('!' . self::FORMATO_FORMULARIO, trim($valor), new DateTimeZone(self::FUSO));
        if ($data === false || $data->format(self::FORMATO_FORMULARIO) !== trim($valor)) {
            return null;
        }

        return $data->getTimestamp();
    }

    /** Epoch → valor para <input type="datetime-local"> (hora de Brasília). */
    public static function paraFormulario(int $epoch): string
    {
        return self::local($epoch)->format(self::FORMATO_FORMULARIO);
    }

    /** Epoch → "16/11/2026 14:30" (hora de Brasília). */
    public static function exibir(int $epoch): string
    {
        return self::local($epoch)->format('d/m/Y H:i');
    }

    /** Epoch → texto ISO 8601 com fuso, aceito pelo PostgreSQL em colunas timestamptz. */
    public static function paraBanco(int $epoch): string
    {
        return (new DateTimeImmutable('@' . $epoch))->format('c');
    }

    private static function local(int $epoch): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone(self::FUSO));
    }
}
