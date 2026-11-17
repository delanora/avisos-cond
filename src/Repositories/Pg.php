<?php

declare(strict_types=1);

namespace App\Repositories;

/** Utilidades para falar com o PostgreSQL. */
final class Pg
{
    /**
     * Literal de array de textos do PostgreSQL (`{"a","b"}`) para uso com `= ANY(:nomes::text[])`. Cada item é
     * entre aspas, com aspas e barras escapadas, então nenhum texto consegue sair do seu lugar no array.
     *
     * @param list<string> $textos
     */
    public static function arrayDeTextos(array $textos): string
    {
        return '{' . implode(',', array_map(
            static fn (string $t): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $t) . '"',
            $textos
        )) . '}';
    }

    /**
     * Literal de array do PostgreSQL ("{1,2,3}") a partir de inteiros, para uso com `= ANY(:ids::bigint[])`.
     * Só aceita inteiros, então nada vindo do usuário entra como texto no valor.
     *
     * @param list<int> $ids
     */
    public static function arrayDeInteiros(array $ids): string
    {
        return '{' . implode(',', array_map('intval', $ids)) . '}';
    }
}
