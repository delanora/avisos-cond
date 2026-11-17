<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Guardas de compatibilidade do front da TV. O Fire Stick e as smart TVs podem ter navegadores antigos, e o
 * código da TV não passa por nenhuma etapa de build: estes testes barram sintaxe moderna e padrões inseguros.
 */
final class TvArquivosTest extends TestCase
{
    private function ler(string $caminho): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/public/' . $caminho);
    }

    /** Remove comentários e textos entre aspas, para os padrões abaixo olharem só o código. */
    private function codigo(string $js): string
    {
        $js = (string) preg_replace('#/\*.*?\*/#s', '', $js);
        $js = (string) preg_replace('#//[^\n]*#', '', $js);

        return (string) preg_replace('/(["\'])(?:\\\\.|(?!\1).)*\1/s', '""', $js);
    }

    /** @return array<string, array{string}> */
    public static function scripts(): array
    {
        return ['tv.js' => ['assets/tv.js'], 'sw.js' => ['tv/sw.js']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scripts')]
    public function testScriptsSaoEs5SemSintaxeModerna(string $arquivo): void
    {
        $codigo = $this->codigo($this->ler($arquivo));

        $proibidos = [
            '/=>/' => 'funções seta',
            '/\blet\s/' => 'let',
            '/\bconst\s/' => 'const',
            '/\bclass\s/' => 'class',
            '/`/' => 'template string',
            '/\basync\b/' => 'async',
            '/\bawait\b/' => 'await',
            '/\.\.\./' => 'spread',
            '/\?\./' => 'optional chaining',
            '/\?\?/' => 'nullish coalescing',
            '/\bimport\s/' => 'import',
            '/\bexport\s/' => 'export',
        ];
        foreach ($proibidos as $padrao => $nome) {
            $this->assertDoesNotMatchRegularExpression($padrao, $codigo, "{$arquivo} usa {$nome}, que navegadores antigos não entendem.");
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scripts')]
    public function testScriptsNaoUsamEvalNemInnerHtmlNemDocumentWrite(string $arquivo): void
    {
        $codigo = $this->codigo($this->ler($arquivo));

        foreach (['/\beval\s*\(/', '/new\s+Function\s*\(/', '/\.innerHTML/', '/\.outerHTML/', '/document\.write/', '/insertAdjacentHTML/', '/setTimeout\s*\(\s*""/', '/setInterval\s*\(\s*""/'] as $padrao) {
            $this->assertDoesNotMatchRegularExpression($padrao, $codigo, "{$arquivo}: {$padrao}");
        }
    }

    public function testTvJsNaoUsaFetchNemPromessasNoCodigoDaPagina(): void
    {
        $codigo = $this->codigo($this->ler('assets/tv.js'));

        $this->assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $codigo, 'Usa XMLHttpRequest, que existe em qualquer navegador.');
        $this->assertDoesNotMatchRegularExpression('/\bnew\s+Promise\b/', $codigo);
        $this->assertStringContainsString('XMLHttpRequest', $codigo);
    }

    public function testTvJsMostraTextosDoServidorSoComTextContent(): void
    {
        $js = $this->ler('assets/tv.js');

        $this->assertStringContainsString('.textContent', $js);
        $this->assertStringContainsString("n.textContent = texto", $js);
        $this->assertStringNotContainsString('innerHTML', $this->codigo($js));
    }

    public function testServiceWorkerTemEscopoSoDaTvENaoGuardaErros(): void
    {
        $sw = $this->ler('tv/sw.js');

        $this->assertFileExists(dirname(__DIR__) . '/public/tv/sw.js', 'O SW fica em /tv/ para o escopo não cobrir o painel nem o login.');
        $this->assertStringContainsString('resposta.ok', $sw, 'Só respostas boas entram no cache.');
        $this->assertStringContainsString("'/tv/' ", "'/tv/' " . $sw);
        $this->assertStringNotContainsString("pathname === '/'", $sw);
        $this->assertMatchesRegularExpression('/\[A-Za-z0-9\]\{12\}/', $sw, 'Só cuida de URLs com token de 12 caracteres.');
        $this->assertStringContainsString('self.clients.claim', $sw);
        $this->assertStringContainsString('caches.delete', $sw, 'Apaga caches de versões antigas.');
    }

    public function testTvJsRegistraOServiceWorkerNoEscopoCerto(): void
    {
        $js = $this->ler('assets/tv.js');

        $this->assertStringContainsString("register('/tv/sw.js', { scope: '/tv/' })", $js);
    }

    public function testCssDaTvNaoUsaRecursosModernos(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->ler('assets/tv.css'));

        // A propriedade aspect-ratio é recente; a media query (min-aspect-ratio: 16/9) existe há muito tempo e é usada de propósito.
        $this->assertDoesNotMatchRegularExpression('/(?<![-\w])aspect-ratio\s*:/', $css, 'tv.css usa a propriedade aspect-ratio, ausente em navegadores antigos.');

        foreach (['cqw' => 'container queries', 'display: grid' => 'grid', 'gap:' => 'gap em flex', 'inset:' => 'inset', ':is(' => ':is()', ':where(' => ':where()', ':has(' => ':has()', 'clamp(' => 'clamp()', 'min(' => 'min()', 'max(' => 'max()'] as $trecho => $nome) {
            $this->assertStringNotContainsString($trecho, $css, "tv.css usa {$nome}, ausente em navegadores antigos.");
        }
        $this->assertStringNotContainsString('gradient(', $css);
        $this->assertDoesNotMatchRegularExpression('#url\(\s*["\']?(https?:)?//#i', $css, 'Sem recursos externos.');
    }

    public function testCssDaTvTemPrefixoWebkitParaFlexEUsaVwNaFonte(): void
    {
        $css = $this->ler('assets/tv.css');

        $this->assertStringContainsString('display: -webkit-flex', $css);
        $this->assertStringContainsString('font-size: 1.0417vw', $css, '1em = 1/96 da largura da TV (20px em 1920x1080).');
        $this->assertStringContainsString('(min-aspect-ratio: 16/9)', $css, 'TVs mais largas que 16:9 limitam pela altura.');
    }

    public function testCssDaTvTemAFonteLocalEDestaqueUrgente(): void
    {
        $css = $this->ler('assets/tv.css');

        preg_match('#url\("(/assets/[^"]+)"\)#', $css, $m);
        $this->assertFileExists(dirname(__DIR__) . '/public' . $m[1]);
        $this->assertStringContainsString('.tv-urgente', $css);
        $this->assertStringContainsString('.tv-modo-relogio', $css);
        $this->assertStringContainsString('.tv-modo-ocioso', $css);
    }

    public function testNaoHaMaisEstilosDaTvDuplicadosNoCssDoPainel(): void
    {
        $app = (string) preg_replace('#/\*.*?\*/#s', '', $this->ler('assets/app.css'));
        // A moldura da prévia (só do painel) pode ajustar a cor da borda do aviso urgente; o resto fica em tv.css.
        $app = str_replace('.tv-moldura .tv-urgente', '.tv-moldura', $app);

        foreach (['.tv-topo', '.tv-titulo', '.tv-texto', '.tv-urgente', '.tv-midia', '.tv-corpo'] as $classe) {
            $this->assertStringNotContainsString($classe, $app, "{$classe} fica só em tv.css.");
        }
    }
}
