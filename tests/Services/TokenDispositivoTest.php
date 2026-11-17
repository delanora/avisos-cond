<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\TokenDispositivo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TokenDispositivoTest extends TestCase
{
    public function testTemDozeCaracteresDoAlfabetoSemAmbiguos(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $token = TokenDispositivo::gerar();

            $this->assertSame(12, strlen($token));
            $this->assertMatchesRegularExpression('/^[23456789ABCDEFGHJKMNPQRSTUVWXYZ]{12}$/', $token);
            $this->assertDoesNotMatchRegularExpression('/[01OIL]/', $token, 'Sem caracteres que se confundem ao digitar.');
        }
    }

    public function testAlfabetoNaoTemRepeticaoNemCaracteresAmbiguos(): void
    {
        $this->assertSame(31, strlen(TokenDispositivo::ALFABETO));
        $this->assertSame(31, count(array_unique(str_split(TokenDispositivo::ALFABETO))));
        foreach (str_split('01OIL') as $ambiguo) {
            $this->assertStringNotContainsString($ambiguo, TokenDispositivo::ALFABETO);
        }
    }

    public function testTokensSaoDiferentesEUsamTodoOAlfabeto(): void
    {
        $tokens = [];
        $usados = [];
        for ($i = 0; $i < 400; $i++) {
            $token = TokenDispositivo::gerar();
            $tokens[$token] = true;
            foreach (str_split($token) as $c) {
                $usados[$c] = true;
            }
        }

        $this->assertCount(400, $tokens, 'Em 400 sorteios não pode haver repetição.');
        $this->assertCount(31, $usados, 'Com 4.800 caracteres sorteados, todos os 31 símbolos devem aparecer.');
    }

    /** @return array<string, array{string, ?string}> */
    public static function normalizacoes(): array
    {
        return [
            'maiúsculas' => ['ABCDEFGH2345', 'ABCDEFGH2345'],
            'minúsculas (teclado do controle remoto)' => ['abcdefgh2345', 'ABCDEFGH2345'],
            'misturado' => ['AbCdEfGh2345', 'ABCDEFGH2345'],
            'curto demais' => ['ABCDEFGH234', null],
            'longo demais' => ['ABCDEFGH23456', null],
            'caractere ambíguo (0)' => ['ABCDEFGH2340', null],
            'caractere ambíguo (O)' => ['ABCDEFGH234O', null],
            'símbolo' => ['ABCDEFGH234-', null],
            'espaço' => ['ABCDEFGH234 ', null],
            'vazio' => ['', null],
            'injeção' => ["ABCDEFGH2345'--", null],
        ];
    }

    #[DataProvider('normalizacoes')]
    public function testNormalizar(string $entrada, ?string $esperado): void
    {
        $this->assertSame($esperado, TokenDispositivo::normalizar($entrada));
    }
}
