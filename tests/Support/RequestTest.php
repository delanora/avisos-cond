<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function testIpVemSomenteDeRemoteAddr(): void
    {
        $r = new Request('GET', '/', server: [
            'REMOTE_ADDR' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '10.9.9.9',
        ]);

        $this->assertSame('203.0.113.7', $r->ip());
    }

    public function testIpPadraoQuandoAusente(): void
    {
        $this->assertSame('0.0.0.0', (new Request('GET', '/'))->ip());
    }

    public function testHeader(): void
    {
        $r = new Request('GET', '/', server: ['HTTP_X_CSRF_TOKEN' => 'abc']);

        $this->assertSame('abc', $r->header('X-CSRF-Token'));
        $this->assertNull($r->header('X-Outro'));
    }

    public function testAtributosSaoImutaveis(): void
    {
        $original = new Request('GET', '/');
        $novo = $original->withAttribute('usuario', ['id' => 1]);

        $this->assertNull($original->attribute('usuario'));
        $this->assertSame(['id' => 1], $novo->attribute('usuario'));
        $this->assertSame('padrao', $novo->attribute('outro', 'padrao'));
    }

    public function testFromGlobals(): void
    {
        $antes = [$_SERVER, $_GET, $_POST, $_COOKIE];
        $_SERVER = ['REQUEST_METHOD' => 'post', 'REQUEST_URI' => '/tv/abc%20d/state?x=1'];
        $_GET = ['x' => '1'];
        $_POST = ['a' => 'b'];
        $_COOKIE = ['c' => 'd'];

        try {
            $r = Request::fromGlobals();
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE] = $antes;
        }

        $this->assertSame('POST', $r->method);
        $this->assertSame('/tv/abc d/state', $r->path);
        $this->assertSame(['x' => '1'], $r->query);
        $this->assertSame(['a' => 'b'], $r->body);
        $this->assertSame(['c' => 'd'], $r->cookies);
    }
}
