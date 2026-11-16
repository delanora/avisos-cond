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
