<?php

declare(strict_types=1);

namespace App\Repositories;

/** Utilidades para falar com o PostgreSQL. */
final class Pg
{
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
