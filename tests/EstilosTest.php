<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/** Garante que os arquivos de estilo seguem as regras do projeto (CSP, fonte local, barra branca). */
final class EstilosTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
    }

    public function testBarraSuperiorEBranca(): void
    {
        $this->assertMatchesRegularExpression('/\.topo\s*\{[^}]*background:\s*rgba\(255,\s*255,\s*255/', $this->css);
    }

    public function testNaoCarregaNadaDeFora(): void
    {
        // O CSP só permite recursos do próprio site (sem CDN, sem @import).
        $this->assertStringNotContainsString('@import', $this->css);
        $this->assertDoesNotMatchRegularExpression('#url\(\s*["\']?(https?:)?//#i', $this->css);
    }

    public function testArquivosReferenciadosNoCssExistem(): void
    {
        preg_match_all('#url\("(/assets/[^"]+)"\)#', $this->css, $m);

        $this->assertNotEmpty($m[1], 'O CSS deve referenciar a fonte local.');
        foreach ($m[1] as $caminho) {
            $this->assertFileExists(dirname(__DIR__) . '/public' . $caminho, $caminho);
        }
    }

    public function testFonteInterTemLicencaJuntoDoArquivo(): void
    {
        $this->assertFileExists(dirname(__DIR__) . '/public/assets/fonts/OFL-Inter.txt');
        $this->assertStringContainsString('SIL Open Font License', (string) file_get_contents(dirname(__DIR__) . '/public/assets/fonts/OFL-Inter.txt'));
    }

    public function testRespeitaPreferenciaDeMenosMovimento(): void
    {
        $this->assertStringContainsString('prefers-reduced-motion', $this->css);
    }

    public function testNaoUsaGradientes(): void
    {
        $this->assertStringNotContainsString('gradient(', $this->css, 'Visual plano, sem gradientes (DESIGN.md).');
    }

    public function testCartaoDeCriarTipoAbreComTransicaoSuaveERespeitaMovimentoReduzido(): void
    {
        $this->assertMatchesRegularExpression('/\.tipo-novo\s*\{[^}]*grid-template-rows:\s*0fr[^}]*transition:[^}]*grid-template-rows/', $this->css, 'O cartão anima a altura (0fr → 1fr).');
        $this->assertMatchesRegularExpression('/\.tipo-bloco\s*\{[^}]*transition:\s*grid-template-columns/', $this->css, 'O dropdown encolhe aos poucos.');
        $this->assertMatchesRegularExpression('/\.tipo-toggle:checked ~ \.tipo-novo\s*\{[^}]*grid-template-rows:\s*1fr/', $this->css);
        $this->assertMatchesRegularExpression('/prefers-reduced-motion:\s*reduce\)\s*\{[^}]*\.tipo-novo[^}]*transition:\s*none/s', $this->css);
        $this->assertMatchesRegularExpression('/\.tipo-bloco \+ \.opcao-simples\s*\{[^}]*margin-top:\s*1\.5rem/', $this->css, 'Espaço entre o cartão e "Salvar como modelo".');
    }
}
