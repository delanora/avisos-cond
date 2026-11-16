<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Response;
use App\Support\SecurityHeaders;
use PHPUnit\Framework\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function testAplicaOsCabecalhosPadrao(): void
    {
        $r = (new SecurityHeaders())->apply(Response::html('x'));

        $this->assertSame('nosniff', $r->headers['X-Content-Type-Options']);
        $this->assertSame('DENY', $r->headers['X-Frame-Options']);
        $this->assertSame('strict-origin-when-cross-origin', $r->headers['Referrer-Policy']);
        $this->assertStringContainsString("default-src 'self'", $r->headers['Content-Security-Policy']);
        $this->assertStringContainsString("frame-ancestors 'none'", $r->headers['Content-Security-Policy']);
        $this->assertStringNotContainsString('unsafe-inline', $r->headers['Content-Security-Policy']);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
    }

    public function testNaoSobrescreveCabecalhoDefinidoPelaRota(): void
    {
        $r = (new SecurityHeaders())->apply(Response::html('x')->withHeader('referrer-policy', 'no-referrer'));

        $this->assertSame('no-referrer', $r->headers['referrer-policy']);
        $this->assertArrayNotHasKey('Referrer-Policy', $r->headers);
    }
}
