<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testDadosValidosPassamEVoltamSemEspacos(): void
    {
        $v = new Validator(
            ['nome' => '  Maria  ', 'email' => 'maria@exemplo.com.br', 'tipo' => 'urgente'],
            ['nome' => 'required|min:2|max:50', 'email' => 'required|email', 'tipo' => 'required|in:comum,urgente']
        );

        $this->assertTrue($v->passes());
        $this->assertSame([], $v->errors());
        $this->assertSame('Maria', $v->valores()['nome']);
    }

    public function testCampoObrigatorioVazioOuAusente(): void
    {
        $v = new Validator(['nome' => '   '], ['nome' => 'required', 'email' => 'required|email']);

        $this->assertFalse($v->passes());
        $this->assertSame('Este campo é obrigatório.', $v->errors()['nome']);
        $this->assertSame('Este campo é obrigatório.', $v->errors()['email']);
    }

    public function testCampoOpcionalVazioNaoValidaOutrasRegras(): void
    {
        $v = new Validator(['email' => ''], ['email' => 'email|max:5']);

        $this->assertTrue($v->passes());
    }

    /** @param array<string, mixed> $dados */
    #[DataProvider('invalidos')]
    public function testRegrasInvalidas(array $dados, string $regra, string $mensagem): void
    {
        $v = new Validator($dados, ['campo' => $regra]);

        $this->assertFalse($v->passes());
        $this->assertSame($mensagem, $v->errors()['campo']);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidos(): array
    {
        return [
            'email' => [['campo' => 'nao-e-email'], 'email', 'Informe um e-mail válido.'],
            'int' => [['campo' => '12a'], 'int', 'Informe um número inteiro.'],
            'min texto' => [['campo' => 'ab'], 'min:3', 'Use no mínimo 3 caracteres.'],
            'max texto' => [['campo' => 'abcd'], 'max:3', 'Use no máximo 3 caracteres.'],
            'min número' => [['campo' => '2'], 'int|min:5', 'O valor mínimo é 5.'],
            'max número' => [['campo' => '9'], 'int|max:5', 'O valor máximo é 5.'],
            'in' => [['campo' => 'outro'], 'in:a,b', 'Valor inválido.'],
            'valor não string' => [['campo' => ['x']], 'required', 'Este campo é obrigatório.'],
        ];
    }

    public function testMinMaxContamCaracteresMultibyte(): void
    {
        $v = new Validator(['campo' => 'ação'], ['campo' => 'max:4']);

        $this->assertTrue($v->passes());
    }
}
