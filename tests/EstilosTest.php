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
        $this->assertMatchesRegularExpression('/\.tipo-toggle:checked ~ \.tipo-botao\s*\{[^}]*justify-self:\s*stretch/', $this->css, 'Aberto, o botão ocupa a linha toda (cabeçalho do cartão); fechado, volta a ser pequeno.');
        $this->assertMatchesRegularExpression('/\.tipo-bloco \+ \.opcao-simples\s*\{[^}]*margin-top:\s*1\.5rem/', $this->css, 'Espaço entre o cartão e "Salvar como modelo".');
    }

    public function testCelularUsaMenuHamburguerComCondominioSempreVisivel(): void
    {
        $this->assertMatchesRegularExpression('/\.menu-botao\s*\{[^}]*display:\s*none/', $this->css, 'No desktop o hambúrguer não aparece.');
        $this->assertMatchesRegularExpression('/\.topo-painel,\s*\.topo-painel-conteudo\s*\{\s*display:\s*contents/', $this->css, 'No desktop menu e perfil ficam na barra.');
        $this->assertMatchesRegularExpression('/max-width:\s*768px\)\s*\{[^@]*\.menu-botao\s*\{[^}]*display:\s*inline-flex/s', $this->css);
        $this->assertMatchesRegularExpression('/\.menu-toggle:checked ~ \.topo-painel\s*\{[^}]*grid-template-rows:\s*1fr/', $this->css);
        $this->assertMatchesRegularExpression('/\.seletor\s*\{[^}]*order:\s*2[^}]*flex:\s*1 1 0/', $this->css, 'O condomínio ocupa o espaço da barra, ao lado do ícone.');
    }

    public function testBotoesDoTopoNoCelularNaoQuebramELinhaEncurtaRotulos(): void
    {
        $this->assertMatchesRegularExpression('/\.pagina-topo > \.acoes-linha\s*\{[^}]*flex-wrap:\s*nowrap/', $this->css);
        $this->assertMatchesRegularExpression('/max-width:\s*460px\)\s*\{[^@]*\.acoes-linha \.rotulo-longo\s*\{\s*display:\s*none[^@]*\.rotulo-curto\s*\{\s*display:\s*inline/s', $this->css);
        $this->assertMatchesRegularExpression('/^\.rotulo-curto\s*\{\s*display:\s*none/m', $this->css, 'Fora do celular só o rótulo longo aparece.');
    }

    public function testFaviconEhOQuadradoVerdeDaMarcaELigadoEmTodasAsPaginas(): void
    {
        $raiz = dirname(__DIR__);
        $svg = (string) file_get_contents($raiz . '/public/favicon.svg');
        $logo = (string) file_get_contents($raiz . '/templates/partials/logo.html.twig');

        // Mesmo desenho do ícone da barra: quadrado arredondado verde (#00A67E) com três linhas brancas (18, 18 e 11 de largura).
        $this->assertStringContainsString('fill="#00A67E"', $svg);
        $this->assertSame(3, substr_count($svg, 'fill="#FFFFFF"'));
        foreach (['width="18" height="3.5" rx="1.75"', 'width="11" height="3.5" rx="1.75"', 'rx="10"'] as $trecho) {
            $this->assertStringContainsString($trecho, $svg);
            $this->assertStringContainsString($trecho, $logo, "O ícone da barra tem o mesmo desenho ({$trecho}).");
        }
        $this->assertFileExists($raiz . '/public/favicon.ico');
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents($raiz . '/public/apple-touch-icon.png'));
        $this->assertSame([0, 1, 1], array_values(array_slice(unpack('v3', (string) file_get_contents($raiz . '/public/favicon.ico', false, null, 0, 6)), 0, 3)), 'Cabeçalho de um arquivo .ico com 1 imagem.');

        $partial = (string) file_get_contents($raiz . '/templates/partials/favicon.html.twig');
        $this->assertStringContainsString('href="/favicon.svg"', $partial);
        $this->assertStringContainsString('href="/favicon.ico"', $partial);
        $this->assertStringContainsString('href="/apple-touch-icon.png"', $partial);
        foreach (['layouts/base', 'layouts/acesso', 'tv/tela'] as $template) {
            $this->assertStringContainsString("partials/favicon.html.twig", (string) file_get_contents("{$raiz}/templates/{$template}.html.twig"), "{$template} inclui o favicon.");
        }
    }
}
