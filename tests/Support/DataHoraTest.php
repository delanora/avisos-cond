<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\DataHora;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DataHoraTest extends TestCase
{
    public function testHoraDeBrasiliaViraEpochUtc(): void
    {
        // 14:30 em Brasília (UTC-3) = 17:30 UTC.
        $epoch = DataHora::doFormulario('2026-11-16T14:30');

        $this->assertSame(gmmktime(17, 30, 0, 11, 16, 2026), $epoch);
    }

    public function testIdaEVoltaNaoMudaOValor(): void
    {
        $epoch = (int) DataHora::doFormulario('2026-12-31T23:59');

        $this->assertSame('2026-12-31T23:59', DataHora::paraFormulario($epoch));
        $this->assertSame('31/12/2026 23:59', DataHora::exibir($epoch));
    }

    public function testViradaDeDiaPeloFuso(): void
    {
        // 01:00 UTC do dia 17 ainda é 22:00 do dia 16 em Brasília.
        $this->assertSame('2026-11-16T22:00', DataHora::paraFormulario(gmmktime(1, 0, 0, 11, 17, 2026)));
    }

    /** @return array<string, array{string}> */
    public static function invalidos(): array
    {
        return [
            'vazio' => [''],
            'texto' => ['amanhã'],
            'mês 13' => ['2026-13-01T10:00'],
            'dia inexistente' => ['2026-02-30T10:00'],
            'hora 25' => ['2026-11-16T25:00'],
            'sem T' => ['2026-11-16 14:30'],
            'com segundos' => ['2026-11-16T14:30:00'],
            'só a data' => ['2026-11-16'],
            'com lixo no fim' => ['2026-11-16T14:30abc'],
        ];
    }

    #[DataProvider('invalidos')]
    public function testValoresInvalidosViramNull(string $valor): void
    {
        $this->assertNull(DataHora::doFormulario($valor));
    }

    public function testToleraEspacosNasPontas(): void
    {
        $this->assertNotNull(DataHora::doFormulario('  2026-11-16T14:30 '));
    }

    public function testParaBancoGeraIso8601ComFuso(): void
    {
        $this->assertSame('2026-11-16T17:30:00+00:00', DataHora::paraBanco(gmmktime(17, 30, 0, 11, 16, 2026)));
    }
}
