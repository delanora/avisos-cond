<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Recorrencia;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecorrenciaTest extends TestCase
{
    // 2026-11-17 é terça-feira. Brasília = UTC-3.
    private static function brasilia(string $quando): int
    {
        return (int) (new \DateTimeImmutable($quando . ' America/Sao_Paulo'))->format('U');
    }

    /** @return array<string, array{string, ?int, ?string, ?string, bool}> */
    public static function casos(): array
    {
        $terca = 1 << 2;
        $terEsex = (1 << 2) | (1 << 5);

        return [
            'sem repetição vale sempre' => ['2026-11-17 03:00', null, null, null, true],
            'terça numa terça' => ['2026-11-17 10:00', $terca, null, null, true],
            'terça numa quarta' => ['2026-11-18 10:00', $terca, null, null, false],
            'terça ou sexta, sexta' => ['2026-11-20 10:00', $terEsex, null, null, true],
            'janela: dentro' => ['2026-11-17 08:00', null, '07:00', '09:00', true],
            'janela: no início exato' => ['2026-11-17 07:00', null, '07:00', '09:00', true],
            'janela: um minuto antes' => ['2026-11-17 06:59', null, '07:00', '09:00', false],
            'janela: no fim exato já saiu' => ['2026-11-17 09:00', null, '07:00', '09:00', false],
            'janela: último minuto' => ['2026-11-17 08:59', null, '07:00', '09:00', true],
            'dia e janela, ambos certos' => ['2026-11-17 08:00', $terca, '07:00', '09:00', true],
            'dia certo, hora errada' => ['2026-11-17 12:00', $terca, '07:00', '09:00', false],
            'hora certa, dia errado' => ['2026-11-18 08:00', $terca, '07:00', '09:00', false],
            'meia-noite em Brasília ainda é o dia local (23:59 de terça)' => ['2026-11-17 23:59', $terca, null, null, true],
            'um minuto depois já é quarta' => ['2026-11-18 00:00', $terca, null, null, false],
        ];
    }

    #[DataProvider('casos')]
    public function testAtivaEm(string $quando, ?int $dias, ?string $inicio, ?string $fim, bool $esperado): void
    {
        $this->assertSame($esperado, Recorrencia::ativaEm(self::brasilia($quando), $dias, $inicio, $fim));
    }

    public function testDiaSeguidoDeUtcNaoTrocaODiaDeBrasilia(): void
    {
        // 22:00 em Brasília de terça = 01:00 UTC de quarta: continua sendo terça para o aviso.
        $epoch = (int) (new \DateTimeImmutable('2026-11-18 01:00:00 UTC'))->format('U');

        $this->assertTrue(Recorrencia::ativaEm($epoch, 1 << 2, null, null));
        $this->assertFalse(Recorrencia::ativaEm($epoch, 1 << 3, null, null));
    }

    public function testMascaraEListaDeDias(): void
    {
        $this->assertSame(0b0100100, Recorrencia::mascaraDeDias([2, 5]));
        $this->assertNull(Recorrencia::mascaraDeDias([]), 'Nenhum dia = todos.');
        $this->assertNull(Recorrencia::mascaraDeDias([0, 1, 2, 3, 4, 5, 6]), 'Os sete dias = sem restrição.');
        $this->assertSame([2, 5], Recorrencia::listaDeDias(0b0100100));
        $this->assertSame([], Recorrencia::listaDeDias(null));
        $this->assertSame(0b0000011, Recorrencia::mascaraDeDias([0, 1, 1]), 'Repetido não conta duas vezes.');
    }

    public function testResumoEmPortugues(): void
    {
        $this->assertNull(Recorrencia::resumo(null, null, null));
        $this->assertSame('Ter e Sex · 07:00 às 09:00', Recorrencia::resumo(0b0100100, '07:00', '09:00'));
        $this->assertSame('Seg, Qua e Sex', Recorrencia::resumo(0b0101010, null, null));
        $this->assertSame('Dom', Recorrencia::resumo(1, null, null));
        $this->assertSame('Todos os dias · 18:00 às 20:00', Recorrencia::resumo(null, '18:00', '20:00'));
    }

    /** @return array<string, array{string, bool}> */
    public static function horas(): array
    {
        return ['meia-noite' => ['00:00', true], 'fim do dia' => ['23:59', true], 'sem zero à esquerda' => ['8:00', false], '24h' => ['24:00', false], 'minuto 60' => ['10:60', false], 'texto' => ['abc', false], 'vazio' => ['', false], 'com quebra de linha' => ["08:00\n", false]];
    }

    #[DataProvider('horas')]
    public function testHoraValida(string $hora, bool $esperado): void
    {
        $this->assertSame($esperado, Recorrencia::horaValida($hora));
    }
}
