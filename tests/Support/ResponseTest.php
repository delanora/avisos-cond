<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testHtml(): void
    {
        $r = Response::html('<p>oi</p>', 201);

        $this->assertSame(201, $r->status);
        $this->assertSame('<p>oi</p>', $r->body);
        $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
    }

    public function testJsonMantemAcentosESemEscaparBarras(): void
    {
        $r = Response::json(['msg' => 'ação', 'url' => '/tv/abc']);

        $this->assertSame('{"msg":"ação","url":"/tv/abc"}', $r->body);
        $this->assertSame('application/json; charset=utf-8', $r->headers['Content-Type']);
    }

    public function testRedirect(): void
    {
        $r = Response::redirect('/login', 303);

        $this->assertSame(303, $r->status);
        $this->assertSame('/login', $r->headers['Location']);
    }

    public function testArquivoEntregaOConteudoComTipoESemSniffing(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'resp');
        file_put_contents($tmp, 'conteudo-do-arquivo');

        try {
            $r = Response::arquivo($tmp, 'image/png');

            $this->assertSame($tmp, $r->arquivo);
            $this->assertSame('image/png', $r->headers['Content-Type']);
            $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
            $this->assertSame('19', $r->headers['Content-Length']);

            ob_start();
            $r->send();
            $this->assertSame('conteudo-do-arquivo', ob_get_clean());
        } finally {
            unlink($tmp);
        }
    }

    public function testArquivoComAceleracaoVaiSemCorpoSoComOCabecalhoDoNginx(): void
    {
        $r = Response::arquivo('/var/www/storage/uploads/abc123.png', 'image/png', '/media/');

        $this->assertNull($r->arquivo);
        $this->assertSame('', $r->body);
        $this->assertSame('/media/abc123.png', $r->headers['X-Accel-Redirect']);
    }

    public function testWithHeaderMantemOArquivo(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'resp');
        file_put_contents($tmp, 'x');

        try {
            $this->assertSame($tmp, Response::arquivo($tmp, 'image/png')->withHeader('Cache-Control', 'private')->arquivo);
        } finally {
            unlink($tmp);
        }
    }

    public function testWithHeaderEImutavelESubstituiSemDistinguirCaixa(): void
    {
        $original = new Response(200, '', ['x-teste' => 'a']);
        $novo = $original->withHeader('X-Teste', 'b');

        $this->assertSame(['x-teste' => 'a'], $original->headers);
        $this->assertSame(['X-Teste' => 'b'], $novo->headers);
        $this->assertTrue($novo->hasHeader('x-TESTE'));
        $this->assertFalse($novo->hasHeader('outro'));
    }
}
