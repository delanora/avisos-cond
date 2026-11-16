<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Saudacao;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SaudacaoTest extends TestCase
{
    /** @return array<string, array{string, string}> hora local de Brasília => período */
    public static function periodos(): array
    {
        return [
            '04:59 ainda é madrugada' => ['04:59', 'Boa noite'],
            '05:00 começa a manhã' => ['05:00', 'Bom dia'],
            '11:59' => ['11:59', 'Bom dia'],
            '12:00 começa a tarde' => ['12:00', 'Boa tarde'],
            '17:59' => ['17:59', 'Boa tarde'],
            '18:00 começa a noite' => ['18:00', 'Boa noite'],
            '23:59' => ['23:59', 'Boa noite'],
            '00:00' => ['00:00', 'Boa noite'],
        ];
    }

    #[DataProvider('periodos')]
    public function testPeriodoDoDia(string $hora, string $esperado): void
    {
        $agora = new DateTimeImmutable("2026-11-16 {$hora}:00", new DateTimeZone('America/Sao_Paulo'));

        $this->assertSame($esperado . ', Maria', Saudacao::para('Maria Silva', $agora));
    }

    public function testUsaOFusoDeBrasiliaEmVezDoHorarioUtc(): void
    {
        // 14:00 UTC = 11:00 em Brasília (manhã), embora em UTC já fosse tarde.
        $agora = new DateTimeImmutable('2026-11-16 14:00:00', new DateTimeZone('UTC'));

        $this->assertSame('Bom dia, Maria', Saudacao::para('Maria', $agora));
    }

    public function testSoOPrimeiroNomeEComEspacosExtras(): void
    {
        $agora = new DateTimeImmutable('2026-11-16 10:00:00', new DateTimeZone('America/Sao_Paulo'));

        $this->assertSame('Bom dia, Ana', Saudacao::para("  Ana   Paula\tSouza ", $agora));
    }

    public function testSemNomeSoOPeriodo(): void
    {
        $agora = new DateTimeImmutable('2026-11-16 15:00:00', new DateTimeZone('America/Sao_Paulo'));

        $this->assertSame('Boa tarde', Saudacao::para('   ', $agora));
    }

    public function testSemHorarioInformadoUsaAgora(): void
    {
        $this->assertMatchesRegularExpression('/^(Bom dia|Boa tarde|Boa noite), Pedro$/', Saudacao::para('Pedro'));
    }
}
