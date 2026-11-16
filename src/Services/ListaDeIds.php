<?php

declare(strict_types=1);

namespace App\Services;

/** Converte a lista de checkboxes de um formulário (["3", "7"]) em inteiros positivos sem repetição. */
final class ListaDeIds
{
    /** @return list<int> */
    public static function de(mixed $valor): array
    {
        if (!is_array($valor)) {
            return [];
        }

        $ids = [];
        foreach ($valor as $item) {
            if (is_string($item) && preg_match('/^[1-9][0-9]{0,17}$/', $item) === 1) {
                $ids[(int) $item] = true;
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }
}
