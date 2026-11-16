<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Services\AparenciaService;
use PHPUnit\Framework\TestCase;

final class AparenciaServiceTest extends TestCase
{
    public function testSemPersonalizacaoUsaOPadraoDaEmbracore(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => null, 'cor_primaria' => null, 'cor_secundaria' => null]);

        $this->assertSame('/assets/logo-embracore.svg', $a['logo']);
        $this->assertTrue($a['logo_padrao']);
        $this->assertSame('#00A67E', $a['cor_primaria']);
        $this->assertSame('#202124', $a['cor_secundaria']);
        $this->assertFalse($a['personalizada']);
    }

    public function testPersonalizacaoParcialMisturaComOPadrao(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => null, 'cor_primaria' => '#112233', 'cor_secundaria' => null]);

        $this->assertSame('#112233', $a['cor_primaria']);
        $this->assertSame('#202124', $a['cor_secundaria']);
        $this->assertTrue($a['personalizada']);
    }

    public function testLogoPersonalizada(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => 'a1b2c3.png', 'cor_primaria' => null, 'cor_secundaria' => null]);

        $this->assertSame('a1b2c3.png', $a['logo']);
        $this->assertFalse($a['logo_padrao']);
        $this->assertTrue($a['personalizada']);
    }

    public function testLogoPersonalizadaUsaAUrlDeEntregaEmVezDoNomeDoArquivo(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => 'a1b2c3.png'], '/gerente/condominios/7/logo');

        $this->assertSame('/gerente/condominios/7/logo', $a['logo']);
        $this->assertFalse($a['logo_padrao']);
    }

    public function testSemLogoAUrlDeEntregaNaoSeAplica(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => null], '/gerente/condominios/7/logo');

        $this->assertSame('/assets/logo-embracore.svg', $a['logo']);
    }

    /** @return array<string, array{string, string}> */
    public static function contrastes(): array
    {
        return [
            'verde da marca pede texto escuro' => ['#00A67E', '#1D2125'],
            'grafite pede texto branco' => ['#202124', '#FFFFFF'],
            'branco pede texto escuro' => ['#FFFFFF', '#1D2125'],
            'preto pede texto branco' => ['#000000', '#FFFFFF'],
            'azul escuro pede branco' => ['#1E3A8A', '#FFFFFF'],
            'amarelo pede escuro' => ['#FACC15', '#1D2125'],
            'inválida cai em branco' => ['azul', '#FFFFFF'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('contrastes')]
    public function testCorDoTextoEscolheOMaiorContraste(string $fundo, string $esperado): void
    {
        $this->assertSame($esperado, AparenciaService::corDoTexto($fundo));
    }

    public function testCorInvalidaCaiParaOPadraoEmVezDeSerUsada(): void
    {
        $a = (new AparenciaService())->resolver(['logo' => null, 'cor_primaria' => 'red" onload="x', 'cor_secundaria' => '#12']);

        $this->assertSame('#00A67E', $a['cor_primaria']);
        $this->assertSame('#202124', $a['cor_secundaria']);
    }

    public function testDadosAusentesUsamOPadrao(): void
    {
        $a = (new AparenciaService())->resolver([]);

        $this->assertSame('#00A67E', $a['cor_primaria']);
        $this->assertTrue($a['logo_padrao']);
    }

    public function testCorValida(): void
    {
        $this->assertTrue(AparenciaService::corValida('#00a67E'));
        $this->assertFalse(AparenciaService::corValida('00A67E'));
        $this->assertFalse(AparenciaService::corValida('#00A67'));
        $this->assertFalse(AparenciaService::corValida('#GGGGGG'));
    }
}
