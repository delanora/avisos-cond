<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\SituacaoDispositivo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SituacaoDispositivoTest extends TestCase
{
    private const AGORA = 1_800_000_000;

    /** @return array<string, array{?int, bool, string}> */
    public static function casos(): array
    {
        return [
            'nunca conectou' => [null, true, 'nunca'],
            'contato agora' => [self::AGORA, true, 'online'],
            'no limite de 3 min ainda é online' => [self::AGORA - 180, true, 'online'],
            '1 s além do limite é offline' => [self::AGORA - 181, true, 'offline'],
            'dias sem contato' => [self::AGORA - 5 * 86400, true, 'offline'],
            'revogado vence qualquer contato' => [self::AGORA, false, 'revogado'],
            'revogado sem contato algum' => [null, false, 'revogado'],
            'relógio do servidor atrás do ping' => [self::AGORA + 5, true, 'online'],
        ];
    }

    #[DataProvider('casos')]
    public function testSituacao(?int $ping, bool $ativo, string $esperado): void
    {
        $this->assertSame($esperado, SituacaoDispositivo::de($ping, $ativo, self::AGORA));
    }

    public function testTodaSituacaoTemRotulo(): void
    {
        foreach ([SituacaoDispositivo::ONLINE, SituacaoDispositivo::OFFLINE, SituacaoDispositivo::NUNCA, SituacaoDispositivo::REVOGADO] as $s) {
            $this->assertArrayHasKey($s, SituacaoDispositivo::ROTULOS);
        }
    }
}
