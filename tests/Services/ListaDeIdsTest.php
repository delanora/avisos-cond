<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\ListaDeIds;
use PHPUnit\Framework\TestCase;

final class ListaDeIdsTest extends TestCase
{
    public function testConverteOrdenaERemoveRepetidos(): void
    {
        $this->assertSame([2, 5, 9], ListaDeIds::de(['9', '2', '5', '2']));
    }

    public function testIgnoraValoresInvalidos(): void
    {
        $this->assertSame([3], ListaDeIds::de(['3', '0', '-1', 'abc', '1; DROP TABLE x', '', '07', '1.5', ['4'], 6, null]));
    }

    public function testEntradaQueNaoELista(): void
    {
        $this->assertSame([], ListaDeIds::de('3'));
        $this->assertSame([], ListaDeIds::de(null));
        $this->assertSame([], ListaDeIds::de([]));
    }

    public function testRejeitaNumeroGrandeDemais(): void
    {
        $this->assertSame([], ListaDeIds::de(['99999999999999999999']));
    }
}
