<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Executa os testes da lógica do tv.js (rotação, prioridade do urgente, duração, relógio de Brasília)
 * num Chromium sem interface. Pulado se o Chromium não estiver instalado.
 */
final class TvJsLogicaTest extends TestCase
{
    private function chromium(): ?string
    {
        foreach (['chromium', 'chromium-browser', 'google-chrome'] as $nome) {
            $caminho = trim((string) shell_exec('command -v ' . escapeshellarg($nome) . ' 2>/dev/null'));
            if ($caminho !== '') {
                return $caminho;
            }
        }

        return null;
    }

    public function testLogicaDoJavaScriptDaTv(): void
    {
        $chromium = $this->chromium();
        if ($chromium === null) {
            $this->markTestSkipped('Chromium não instalado: testes de JavaScript pulados.');
        }

        $pagina = dirname(__DIR__) . '/tests/js/logica.html';
        $perfil = sys_get_temp_dir() . '/chromium_teste_' . bin2hex(random_bytes(4));
        $comando = sprintf(
            '%s --headless --no-sandbox --disable-gpu --allow-file-access-from-files --user-data-dir=%s --virtual-time-budget=3000 --dump-dom %s 2>/dev/null',
            escapeshellarg($chromium),
            escapeshellarg($perfil),
            escapeshellarg('file://' . $pagina)
        );

        $dom = (string) shell_exec($comando);
        shell_exec('rm -rf ' . escapeshellarg($perfil));

        // Só o resultado (dentro do <pre>): o código-fonte do script também aparece no DOM exportado.
        preg_match('#<pre id="resultado">(.*?)</pre>#s', $dom, $m);
        $resultado = html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('TODOS OS TESTES PASSARAM', $resultado, "Saída dos testes de JavaScript:\n" . $resultado);
        $this->assertStringNotContainsString('FALHOU', $resultado);
        $this->assertGreaterThan(25, substr_count($resultado, 'OK     '), 'Os testes realmente rodaram.');
    }
}
