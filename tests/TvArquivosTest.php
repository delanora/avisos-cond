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

    /**
     * Remove comentários e troca o conteúdo dos textos entre aspas por "", para os padrões abaixo olharem só o
     * código. Um único passe da esquerda para a direita: um "//" dentro de um texto (ex.: uma URL) não é comentário.
     */
    private function codigo(string $js): string
    {
        return (string) preg_replace_callback(
            '~"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|/\*.*?\*/|//[^\n]*~s',
            static fn (array $m): string => ($m[0][0] === '"' || $m[0][0] === "'") ? '""' : '',
            $js
        );
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

    public function testTiposDoJavaScriptEstaoEmSincroniaComOsDoPhp(): void
    {
        $js = $this->ler('assets/tv.js');

        // Todos os tipos conhecidos pelo painel têm rótulo na TV.
        foreach (array_keys(\App\Services\AvisoService::TIPOS) as $tipo) {
            $this->assertMatchesRegularExpression('/\b' . $tipo . ':\s*\'/', $js, "tv.js não tem o rótulo do tipo '{$tipo}'.");
        }
        // Os tipos da faixa do topo são os mesmos nos dois lados.
        foreach (\App\Services\AvisoService::TIPOS_FIXOS as $tipo) {
            $this->assertStringContainsString("aviso.tipo === '{$tipo}'", $js, "ehFixo() do tv.js não trata '{$tipo}'.");
        }
        $this->assertCount(2, \App\Services\AvisoService::TIPOS_FIXOS);
        $this->assertSame([], array_diff(\App\Services\AvisoService::TIPOS_FIXOS, array_keys(\App\Services\AvisoService::TIPOS)));
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

    public function testFaixaDeFixosOcupa40PorCentoDaAlturaEDivideEntreOsAvisos(): void
    {
        $css = $this->ler('assets/tv.css');

        $this->assertMatchesRegularExpression('/\.tv-fixos\s*\{[^}]*flex:\s*0 0 40%/', $css, 'A faixa tem altura fixa de 40% da tela.');
        foreach ([1, 2, 3, 4, 5] as $colunas) {
            $this->assertStringContainsString(".tv-fixos-cols-{$colunas}", $css, "Disposição com {$colunas} coluna(s).");
        }
        $this->assertStringContainsString('.tv-fixos-rows-2 .tv-fixo', $css);
        // A letra encolhe quanto mais colunas.
        preg_match_all('/\.tv-fixos-cols-([1-5])\.tv-fixos-rows-1 \.tv-fixo-conteudo\s*\{\s*font-size:\s*([0-9.]+)em/', $css, $m);
        $escalas = array_combine($m[1], array_map('floatval', $m[2]));
        $this->assertGreaterThan($escalas['2'], $escalas['1']);
        $this->assertGreaterThan($escalas['3'], $escalas['2']);
        $this->assertGreaterThan($escalas['4'], $escalas['3']);
        $this->assertGreaterThan($escalas['5'], $escalas['4']);
        $this->assertStringContainsString('-webkit-line-clamp', $css, 'Texto longo não estoura o cartão.');
        $this->assertStringNotContainsString('-webkit-line-clamp: 0', $css);
    }

    public function testDesignDosCartoesDaFaixa(): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->ler('assets/tv.css'));
        $regra = static function (string $seletor) use ($css): string {
            preg_match('/(?:^|\})\s*' . preg_quote($seletor, '/') . '\s*\{([^}]*)\}/m', $css, $m);

            return $m[1] ?? '';
        };

        // Sem moldura lateral esquerda: nenhum border-left nos cartões fixos.
        $this->assertStringNotContainsString('border-left', $regra('.tv-fixo'));
        $this->assertStringNotContainsString('border-left', $css, 'Cartões sem a moldura lateral esquerda.');
        // Sombra suave nos cartões (fixos e carrossel).
        $this->assertMatchesRegularExpression('/\.tv-corpo,\s*\.tv-fixo\s*\{[^}]*box-shadow:/', $css);
        // A faixa não tem fundo nem linha de separação: os cartões ficam sobre o mesmo fundo do carrossel.
        $faixa = $regra('.tv-fixos');
        $this->assertStringNotContainsString('background', $faixa);
        $this->assertDoesNotMatchRegularExpression('/(^|[;\s])border(-[a-z-]+)?\s*:/', $faixa, 'Sem linha de separação (box-sizing: border-box é permitido).');
        // A hora é sempre branca, independente da cor do condomínio.
        $this->assertMatchesRegularExpression('/\.tv-relogio\s*\{[^}]*color:\s*#FFFFFF/i', $css);
        $this->assertStringNotContainsString('tv-primaria-texto', $css, 'O texto da barra não depende mais da cor do condomínio.');
    }

    public function testCssDaTvTemAFonteLocalEDestaqueUrgente(): void
    {
        $css = $this->ler('assets/tv.css');

        preg_match('#url\("(/assets/[^"]+)"\)#', $css, $m);
        $this->assertFileExists(dirname(__DIR__) . '/public' . $m[1]);
        $this->assertStringContainsString('.tv-fixos', $css, 'Faixa do topo para avisos fixos e urgentes.');
        $this->assertStringContainsString('.tv-fixo-urgente', $css);
        $this->assertStringContainsString('.tv-sem-carrossel', $css);
        $this->assertStringContainsString('.tv-modo-relogio', $css);
        $this->assertStringContainsString('.tv-modo-ocioso', $css);
    }

    public function testNaoHaMaisEstilosDaTvDuplicadosNoCssDoPainel(): void
    {
        $app = (string) preg_replace('#/\*.*?\*/#s', '', $this->ler('assets/app.css'));

        foreach (['.tv-topo', '.tv-titulo', '.tv-texto', '.tv-fixos', '.tv-fixo', '.tv-midia', '.tv-corpo'] as $classe) {
            $this->assertStringNotContainsString($classe, $app, "{$classe} fica só em tv.css.");
        }
    }
}
